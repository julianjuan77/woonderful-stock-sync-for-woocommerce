<?php
/**
 * Tests de GET /wss/v1/products y de su cliente.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Linking\Links;
use Woonderful\StockSync\Rest\Products;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Catalog;
use Woonderful\StockSync\Transport\Peers;
use Woonderful\StockSync\Transport\Signature;

/**
 * El catálogo, de sólo lectura, para la pantalla de la otra tienda (ADR-005 §5).
 */
class Test_Rest_Products extends WP_UnitTestCase {

	private const OTHER = 'b1b1b1b1-0000-4000-8000-000000000000';

	private const SECRET = 'secreto-de-prueba';

	/**
	 * Un sufijo para los SKU de esta corrida.
	 *
	 * @var string
	 */
	private static string $run = '';

	/**
	 * El servidor REST, con la ruta registrada.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	public function set_up(): void {
		parent::set_up();

		self::$run = self::$run ?: uniqid();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		// En el set_up y no en el tear_down: ver tests/TEST-CASES.md.
		delete_option( Peers::OPTION );

		Installer::install();
		Store::ensure_identity();
		wss_unhook_detector();

		Peers::pair( self::OTHER, 'https://otra.test/wp-json/wss/v1', self::SECRET, 'Otra', Peers::MASTER );

		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride

		add_action( 'rest_api_init', array( Products::class, 'register_routes' ) );
		$this->server = rest_get_server();
	}

	public function tear_down(): void {
		global $wpdb, $wp_rest_server;

		remove_all_filters( 'pre_http_request' );
		remove_action( 'rest_api_init', array( Products::class, 'register_routes' ) );
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride

		// Las filas del log, no la tabla: el DROP de las otras confirma el borrado, y una
		// tabla menos que crear y borrar en cada test hace la suite más rápida.
		$wpdb->query( 'DELETE FROM ' . Installer::table( 'events' ) ); // phpcs:ignore WordPress.DB

		foreach ( array( 'movements', 'links', 'nonces' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		parent::tear_down();
	}

	/**
	 * Un producto simple.
	 *
	 * @param int|null $stock Con cuánto arranca, o null si no gestiona stock.
	 * @param string   $sku   Su SKU.
	 */
	private function simple( ?int $stock, string $sku = '' ): int {
		// Con un sufijo único: el CREATE TABLE del set_up confirma la transacción del test,
		// los productos de un test quedan para el siguiente, y WooCommerce no deja repetir
		// un SKU.
		$sku = '' === $sku ? '' : $sku . '-' . self::$run;

		$product = new WC_Product_Simple();
		$product->set_name( 'Alpargata ' . $sku );
		$product->set_sku( $sku );
		$product->set_manage_stock( null !== $stock );
		$product->set_stock_quantity( $stock ?? 0 );

		return (int) $product->save();
	}

