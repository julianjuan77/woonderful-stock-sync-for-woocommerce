<?php
/**
 * El namespace REST del admin.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Admin;

use Woonderful\StockSync\Catalog\Bulk;
use Woonderful\StockSync\Catalog\Copy;
use Woonderful\StockSync\Catalog\Rows;
use Woonderful\StockSync\Detector\Detector;
use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Linking\Linker;
use Woonderful\StockSync\Linking\Links;
use Woonderful\StockSync\Log\Events;
use Woonderful\StockSync\Log\Notifier;
use Woonderful\StockSync\Log\Peer;
use Woonderful\StockSync\Plugin;
use Woonderful\StockSync\Reconciliation\Audit;
use Woonderful\StockSync\Reconciliation\Reconciler;
use Woonderful\StockSync\Reconciliation\Result;
use Woonderful\StockSync\Transport\Catalog;
use Woonderful\StockSync\Transport\Pairing;
use Woonderful\StockSync\Transport\Peers;

defined( 'ABSPATH' ) || exit;

/**
 * `wss-admin/v1`: lo que llama la app, en el navegador del dueño (ADR-013 §4).
 *
 * Separado de `wss/v1`, que llama la otra tienda: éste se autentica con la sesión de WordPress
 * y su nonce, aquél con la firma. Cada endpoint pide `manage_woocommerce`, y llama a las mismas
 * clases que WP-CLI: la pantalla no tiene lógica propia.
 */
final class Api {

	/**
	 * El namespace.
	 */
	public const NAMESPACE = 'wss-admin/v1';

	/**
	 * La casilla de borrar todo al desinstalar (ADR-003). La lee `uninstall.php`.
	 */
	public const OPTION_DELETE_DATA = 'wss_delete_data_on_uninstall';

	/**
	 * Engancha el registro de las rutas.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Registra las rutas.
	 */
	public static function register_routes(): void {
		$routes = array(
			'/status'                         => array( 'GET', 'status' ),
			'/reconcile'                      => array( 'POST', 'reconcile' ),
			'/log'                            => array( 'GET', 'log' ),
			'/settings'                       => array( 'GET, POST', 'settings' ),
			'/store'                          => array( 'POST', 'store' ),
			'/pairing/code'                   => array( 'POST', 'pairing_code' ),
			'/pairing/redeem'                 => array( 'POST', 'pairing_redeem' ),
			'/catalog/refresh'                => array( 'POST', 'catalog_refresh' ),
			'/products'                       => array( 'GET', 'products' ),
			'/catalog/search'                 => array( 'GET', 'catalog_search' ),
			'/catalog/fresh/(?P<remote>\\d+)' => array( 'GET', 'catalog_fresh' ),
			'/link'                           => array( 'POST', 'link' ),
			'/unlink'                         => array( 'POST', 'unlink' ),
			'/reassign'                       => array( 'POST', 'reassign' ),
			'/discard'                        => array( 'POST', 'discard' ),
			'/undiscard'                      => array( 'POST', 'undiscard' ),
			'/link/bulk'                      => array( 'GET, POST', 'link_bulk' ),
			'/peer'                           => array( 'GET', 'peer' ),
			'/discrepancies/(?P<product>\\d+)/(?P<action>accept|restore)' => array( 'POST', 'resolve' ),
		);

		foreach ( $routes as $route => $route_args ) {
			register_rest_route(
				self::NAMESPACE,
				$route,
				array(
					'methods'             => $route_args[0],
					'callback'            => array( self::class, $route_args[1] ),
					'permission_callback' => array( self::class, 'can_manage' ),
				)
			);
		}
	}

	/**
	 * El permiso de cada endpoint: `manage_woocommerce`, verificado en el servidor (ADR-013 §5).
	 */
	public static function can_manage(): bool {
		return current_user_can( Page::CAPABILITY );
	}

