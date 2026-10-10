<?php
/**
 * Tests de la pausa y el corte.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Admin\Api;
use Woonderful\StockSync\Cli\Command;
use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Ledger\Ledger;
use Woonderful\StockSync\Linking\Linker;
use Woonderful\StockSync\Log\Events;
use Woonderful\StockSync\Log\Notifier;
use Woonderful\StockSync\Log\Peer;
use Woonderful\StockSync\Reconciliation\Reconciler;
use Woonderful\StockSync\Reconciliation\Result;
use Woonderful\StockSync\Rest\Movements;
use Woonderful\StockSync\Rest\Pair;
use Woonderful\StockSync\Rest\Reconcile;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Pass;
use Woonderful\StockSync\Transport\Pause;
use Woonderful\StockSync\Transport\Peers;
use Woonderful\StockSync\Transport\Sender;

/**
 * En pausa el libro anota y nadie manda, aplica, reconcilia ni vincula; desemparejar olvida la
 * URL y el secreto en las dos tiendas (ADR-015).
 */
class Test_Pause extends WP_UnitTestCase {

	private const REMOTE_STORE = 'b1b1b1b1-0000-4000-8000-000000000000';

	/**
	 * Lo que contesta la otra tienda.
	 *
	 * @var array<string, mixed>|WP_Error
	 */
	private $reply;

	/**
	 * Las URLs que se pidieron.
	 *
	 * @var string[]
	 */
	private array $urls = array();

	/**
	 * Los argumentos de cada request.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $args = array();

	public function set_up(): void {
		global $wpdb;

		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Installer::install();
		Store::ensure_identity();

		// Acá y no en el tear_down: el DROP de las tablas confirma lo que escribió el test.
		foreach ( array( Peer::OPTION, Pause::OPTION, Result::OPTION, Notifier::OPTION_STATE ) as $option ) {
			delete_option( $option );
		}
		$wpdb->query( 'DELETE FROM ' . Installer::table( 'events' ) ); // phpcs:ignore WordPress.DB

		update_option(
			Peers::OPTION,
			array(
				self::REMOTE_STORE => array(
					'rest_url'       => 'https://otra.test/wp-json/wss/v1',
					'secret'         => 'secreto-de-prueba',
					'role'           => Peers::SECONDARY,
					'label'          => 'Minorista',
					'purged_through' => 7,
				),
			),
			false
		);

		$this->reply = $this->http( 200, array() );
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

		parent::tear_down();
	}

	/**
	 * Hace de la otra tienda.
	 *
	 * @param false|array|WP_Error $preempt Lo que venía.
	 * @param array<string, mixed> $args    Los argumentos.
	 * @param string               $url     La URL.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function intercept( $preempt, array $args, string $url ) {
		$this->urls[] = $url;
		$this->args[] = $args;

		return $this->reply;
	}

	/**
	 * Una respuesta HTTP.
	 *
	 * @param int                  $status El código.
	 * @param array<string, mixed> $data   El cuerpo.
	 *
	 * @return array<string, mixed>
	 */
	private function http( int $status, array $data ): array {
		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( $data ),
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Un movimiento propio pendiente, listo para salir.
	 */
	private function pending_movement(): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB
			Installer::table( 'links' ),
			array(
				'local_product'  => 900501,
				'remote_store'   => self::REMOTE_STORE,
				'remote_product' => 77,
				'generation'     => 1,
				'base_stock'     => 10,
				'base_set_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$movement = (int) Ledger::record( 900501, -2, 'sale', 'pedido #1' );
		$wpdb->update( Installer::table( 'movements' ), array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $movement ) ); // phpcs:ignore WordPress.DB

		return $movement;
	}