	/**
	 * Un GET firmado como lo firmaría la otra tienda.
	 *
	 * @param array<string, string> $query   Los parámetros.
	 * @param array<string, string> $headers Cabeceras a pisar después de firmar.
	 */
	private function request( array $query = array(), array $headers = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', Catalog::ROUTE );
		$request->set_query_params( $query );

		foreach ( array_merge( Signature::headers( self::OTHER, self::SECRET, 'GET', Catalog::ROUTE, $query, '' ), $headers ) as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return $request;
	}

	/**
	 * Los productos de una respuesta, por ID.
	 *
	 * @param array<string, string> $query Los parámetros.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function products( array $query = array() ): array {
		$products = $this->server->dispatch( $this->request( $query ) )->get_data()['products'];

		return array_column( $products, null, 'id' );
	}

	/**
	 * Cada producto viaja con sus seis campos. Uno sin stock gestionado aparece con
	 * `stock: null`; una variación que toma el stock del padre, también; el padre que lo
	 * gestiona, con su número; la papelera, con su estado; y `linked` dice si está vinculado.
	 *
	 * PRD-01
	 */
	public function test_each_product_carries_its_fields(): void {
		$managed   = $this->simple( 9, 'ZAP-001' );
		$unmanaged = $this->simple( null, 'ZAP-002' );
		$trashed   = $this->simple( 4, 'ZAP-003' );
		wp_trash_post( $trashed );
		Links::create( $managed, self::OTHER, 900, 77, 9 );

		$parent = new WC_Product_Variable();
		$parent->set_manage_stock( true );
		$parent->set_stock_quantity( 20 );
		$parent_id = (int) $parent->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation_id = (int) $variation->save();

		$products = $this->products();

		$this->assertSame(
			array(
				'id'     => $managed,
				'sku'    => 'ZAP-001-' . self::$run,
				'name'   => 'Alpargata ZAP-001-' . self::$run,
				'stock'  => 9,
				'status' => 'publish',
				'linked' => true,
			),
			$products[ $managed ]
		);
		$this->assertNull( $products[ $unmanaged ]['stock'] );
		$this->assertSame( 'trash', $products[ $trashed ]['status'] );
		$this->assertSame( 20, $products[ $parent_id ]['stock'] );
		$this->assertNull( $products[ $variation_id ]['stock'], 'la variación toma el número del padre: no es suyo' );
		$this->assertFalse( $products[ $unmanaged ]['linked'] );
	}

	/**
	 * Se pueden pedir productos puntuales. Uno que ya no existe no vuelve: así se sabe que
	 * lo borraron.
	 *
	 * PRD-02
	 */
	public function test_include_returns_only_those_and_a_deleted_one_is_absent(): void {
		$wanted  = $this->simple( 9, 'A' . __LINE__ );
		$other   = $this->simple( 9, 'B' . __LINE__ );
		$deleted = $this->simple( 9, 'C' . __LINE__ );
		wp_delete_post( $deleted, true );

		$products = $this->products( array( 'include' => $wanted . ',' . $deleted ) );

		$this->assertSame( array( $wanted ), array_keys( $products ) );
		$this->assertArrayNotHasKey( $other, $products );
	}

	/**
	 * Un `include` sin IDs válidos no devuelve nada; sin esto, devolvería el catálogo entero.
	 *
	 * PRD-03
	 */
	public function test_an_include_without_valid_ids_returns_nothing(): void {
		$this->simple( 9, 'A' . __LINE__ );

		$this->assertSame( array(), $this->products( array( 'include' => '0,-3' ) ) );
	}

	/**
	 * Recorrer el catálogo de a dos por página: en orden de ID, sin repetidos, con los tres de
	 * este test, y el total coincide con lo recorrido. Los productos de los tests anteriores
	 * siguen en la base, así que se recorre todo.
	 *
	 * PRD-04
	 */
	public function test_pages_are_ordered_by_id_with_the_total(): void {
		$ids = array( $this->simple( 1, 'P1' ), $this->simple( 2, 'P2' ), $this->simple( 3, 'P3' ) );

		$seen  = array();
		$total = null;

		for ( $page = 1; $page < 200; $page++ ) {
			$data = $this->server->dispatch(
				$this->request(
					array(
						'per_page' => '2',
						'page'     => (string) $page,
					)
				)
			)->get_data();

			// De la primera: una página más allá del final no trae el total.
			$total = $total ?? $data['total'];

			if ( array() === $data['products'] ) {
				break;
			}

			$seen = array_merge( $seen, array_column( $data['products'], 'id' ) );
		}

		$sorted = $seen;
		sort( $sorted );

		$this->assertSame( $sorted, $seen, 'en orden de ID' );
		$this->assertSame( array_values( array_unique( $seen ) ), $seen, 'sin repetidos' );
		$this->assertSame( $ids, array_values( array_intersect( $seen, $ids ) ) );
		$this->assertSame( count( $seen ), $total );
	}

	/**
	 * Hasta 100 por página, aunque se pidan más.
	 *
	 * PRD-05
	 */
	public function test_per_page_is_capped_at_100(): void {
		for ( $i = 0; $i < 101; $i++ ) {
			$this->simple( 1 );
		}

		$products = $this->server->dispatch( $this->request( array( 'per_page' => '500' ) ) )->get_data()['products'];

		$this->assertCount( Products::MAX_PER_PAGE, $products );
	}

	/**
	 * Sin firma no muestra nada, y no anota el nonce: sólo lee.
	 *
	 * PRD-06
	 */
	public function test_it_requires_the_signature_and_leaves_no_nonce(): void {
		global $wpdb;

		$bad = $this->server->dispatch( $this->request( array(), array( 'X-WSS-Signature' => str_repeat( '0', 64 ) ) ) );

		$this->assertSame( 401, $bad->get_status() );

		$this->server->dispatch( $this->request() );

		$table = Installer::table( 'nonces' );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * El contrato de los dos lados: lo que pide `Catalog::fetch()` —con la query firmada—
	 * pasa la autenticación del endpoint y trae lo pedido.
	 *
	 * PRD-07
	 */
	public function test_the_client_request_is_answered_by_the_endpoint(): void {
		$wanted = array( $this->simple( 9, 'A' . __LINE__ ), $this->simple( 8, 'B' . __LINE__ ) );
		$this->simple( 9, 'C' . __LINE__ );

		$server = $this->server;

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $server ) {
				wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

				$request = new WP_REST_Request( 'GET', Catalog::ROUTE );
				$request->set_query_params( $query );

				foreach ( $args['headers'] as $name => $value ) {
					$request->set_header( $name, $value );
				}

				// Del otro lado, quien firma es esta tienda.
				$peers = get_option( Peers::OPTION );
				update_option( Peers::OPTION, array( Store::id() => array( 'secret' => self::SECRET ) ), false );
				$response = $server->dispatch( $request );
				update_option( Peers::OPTION, $peers, false );

				return array(
					'headers'  => array(),
					'body'     => (string) wp_json_encode( $response->get_data() ),
					'response' => array(
						'code'    => $response->get_status(),
						'message' => '',
					),
					'cookies'  => array(),
				);
			},
			10,
			3
		);

		// Dos: la coma viaja como %2C y se decodifica del otro lado antes de verificar.
		$result = Catalog::fetch( 1, 100, $wanted );

		$this->assertSame( $wanted, array_column( $result['products'], 'id' ) );
		$this->assertSame( 2, $result['total'] );
	}