	/**
	 * Lo que la app necesita al cargar, y todo lo de Resumen: esta tienda, la otra, la última
	 * reconciliación, las discrepancias de las dos y cuántos no sincronizan.
	 */
	public static function status(): \WP_REST_Response {
		$peer  = Peers::describe();
		$state = Peer::current();
		$last  = Result::last();

		return new \WP_REST_Response(
			array(
				'store'          => array(
					'label'     => Store::label(),
					'master'    => Peers::this_is_master(),
					'suspended' => Plugin::suspension_reason(),
				),
				'paired'         => null !== $peer,
				'peer'           => array(
					'label'     => $peer['label'] ?? null,
					// Para saber que un re-emparejamiento terminó: la tienda ya figuraba emparejada.
					'paired_at' => $peer['paired_at'] ?? null,
					'state'     => $state['state'],
					'since'     => Peer::OK === $state['state'] ? null : Events::local_time( gmdate( 'Y-m-d H:i:s', $state['since'] ) ),
					'error'     => $state['error'],
				),
				'reconciliation' => null === $last ? null : self::reconciliation( $last ),
				'discrepancies'  => array(
					'own'  => array_map( array( self::class, 'own_discrepancy' ), Audit::open() ),
					// Sólo la maestra las conoce, y sólo si la última llegó a preguntarlas.
					'peer' => isset( $last['details']['peer_discrepancies'] ) ? array_map( array( self::class, 'peer_discrepancy' ), (array) $last['details']['peer_discrepancies'] ) : null,
				),
				'unsynced'       => count( Notifier::unsynced( time() ) ),
			),
			200
		);
	}

	/**
	 * La reconciliación manual: lo mismo que `wp wss reconcile`. Sólo en la maestra.
	 */
	public static function reconcile(): \WP_REST_Response|\WP_Error {
		if ( null !== Plugin::suspension_reason() ) {
			return new \WP_Error( 'wss_suspended', __( 'La sincronización está suspendida en esta tienda: no se reconcilia.', 'woonderful-stock-sync-for-woocommerce' ), array( 'status' => 409 ) );
		}

		if ( ! Peers::this_is_master() ) {
			return new \WP_Error( 'wss_not_master', __( 'La reconciliación se lanza desde la tienda maestra.', 'woonderful-stock-sync-for-woocommerce' ), array( 'status' => 403 ) );
		}

		if ( Result::BUSY === Reconciler::run( Result::MANUAL )['outcome'] ) {
			return new \WP_Error( 'wss_busy', __( 'Hay una reconciliación en curso, en esta tienda o en la otra. Probá de nuevo en unos minutos.', 'woonderful-stock-sync-for-woocommerce' ), array( 'status' => 409 ) );
		}

		// "No se pudo mirar" no es un error del pedido: es un resultado, y se muestra como tal.
		return self::status();
	}

