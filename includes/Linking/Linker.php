<?php
/**
 * Vincular y desvincular, del lado de la maestra.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Linking;

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Ledger\Ledger;
use Woonderful\StockSync\Plugin;
use Woonderful\StockSync\Transport\Peers;
use Woonderful\StockSync\Transport\Sender;
use Woonderful\StockSync\Transport\Signature;

defined( 'ABSPATH' ) || exit;

/**
 * Cómo se vincula un producto (ADR-008).
 */
final class Linker {

	/**
	 * Cuánto se espera antes de preguntar por un bootstrap sin respuesta, en segundos.
	 *
	 * Uno que la otra tienda todavía esté procesando no se toma por perdido (ADR-008 §5).
	 */
	public const QUERY_AFTER = 600;

	/**
	 * Vincula un producto de acá con uno de la otra tienda: el bootstrap.
	 *
	 * Revisa lo suyo antes de escribir nada (ADR-008 §3), escribe el bootstrap y su vínculo
	 * juntos (§4), lo manda en el momento y espera (§1).
	 *
	 * @param int         $local       Producto de esta tienda.
	 * @param int         $remote      Producto de la otra tienda.
	 * @param int         $seen        El stock de acá que vio el dueño.
	 * @param int         $remote_seen El stock de allá que vio el dueño.
	 * @param string|null $reference   Quién lo vinculó.
	 *
	 * @return string|\WP_Error `confirmed`, `uncertain`, o por qué no se vinculó.
	 */
	public static function link( int $local, int $remote, int $seen, int $remote_seen, ?string $reference = null ) {
		$peer    = Peers::only();
		$refusal = self::refusal( $peer );

		if ( null !== $refusal || null === $peer ) {
			return $refusal ?? new \WP_Error( 'wss_not_paired', '' );
		}

		$product = wc_get_product( $local );

		if ( ! $product instanceof \WC_Product || 'trash' === $product->get_status() ) {
			return new \WP_Error( 'product_missing', __( 'Ese producto no existe en esta tienda.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		if ( ! Links::holds_own_stock( $product ) ) {
			return new \WP_Error( 'product_not_managing_stock', __( 'Ese producto no gestiona su propio stock.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		if ( null !== Links::for_product( $local ) || null !== Links::for_remote_product( $peer['store'], $remote ) ) {
			return new \WP_Error( 'already_linked', __( 'Alguno de los dos productos ya está vinculado.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		// El stock de arranque es el de la maestra (UC-07).
		$details = array(
			'target_product'        => $remote,
			'base_stock'            => $seen,
			'origin_previous_stock' => $seen,
			'target_previous_stock' => $remote_seen,
		);

		// El stock propio se revisa adentro, con la fila bloqueada (ADR-008 §3).
		$movement_id = Ledger::record_bootstrap( $local, $peer['store'], $details, $reference );

		if ( 'stock_changed' === $movement_id ) {
			return new \WP_Error( 'stock_changed', __( 'El stock de esta tienda cambió desde que lo miraste. Volvé a mirarlo.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		if ( ! is_int( $movement_id ) ) {
			return new \WP_Error( 'not_stored', __( 'No se pudo escribir el bootstrap.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		$movement = array_merge(
			array(
				'origin'     => Store::id(),
				'seq'        => $movement_id,
				'generation' => $movement_id,
				'delta'      => 0,
				'product'    => $local,
				'reason'     => Ledger::BOOTSTRAP,
				'reference'  => $reference,
			),
			$details
		);

		$outcome = self::outcome_of( Sender::post( $peer, array( $movement ) ), $movement_id );

		Ledger::resolve_bootstrap( $movement_id, $outcome[0], $outcome[1] );

		if ( 'failed' === $outcome[0] ) {
			$reason = (string) $outcome[1];

			// Los rechazos de la otra tienda tienen código propio; lo demás —un 4xx del lote
			// entero— va con el detalle en el mensaje.
			$code = in_array( $reason, Ledger::BOOTSTRAP_REJECTIONS, true ) ? $reason : 'bootstrap_failed';

			return new \WP_Error( $code, self::message_for( $reason ) );
		}

		return $outcome[0];
	}

	/**
	 * Desvincula un producto. El `unlink` sale por la cola (ADR-008 §6).
	 *
	 * @param int $local Producto de esta tienda.
	 *
	 * @return true|\WP_Error
	 */
	public static function unlink( int $local ) {
		$refusal = self::refusal( Peers::only() );

		if ( null !== $refusal ) {
			return $refusal;
		}

		if ( null === Links::for_product( $local ) ) {
			return new \WP_Error( 'not_linked', __( 'Ese producto no está vinculado.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		if ( null === Ledger::record_unlink( $local ) ) {
			return new \WP_Error( 'not_stored', __( 'No se pudo escribir el desvincular.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		return true;
	}

	/**
	 * Pregunta por los bootstraps sin respuesta (ADR-008 §5). La llama la pasada.
	 *
	 * Preguntar no escribe nada del otro lado: si el bootstrap nunca llegó, no se aplica.
	 */
	public static function resolve_uncertain(): void {
		$peer = Peers::only();

		if ( null === $peer ) {
			return;
		}

		foreach ( Ledger::unresolved_bootstraps( self::QUERY_AFTER ) as $row ) {
			$state = self::ask( $peer, (int) $row->origin_seq );

			if ( null === $state ) {
				// Sin respuesta sigue incierto. Un `syncing` de un proceso que murió pasa a
				// incierto, que es lo que es.
				Ledger::resolve_bootstrap( (int) $row->id, Ledger::UNCERTAIN );

				continue;
			}

			// Un bootstrap no se retiene ni se descarta: si pasó, algo se rompió, y lo tiene
			// que ver el dueño.
			if ( Ledger::APPLIED === $state ) {
				Ledger::resolve_bootstrap( (int) $row->id, 'confirmed' );
			} else {
				Ledger::resolve_bootstrap( (int) $row->id, 'failed', 'not_applied: ' . $state );
			}
		}
	}

	/**
	 * Por qué esta tienda no puede vincular ni desvincular ahora, o null si puede.
	 *
	 * **Una copia de la tienda no vincula.** Trae el identificador y el secreto de la real, así
	 * que la otra tienda le aceptaría el bootstrap: pisaría su stock de verdad, dejaría el par
	 * armado de un solo lado, y el número del bootstrap chocaría con el de la próxima venta de
	 * la tienda real, que la otra daría por recibida y no aplicaría nunca. Los comandos se
	 * registran antes de la guarda —por el emparejamiento—, así que la guarda va acá.
	 *
	 * @param array{store: string, rest_url: string, secret: string, role: string}|null $peer La otra tienda.
	 */
	private static function refusal( ?array $peer ): ?\WP_Error {
		if ( null !== Plugin::suspension_reason() ) {
			return new \WP_Error( 'wss_suspended', __( 'La sincronización está suspendida en esta tienda: no se vincula ni se desvincula.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		if ( null === $peer ) {
			return new \WP_Error( 'wss_not_paired', __( 'Esta tienda no está emparejada con otra.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		if ( ! Peers::this_is_master() ) {
			return new \WP_Error( 'wss_not_master', __( 'Vincular y desvincular se hace desde la tienda maestra: la que generó el código de emparejamiento.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		return null;
	}

	/**
	 * Cómo terminó el envío del bootstrap: `confirmed`, `failed` con su motivo, o `uncertain`.
	 *
	 * Sin respuesta, o con un 5xx, no se sabe si la otra tienda lo aplicó: incierto. Con un
	 * 4xx del lote entero —la firma, el reloj— no llegó a aplicarse: fallido.
	 *
	 * @param array<string, mixed>|\WP_Error $response    Lo que devolvió `wp_remote_post()`.
	 * @param int                            $movement_id El bootstrap.
	 *
	 * @return array{0: string, 1: string|null}
	 */
	private static function outcome_of( $response, int $movement_id ): array {
		if ( is_wp_error( $response ) ) {
			return array( Ledger::UNCERTAIN, $response->get_error_message() );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $status >= 500 || 0 === $status ) {
			return array( Ledger::UNCERTAIN, 'HTTP ' . $status );
		}

		if ( 200 !== $status ) {
			$code = is_array( $payload ) && is_string( $payload['code'] ?? null ) ? ' ' . $payload['code'] : '';

			return array( 'failed', 'HTTP ' . $status . $code );
		}

		$result = is_array( $payload ) ? ( $payload['results'][0] ?? null ) : null;

		// Un 200 que no se entiende no dice si se aplicó.
		if ( ! is_array( $result ) || (int) ( $result['seq'] ?? 0 ) !== $movement_id ) {
			return array( Ledger::UNCERTAIN, 'unreadable_result' );
		}

		if ( 'received' === ( $result['status'] ?? null ) ) {
			return array( 'confirmed', null );
		}

		return array( 'failed', is_string( $result['reason'] ?? null ) ? $result['reason'] : 'error' );
	}

	/**
	 * Le pregunta a la otra tienda qué tiene de un bootstrap.
	 *
	 * @param array{store: string, rest_url: string, secret: string, role: string} $peer La otra tienda.
	 * @param int                                                                  $seq  La secuencia.
	 *
	 * @return string|null Lo que contestó, o null si no contestó.
	 */
	private static function ask( array $peer, int $seq ): ?string {
		$route    = '/wss/v1/movements/' . Store::id() . '/' . $seq;
		$response = wp_remote_get(
			untrailingslashit( $peer['rest_url'] ) . '/movements/' . Store::id() . '/' . $seq,
			array(
				'headers'     => Signature::headers( Store::id(), $peer['secret'], 'GET', $route, array(), '' ),
				'timeout'     => Sender::TIMEOUT,
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$state   = is_array( $payload ) ? ( $payload['state'] ?? null ) : null;

		return is_string( $state ) ? $state : null;
	}

	/**
	 * Lo que se le dice al dueño de un rechazo de la otra tienda.
	 *
	 * @param string $reason El motivo.
	 */
	private static function message_for( string $reason ): string {
		$messages = array(
			'stock_changed'             => __( 'El stock de la otra tienda cambió desde que lo miraste. Volvé a mirarlo.', 'woonderful-stock-sync-for-woocommerce' ),
			'target_missing'            => __( 'El producto de la otra tienda no existe o está en la papelera.', 'woonderful-stock-sync-for-woocommerce' ),
			'target_not_managing_stock' => __( 'El producto de la otra tienda no gestiona su propio stock.', 'woonderful-stock-sync-for-woocommerce' ),
			'not_master'                => __( 'La otra tienda no reconoce a ésta como su maestra: volvé a emparejarlas.', 'woonderful-stock-sync-for-woocommerce' ),
		);

		return $messages[ $reason ] ?? $reason;
	}
}