	/**
	 * Con `include` vuelve cada producto pedido, de cualquier tipo y estado: uno agrupado, y
	 * uno con un estado que registró otro plugin. Si no volvieran, la otra tienda los daría
	 * por borrados.
	 *
	 * PRD-08
	 */
	public function test_include_returns_any_type_and_status(): void {
		register_post_status( 'wss-archivado', array( 'label' => 'Archivado' ) );

		$grouped = ( new WC_Product_Grouped() )->save();
		$custom  = $this->simple( 9, 'X' . __LINE__ );
		wp_update_post(
			array(
				'ID'          => $custom,
				'post_status' => 'wss-archivado',
			)
		);

		$products = $this->products( array( 'include' => $grouped . ',' . $custom ) );

		$this->assertSame( array( $grouped, $custom ), array_keys( $products ) );
		$this->assertSame( 'wss-archivado', $products[ $custom ]['status'] );
	}

	/**
	 * PRD-09 — Más de 100 IDs por request es un `wss_bad_request`.
	 */
	public function test_more_than_100_ids_is_a_bad_request(): void {
		$response = $this->server->dispatch( $this->request( array( 'include' => implode( ',', range( 1, 101 ) ) ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wss_bad_request', $response->get_data()['code'] );
	}

	/**
	 * PRD-10 — Con `include` no hay páginas: vuelven todos los pedidos, aunque se pida otra.
	 */
	public function test_include_ignores_the_page(): void {
		$wanted = array( $this->simple( 9, 'A' . __LINE__ ), $this->simple( 9, 'B' . __LINE__ ) );

		$products = $this->products(
			array(
				'include'  => implode( ',', $wanted ),
				'page'     => '2',
				'per_page' => '1',
			)
		);

		$this->assertSame( $wanted, array_keys( $products ) );
	}

	/**
	 * El stock viene de la base, no de la caché: con una caché desactualizada, el dueño
	 * copiaría un número viejo, el bootstrap fallaría con `stock_changed`, y al volver a mirar
	 * vería el mismo número.
	 *
	 * PRD-11
	 */
	public function test_the_stock_comes_from_the_database(): void {
		global $wpdb;

		$id = $this->simple( 9, 'S' . __LINE__ );
		wc_get_product( $id )->get_stock_quantity();

		// Otro proceso lo cambió en la base; la caché de este sigue diciendo 9.
		$wpdb->update( // phpcs:ignore WordPress.DB
			$wpdb->postmeta,
			array( 'meta_value' => '5' ), // phpcs:ignore WordPress.DB.SlowDBQuery
			array(
				'post_id'  => $id,
				'meta_key' => '_stock', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		$this->assertSame( 5, $this->products( array( 'include' => (string) $id ) )[ $id ]['stock'] );
	}

	/**
	 * Hace de la otra tienda: contesta cada request con los IDs que pidió, o con lo que se le
	 * pase.
	 *
	 * @param callable|null $products Arma los productos de la respuesta a partir de los IDs.
	 *
	 * @return \ArrayObject<int, string> Las URLs que se pidieron, que el filtro va llenando.
	 */
	private function fake_catalog( ?callable $products = null ): \ArrayObject {
		$urls     = new \ArrayObject();
		$products = $products ?? static fn ( array $ids ) => array_map(
			static fn ( int $id ) => array(
				'id'     => $id,
				'sku'    => '',
				'name'   => 'P',
				'stock'  => 1,
				'status' => 'publish',
				'linked' => false,
			),
			$ids
		);

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $urls, $products ) {
				$urls[] = $url;

				wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
				$ids = array_map( 'intval', explode( ',', (string) ( $query['include'] ?? '' ) ) );

				return array(
					'headers'  => array(),
					'body'     => (string) wp_json_encode(
						array(
							'products' => $products( $ids ),
							'total'    => count( $ids ),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => '',
					),
					'cookies'  => array(),
				);
			},
			10,
			3
		);

		return $urls;
	}

	/**
	 * Una lista vacía no pide nada: el endpoint la tomaría como "no se pidió" y devolvería el
	 * catálogo entero. Es lo que pasa cuando no hay ningún vínculo.
	 *
	 * PRD-12
	 */
	public function test_an_empty_list_asks_for_nothing(): void {
		$urls = $this->fake_catalog();

		$this->assertSame(
			array(
				'products' => array(),
				'total'    => 0,
			),
			Catalog::fetch( 1, 100, array() )
		);
		$this->assertCount( 0, $urls );
	}

	/**
	 * PRD-13 — Más de 100 IDs se piden de a 100, y vuelven todos juntos.
	 */
	public function test_more_than_100_ids_go_in_batches(): void {
		$urls = $this->fake_catalog();

		$result = Catalog::fetch( 1, 100, range( 1, 150 ) );

		$this->assertCount( 2, $urls );
		$this->assertSame( range( 1, 150 ), array_column( $result['products'], 'id' ) );
	}

	/**
	 * PRD-14 — Una entrada mal armada se descarta: quien use la lista no desconfía de cada campo.
	 */
	public function test_malformed_entries_are_dropped(): void {
		$this->fake_catalog(
			static fn () => array(
				array(
					'id'     => 7,
					'sku'    => 'A',
					'name'   => 'Bien',
					'stock'  => null,
					'status' => 'publish',
					'linked' => true,
				),
				array(
					'id'     => '8',
					'sku'    => 'B',
					'name'   => 'Mal: id como texto',
					'stock'  => 1,
					'status' => 'publish',
					'linked' => false,
				),
				'no es un producto',
			)
		);

		$this->assertSame( array( 7 ), array_column( Catalog::fetch( 1, 100, array( 7, 8 ) )['products'], 'id' ) );
	}
}
