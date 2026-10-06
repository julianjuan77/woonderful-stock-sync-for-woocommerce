<?php
/**
 * Tests de POST /wss/v1/movements.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Rest\Movements;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Peers;
use Woonderful\StockSync\Transport\Signature;

/**
 * El receptor autentica, recibe el lote y responde un ACK por movimiento (ADR-001 §4).
 */
class Test_Rest_Movements extends WP_UnitTestCase {

	private const REMOTE_STORE = 'b1b1b1b1-0000-4000-8000-000000000000';

	private const SECRET = 'secreto-de-prueba';

	private const ROUTE = '/wss/v1/movements';

	/**
	 * El servidor REST, con la ruta registrada.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	/**
	 * Productos remotos para los vínculos: el índice `remote` no admite dos iguales.
	 *
	 * @var int
	 */
	private int $next_remote = 7000;

	public function set_up(): void {
		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Installer::install();
		Store::ensure_identity();
		wss_unhook_detector();

		update_option( Peers::OPTION, array( self::REMOTE_STORE => array( 'secret' => self::SECRET ) ), false );

		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride

		add_action( 'rest_api_init', array( Movements::class, 'register_routes' ) );
		$this->server = rest_get_server();
	}

	public function tear_down(): void {
		global $wpdb, $wp_rest_server;

		remove_action( 'rest_api_init', array( Movements::class, 'register_routes' ) );
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride

		// Las filas del log, no la tabla: el DROP de las otras confirma el borrado, y una
		// tabla menos que crear y borrar en cada test hace la suite más rápida.
		$wpdb->query( 'DELETE FROM ' . Installer::table( 'events' ) ); // phpcs:ignore WordPress.DB

		foreach ( array( 'movements', 'links', 'nonces' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		delete_option( Peers::OPTION );
		delete_option( Installer::OPTION_VERSION );
		delete_option( Store::OPTION_ID );

		parent::tear_down();
	}

	/**
	 * Un producto que gestiona stock, vinculado con uno remoto.
	 *
	 * @param int $stock Con cuántas unidades arranca.
	 *
	 * @return array{0: int, 1: int} El producto local y el remoto.
	 */
	private function linked_product( int $stock ): array {
		global $wpdb;

		$product = new WC_Product_Simple();
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );
		$product_id = (int) $product->save();
		$remote     = $this->next_remote++;

		$wpdb->insert(
			Installer::table( 'links' ),
			array(
				'local_product'  => $product_id,
				'remote_store'   => self::REMOTE_STORE,
				'remote_product' => $remote,
				'generation'     => 1,
				'base_stock'     => $stock,
				'base_set_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		return array( $product_id, $remote );
	}

	/**
	 * Un movimiento como lo manda la otra tienda.
	 *
	 * @param int                  $seq       Secuencia.
	 * @param int                  $remote    Producto en la otra tienda.
	 * @param int                  $delta     Unidades.
	 * @param array<string, mixed> $overrides Campos a pisar.
	 *
	 * @return array<string, mixed>
	 */
	private function movement( int $seq, int $remote, int $delta, array $overrides = array() ): array {
		return array_merge(
			array(
				'origin'     => self::REMOTE_STORE,
				'seq'        => $seq,
				'generation' => 1,
				'delta'      => $delta,
				'product'    => $remote,
				'reason'     => 'sale',
				'reference'  => 'pedido #4512',
			),
			$overrides
		);
	}

	/**
	 * Un request firmado como lo firmaría la otra tienda.
	 *
	 * @param string                $body    El cuerpo crudo.
	 * @param array<string, string> $headers Cabeceras a pisar después de firmar.
	 */
	private function request( string $body, array $headers = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_body( $body );

		$signed = Signature::headers( self::REMOTE_STORE, self::SECRET, 'POST', self::ROUTE, array(), $body );

		foreach ( array_merge( $signed, $headers ) as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return $request;
	}

	/**
	 * Manda un lote y devuelve la respuesta.
	 *
	 * @param array<int, mixed>     $movements Los movimientos.
	 * @param array<string, string> $headers   Cabeceras a pisar.
	 */
	private function send( array $movements, array $headers = array() ): WP_REST_Response {
		return $this->server->dispatch( $this->request( (string) wp_json_encode( array( 'movements' => $movements ) ), $headers ) );
	}

	/**
	 * El código de error de una respuesta.
	 *
	 * @param WP_REST_Response $response La respuesta.
	 */
	private function code_of( WP_REST_Response $response ): string {
		return (string) ( $response->get_data()['code'] ?? '' );
	}

	/**
	 * El estado de cada resultado, en orden.
	 *
	 * @param WP_REST_Response $response La respuesta.
	 *
	 * @return string[]
	 */
	private function statuses( WP_REST_Response $response ): array {
		return array_column( $response->get_data()['results'], 'status' );
	}

	/**
	 * El stock de un producto, leído de la base.
	 *
	 * @param int $product_id Producto.
	 */
	private function stock_of( int $product_id ): int {
		return (int) get_post_meta( $product_id, '_stock', true );
	}

	/**
	 * Cuántas filas tiene una tabla del plugin.
	 *
	 * @param string $name Nombre corto.
	 */
	private function rows_in( string $name ): int {
		global $wpdb;

		$table = Installer::table( $name );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * MOV-01 — Un lote bien firmado se aplica, con un resultado por movimiento.
	 */
	public function test_a_signed_batch_is_received(): void {
		list( $first, $first_remote )   = $this->linked_product( 10 );
		list( $second, $second_remote ) = $this->linked_product( 20 );

		$response = $this->send( array( $this->movement( 1, $first_remote, -2 ), $this->movement( 2, $second_remote, 3 ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				array(
					'origin' => self::REMOTE_STORE,
					'seq'    => 1,
					'status' => 'received',
				),
				array(
					'origin' => self::REMOTE_STORE,
					'seq'    => 2,
					'status' => 'received',
				),
			),
			$response->get_data()['results']
		);
		$this->assertSame( 8, $this->stock_of( $first ) );
		$this->assertSame( 23, $this->stock_of( $second ) );
	}

	/**
	 * Sin secreto no hay con qué verificar: una tienda que no está, y una que está sin
	 * secreto, son lo mismo.
	 *
	 * MOV-02
	 */
	public function test_an_unknown_store_is_rejected(): void {
		$response = $this->send( array(), array( 'X-WSS-Store' => 'c2c2c2c2-0000-4000-8000-000000000000' ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'wss_unknown_store', $this->code_of( $response ) );

		update_option( Peers::OPTION, array( self::REMOTE_STORE => array( 'secret' => '' ) ), false );

		$this->assertSame( 'wss_unknown_store', $this->code_of( $this->send( array() ) ) );
	}

	/**
	 * Un reloj desfasado se diagnostica como tal, no como una firma mala.
	 *
	 * MOV-03
	 */
	public function test_a_stale_timestamp_is_rejected(): void {
		$response = $this->send( array(), array( 'X-WSS-Timestamp' => (string) ( time() - Signature::WINDOW - 60 ) ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'wss_stale_timestamp', $this->code_of( $response ) );
	}

	/**
	 * Una firma mala se rechaza, y **no deja el nonce anotado**: si lo dejara, cualquiera
	 * sin el secreto podría llenar la tabla.
	 *
	 * MOV-04
	 */
	public function test_a_bad_signature_is_rejected_without_claiming_the_nonce(): void {
		$response = $this->send( array(), array( 'X-WSS-Signature' => str_repeat( '0', 64 ) ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'wss_bad_signature', $this->code_of( $response ) );
		$this->assertSame( 0, $this->rows_in( 'nonces' ) );
	}

	/**
	 * El mismo request, reenviado tal cual: se rechaza y el stock se descuenta una sola vez.
	 *
	 * MOV-05
	 */
	public function test_a_replayed_request_is_rejected(): void {
		list( $product_id, $remote ) = $this->linked_product( 10 );

		$request = $this->request( (string) wp_json_encode( array( 'movements' => array( $this->movement( 1, $remote, -2 ) ) ) ) );

		$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );

		$replay = $this->server->dispatch( $request );

		$this->assertSame( 401, $replay->get_status() );
		$this->assertSame( 'wss_replayed_nonce', $this->code_of( $replay ) );
		$this->assertSame( 8, $this->stock_of( $product_id ) );
	}

	/**
	 * MOV-06 — Un cuerpo que no es un JSON con una lista `movements` es un 400.
	 */
	public function test_a_malformed_body_is_rejected(): void {
		$bodies = array(
			'no es JSON'          => 'no es json',
			'sin movements'       => '{"otra":[]}',
			'movements no lista'  => '{"movements":{"a":1}}',
			'movements no es array' => '{"movements":3}',
		);

		foreach ( $bodies as $what => $body ) {
			$response = $this->server->dispatch( $this->request( $body ) );

			$this->assertSame( 400, $response->get_status(), $what );
			$this->assertSame( 'wss_bad_request', $this->code_of( $response ), $what );
		}
	}

	/**
	 * Un lote de 201 se rechaza entero y no se aplica nada: el techo del receptor es
	 * contrato (ADR-001 §4).
	 *
	 * MOV-07
	 */
	public function test_a_batch_over_200_is_rejected(): void {
		list( $product_id, $remote ) = $this->linked_product( 1000 );

		$movements = array();

		for ( $seq = 1; $seq <= 201; $seq++ ) {
			$movements[] = $this->movement( $seq, $remote, -1 );
		}

		$response = $this->send( $movements );

		$this->assertSame( 413, $response->get_status() );
		$this->assertSame( 'wss_batch_too_large', $this->code_of( $response ) );
		$this->assertSame( 1000, $this->stock_of( $product_id ) );
		$this->assertSame( 0, $this->rows_in( 'movements' ) );
	}

	/**
	 * Un movimiento que dice venir de otra tienda que la que firmó —por ejemplo, de esta
	 * misma— vuelve con error y no se escribe. El resto del lote sigue, y los resultados
	 * mantienen el orden.
	 *
	 * MOV-08
	 */
	public function test_a_movement_from_another_origin_is_an_error(): void {
		list( $product_id, $remote ) = $this->linked_product( 10 );

		$response = $this->send(
			array(
				$this->movement( 1, $remote, -1 ),
				$this->movement( 78, $remote, -1, array( 'origin' => Store::id() ) ),
				$this->movement( 2, $remote, -1 ),
			)
		);

		$results = $response->get_data()['results'];

		$this->assertSame( array( 'received', 'error', 'received' ), $this->statuses( $response ) );
		$this->assertSame( 'origin_mismatch', $results[1]['reason'] );
		$this->assertSame( 8, $this->stock_of( $product_id ) );
		$this->assertSame( 2, $this->rows_in( 'movements' ) );
	}

	/**
	 * Lo que impide identificar o aplicar un movimiento es un error de ese movimiento, no
	 * del lote. Un `seq` que llega como texto no se puede leer: el emisor lo empareja por la
	 * posición.
	 *
	 * MOV-09
	 */
	public function test_a_malformed_movement_is_an_error_in_its_position(): void {
		list( $product_id, $remote ) = $this->linked_product( 10 );

		$cases = array(
			'invalid_seq'        => array( 'seq' => '5' ),
			'invalid_generation' => array( 'generation' => -1 ),
			'invalid_delta'      => array( 'delta' => 1.5 ),
			'invalid_product'    => array( 'product' => 0 ),
		);

		$movements = array( $this->movement( 1, $remote, -1 ) );

		foreach ( $cases as $overrides ) {
			$movements[] = $this->movement( 90, $remote, -1, $overrides );
		}

		$movements[] = 'no es un movimiento';

		$results = $this->send( $movements )->get_data()['results'];

		$this->assertCount( 6, $results );
		$this->assertSame( 'received', $results[0]['status'] );
		$this->assertSame( array_keys( $cases ), array_column( array_slice( $results, 1, 4 ), 'reason' ) );
		$this->assertNull( $results[1]['seq'], 'el seq ilegible no se inventa' );
		$this->assertSame( 'invalid_movement', $results[5]['reason'] );
		$this->assertSame( 9, $this->stock_of( $product_id ) );
	}

	/**
	 * Lo que no impide aplicar no se rechaza: la referencia larga se recorta, y un motivo
	 * que esta versión no conoce se guarda como llega.
	 *
	 * MOV-10
	 */
	public function test_labels_are_kept_not_rejected(): void {
		global $wpdb;

		list( $product_id, $remote ) = $this->linked_product( 10 );

		$response = $this->send(
			array(
				$this->movement(
					1,
					$remote,
					-1,
					array(
						'reason'    => 'restock_v2',
						'reference' => str_repeat( 'x', 150 ),
					)
				),
			)
		);

		$table = Installer::table( 'movements' );
		$row   = $wpdb->get_row( "SELECT reason, reference FROM {$table}" ); // phpcs:ignore WordPress.DB

		$this->assertSame( array( 'received' ), $this->statuses( $response ) );
		$this->assertSame( 'restock_v2', $row->reason );
		$this->assertSame( str_repeat( 'x', 100 ), $row->reference );
		$this->assertSame( 9, $this->stock_of( $product_id ) );
	}

	/**
	 * Si `apply()` no pudo guardar un movimiento —un hook de terceros falló—, ése vuelve
	 * con error para que el emisor lo reintente, y el siguiente del lote se aplica.
	 *
	 * MOV-11
	 */
	public function test_a_movement_that_could_not_be_stored_is_an_error(): void {
		list( $broken, $broken_remote ) = $this->linked_product( 10 );
		list( $fine, $fine_remote )     = $this->linked_product( 20 );

		$break = static function ( $product ) use ( $broken ): void {
			if ( $product->get_id() === $broken ) {
				throw new RuntimeException( 'un plugin roto' );
			}
		};

		add_action( 'woocommerce_product_set_stock', $break );
		$response = $this->send( array( $this->movement( 1, $broken_remote, -1 ), $this->movement( 2, $fine_remote, -1 ) ) );
		remove_action( 'woocommerce_product_set_stock', $break );

		$results = $response->get_data()['results'];

		$this->assertSame( array( 'error', 'received' ), $this->statuses( $response ) );
		$this->assertSame( 'not_stored', $results[0]['reason'] );
		$this->assertSame( 10, $this->stock_of( $broken ) );
		$this->assertSame( 19, $this->stock_of( $fine ) );
	}

	/**
	 * Justo en el techo se procesa: 200 entra, 201 no (MOV-07). Productos sin vincular, que
	 * se descartan sin tocar stock, para que el test no tarde.
	 *
	 * MOV-12
	 */
	public function test_a_batch_of_200_is_received(): void {
		$movements = array();

		for ( $seq = 1; $seq <= 200; $seq++ ) {
			$movements[] = $this->movement( $seq, 99999, -1 );
		}

		$response = $this->send( $movements );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 200, $response->get_data()['results'] );
	}

	/**
	 * Un entero que PHP acepta pero la columna no: sin modo estricto, MySQL guardaría el
	 * delta recortado y WooCommerce aplicaría el real.
	 *
	 * MOV-13
	 */
	public function test_integers_outside_the_column_range_are_errors(): void {
		list( $product_id, $remote ) = $this->linked_product( 10 );

		$results = $this->send(
			array(
				$this->movement( 1, $remote, 2147483648 ),
				$this->movement( 2, $remote, -2147483649 ),
				$this->movement( 3, $remote, -1, array( 'generation' => 4294967296 ) ),
				$this->movement( 4, $remote, -1 ),
			)
		)->get_data()['results'];

		$this->assertSame( array( 'invalid_delta', 'invalid_delta', 'invalid_generation' ), array_column( array_slice( $results, 0, 3 ), 'reason' ) );
		$this->assertSame( 'received', $results[3]['status'] );
		$this->assertSame( 9, $this->stock_of( $product_id ) );
	}
}
