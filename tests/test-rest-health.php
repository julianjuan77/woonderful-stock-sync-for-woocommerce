<?php
/**
 * Tests de GET /wss/v1/health.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Plugin;
use Woonderful\StockSync\Rest\Health;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Peers;
use Woonderful\StockSync\Transport\Signature;

use const Woonderful\StockSync\VERSION;

/**
 * La tienda dice quién es, qué versión corre y si está suspendida (ADR-001 §4).
 */
class Test_Rest_Health extends WP_UnitTestCase {

	private const REMOTE_STORE = 'b1b1b1b1-0000-4000-8000-000000000000';

	private const SECRET = 'secreto-de-prueba';

	private const ROUTE = '/wss/v1/health';

	/**
	 * El servidor REST, con la ruta registrada.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	public function set_up(): void {
		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Installer::install();
		Store::ensure_identity();

		update_option( Store::OPTION_FINGERPRINT, Store::fingerprint() );
		update_option( Peers::OPTION, array( self::REMOTE_STORE => array( 'secret' => self::SECRET ) ), false );

		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride

		add_action( 'rest_api_init', array( Health::class, 'register_routes' ) );
		$this->server = rest_get_server();
	}

	public function tear_down(): void {
		global $wpdb, $wp_rest_server;

		remove_action( 'rest_api_init', array( Health::class, 'register_routes' ) );
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride

		foreach ( array( 'movements', 'links', 'nonces' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		delete_option( Peers::OPTION );
		delete_option( Store::OPTION_FINGERPRINT );
		delete_option( Installer::OPTION_VERSION );
		delete_option( Installer::OPTION_SCHEMA_HEALTHY );
		delete_option( Store::OPTION_ID );

		parent::tear_down();
	}

	/**
	 * Un GET firmado como lo firmaría la otra tienda.
	 *
	 * @param array<string, string> $headers Cabeceras a pisar después de firmar.
	 */
	private function request( array $headers = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', self::ROUTE );

		foreach ( array_merge( Signature::headers( self::REMOTE_STORE, self::SECRET, 'GET', self::ROUTE, array(), '' ), $headers ) as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return $request;
	}

	/**
	 * HLT-01 — Un GET firmado responde quién es, qué versión corre y que no está suspendida.
	 */
	public function test_a_signed_request_gets_the_store_state(): void {
		$response = $this->server->dispatch( $this->request() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'store'     => Store::id(),
				'label'     => Store::label(),
				'version'   => VERSION,
				'api'       => 'wss/v1',
				'suspended' => null,
			),
			$response->get_data()
		);
	}

	/**
	 * Un clon responde igual, y dice que está suspendido: es lo que deja a la otra tienda
	 * explicar por qué no le llegan movimientos.
	 *
	 * HLT-02
	 */
	public function test_a_suspended_site_says_why(): void {
		update_option( Store::OPTION_FINGERPRINT, hash( 'sha256', 'https://otra.test' ) );

		$response = $this->server->dispatch( $this->request() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Plugin::SUSPENDED_CLONE, $response->get_data()['suspended'] );
	}

	/**
	 * Con la tabla de nonces rota, `health` todavía responde que las tablas están mal. Si
	 * anotara el nonce, respondería `wss_replayed_nonce`.
	 *
	 * HLT-03
	 */
	public function test_it_answers_with_the_nonce_table_broken(): void {
		global $wpdb;

		$table = Installer::table( 'nonces' );
		$wpdb->query( "DROP TABLE {$table}" ); // phpcs:ignore WordPress.DB
		update_option( Installer::OPTION_SCHEMA_HEALTHY, 'no', true );

		$response = $this->server->dispatch( $this->request() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Plugin::SUSPENDED_SCHEMA, $response->get_data()['suspended'] );
	}

	/**
	 * HLT-04 — Reenviar el mismo `health` no se rechaza, y no deja nonces anotados: sólo lee.
	 */
	public function test_a_repeated_request_is_answered_and_leaves_no_nonce(): void {
		global $wpdb;

		$request = $this->request();

		$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );
		$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );

		$table = Installer::table( 'nonces' );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Sin la firma no dice nada: ni la versión ni si está suspendida.
	 *
	 * HLT-05
	 */
	public function test_it_requires_the_signature(): void {
		$bad = $this->server->dispatch( $this->request( array( 'X-WSS-Signature' => str_repeat( '0', 64 ) ) ) );

		$this->assertSame( 401, $bad->get_status() );
		$this->assertSame( 'wss_bad_signature', $bad->get_data()['code'] );

		$unknown = $this->server->dispatch( $this->request( array( 'X-WSS-Store' => 'c2c2c2c2-0000-4000-8000-000000000000' ) ) );

		$this->assertSame( 401, $unknown->get_status() );
		$this->assertSame( 'wss_unknown_store', $unknown->get_data()['code'] );
	}
}
