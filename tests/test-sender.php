<?php
/**
 * Tests del envío de movimientos.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Ledger\Ledger;
use Woonderful\StockSync\Rest\Movements;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Peers;
use Woonderful\StockSync\Transport\Sender;

/**
 * El emisor reclama, manda firmado y anota cada resultado por posición (ADR-001 §2, §4).
 */
class Test_Sender extends WP_UnitTestCase {

	private const REMOTE_STORE = 'b1b1b1b1-0000-4000-8000-000000000000';

	private const SECRET = 'secreto-de-prueba';

	private const REST_URL = 'https://otra.test/wp-json/wss/v1';

	/**
	 * Los requests que salieron, como los recibió `pre_http_request`.
	 *
	 * @var array<int, array{url: string, args: array<string, mixed>}>
	 */
	private array $sent = array();

	/**
	 * Lo que contesta la otra tienda: una función que recibe los movimientos enviados.
	 *
	 * @var callable|null
	 */
	private $answer = null;

	/**
	 * Productos locales para los vínculos.
	 *
	 * @var int
	 */
	private int $next_product = 3000;

	public function set_up(): void {
		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Installer::install();
		Store::ensure_identity();
		wss_unhook_detector();

		$this->pair( self::REST_URL );

		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	public function tear_down(): void {
		global $wpdb;

		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );

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
	 * Empareja con la otra tienda.
	 *
	 * @param string $rest_url La base de su API.
	 */
	private function pair( string $rest_url ): void {
		update_option(
			Peers::OPTION,
			array(
				self::REMOTE_STORE => array(
					'rest_url' => $rest_url,
					'secret'   => self::SECRET,
				),
			),
			false
		);
	}

	/**
	 * Hace de red: guarda el request y devuelve lo que diga `$this->answer`.
	 *
	 * @param false|array|WP_Error $preempt Lo que venía.
	 * @param array<string, mixed> $args    Los argumentos del request.
	 * @param string               $url     La URL.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function intercept( $preempt, array $args, string $url ) {
		$this->sent[] = array(
			'url'  => $url,
			'args' => $args,
		);

		$movements = json_decode( (string) $args['body'], true )['movements'] ?? array();
		$answer    = $this->answer ?? static fn ( array $movements ) => array(
			200,
			array(
				'results' => array_map(
					static fn ( $movement ) => array(
						'origin' => $movement['origin'],
						'seq'    => $movement['seq'],
						'status' => 'received',
					),
					$movements
				),
			),
		);

		$reply = $answer( $movements );

		if ( $reply instanceof WP_Error ) {
			return $reply;
		}

		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( $reply[1] ),
			'response' => array(
				'code'    => $reply[0],
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Un movimiento propio pendiente y ya vencido su retardo, listo para salir.
	 *
	 * @param int $delta Unidades.
	 */
	private function pending_movement( int $delta ): int {
		global $wpdb;

		$product_id = $this->next_product++;

		$wpdb->insert(
			Installer::table( 'links' ),
			array(
				'local_product'  => $product_id,
				'remote_store'   => self::REMOTE_STORE,
				'remote_product' => $product_id + 1000,
				'generation'     => 3,
				'base_stock'     => 10,
				'base_set_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$movement_id = (int) Ledger::record( $product_id, $delta, 'sale', 'pedido #4512' );

		$wpdb->update( Installer::table( 'movements' ), array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $movement_id ) ); // phpcs:ignore WordPress.DB

		return $movement_id;
	}

	/**
	 * Una columna de un movimiento.
	 *
	 * @param int    $movement_id ID.
	 * @param string $column      Columna.
	 */
	private function column_of( int $movement_id, string $column ): ?string {
		global $wpdb;

		$table = Installer::table( 'movements' );

		return $wpdb->get_var( $wpdb->prepare( "SELECT {$column} FROM {$table} WHERE id = %d", $movement_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Sin tienda emparejada no sale nada, y **no se reclama nada**: reclamar gastaría un
	 * intento contra el techo de 6 horas sin haber intentado nada.
	 *
	 * SND-01
	 */
	public function test_without_a_peer_nothing_is_claimed(): void {
		$movement_id = $this->pending_movement( -2 );
		delete_option( Peers::OPTION );

		$summary = Sender::run();

		$this->assertSame( array(), $this->sent );
		$this->assertSame( 'pending', $this->column_of( $movement_id, 'delivery' ) );
		$this->assertSame( '0', $this->column_of( $movement_id, 'attempts' ) );
		$this->assertSame( Sender::NO_PEER, $summary['error'], 'distinto de no tener nada que mandar' );
	}

	/**
	 * SND-02 — Lo que está pendiente sale en un POST a la base de la otra tienda, y se confirma.
	 */
	public function test_pending_movements_are_sent_and_confirmed(): void {
		$first  = $this->pending_movement( -2 );
		$second = $this->pending_movement( 3 );

		$summary = Sender::run();

		$this->assertCount( 1, $this->sent );
		$this->assertSame( self::REST_URL . '/movements', $this->sent[0]['url'] );
		$this->assertSame( 'POST', $this->sent[0]['args']['method'] );
		$this->assertSame( 'confirmed', $this->column_of( $first, 'delivery' ) );
		$this->assertSame( 'confirmed', $this->column_of( $second, 'delivery' ) );
		$this->assertSame(
			array(
				'sent'      => 2,
				'confirmed' => 2,
				'failed'    => 0,
				'error'     => null,
			),
			$summary
		);
	}

	/**
	 * SND-03 — Cada movimiento viaja con los campos de ADR-001 §4.
	 */
	public function test_the_body_carries_the_contract_fields(): void {
		$movement_id = $this->pending_movement( -2 );

		Sender::run();

		$movements = json_decode( (string) $this->sent[0]['args']['body'], true )['movements'];

		$this->assertSame(
			array(
				array(
					'origin'     => Store::id(),
					'seq'        => $movement_id,
					'generation' => 3,
					'delta'      => -2,
					'product'    => 3000,
					'reason'     => 'sale',
					'reference'  => 'pedido #4512',
				),
			),
			$movements
		);
	}

	/**
	 * El contrato de los dos lados: el request que arma el emisor pasa la autenticación del
	 * receptor. Con permalinks y sin ellos: sin, la base trae `?rest_route=` y el receptor
	 * lo saca de la query antes de verificar.
	 *
	 * SND-04
	 */
	public function test_the_request_authenticates_at_the_receiver(): void {
		$bases = array(
			'con permalinks' => self::REST_URL,
			'sin permalinks' => 'https://otra.test/?rest_route=/wss/v1',
		);

		foreach ( $bases as $what => $base ) {
			$this->sent = array();
			$this->pair( $base );
			$this->pending_movement( -1 );

			Sender::run();

			$sent = $this->sent[0];

			$this->assertStringStartsWith( $base . '/movements', $sent['url'], $what );

			// Del otro lado: WordPress arma el request con la ruta y la query que llegaron.
			wp_parse_str( (string) wp_parse_url( $sent['url'], PHP_URL_QUERY ), $query );

			$request = new WP_REST_Request( 'POST', Sender::ROUTE );
			$request->set_query_params( $query );
			$request->set_body( (string) $sent['args']['body'] );

			foreach ( $sent['args']['headers'] as $name => $value ) {
				$request->set_header( $name, $value );
			}

			// El receptor busca el secreto de quien firma: esta tienda.
			update_option( Peers::OPTION, array( Store::id() => array( 'secret' => self::SECRET ) ), false );

			$this->assertTrue( Movements::authenticate( $request ), $what );
		}
	}

	/**
	 * SND-05 — Cada resultado va a su fila: el confirmado cierra, el error vuelve a la cola con su motivo.
	 */
	public function test_each_result_goes_to_its_row(): void {
		$first  = $this->pending_movement( -2 );
		$second = $this->pending_movement( -1 );

		$this->answer = static fn ( array $movements ) => array(
			200,
			array(
				'results' => array(
					array(
						'origin' => $movements[0]['origin'],
						'seq'    => $movements[0]['seq'],
						'status' => 'received',
					),
					array(
						'origin' => $movements[1]['origin'],
						'seq'    => $movements[1]['seq'],
						'status' => 'error',
						'reason' => 'invalid_delta',
					),
				),
			),
		);

		Sender::run();

		$this->assertSame( 'confirmed', $this->column_of( $first, 'delivery' ) );
		$this->assertSame( 'pending', $this->column_of( $second, 'delivery' ) );
		$this->assertSame( 'invalid_delta', $this->column_of( $second, 'last_error' ) );
	}

	/**
	 * SND-06 — Si falla la red, falla todo el lote con el motivo de la red.
	 */
	public function test_a_network_error_fails_the_whole_batch(): void {
		$first  = $this->pending_movement( -2 );
		$second = $this->pending_movement( -1 );

		$this->answer = static fn () => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );

		$summary = Sender::run();

		foreach ( array( $first, $second ) as $movement_id ) {
			$this->assertSame( 'pending', $this->column_of( $movement_id, 'delivery' ) );
			$this->assertSame( 'cURL error 28: Operation timed out', $this->column_of( $movement_id, 'last_error' ) );
		}

		$this->assertSame( 'cURL error 28: Operation timed out', $summary['error'] );
	}

	/**
	 * Un rechazo del receptor falla todo el lote, y el motivo lleva el código: es lo que
	 * dice si el problema es el reloj o la firma.
	 *
	 * SND-07
	 */
	public function test_a_rejection_keeps_the_receiver_code(): void {
		$movement_id = $this->pending_movement( -2 );

		$this->answer = static fn () => array(
			401,
			array(
				'code'    => 'wss_stale_timestamp',
				'message' => 'x',
			),
		);

		Sender::run();

		$this->assertSame( 'pending', $this->column_of( $movement_id, 'delivery' ) );
		$this->assertSame( 'HTTP 401 wss_stale_timestamp', $this->column_of( $movement_id, 'last_error' ) );
	}

	/**
	 * Un resultado que falta, o que en su posición trae el `seq` de otra fila, no confirma:
	 * un receptor que desordenara la respuesta haría confirmar filas ajenas.
	 *
	 * SND-08
	 */
	public function test_a_missing_or_mismatched_result_does_not_confirm(): void {
		$first  = $this->pending_movement( -2 );
		$second = $this->pending_movement( -1 );
		$third  = $this->pending_movement( -1 );

		$this->answer = static fn ( array $movements ) => array(
			200,
			array(
				'results' => array(
					array(
						'origin' => $movements[0]['origin'],
						'seq'    => $movements[0]['seq'],
						'status' => 'received',
					),
					array(
						'origin' => $movements[2]['origin'],
						'seq'    => $movements[2]['seq'],
						'status' => 'received',
					),
				),
			),
		);

		Sender::run();

		$this->assertSame( 'confirmed', $this->column_of( $first, 'delivery' ) );
		$this->assertSame( 'result_mismatch', $this->column_of( $second, 'last_error' ) );
		$this->assertSame( 'missing_result', $this->column_of( $third, 'last_error' ) );
	}

	/**
	 * La purga anota hasta dónde purgó de cada tienda de origen. Una tienda que se volvió a
	 * emparejar con otro UUID deja una entrada vieja sin URL ni secreto, y contarla cortaría
	 * la entrega para siempre.
	 *
	 * SND-10
	 */
	public function test_a_leftover_peer_entry_does_not_stop_delivery(): void {
		$movement_id = $this->pending_movement( -2 );

		$peers = get_option( Peers::OPTION );
		$peers['c2c2c2c2-0000-4000-8000-000000000000'] = array( 'purged_through' => 77 );
		update_option( Peers::OPTION, $peers, false );

		Sender::run();

		$this->assertCount( 1, $this->sent );
		$this->assertSame( 'confirmed', $this->column_of( $movement_id, 'delivery' ) );
	}

	/**
	 * SND-09 — Lo que todavía no venció su retardo no sale.
	 */
	public function test_nothing_due_sends_nothing(): void {
		$product_id = $this->next_product++;

		global $wpdb;
		$wpdb->insert(
			Installer::table( 'links' ),
			array(
				'local_product'  => $product_id,
				'remote_store'   => self::REMOTE_STORE,
				'remote_product' => $product_id + 1000,
				'generation'     => 3,
				'base_stock'     => 10,
				'base_set_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		Ledger::record( $product_id, -2 );

		$summary = Sender::run();

		$this->assertSame( array(), $this->sent );
		$this->assertNull( $summary['error'], 'no tener nada que mandar no es un error' );
	}
}
