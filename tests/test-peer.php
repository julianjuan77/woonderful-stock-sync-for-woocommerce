<?php
/**
 * Tests del estado de la otra tienda.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Ledger\Ledger;
use Woonderful\StockSync\Linking\Linker;
use Woonderful\StockSync\Log\Events;
use Woonderful\StockSync\Log\Peer;
use Woonderful\StockSync\Reconciliation\Remote;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Catalog;
use Woonderful\StockSync\Transport\Peers;
use Woonderful\StockSync\Transport\Sender;

/**
 * Caída, rechazo y vuelta: sólo el cambio va al log (ADR-011 §4).
 */
class Test_Peer extends WP_UnitTestCase {

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

	public function set_up(): void {
		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Installer::install();
		Store::ensure_identity();
		delete_option( Peer::OPTION );

		update_option(
			Peers::OPTION,
			array(
				self::REMOTE_STORE => array(
					'rest_url' => 'https://otra.test/wp-json/wss/v1',
					'secret'   => 'secreto-de-prueba',
					'role'     => Peers::SECONDARY,
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

		foreach ( array( 'movements', 'links', 'nonces', 'events' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		delete_option( Peer::OPTION );
		delete_option( Peers::OPTION );
		delete_option( Installer::OPTION_VERSION );
		delete_option( Store::OPTION_ID );

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
	 * Los tipos de evento registrados, en orden.
	 *
	 * @return string[]
	 */
	private function kinds(): array {
		return array_reverse( array_column( Events::list()['items'], 'kind' ) );
	}

	/**
	 * PER-01 — Con la otra andando, no se escribe nada.
	 */
	public function test_a_working_peer_writes_nothing(): void {
		Peer::observe( $this->http( 200, array() ) );

		$this->assertSame( Peer::OK, Peer::current()['state'] );
		$this->assertSame( array(), $this->kinds() );
	}

	/**
	 * PER-02 — **Una caída se registra una vez**: un error de red, un timeout o un 5xx; las
	 * respuestas siguientes iguales no escriben nada, ni mueven el "desde".
	 */
	public function test_an_outage_is_recorded_once(): void {
		Peer::observe( new WP_Error( 'http_request_failed', 'cURL error 28' ) );
		$since = Peer::current()['since'];
		Peer::observe( $this->http( 503, array() ) );
		Peer::observe( new WP_Error( 'http_request_failed', 'cURL error 7' ) );

		$this->assertSame( Peer::UNREACHABLE, Peer::current()['state'] );
		$this->assertSame( $since, Peer::current()['since'] );
		$this->assertSame( array( Events::PEER_UNREACHABLE ), $this->kinds() );
		$this->assertSame( 'cURL error 28', Events::list()['items'][0]['context']['error'] );
	}

	/**
	 * PER-03 — **Un 4xx es un rechazo, no una caída**, y es un error: esperar no lo arregla.
	 */
	public function test_a_4xx_is_a_rejection(): void {
		Peer::observe( $this->http( 401, array( 'code' => 'wss_bad_signature' ) ) );

		$event = Events::list()['items'][0];

		$this->assertSame( Peer::REJECTED, Peer::current()['state'] );
		$this->assertSame( Events::PEER_REJECTED, $event['kind'] );
		$this->assertSame( Events::ERROR, $event['level'] );
		$this->assertSame( 'HTTP 401 wss_bad_signature', $event['context']['error'] );
	}

	/**
	 * PER-04 — **Un `409 wss_reconciling` no cambia nada**: la otra está ocupada, y es normal.
	 * Otro 409 sí es un rechazo.
	 */
	public function test_a_busy_peer_is_not_a_rejection(): void {
		Peer::observe( $this->http( 409, array( 'code' => 'wss_reconciling' ) ) );

		$this->assertSame( Peer::OK, Peer::current()['state'] );

		Peer::observe( $this->http( 409, array( 'code' => 'otro' ) ) );

		$this->assertSame( Peer::REJECTED, Peer::current()['state'] );
	}

	/**
	 * PER-05 — Cuando vuelve, se registra con cuánto duró; y "volvió" queda pendiente de
	 * avisar sólo si se había avisado la caída.
	 */
	public function test_coming_back_is_recorded(): void {
		Peer::observe( new WP_Error( 'http_request_failed', 'x' ) );
		Peer::observe( $this->http( 200, array() ) );

		$this->assertSame( array( Events::PEER_UNREACHABLE, Events::PEER_BACK ), $this->kinds() );
		$this->assertFalse( Peer::take_back_pending(), 'no se avisó la caída' );

		Peer::observe( $this->http( 401, array() ) );
		Peer::mark_notified();
		Peer::observe( $this->http( 200, array() ) );

		$kinds = $this->kinds();

		$this->assertSame( Events::PEER_ACCEPTED, end( $kinds ) );
		$this->assertTrue( Peer::take_back_pending() );
		$this->assertFalse( Peer::take_back_pending(), 'se da por avisado una vez' );
	}

	/**
	 * PER-06 — La causa de lo que falla ahora: la caída o el rechazo, o nada.
	 */
	public function test_the_cause_follows_the_state(): void {
		$this->assertNull( Peer::cause() );

		Peer::observe( new WP_Error( 'http_request_failed', 'x' ) );
		$this->assertSame( Events::PEER_UNREACHABLE, Peer::cause() );

		Peer::observe( $this->http( 403, array() ) );
		$this->assertSame( Events::PEER_REJECTED, Peer::cause() );
	}

	/**
	 * PER-07 — Preguntarle si anda es un `GET /health` firmado, y anota la respuesta.
	 */
	public function test_probing_asks_health(): void {
		$this->reply = new WP_Error( 'http_request_failed', 'x' );

		Peer::probe();

		$this->assertSame( array( 'https://otra.test/wp-json/wss/v1/health' ), $this->urls );
		$this->assertSame( Peer::UNREACHABLE, Peer::current()['state'] );
	}

	/**
	 * PER-08 — **Todo lo que le habla a la otra anota el estado**: el envío, el catálogo, la
	 * reconciliación y la pregunta por un bootstrap incierto.
	 */
	public function test_every_request_observes_the_state(): void {
		$this->reply = new WP_Error( 'http_request_failed', 'x' );

		Sender::post( Peers::only(), array() );
		$this->assertSame( Peer::UNREACHABLE, Peer::current()['state'], 'el envío' );

		$this->reply = $this->http( 200, array( 'products' => array() ) );
		Catalog::fetch( 1, 10 );
		$this->assertSame( Peer::OK, Peer::current()['state'], 'el catálogo' );

		$this->reply = $this->http( 401, array() );
		Remote::ask( Peers::only(), array( 'step' => 'theirs' ) );
		$this->assertSame( Peer::REJECTED, Peer::current()['state'], 'la reconciliación' );

		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB
			Installer::table( 'movements' ),
			array(
				'origin_store'   => Store::id(),
				'origin_seq'     => 5,
				'generation'     => 5,
				'delta'          => 0,
				'reason'         => Ledger::BOOTSTRAP,
				'created_at'     => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
				'origin_product' => 10,
				'delivery'       => Ledger::UNCERTAIN,
			)
		);

		$this->reply = new WP_Error( 'http_request_failed', 'x' );
		Linker::resolve_uncertain();
		$this->assertSame( Peer::UNREACHABLE, Peer::current()['state'], 'la pregunta por un bootstrap' );
	}
}