	/**
	 * Un movimiento del libro.
	 *
	 * @param int $id Su ID.
	 *
	 * @return array<string, mixed>
	 */
	private function movement( int $id ): array {
		global $wpdb;

		$table = Installer::table( 'movements' );

		return (array) $wpdb->get_row( $wpdb->prepare( "SELECT delivery, first_attempt_at FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Los tipos de evento registrados, en orden.
	 *
	 * @return string[]
	 */
	private function kinds(): array {
		return array_reverse( array_column( Events::list()['items'], 'kind' ) );
	}

	/**
	 * PAU-01 — **La pausa se guarda una vez y se anota**: pausar dos veces no anota dos, y
	 * reanudar la borra.
	 */
	public function test_the_pause_is_stored_and_logged(): void {
		$this->assertTrue( Pause::set( true, Pause::HERE ) );
		$this->assertFalse( Pause::set( true, Pause::HERE ), 'ya estaba' );
		$this->assertSame( Pause::HERE, Pause::current()['by'] );

		$this->assertTrue( Pause::set( false, Pause::PEER ) );
		$this->assertNull( Pause::current() );
		$this->assertSame( array( Events::PAUSED, Events::RESUMED ), $this->kinds() );
		$this->assertSame( 'La otra tienda reanudó la sincronización.', Events::describe( Events::list()['items'][0] ) );
	}

	/**
	 * PAU-02 — **En pausa el emisor no reclama nada**: el movimiento queda pendiente y sin primer
	 * intento, así el techo de 6 horas no corre; la pasada tampoco le pregunta nada a la otra.
	 */
	public function test_nothing_is_sent_while_paused(): void {
		$movement = $this->pending_movement();
		Pause::set( true, Pause::HERE );

		$this->assertSame( Sender::PAUSED, Sender::run()['error'] );
		Pass::run();

		$this->assertSame( array(), $this->urls, 'ni un request a la otra' );
		$row = $this->movement( $movement );
		$this->assertSame( 'pending', $row['delivery'] );
		$this->assertNull( $row['first_attempt_at'] );

		Pause::set( false, Pause::HERE );
		Sender::run();

		$this->assertStringEndsWith( '/movements', $this->urls[0], 'al reanudar, sale' );
	}

	/**
	 * PAU-03 — **En pausa no se aplica ni se contesta la reconciliación**: `409 wss_paused`.
	 */
	public function test_a_paused_store_refuses_movements_and_reconciliation(): void {
		Pause::set( true, Pause::PEER );

		$request = new WP_REST_Request( 'POST', '/wss/v1/movements' );
		$request->set_body( (string) wp_json_encode( array( 'movements' => array() ) ) );
		$refused = Movements::receive( $request );

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'wss_paused', $refused->get_error_code() );
		$this->assertSame( 409, $refused->get_error_data()['status'] );

		$answer = Reconcile::answer( new WP_REST_Request( 'POST', '/wss/v1/reconcile/digest' ) );

		$this->assertSame( 'wss_paused', $answer->get_error_code() );
	}

	/**
	 * PAU-04 — **En pausa no se reconcilia ni se vincula**, y no queda guardado ningún resultado.
	 * Descartar sí: no le habla a la otra.
	 */
	public function test_nothing_is_reconciled_or_linked_while_paused(): void {
		Pause::set( true, Pause::HERE );

		$this->assertSame( Reconciler::PAUSED, Reconciler::run( Result::MANUAL )['outcome'] );
		$this->assertNull( Result::last() );
		$this->assertSame( 'wss_paused', Linker::link( 900502, 78, 5, 5 )->get_error_code() );
		$product = self::factory()->post->create( array( 'post_type' => 'product' ) );
		$this->assertTrue( Linker::discard( $product ), 'descartar es local: en pausa se puede' );
		$this->assertSame( array(), $this->urls );
	}

	/**
	 * PAU-05 — **Un `409 wss_paused` de la otra pausa también a ésta**, sin marcarla caída ni
	 * con rechazo, y sin anotar como autor a quien esté logueado acá.
	 */
	public function test_a_paused_answer_pauses_this_store(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Peer::observe( $this->http( 409, array( 'code' => 'wss_paused' ) ) );

		$this->assertSame( Pause::PEER, Pause::current()['by'] );
		$this->assertNull( Pause::current()['user'], 'la pidió la otra, no quien está logueado acá' );
		$this->assertSame( Peer::OK, Peer::current()['state'] );
	}

	/**
	 * PAU-06 — **Pausar le avisa a la otra** con `POST /pause` firmado. Si no responde, queda en
	 * pausa igual acá.
	 */
	public function test_pausing_tells_the_other_store(): void {
		$this->assertTrue( Pause::toggle( true ) );
		$this->assertSame( 'https://otra.test/wp-json/wss/v1/pause', $this->urls[0] );
		$this->assertSame( array( 'paused' => true ), json_decode( $this->args[0]['body'], true ) );
		$this->assertArrayHasKey( 'X-WSS-Signature', $this->args[0]['headers'] );

		$this->reply = new WP_Error( 'http_request_failed', 'cURL error 7' );

		$this->assertFalse( Pause::toggle( false ) );
		$this->assertNull( Pause::current(), 'reanudada acá aunque la otra no supo' );
	}

	/**
	 * PAU-07 — **La otra pausó o desempareja**: `POST /pause` deja a ésta igual; un cuerpo malo
	 * es un 400. `POST /unpair` la deja sin socia.
	 */
	public function test_the_peer_endpoints(): void {
		$request = new WP_REST_Request( 'POST', '/wss/v1/pause' );
		$request->set_body( '{"paused":true}' );
		Pair::pause( $request );

		$this->assertSame( Pause::PEER, Pause::current()['by'] );

		$request->set_body( '{"paused":"si"}' );

		$this->assertSame( 400, Pair::pause( $request )->get_error_data()['status'] );

		Pair::unpair();

		$this->assertNull( Peers::only() );
		$this->assertNull( Pause::current(), 'sin par no hay pausa' );
	}

	/**
	 * PAU-08 — **Desemparejar olvida la URL y el secreto, y nada más**: la etiqueta y
	 * `purged_through` quedan, el estado de la otra se borra, y se le avisa con `POST /unpair`.
	 * Si no responde, se desempareja igual.
	 */
	public function test_unpairing_forgets_only_the_address_and_secret(): void {
		update_option( Peer::OPTION, array( 'state' => Peer::REJECTED ), false );
		$this->reply = new WP_Error( 'http_request_failed', 'cURL error 7' );

		$this->assertFalse( Peers::unpair() );
		$this->assertSame( 'https://otra.test/wp-json/wss/v1/unpair', $this->urls[0] );

		$stored = get_option( Peers::OPTION )[ self::REMOTE_STORE ];

		$this->assertArrayNotHasKey( 'secret', $stored );
		$this->assertArrayNotHasKey( 'rest_url', $stored );
		$this->assertSame( 'Minorista', $stored['label'] );
		$this->assertSame( 7, $stored['purged_through'] );
		$this->assertFalse( get_option( Peer::OPTION, false ) );
		$this->assertContains( Events::UNPAIRED, $this->kinds() );
	}

	/**
	 * PAU-09 — **En pausa no se avisa que la otra no responde**: el tick ni le pregunta.
	 */
	public function test_no_outage_is_reported_while_paused(): void {
		update_option(
			Peer::OPTION,
			array(
				'state'    => Peer::UNREACHABLE,
				'since'    => time() - 2 * HOUR_IN_SECONDS,
				'notified' => false,
			),
			false
		);
		Pause::set( true, Pause::HERE );

		Notifier::tick();

		$this->assertSame( array(), $this->urls, 'ni el sondeo' );
		$this->assertFalse( Peer::current()['notified'] );
	}

	/**
	 * PAU-10 — **Desde la pantalla**: `POST /pause` devuelve el estado con la pausa y si la otra
	 * se enteró; `POST /unpair`, sin socia. Sin socia, las dos son un 409.
	 */
	public function test_the_admin_api(): void {
		global $wp_rest_server;

		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		add_action( 'rest_api_init', array( Api::class, 'register_routes' ) );
		$server = rest_get_server();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/wss-admin/v1/pause' );
		$request->set_param( 'paused', 'true' );
		$data = $server->dispatch( $request )->get_data();

		$this->assertSame( Pause::HERE, $data['paused']['by'] );
		$this->assertSame( wp_get_current_user()->display_name, $data['paused']['user'] );
		$this->assertTrue( $data['peer_told'] );
		$this->assertSame( 'otra.test', $data['peer']['host'] );

		$data = $server->dispatch( new WP_REST_Request( 'POST', '/wss-admin/v1/unpair' ) )->get_data();

		$this->assertFalse( $data['paired'] );
		$this->assertNull( $data['paused'] );
		$this->assertSame( 409, $server->dispatch( new WP_REST_Request( 'POST', '/wss-admin/v1/unpair' ) )->get_status() );
	}

	/**
	 * PAU-11 — **Por WP-CLI**: `pause` avisa si la otra no se enteró, y `unpair` sin `--yes`
	 * pregunta.
	 */
	public function test_the_commands(): void {
		WP_CLI::$out = array();
		$this->reply = new WP_Error( 'http_request_failed', 'cURL error 7' );

		( new Command() )->pause( array(), array() );

		$this->assertTrue( Pause::is_on() );
		$this->assertStringStartsWith( 'Warning: Sincronización en pausa.', WP_CLI::$out[0] );

		try {
			( new Command() )->unpair( array(), array() );
			$this->fail( 'Sin --yes tenía que preguntar.' );
		} catch ( RuntimeException $e ) {
			$this->assertNotNull( Peers::only(), 'sigue emparejada' );
		}

		( new Command() )->unpair( array(), array( 'yes' => true ) );

		$this->assertNull( Peers::only() );
	}
}