	/**
	 * Resuelve una discrepancia de esta tienda: lo mismo que `wp wss discrepancy`.
	 *
	 * @param \WP_REST_Request $request El producto y la acción.
	 */
	public static function resolve( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$product = (int) $request['product'];
		$result  = 'accept' === $request['action'] ? Audit::accept( $product ) : Audit::restore( $product );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 409 ) );

			return $result;
		}

		return self::status();
	}

	/**
	 * El log, filtrado y paginado de a 50; con `product`, el historial de ese producto: sus
	 * movimientos y sus eventos, juntos (ADR-011 §8). Lo mismo que `wp wss log`.
	 *
	 * Las fechas del filtro son días del sitio, `AAAA-MM-DD`, y se pasan a UTC, que es como se
	 * guardan.
	 *
	 * @param \WP_REST_Request $request Los filtros y la página.
	 */
	public static function log( \WP_REST_Request $request ): \WP_REST_Response {
		$product = (int) $request['product'];

		if ( $product > 0 ) {
			$items = array_map( array( self::class, 'entry' ), Events::history( $product, 100 ) );

			return new \WP_REST_Response(
				array(
					'items' => $items,
					'total' => count( $items ),
					'name'  => self::product_name( $product ),
				),
				200
			);
		}

		$filters = array();

		foreach ( array( 'level', 'kind' ) as $key ) {
			if ( is_string( $request[ $key ] ) && '' !== $request[ $key ] ) {
				$filters[ $key ] = sanitize_key( $request[ $key ] );
			}
		}

		$bounds = array(
			'since' => array( 'from', '00:00:00' ),
			'until' => array( 'to', '23:59:59' ),
		);

		foreach ( $bounds as $key => $bound ) {
			$day = (string) $request[ $bound[0] ];

			if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
				$filters[ $key ] = get_gmt_from_date( $day . ' ' . $bound[1] );
			}
		}

		$list = Events::list( $filters, max( 1, (int) $request['page'] ), 50 );

		return new \WP_REST_Response(
			array(
				'items' => array_map( array( self::class, 'entry' ), $list['items'] ),
				'total' => $list['total'],
			),
			200
		);
	}

	/**
	 * Una entrada del log o del historial, con su frase, en la hora del sitio.
	 *
	 * @param array<string, mixed> $entry Un evento, o un movimiento del historial.
	 *
	 * @return array<string, mixed>
	 */
	private static function entry( array $entry ): array {
		$event = 'movement' !== ( $entry['type'] ?? 'event' );
		$user  = $event && null !== $entry['user_id'] ? get_userdata( (int) $entry['user_id'] ) : false;

		return array(
			'id'      => $event ? (int) $entry['id'] : null,
			'type'    => $event ? 'event' : 'movement',
			'at'      => Events::local_time( (string) $entry['at'] ),
			'level'   => $event ? $entry['level'] : null,
			'kind'    => $event ? $entry['kind'] : null,
			'text'    => $event ? Events::describe( $entry ) : Events::movement_text( $entry ),
			'product' => $event ? $entry['product'] : null,
			'name'    => $event && null !== $entry['product'] ? self::product_name( (int) $entry['product'] ) : null,
			'cause'   => $event && null !== $entry['cause'] ? Events::cause_text( (string) $entry['cause'] ) : null,
			'user'    => $user instanceof \WP_User ? $user->display_name : null,
			'context' => $event ? $entry['context'] : array_intersect_key( $entry, array_flip( array( 'delivery', 'application' ) ) ),
		);
	}

	/**
	 * Los ajustes del dueño: los avisos (ADR-012 §6) y la casilla de borrar todo al desinstalar
	 * (ADR-003). Con `POST`, cambia lo que venga y los devuelve como quedaron; los avisos, con la
	 * misma validación que `wp wss notify`.
	 *
	 * @param \WP_REST_Request $request Lo que se cambia.
	 */
	public static function settings( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( 'POST' === $request->get_method() ) {
			$changes = array();
			$keys    = array(
				'notify_enabled' => 'enabled',
				'notify_email'   => 'email',
				'notify_after'   => 'after',
			);

			foreach ( $keys as $param => $key ) {
				if ( null !== $request[ $param ] ) {
					$changes[ $key ] = 'enabled' === $key ? rest_sanitize_boolean( (string) $request[ $param ] ) : $request[ $param ];
				}
			}

			$result = Notifier::configure( $changes );

			if ( is_wp_error( $result ) ) {
				$result->add_data( array( 'status' => 400 ) );

				return $result;
			}

			if ( null !== $request['delete_data'] ) {
				update_option( self::OPTION_DELETE_DATA, rest_sanitize_boolean( (string) $request['delete_data'] ), false );
			}
		}

		return new \WP_REST_Response(
			array(
				'notify_enabled' => Notifier::enabled(),
				// Vacío es "el del administrador": la pantalla lo muestra como sugerencia.
				'notify_email'   => (string) get_option( Notifier::OPTION_EMAIL, '' ),
				'admin_email'    => (string) get_option( 'admin_email' ),
				'notify_after'   => Notifier::after_hours(),
				'delete_data'    => (bool) get_option( self::OPTION_DELETE_DATA, false ),
			),
			200
		);
	}

	/**
	 * El nombre de esta tienda, con el que la ve la otra.
	 *
	 * @param \WP_REST_Request $request La etiqueta.
	 */
	public static function store( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$label = sanitize_text_field( (string) $request['label'] );

		if ( '' === $label ) {
			return new \WP_Error( 'wss_invalid_label', __( 'La tienda necesita un nombre.', 'woonderful-stock-sync-for-woocommerce' ), array( 'status' => 400 ) );
		}

		update_option( Store::OPTION_LABEL, $label, false );

		return self::status();
	}

	/**
	 * Genera un código de emparejamiento: esta tienda va a ser la maestra. Lo mismo que
	 * `wp wss pairing-code`.
	 */
	public static function pairing_code(): \WP_REST_Response|\WP_Error {
		$code = Pairing::create_code();

		if ( is_wp_error( $code ) ) {
			$code->add_data( array( 'status' => 409 ) );

			return $code;
		}

		return new \WP_REST_Response(
			array(
				'code'    => $code,
				'minutes' => (int) ( Pairing::CODE_TTL / MINUTE_IN_SECONDS ),
			),
			200
		);
	}

	/**
	 * Canjea el código de la otra: esta tienda va a ser la secundaria. Lo mismo que
	 * `wp wss pair`.
	 *
	 * @param \WP_REST_Request $request El código.
	 */
	public static function pairing_redeem( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = Pairing::redeem( (string) $request['code'] );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );

			return $result;
		}

		return self::status();
	}

	/**
	 * Las filas de Productos: filtradas y buscadas sobre todo el catálogo, de a 50 (ADR-014 §3,
	 * §4). Con la edad de la copia: la app la refresca antes si está vieja.
	 *
	 * @param \WP_REST_Request $request Los filtros y la página.
	 */
	public static function products( \WP_REST_Request $request ): \WP_REST_Response {
		$state = (string) $request['state'];

		$result = Rows::page(
			array(
				'state'          => in_array( $state, Rows::STATES, true ) ? $state : 'all',
				'motive'         => sanitize_key( (string) $request['motive'] ),
				'search'         => sanitize_text_field( (string) $request['search'] ),
				'show_discarded' => rest_sanitize_boolean( (string) $request['show_discarded'] ),
			),
			max( 1, (int) $request['page'] )
		);

		$result['catalog'] = array(
			'age'   => Copy::age(),
			'stale' => Copy::is_stale(),
		);

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Busca el producto de la otra para vincular a mano, en la copia (ADR-014 §5).
	 *
	 * @param \WP_REST_Request $request Lo buscado, en `q`.
	 */
	public static function catalog_search( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( array( 'items' => Copy::search( sanitize_text_field( (string) $request['q'] ) ) ), 200 );
	}

	/**
	 * El producto de la otra **en el momento**, antes de confirmar: los dos stocks y cuál va a
	 * quedar, el de la maestra (ADR-014 §5, UC-07). Con `local`, el stock de acá leído de la
	 * base, que es contra lo que compara el bootstrap.
	 *
	 * @param \WP_REST_Request $request El de allá en la ruta, y el de acá en `local`.
	 */
	public static function catalog_fresh( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = Catalog::fetch( 1, Catalog::MAX_INCLUDE, array( (int) $request['remote'] ) );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 'wss_not_paired' === $result->get_error_code() ? 409 : 502 ) );

			return $result;
		}

		$remote = $result['products'][0] ?? null;

		if ( null === $remote ) {
			return new \WP_Error( 'target_missing', Events::reason_text( 'target_missing' ), array( 'status' => 404 ) );
		}

		$local = (int) $request['local'];
		$stock = $local > 0 ? Detector::stock_in_db( $local ) : null;

		return new \WP_REST_Response(
			array(
				'remote' => $remote,
				'local'  => null === $stock ? null : array(
					'id'    => $local,
					'stock' => $stock,
				),
				// El stock de arranque es el de la maestra.
				'result' => Peers::this_is_master() ? $stock : $remote['stock'],
			),
			200
		);
	}

	/**
	 * Vincula uno a mano, con los dos stocks que vio el dueño: lo mismo que `wp wss link`. Si
	 * alguno cambió, `stock_changed`, y la pantalla vuelve a pedirlos.
	 *
	 * @param \WP_REST_Request $request `local`, `remote`, `stock` y `remote_stock`.
	 */
	public static function link( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return self::acted(
			(int) $request['local'],
			Linker::link( (int) $request['local'], (int) $request['remote'], self::seen( $request['stock'] ), self::seen( $request['remote_stock'] ), __( 'vinculado desde la pantalla', 'woonderful-stock-sync-for-woocommerce' ) )
		);
	}

	/**
	 * Desvincula: el producto deja de sincronizar y el stock no se toca. Lo mismo que
	 * `wp wss unlink`.
	 *
	 * @param \WP_REST_Request $request `local`.
	 */
	public static function unlink( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return self::acted( (int) $request['local'], Linker::unlink( (int) $request['local'] ) );
	}

	/**
	 * Reasigna: desvincula y vincula con otro.
	 *
	 * @param \WP_REST_Request $request Como en `link`.
	 */
	public static function reassign( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return self::acted(
			(int) $request['local'],
			Linker::reassign( (int) $request['local'], (int) $request['remote'], self::seen( $request['stock'] ), self::seen( $request['remote_stock'] ), __( 'reasignado desde la pantalla', 'woonderful-stock-sync-for-woocommerce' ) )
		);
	}

	/**
	 * Descarta la sugerencia de un producto.
	 *
	 * @param \WP_REST_Request $request `local`.
	 */
	public static function discard( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return self::acted( (int) $request['local'], Linker::discard( (int) $request['local'] ) );
	}

	/**
	 * Deshace el descarte.
	 *
	 * @param \WP_REST_Request $request `local`.
	 */
	public static function undiscard( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return self::acted( (int) $request['local'], Linker::undiscard( (int) $request['local'] ) );
	}

	/**
	 * La otra tienda, preguntada en el momento: su nombre y dónde está su pantalla. La
	 * secundaria lo usa para decir que la vinculación se hace desde la maestra, con el enlace
	 * (ADR-013 §6). Una versión vieja del otro lado no manda `admin_url`: va null.
	 */
	public static function peer(): \WP_REST_Response|\WP_Error {
		$health = Peer::health();

		if ( is_wp_error( $health ) ) {
			$health->add_data( array( 'status' => 'wss_not_paired' === $health->get_error_code() ? 409 : 502 ) );

			return $health;
		}

		$url = is_string( $health['admin_url'] ?? null ) ? esc_url_raw( $health['admin_url'], array( 'http', 'https' ) ) : '';

		return new \WP_REST_Response(
			array(
				'label'     => is_string( $health['label'] ?? null ) ? sanitize_text_field( $health['label'] ) : null,
				// Sólo http o https: lo que manda la otra termina en un enlace.
				'admin_url' => '' === $url ? null : $url,
			),
			200
		);
	}

	/**
	 * La vinculación masiva (ADR-014 §6). Con `GET`, lo que va a vincular: los pares con
	 * sugerencia, y cuántos ambiguos quedan aparte. Con `POST`, una tanda de hasta 20 en
	 * `pairs`, cada par revalidado en el momento; vuelven los vinculados y los omitidos con su
	 * motivo.
	 *
	 * @param \WP_REST_Request $request Los pares, en un `POST`.
	 */
	public static function link_bulk( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! Peers::this_is_master() ) {
			return new \WP_Error( 'wss_not_master', __( 'Vincular se hace desde la tienda maestra.', 'woonderful-stock-sync-for-woocommerce' ), array( 'status' => 403 ) );
		}

		if ( 'GET' === $request->get_method() ) {
			return new \WP_REST_Response( Bulk::preview(), 200 );
		}

		$pairs = array();

		foreach ( (array) $request['pairs'] as $pair ) {
			$pair    = (array) $pair;
			$pairs[] = array(
				'local'  => (int) ( $pair['local'] ?? 0 ),
				'remote' => (int) ( $pair['remote'] ?? 0 ),
			);
		}

		$result = Bulk::link( $pairs );

		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();

			$result->add_data( array( 'status' => 'wss_bad_request' === $code ? 400 : ( 'wss_not_paired' === $code ? 409 : 502 ) ) );

			return $result;
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Lo que vuelve de una acción sobre un producto: su fila como quedó, o por qué no se hizo.
	 *
	 * @param int                   $local  El producto.
	 * @param string|true|\WP_Error $result Lo que devolvió `Linker`.
	 */
	private static function acted( int $local, $result ): \WP_REST_Response|\WP_Error {
		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();

			$result->add_data( array( 'status' => in_array( $code, array( 'wss_not_master', 'wss_suspended' ), true ) ? 403 : 409 ) );

			return $result;
		}

		return new \WP_REST_Response(
			array(
				'result' => true === $result ? 'done' : $result,
				'row'    => Rows::one( $local ),
			),
			200
		);
	}

	/**
	 * Un stock que vio el dueño, o -1 si no vino: `Linker` lo rechaza como cambiado.
	 *
	 * @param mixed $value Lo que vino.
	 */
	private static function seen( $value ): int {
		return is_numeric( $value ) ? (int) $value : -1;
	}

	/**
	 * Una página del refresco de la copia del catálogo de la otra, con su avance. La app pide
	 * una por request: con 5.000 productos son 50 requests cortos (ADR-014 §1).
	 *
	 * @param \WP_REST_Request $request La página, desde 1.
	 */
	public static function catalog_refresh( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = Copy::refresh_page( max( 1, (int) $request['page'] ) );

		if ( is_wp_error( $result ) ) {
			// Sin emparejar es de acá; lo demás, que la otra no contestó bien.
			$result->add_data( array( 'status' => 'wss_not_paired' === $result->get_error_code() ? 409 : 502 ) );

			return $result;
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * La última reconciliación, para mostrarla.
	 *
	 * @param array{at: string, trigger: string, outcome: string, details: array<string, mixed>} $last La de `Result::last()`.
	 *
	 * @return array<string, mixed>
	 */
	private static function reconciliation( array $last ): array {
		$details = $last['details'];

		return array(
			'at'             => Events::local_time( $last['at'] ),
			'outcome'        => $last['outcome'],
			'text'           => Events::describe(
				array(
					'kind'    => Events::RECONCILED,
					'product' => null,
					'context' => $last,
				)
			),
			'reopened'       => (int) ( $details['reopened'] ?? 0 ),
			'confirmed'      => (int) ( $details['confirmed'] ?? 0 ),
			'peer_reopened'  => (int) ( $details['peer_reopened'] ?? 0 ),
			'peer_confirmed' => (int) ( $details['peer_confirmed'] ?? 0 ),
		);
	}

	/**
	 * Una discrepancia de acá, con el nombre del producto.
	 *
	 * @param array{product: int, expected: int, found: int, since: string, seen_at: string} $open Una de `Audit::open()`.
	 *
	 * @return array<string, mixed>
	 */
	private static function own_discrepancy( array $open ): array {
		return array(
			'product'  => $open['product'],
			'name'     => self::product_name( $open['product'] ),
			'expected' => $open['expected'],
			'found'    => $open['found'],
			'since'    => Events::local_time( $open['since'] ),
		);
	}

	/**
	 * Una discrepancia de la otra tienda, con el producto de acá con que está vinculado.
	 *
	 * @param mixed $open Una de `Audit::open()` de la otra, como llegó.
	 *
	 * @return array<string, mixed>
	 */
	private static function peer_discrepancy( $open ): array {
		$open   = (array) $open;
		$remote = (int) ( $open['product'] ?? 0 );
		$peer   = Peers::only();
		$link   = null === $peer ? null : Links::for_remote_product( $peer['store'], $remote );
		$local  = null === $link ? null : (int) $link->local_product;

		return array(
			'product'  => $remote,
			'local'    => $local,
			'name'     => null === $local ? null : self::product_name( $local ),
			'expected' => (int) ( $open['expected'] ?? 0 ),
			'found'    => (int) ( $open['found'] ?? 0 ),
			'since'    => is_string( $open['since'] ?? null ) ? Events::local_time( $open['since'] ) : null,
		);
	}

	/**
	 * El nombre de un producto de acá, o null si ya no existe.
	 *
	 * @param int $product El producto.
	 */
	private static function product_name( int $product ): ?string {
		$found = wc_get_product( $product );

		return $found instanceof \WC_Product ? $found->get_name() : null;
	}
}
