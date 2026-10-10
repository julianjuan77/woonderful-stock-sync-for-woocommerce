<?php
/**
 * Tests de la base de la interfaz.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Admin\Api;
use Woonderful\StockSync\Admin\Page;
use Woonderful\StockSync\Catalog\Copy;
use Woonderful\StockSync\Catalog\Rows;
use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Linking\Links;
use Woonderful\StockSync\Log\Events;
use Woonderful\StockSync\Log\Notifier;
use Woonderful\StockSync\Log\Peer;
use Woonderful\StockSync\Plugin;
use Woonderful\StockSync\Reconciliation\Audit;
use Woonderful\StockSync\Reconciliation\Lock;
use Woonderful\StockSync\Reconciliation\Result;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Pairing;
use Woonderful\StockSync\Transport\Peers;

/**
 * La página, el menú y `wss-admin/v1` (ADR-013).
 */
class Test_Admin extends WP_UnitTestCase {

	/**
	 * La otra tienda.
	 */
	private const REMOTE_STORE = 'b1b1b1b1-0000-4000-8000-000000000000';

	/**
	 * El servidor REST.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	public function set_up(): void {
		global $wpdb, $wp_rest_server;

		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Installer::install();
		Store::ensure_identity();
		delete_option( Peer::OPTION );
		delete_option( Peers::OPTION );
		delete_option( Result::OPTION );
		delete_option( Lock::OPTION );
		$wpdb->query( 'DELETE FROM ' . Installer::table( Installer::REMOTE_PRODUCTS ) ); // phpcs:ignore WordPress.DB
		// En el set_up: el DROP del tear_down confirma lo que escribió el test anterior, y un
		// borrado después del DROP lo deshace el rollback.
		delete_option( Notifier::OPTION_ENABLED );
		delete_option( Notifier::OPTION_EMAIL );
		delete_option( Notifier::OPTION_AFTER );
		delete_option( Api::OPTION_DELETE_DATA );
		delete_option( Pairing::OPTION_PAIRING );
		update_option( Store::OPTION_FINGERPRINT, Store::fingerprint() );
		wss_unhook_detector();

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
		delete_option( Result::OPTION );
		delete_option( Lock::OPTION );
		delete_option( Store::OPTION_FINGERPRINT );
		delete_option( Notifier::OPTION_STATE );
		remove_all_filters( 'pre_http_request' );
		wss_unhook_detector();
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

	/**
	 * Empareja esta tienda como maestra.
	 */
	private function as_master(): void {
		Peers::pair( self::REMOTE_STORE, 'https://otra.test/wp-json/wss/v1', 'secreto', 'Mayorista', Peers::SECONDARY );
	}

	/**
	 * Un producto vinculado, con su stock y con cuánto arrancó el par.
	 *
	 * @param int    $stock  El stock que tiene.
	 * @param int    $base   El stock base.
	 * @param int    $remote Su par en la otra tienda.
	 * @param string $name   El nombre.
	 *
	 * @return int El producto.
	 */
	private function linked( int $stock, int $base, int $remote, string $name = 'Alpargata' ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );
		$id = (int) $product->save();

		Links::create( $id, self::REMOTE_STORE, $remote, 1, $base );

