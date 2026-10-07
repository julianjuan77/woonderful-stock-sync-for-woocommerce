<?php
/**
 * Tests de la base de la interfaz.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Admin\Api;
use Woonderful\StockSync\Admin\Page;
use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Log\Peer;
use Woonderful\StockSync\Plugin;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Peers;

/**
 * La página, el menú y `wss-admin/v1` (ADR-013).
 */
class Test_Admin extends WP_UnitTestCase {

	/**
	 * El servidor REST.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	public function set_up(): void {
		global $wp_rest_server;

		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Installer::install();
		Store::ensure_identity();
		delete_option( Peer::OPTION );
		delete_option( Peers::OPTION );

		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		add_action( 'rest_api_init', array( Api::class, 'register_routes' ) );
		$this->server = rest_get_server();
	}

	public function tear_down(): void {
		global $wpdb, $wp_rest_server;

		remove_action( 'rest_api_init', array( Api::class, 'register_routes' ) );
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		wp_set_current_user( 0 );

		// Las filas del log, no la tabla: el DROP de las otras confirma el borrado.
		$wpdb->query( 'DELETE FROM ' . Installer::table( 'events' ) ); // phpcs:ignore WordPress.DB

		foreach ( array( 'movements', 'links', 'nonces' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		delete_option( Peers::OPTION );
		delete_option( Peer::OPTION );
		delete_option( Installer::OPTION_VERSION );
		delete_option( Store::OPTION_ID );

		parent::tear_down();
	}

	/**
	 * Un usuario con ese rol.
	 *
	 * @param string $role El rol.
	 */
	private function as_user( string $role ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	/**
	 * ADM-01 — **Sin `manage_woocommerce`, `wss-admin/v1` no contesta**; un suscriptor tampoco.
	 */
	public function test_the_admin_api_needs_manage_woocommerce(): void {
		$this->assertSame( 401, $this->server->dispatch( new WP_REST_Request( 'GET', '/wss-admin/v1/status' ) )->get_status() );

		$this->as_user( 'subscriber' );

		$this->assertSame( 403, $this->server->dispatch( new WP_REST_Request( 'GET', '/wss-admin/v1/status' ) )->get_status() );
	}

	/**
	 * ADM-02 — Un gestor de la tienda ve el estado: esta tienda, si está emparejada y cómo está
	 * la otra, con desde cuándo si no anda.
	 */
	public function test_a_shop_manager_sees_the_status(): void {
		$this->as_user( 'shop_manager' );
		Peers::pair( 'b1b1b1b1-0000-4000-8000-000000000000', 'https://otra.test/wp-json/wss/v1', 'secreto', 'Mayorista', Peers::SECONDARY );
		Peer::observe( new WP_Error( 'http_request_failed', 'no responde' ) );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wss-admin/v1/status' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['paired'] );
		$this->assertTrue( $data['store']['master'] );
		$this->assertSame( Peer::UNREACHABLE, $data['peer']['state'] );
		$this->assertNotNull( $data['peer']['since'] );
	}

	/**
	 * ADM-03 — Sin emparejar, el estado lo dice: la app abre la configuración inicial.
	 */
	public function test_an_unpaired_store_says_so(): void {
		$this->as_user( 'administrator' );

		$data = $this->server->dispatch( new WP_REST_Request( 'GET', '/wss-admin/v1/status' ) )->get_data();

		$this->assertFalse( $data['paired'] );
		$this->assertSame( Peer::OK, $data['peer']['state'] );
		$this->assertNull( $data['peer']['since'] );
	}

	/**
	 * ADM-04 — El menú pide `manage_woocommerce`, y tiene una entrada por pantalla.
	 */
	public function test_the_menu_needs_manage_woocommerce(): void {
		global $menu, $submenu;

		$this->as_user( 'administrator' );
		set_current_screen( 'dashboard' );

		Page::menu();

		$entry = array_values( array_filter( (array) $menu, static fn ( $item ) => Page::SLUG === ( $item[2] ?? null ) ) );

		$this->assertCount( 1, $entry );
		$this->assertSame( Page::CAPABILITY, $entry[0][1] );
		$this->assertCount( 4, $submenu[ Page::SLUG ] );
	}

	/**
	 * ADM-05 — La app se encola en sus páginas, con el nonce; en las demás del admin, no.
	 */
	public function test_the_app_is_enqueued_only_on_its_pages(): void {
		Page::enqueue( 'edit.php' );
		$this->assertFalse( wp_script_is( 'wss-admin', 'enqueued' ) );

		Page::enqueue( 'toplevel_page_' . Page::SLUG );
		$this->assertTrue( wp_script_is( 'wss-admin', 'enqueued' ) );
		$this->assertStringContainsString( 'wssAdmin', (string) wp_scripts()->get_data( 'wss-admin', 'data' ) );

		wp_dequeue_script( 'wss-admin' );
		wp_dequeue_style( 'wss-admin' );
	}

	/**
	 * ADM-06 — **Un sitio suspendido engancha igual la interfaz**: tiene que poder mostrar por qué
	 * y re-emparejarse desde la pantalla.
	 */
	public function test_a_suspended_site_still_hooks_the_interface(): void {
		update_option( Store::OPTION_FINGERPRINT, hash( 'sha256', 'https://otra.test' ) );
		remove_action( 'rest_api_init', array( Api::class, 'register_routes' ) );

		Plugin::boot();

		$this->assertNotFalse( has_action( 'rest_api_init', array( Api::class, 'register_routes' ) ) );

		delete_option( Store::OPTION_FINGERPRINT );
	}
}