		return $id;
	}

	/**
	 * Un pedido a `wss-admin/v1`.
	 *
	 * @param string $method El método.
	 * @param string $route  La ruta.
	 */
	private function call( string $method, string $route ): WP_REST_Response {
		return $this->server->dispatch( new WP_REST_Request( $method, '/wss-admin/v1' . $route ) );
	}

	/**
	 * ADM-07 — **Resumen trae la última reconciliación en castellano, las discrepancias de las
	 * dos tiendas y cuántos no sincronizan.** Las de la otra, con el producto de acá con que
	 * están vinculadas; las dos, con su SKU si tiene.
	 */
	public function test_the_status_has_everything_for_the_summary(): void {
		$this->as_user( 'administrator' );
		$this->as_master();

		$own = $this->linked( 47, 50, 900, 'Alpargata verde' );
		$sku = 'ADM07-' . uniqid();
		$product = wc_get_product( $own );
		$product->set_sku( $sku );
		$product->save();
		Audit::confirm( array( array( (int) Links::for_product( $own )->id, -3 ) ) );
		$mirror = $this->linked( 8, 8, 901, 'Ojota azul' );
		Result::save(
			Result::NIGHTLY,
			Result::DONE,
			array(
				'reopened'           => 2,
				'peer_confirmed'     => 5,
				'peer_discrepancies' => array(
					array(
						'product'  => 901,
						'expected' => 8,
						'found'    => 6,
						'since'    => '2026-10-06 03:00:00',
					),
				),
			)
		);

		$unlinked = new WC_Product_Simple();
		$unlinked->set_manage_stock( true );
		$unlinked->set_date_created( time() - 2 * DAY_IN_SECONDS );
		$unlinked->save();

		$data = $this->call( 'GET', '/status' )->get_data();

		$this->assertSame( 'Mayorista', $data['peer']['label'] );
		$this->assertNotSame( '', $data['peer']['paired_at'] );
		$this->assertSame( 'Reconciliación de la noche: completa.', $data['reconciliation']['text'] );
		$this->assertSame( 2, $data['reconciliation']['reopened'] );
		$this->assertSame( 5, $data['reconciliation']['peer_confirmed'] );
		$this->assertSame( array( $own ), array_column( $data['discrepancies']['own'], 'product' ) );
		$this->assertSame( 'Alpargata verde', $data['discrepancies']['own'][0]['name'] );
		$this->assertSame( 50, $data['discrepancies']['own'][0]['expected'] );
		$this->assertSame( $sku, $data['discrepancies']['own'][0]['sku'] );
		$this->assertNull( $data['discrepancies']['peer'][0]['sku'], 'sin SKU, null' );
		$this->assertSame( $mirror, $data['discrepancies']['peer'][0]['local'] );
		$this->assertSame( 'Ojota azul', $data['discrepancies']['peer'][0]['name'] );
		$this->assertSame( 6, $data['discrepancies']['peer'][0]['found'] );
		$this->assertSame( Rows::linking(), $data['linking'], 'los mismos números que los filtros de Productos' );
	}

	/**
	 * ADM-08 — Sin una reconciliación que haya preguntado, las de la otra no se saben: `null`,
	 * no una lista vacía, que diría que no hay.
	 */
	public function test_the_peer_discrepancies_are_unknown_until_asked(): void {
		$this->as_user( 'administrator' );
		$this->as_master();
		Result::save( Result::NIGHTLY, Result::UNREACHABLE, array( 'error' => 'timeout' ) );

		$data = $this->call( 'GET', '/status' )->get_data();

		$this->assertNull( $data['discrepancies']['peer'] );
		$this->assertSame( Result::UNREACHABLE, $data['reconciliation']['outcome'] );
	}

	/**
	 * ADM-09 — **La reconciliación manual se rechaza en el servidor fuera de la maestra**, y con
	 * el sitio suspendido.
	 */
	public function test_reconcile_is_only_for_the_master(): void {
		$this->as_user( 'administrator' );
		Peers::pair( self::REMOTE_STORE, 'https://otra.test/wp-json/wss/v1', 'secreto', 'Mayorista', Peers::MASTER );

		$response = $this->call( 'POST', '/reconcile' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wss_not_master', $response->get_data()['code'] );

		delete_option( Store::OPTION_FINGERPRINT );
		update_option( Store::OPTION_FINGERPRINT, hash( 'sha256', 'https://otra.test' ) );

		$this->assertSame( 'wss_suspended', $this->call( 'POST', '/reconcile' )->get_data()['code'] );
	}

	/**
	 * ADM-10 — Con otra en curso, lo dice y no guarda nada.
	 */
	public function test_reconcile_while_busy_says_so(): void {
		$this->as_user( 'administrator' );
		$this->as_master();
		Lock::acquire();

		$response = $this->call( 'POST', '/reconcile' );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wss_busy', $response->get_data()['code'] );
		$this->assertNull( Result::last() );
	}

	/**
	 * ADM-11 — **Si la otra no responde, no es un error del pedido: es "no se pudo mirar"**, y
	 * vuelve como resultado.
	 */
	public function test_reconcile_with_the_peer_down_is_a_result(): void {
		$this->as_user( 'administrator' );
		$this->as_master();
		add_filter( 'pre_http_request', static fn () => new WP_Error( 'http_request_failed', 'no responde' ) );

		$response = $this->call( 'POST', '/reconcile' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Result::UNREACHABLE, $response->get_data()['reconciliation']['outcome'] );
		$this->assertSame( Result::MANUAL, Result::last()['trigger'] );
	}

	/**
	 * ADM-12 — Resolver una discrepancia desde la pantalla hace lo mismo que WP-CLI: "aceptar"
	 * anota el ajuste, "restaurar" vuelve el stock al libro. Y devuelve el estado sin ella.
	 */
	public function test_a_discrepancy_is_resolved_from_the_screen(): void {
		$this->as_user( 'administrator' );
		$this->as_master();

		$accepted = $this->linked( 47, 50, 900 );
		$restored = $this->linked( 9, 10, 901 );
		Audit::confirm(
			array(
				array( (int) Links::for_product( $accepted )->id, -3 ),
				array( (int) Links::for_product( $restored )->id, -1 ),
			)
		);

		$response = $this->call( 'POST', "/discrepancies/{$accepted}/accept" );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $restored ), array_column( $response->get_data()['discrepancies']['own'], 'product' ) );
		$this->assertSame( 47, wc_get_product( $accepted )->get_stock_quantity() );

		$this->call( 'POST', "/discrepancies/{$restored}/restore" );

		$this->assertSame( 10, (int) wc_get_product( $restored )->get_stock_quantity() );
		$this->assertSame( array(), Audit::open() );
	}

	/**
	 * ADM-13 — Lo que no se puede resolver vuelve con su motivo, y una acción que no existe no
	 * llega a nada.
	 */
	public function test_a_discrepancy_that_is_not_there_is_refused(): void {
		$this->as_user( 'administrator' );
		$product = $this->linked( 5, 5, 900 );

		$response = $this->call( 'POST', "/discrepancies/{$product}/accept" );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wss_no_discrepancy', $response->get_data()['code'] );
		$this->assertSame( 404, $this->call( 'POST', "/discrepancies/{$product}/delete" )->get_status() );
	}

	/**
	 * ADM-14 — **Registros trae el log en castellano**: la frase, la hora del sitio, el nombre
	 * del producto, la causa y quién fue; paginado de a 50, filtrado por nivel y tipo.
	 */
	public function test_the_log_is_readable_and_filtered(): void {
		global $wpdb;

		update_option( 'timezone_string', 'America/Argentina/Buenos_Aires' );
		$this->as_user( 'administrator' );
		$product = $this->linked( 5, 5, 900, 'Alpargata verde' );

		Events::record( Events::LINKED, Events::INFO, $product, array( 'remote' => 900 ) );
		$wpdb->update( Installer::table( 'events' ), array( 'created_at' => '2026-10-07 00:09:06' ), array( 'id' => (int) $wpdb->insert_id ) ); // phpcs:ignore WordPress.DB
		Events::record( Events::LINK_FAILED, Events::WARNING, $product, array( 'reason' => 'stock_changed' ), Events::PEER_UNREACHABLE );

		$all = $this->call( 'GET', '/log' )->get_data();

		$this->assertSame( 2, $all['total'] );
		$this->assertSame( "No se pudo vincular el producto {$product}: el stock cambió desde que se miró.", $all['items'][0]['text'] );
		$this->assertSame( 'la otra tienda no respondía', $all['items'][0]['cause'] );
		$this->assertSame( 'Alpargata verde', $all['items'][1]['name'] );
		$this->assertSame( '2026-10-06 21:09:06', $all['items'][1]['at'] );
		$this->assertSame( wp_get_current_user()->display_name, $all['items'][1]['user'] );

		$request = new WP_REST_Request( 'GET', '/wss-admin/v1/log' );
		$request->set_param( 'level', Events::WARNING );

		$this->assertSame( array( Events::LINK_FAILED ), array_column( $this->server->dispatch( $request )->get_data()['items'], 'kind' ) );

		delete_option( 'timezone_string' );
	}

	/**
	 * ADM-15 — **Las fechas del filtro son días del sitio**: un evento de las 00:09 UTC es del día
	 * anterior en Buenos Aires, y cae en ese día, no en el siguiente.
	 */
	public function test_the_date_filter_uses_site_days(): void {
		global $wpdb;

		update_option( 'timezone_string', 'America/Argentina/Buenos_Aires' );
		$this->as_user( 'administrator' );

		Events::record( Events::RECONCILED, Events::INFO, null, array( 'trigger' => 'manual', 'outcome' => 'done' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$wpdb->update( Installer::table( 'events' ), array( 'created_at' => '2026-10-07 00:09:06' ), array( 'id' => (int) $wpdb->insert_id ) ); // phpcs:ignore WordPress.DB

		$day = static function ( string $from, string $to ): int {
			$request = new WP_REST_Request( 'GET', '/wss-admin/v1/log' );
			$request->set_param( 'from', $from );
			$request->set_param( 'to', $to );

			return rest_get_server()->dispatch( $request )->get_data()['total'];
		};

		$this->assertSame( 1, $day( '2026-10-06', '2026-10-06' ) );
		$this->assertSame( 0, $day( '2026-10-07', '2026-10-07' ) );

		delete_option( 'timezone_string' );
	}

	/**
	 * ADM-16 — Con `product`, el historial: sus movimientos y sus eventos juntos, cada uno con su
	 * frase, y el nombre del producto.
	 */
	public function test_the_history_of_a_product(): void {
		global $wpdb;

		$this->as_user( 'administrator' );
		$product = $this->linked( 5, 5, 900, 'Alpargata verde' );

		$wpdb->insert( Installer::table( 'movements' ), array( 'origin_store' => Store::id(), 'origin_seq' => 1, 'generation' => 1, 'delta' => -2, 'reason' => 'sale', 'reference' => 'pedido #9', 'created_at' => '2026-10-01 10:00:00', 'origin_product' => $product, 'delivery' => 'confirmed' ) ); // phpcs:ignore WordPress.DB, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		Events::record( Events::UNLINKED, Events::INFO, $product );

		$request = new WP_REST_Request( 'GET', '/wss-admin/v1/log' );
		$request->set_param( 'product', $product );
		$data = $this->server->dispatch( $request )->get_data();

		$this->assertSame( 'Alpargata verde', $data['name'] );
		$this->assertSame( array( 'event', 'movement' ), array_column( $data['items'], 'type' ) );
		$this->assertSame( 'Acá: venta (pedido #9), -2', $data['items'][1]['text'] );
	}

	/**
	 * ADM-34 — **Registros busca por producto**: por parte del nombre, por SKU o por ID; lo que
	 * no encuentra productos no trae nada.
	 */
	public function test_the_log_searches_by_product(): void {
		$this->as_user( 'administrator' );
		$token = uniqid( 'adm34' );
		$green = $this->linked( 5, 5, 900, "Alpargata verde {$token}" );
		$blue  = $this->linked( 5, 5, 901, 'Ojota azul' );
		$found = wc_get_product( $blue );
		$found->set_sku( "SKU-{$token}" );
		$found->save();

		Events::record( Events::LINKED, Events::INFO, $green );
		Events::record( Events::LINKED, Events::INFO, $blue );
		Events::record( Events::RECONCILED, Events::INFO, null, array( 'trigger' => 'manual', 'outcome' => 'done' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$products = fn ( string $search ) => array_column( $this->send( 'GET', '/log', array( 'search' => $search ) )->get_data()['items'], 'product' );

		$this->assertSame( array( $green ), $products( "verde {$token}" ) );
		$this->assertSame( array( $blue ), $products( "sku-{$token}" ) );
		$this->assertSame( array( $green ), $products( (string) $green ) );
		$this->assertSame( array(), $products( 'no-existe-' . $token ) );
		$this->assertSame( 3, $this->call( 'GET', '/log' )->get_data()['levels'][ Events::INFO ] );
	}

	/**
	 * Un pedido a `wss-admin/v1` con parámetros.
	 *
	 * @param string               $method El método.
	 * @param string               $route  La ruta.
	 * @param array<string, mixed> $params Los parámetros.
	 */
	private function send( string $method, string $route, array $params ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/wss-admin/v1' . $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $this->server->dispatch( $request );
	}

	/**
	 * ADM-17 — Los ajustes: los avisos y la casilla de borrado, por defecto y cambiados. Lo que no
	 * viene no se toca.
	 */
	public function test_the_settings_are_read_and_changed(): void {
		$this->as_user( 'administrator' );

		$defaults = $this->call( 'GET', '/settings' )->get_data();

		$this->assertTrue( $defaults['notify_enabled'] );
		$this->assertSame( '', $defaults['notify_email'] );
		$this->assertSame( get_option( 'admin_email' ), $defaults['admin_email'] );
		$this->assertSame( 24, $defaults['notify_after'] );
		$this->assertFalse( $defaults['delete_data'] );

		$changed = $this->send(
			'POST',
			'/settings',
			array(
				'notify_enabled' => false,
				'notify_email'   => 'deposito@example.org',
				'delete_data'    => true,
			)
		)->get_data();

		$this->assertFalse( $changed['notify_enabled'] );
		$this->assertSame( 'deposito@example.org', $changed['notify_email'] );
		$this->assertSame( 24, $changed['notify_after'] );
		$this->assertTrue( $changed['delete_data'] );
		$this->assertTrue( (bool) get_option( 'wss_delete_data_on_uninstall' ), 'la que lee uninstall.php' );
	}

	/**
	 * ADM-18 — **Con un dato malo no se guarda ninguno**: ni el email, ni lo que venía bien al
	 * lado.
	 */
	public function test_a_bad_setting_saves_nothing(): void {
		$this->as_user( 'administrator' );

		$response = $this->send(
			'POST',
			'/settings',
			array(
				'notify_enabled' => false,
				'notify_email'   => 'no-es-un-email',
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wss_invalid_email', $response->get_data()['code'] );
		$this->assertTrue( Notifier::enabled() );
		$this->assertSame( 'wss_invalid_after', $this->send( 'POST', '/settings', array( 'notify_after' => '0' ) )->get_data()['code'] );
		$this->assertSame( 24, Notifier::after_hours() );
	}

	/**
	 * ADM-19 — El nombre de la tienda se cambia, y vacío no.
	 */
	public function test_the_store_label_is_changed(): void {
		$this->as_user( 'administrator' );

		$this->assertSame( 'Minorista', $this->send( 'POST', '/store', array( 'label' => ' Minorista ' ) )->get_data()['store']['label'] );
		$this->assertSame( 400, $this->send( 'POST', '/store', array( 'label' => '  ' ) )->get_status() );
		$this->assertSame( 'Minorista', Store::label() );
	}

	/**
	 * ADM-20 — Generar el código desde la pantalla es lo mismo que `wp wss pairing-code`: lleva
	 * esta tienda y queda pendiente de canje.
	 */
	public function test_a_pairing_code_is_created(): void {
		$this->as_user( 'administrator' );
		// Fuera de un entorno local, el código pide https.
		add_filter( 'option_home', static fn () => 'https://example.org' );

		$data = $this->call( 'POST', '/pairing/code' )->get_data();

		$this->assertSame( Store::id(), Pairing::decode( $data['code'] )['id'] );
		$this->assertSame( Pairing::decode( $data['code'] )['c'], Pairing::pending_code() );
		$this->assertSame( 15, $data['minutes'] );

		remove_all_filters( 'option_home' );
	}

	/**
	 * ADM-21 — Canjear algo que no es un código vuelve con su motivo, sin emparejar.
	 */
	public function test_a_bad_code_is_refused(): void {
		$this->as_user( 'administrator' );

		$response = $this->send( 'POST', '/pairing/redeem', array( 'code' => 'cualquier-cosa' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wss_pairing_invalid', $response->get_data()['code'] );
		$this->assertNull( Peers::only() );
	}

	/**
	 * ADM-22 — El refresco de la copia, desde la pantalla: una página con su avance; sin
	 * emparejar, `409`; con la otra caída, `502`.
	 */
	public function test_the_catalog_is_refreshed_page_by_page(): void {
		$this->as_user( 'administrator' );

		$this->assertSame( 409, $this->send( 'POST', '/catalog/refresh', array( 'page' => 1 ) )->get_status() );

		$this->as_master();
		add_filter(
			'pre_http_request',
			static fn () => array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) wp_json_encode(
					array(
						'products' => array(),
						'total'    => 0,
					)
				),
			)
		);

		$this->assertSame( array( 'page' => 1, 'pages' => 1, 'total' => 0, 'done' => true ), $this->send( 'POST', '/catalog/refresh', array( 'page' => 1 ) )->get_data() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		remove_all_filters( 'pre_http_request' );
		add_filter( 'pre_http_request', static fn () => new WP_Error( 'http_request_failed', 'no responde' ) );

		$this->assertSame( 502, $this->send( 'POST', '/catalog/refresh', array( 'page' => 1 ) )->get_status() );
	}

	/**
	 * ADM-23 — Productos: las filas de `Rows`, con un estado que no existe tomado como "todos",
	 * y la edad de la copia para que la app la refresque si está vieja.
	 */
	public function test_the_products_come_with_the_age_of_the_copy(): void {
		$this->as_user( 'administrator' );
		$this->as_master();
		$product = $this->linked( 5, 5, 900, 'Alpargata ' . __FUNCTION__ );
		delete_option( Copy::OPTION_REFRESHED );

		$data = $this->send(
			'GET',
			'/products',
			array(
				'state'  => 'cualquiera',
				'search' => __FUNCTION__,
			)
		)->get_data();

		$this->assertSame( array( $product ), array_column( $data['rows'], 'id' ) );
		$this->assertNull( $data['catalog']['age'] );
		$this->assertTrue( $data['catalog']['stale'] );
	}

	/**
	 * Uno de allá, en la copia.
	 *
	 * @param int                  $id      Su ID.
	 * @param array<string, mixed> $columns Lo que cambia.
	 */
	private function in_copy( int $id, array $columns = array() ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB
			Installer::table( Installer::REMOTE_PRODUCTS ),
			array_merge(
				array(
					'remote_product' => $id,
					'type'           => 'simple',
					'sku'            => 'ALP-' . $id,
					'name'           => 'Alpargata ' . $id,
					'stock'          => 4,
					'status'         => 'publish',
					'seen_at'        => gmdate( 'Y-m-d H:i:s' ),
				),
				$columns
			)
		);
	}

	/**
	 * La otra contesta: el catálogo con `include`, y el bootstrap como recibido.
	 *
	 * @param int $stock El stock de su producto.
	 */
	private function other_store( int $stock ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $stock ) {
				if ( str_contains( $url, '/products' ) ) {
					parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
					$ids = array_map( 'intval', explode( ',', (string) ( $query['include'] ?? '' ) ) );

					$body = array(
						'products' => array_map(
							static fn ( int $id ) => array(
								'id'     => $id,
								'sku'    => 'ALP-' . $id,
								'name'   => 'Alpargata ' . $id,
								'stock'  => $stock,
								'status' => 'publish',
								'linked' => false,
							),
							array_filter( $ids, static fn ( int $id ) => 404 !== $id )
						),
						'total'    => 1,
					);
				} else {
					$movement = json_decode( (string) $args['body'], true )['movements'][0];
					$body     = array(
						'results' => array(
							array(
								'origin' => $movement['origin'],
								'seq'    => $movement['seq'],
								'status' => 'received',
							),
						),
					);
				}

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => (string) wp_json_encode( $body ),
				);
			},
			10,
			3
		);
	}

	/**
	 * ADM-24 — **Buscar el de allá para vincular a mano**: en la copia, por nombre o SKU, los
	 * vinculables —ni en la papelera, ni sin su stock, ni un padre variable—. Primero los
	 * libres; después los que ya tienen par, marcados y con el producto de acá: sin ellos, una
	 * búsqueda que sólo coincidía con vinculados volvía vacía.
	 */
	public function test_the_copy_is_searched_for_linkables(): void {
		$this->as_user( 'administrator' );
		$this->as_master();
		$local = $this->linked( 5, 5, 906, 'Alpargata de acá' );
		$this->in_copy( 906 );
		$this->in_copy( 900 );
		$this->in_copy( 901, array( 'linked' => 1 ) );
		$this->in_copy( 902, array( 'status' => 'trash' ) );
		$this->in_copy( 903, array( 'stock' => null ) );
		$this->in_copy( 905, array( 'type' => 'variable' ) );
		$this->in_copy(
			904,
			array(
				'sku'  => 'OTRO',
				'name' => 'Ojota',
			)
		);

		$items = $this->send( 'GET', '/catalog/search', array( 'q' => 'alpargata' ) )->get_data()['items'];

		$this->assertSame( array( 900, 901, 906 ), array_column( $items, 'id' ) );
		$this->assertSame( array( false, true, true ), array_column( $items, 'linked' ) );
		$this->assertNull( $items[1]['linked_to'], 'vinculado allá con algo que acá no se sabe' );
		$this->assertSame(
			array(
				'id'   => $local,
				'name' => 'Alpargata de acá',
			),
			$items[2]['linked_to']
		);
		$this->assertSame( array( 904 ), array_column( $this->send( 'GET', '/catalog/search', array( 'q' => 'otro' ) )->get_data()['items'], 'id' ) );
	}

	/**
	 * ADM-25 — **Antes de confirmar, el de allá en el momento**, el de acá de la base, y cuál
	 * queda: el de la maestra. Uno que ya no existe, `404`.
	 */
	public function test_the_other_product_is_read_fresh_before_confirming(): void {
		$this->as_user( 'administrator' );
		$this->as_master();
		$this->other_store( 7 );

		$product = new WC_Product_Simple();
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 9 );
		$local = (int) $product->save();

		$data = $this->send( 'GET', '/catalog/fresh/900', array( 'local' => $local ) )->get_data();

		$this->assertSame( 7, $data['remote']['stock'] );
		$this->assertSame( 9, $data['local']['stock'] );
		$this->assertSame( 9, $data['result'] );
		$this->assertSame( 404, $this->call( 'GET', '/catalog/fresh/404' )->get_status() );
	}

	/**
	 * ADM-26 — **Vincular desde la pantalla devuelve la fila como quedó**; con un stock que
	 * cambió, `409 stock_changed`, y no vincula.
	 */
	public function test_linking_returns_the_row_as_it_is(): void {
		$this->as_user( 'administrator' );
		$this->as_master();
		$this->other_store( 7 );
		$this->in_copy( 900 );

		$product = new WC_Product_Simple();
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 9 );
		$local = (int) $product->save();

		$changed = $this->send(
			'POST',
			'/link',
			array(
				'local'        => $local,
				'remote'       => 900,
				'stock'        => 8,
				'remote_stock' => 7,
			)
		);

		$this->assertSame( 409, $changed->get_status() );
		$this->assertSame( 'stock_changed', $changed->get_data()['code'] );

		$data = $this->send(
			'POST',
			'/link',
			array(
				'local'        => $local,
				'remote'       => 900,
				'stock'        => 9,
				'remote_stock' => 7,
			)
		)->get_data();

		$this->assertSame( 'confirmed', $data['result'] );
		$this->assertSame( 'linked', $data['row']['state'] );
		$this->assertSame( 900, $data['row']['remote']['id'] );
	}

	/**
	 * ADM-27 — **Desde la secundaria, vincular, desvincular, reasignar y descartar se rechazan en
	 * el servidor**, con `403`.
	 */
	public function test_the_secondary_cannot_change_links(): void {
		$this->as_user( 'administrator' );
		Peers::pair( self::REMOTE_STORE, 'https://otra.test/wp-json/wss/v1', 'secreto', 'Mayorista', Peers::MASTER );
		$local = $this->linked( 5, 5, 900 );

		foreach ( array( '/link', '/unlink', '/reassign', '/discard', '/undiscard' ) as $route ) {
			$response = $this->send(
				'POST',
				$route,
				array(
					'local'        => $local,
					'remote'       => 901,
					'stock'        => 5,
					'remote_stock' => 5,
				)
			);

			$this->assertSame( 403, $response->get_status(), $route );
			$this->assertSame( 'wss_not_master', $response->get_data()['code'], $route );
		}
	}

	/**
	 * ADM-28 — Descartar devuelve la fila descartada; desvincular, sin vincular.
	 */
	public function test_discard_and_unlink_return_the_row(): void {
		$this->as_user( 'administrator' );
		$this->as_master();
		$linked = $this->linked( 5, 5, 900 );

		$product = new WC_Product_Simple();
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 3 );
		$loose = (int) $product->save();

		$this->assertSame( 'discarded', $this->send( 'POST', '/discard', array( 'local' => $loose ) )->get_data()['row']['motive'] );
		$this->assertSame( 'unlinked', $this->send( 'POST', '/unlink', array( 'local' => $linked ) )->get_data()['row']['state'] );
	}

	/**
	 * ADM-29 — La masiva desde la pantalla: lo que va a vincular; desde la secundaria, `403`.
	 */
	public function test_the_bulk_link_is_only_for_the_master(): void {
		$this->as_user( 'administrator' );
		$this->as_master();

		$this->assertArrayHasKey( 'pairs', $this->call( 'GET', '/link/bulk' )->get_data() );

		Peers::pair( self::REMOTE_STORE, 'https://otra.test/wp-json/wss/v1', 'secreto', 'Mayorista', Peers::MASTER );

		$this->assertSame( 403, $this->call( 'GET', '/link/bulk' )->get_status() );
		$this->assertSame( 403, $this->send( 'POST', '/link/bulk', array( 'pairs' => array() ) )->get_status() );
	}

	/**
	 * ADM-30 — **La otra, preguntada en el momento: su nombre y el enlace a su pantalla.** Un
	 * enlace que no es http ni https no llega a la pantalla.
	 */
	public function test_the_peer_is_asked_for_its_screen(): void {
		$this->as_user( 'administrator' );
		Peers::pair( self::REMOTE_STORE, 'https://otra.test/wp-json/wss/v1', 'secreto', 'Mayorista', Peers::MASTER );

		$url = 'https://otra.test/wp-admin/admin.php?page=woonderful-stock-sync';
		add_filter(
			'pre_http_request',
			static function () use ( &$url ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => (string) wp_json_encode(
						array(
							'label'     => 'Mayorista',
							'admin_url' => $url,
						)
					),
				);
			}
		);

		$data = $this->call( 'GET', '/peer' )->get_data();

		$this->assertSame( 'Mayorista', $data['label'] );
		$this->assertSame( $url, $data['admin_url'] );

		$url = 'javascript:alert(1)';

		$this->assertNull( $this->call( 'GET', '/peer' )->get_data()['admin_url'] );
	}

	/**
	 * ADM-31 — **El email de prueba sale al destinatario de los avisos**, aunque estén apagados.
	 */
	public function test_a_test_email_is_sent(): void {
		$this->as_user( 'administrator' );
		Notifier::configure(
			array(
				'enabled' => false,
				'email'   => 'deposito@example.org',
			)
		);
		reset_phpmailer_instance();

		$data = $this->call( 'POST', '/test-email' )->get_data();

		$this->assertTrue( $data['sent'] );
		$this->assertSame( 'deposito@example.org', $data['to'] );
		$this->assertSame( 'deposito@example.org', tests_retrieve_phpmailer_instance()->get_recipient( 'to' )->address );
	}

	/**
	 * ADM-32 — Los requisitos, desde la pantalla.
	 */
	public function test_the_requirements_are_listed(): void {
		$this->as_user( 'administrator' );
		add_filter( 'pre_http_request', static fn () => new WP_Error( 'http_request_failed', 'x' ) );

		$this->assertCount( 8, $this->call( 'GET', '/requirements' )->get_data()['items'] );
	}

	/**
	 * ADM-33 — **Las fuentes de la marca salen del plugin**, no de otro servidor, y cada archivo
	 * que nombra existe (ADR-013 §2.1).
	 */
	public function test_the_brand_fonts_are_served_by_the_plugin(): void {
		$css = Page::fonts_css();

		preg_match_all( '/url\("([^"]+)"\)/', $css, $urls );

		$this->assertCount( 4, $urls[1] );

		foreach ( $urls[1] as $url ) {
			$this->assertStringStartsWith( plugins_url( 'assets/fonts/', WSS_PLUGIN_FILE ), $url );
			$this->assertFileExists( WSS_PLUGIN_DIR . 'assets/fonts/' . basename( $url ) );
		}

		$this->assertStringNotContainsString( 'googleapis', $css );
	}
}
