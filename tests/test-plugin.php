<?php
/**
 * Tests de la guarda que suspende la sincronización.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Detector\Detector;
use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Plugin;
use Woonderful\StockSync\Rest\Auth;
use Woonderful\StockSync\Rest\Health;
use Woonderful\StockSync\Rest\Movements;
use Woonderful\StockSync\Rest\Pair;
use Woonderful\StockSync\Rest\Reconcile;
use Woonderful\StockSync\Reconciliation\Audit;
use Woonderful\StockSync\Reconciliation\Schedule;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Pass;

/**
 * Un clon o una tabla sin transacciones apagan la sincronización (ADR-001 §1, ADR-004 §5).
 */
class Test_Plugin extends WP_UnitTestCase {

	/**
	 * La URL del sitio, que algún test cambia para simular un restore.
	 *
	 * @var string
	 */
	private string $original_home = '';

	public function set_up(): void {
		parent::set_up();

		// Estos tests miran el motor de las tablas, y una tabla temporal no figura en
		// information_schema (ver tests/TEST-CASES.md).
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->original_home = (string) get_option( 'home' );

		Installer::install();
	}

	public function tear_down(): void {
		global $wpdb;

		update_option( 'home', $this->original_home );
		delete_option( Store::OPTION_FINGERPRINT );

		foreach ( array( 'movements', 'links', 'nonces' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		delete_option( Installer::OPTION_VERSION );
		delete_option( Installer::OPTION_SCHEMA_HEALTHY );

		wss_unhook_detector();
		remove_action( 'rest_api_init', array( Movements::class, 'register_routes' ) );
		remove_action( Pass::HOOK, array( Pass::class, 'run' ) );
		remove_action( 'action_scheduler_init', array( Pass::class, 'maybe_ensure_scheduled' ) );
		remove_action( 'rest_api_init', array( Health::class, 'register_routes' ) );
		remove_filter( 'rest_post_dispatch', array( Auth::class, 'no_cache' ), 10 );
		remove_action( 'rest_api_init', array( Pair::class, 'register_routes' ) );
		remove_action( 'rest_api_init', array( Reconcile::class, 'register_routes' ) );

		parent::tear_down();
	}

	/**
	 * PLG-01 — Con las tablas sanas y el sitio en su lugar, nada suspende la sincronización.
	 */
	public function test_nothing_suspends_a_healthy_site(): void {
		update_option( Store::OPTION_FINGERPRINT, Store::fingerprint() );

		$this->assertNull( Plugin::suspension_reason() );
	}

	/**
	 * PLG-02 — Una base restaurada en otra URL suspende la sincronización.
	 */
	public function test_a_site_restored_on_another_url_is_suspended(): void {
		update_option( Store::OPTION_FINGERPRINT, Store::fingerprint() );

		update_option( 'home', 'https://tienda-restaurada.test' );

		$this->assertSame( Plugin::SUSPENDED_CLONE, Plugin::suspension_reason() );
	}

	/**
	 * PLG-03 — Una tabla sin transacciones suspende la sincronización.
	 */
	public function test_a_table_without_transactions_suspends_sync(): void {
		global $wpdb;

		update_option( Store::OPTION_FINGERPRINT, Store::fingerprint() );

		$table = Installer::table( 'movements' );
		$wpdb->query( "ALTER TABLE {$table} ENGINE=MyISAM" ); // phpcs:ignore WordPress.DB
		Installer::refresh_schema_status();

		$this->assertSame( Plugin::SUSPENDED_SCHEMA, Plugin::suspension_reason() );
	}

	/**
	 * Con las dos causas a la vez, al dueño hay que decirle que está mirando una copia:
	 * arreglar el motor de una copia no sirve de nada.
	 *
	 * PLG-04
	 */
	public function test_the_clone_wins_when_both_causes_apply(): void {
		global $wpdb;

		update_option( Store::OPTION_FINGERPRINT, Store::fingerprint() );
		update_option( 'home', 'https://tienda-restaurada.test' );

		$table = Installer::table( 'movements' );
		$wpdb->query( "ALTER TABLE {$table} ENGINE=MyISAM" ); // phpcs:ignore WordPress.DB
		Installer::refresh_schema_status();

		$this->assertSame( Plugin::SUSPENDED_CLONE, Plugin::suspension_reason() );
	}

	/**
	 * El control del motor es caro —consulta information_schema— y el motor de una tabla
	 * no cambia solo, así que la guarda lee el resultado guardado y no la base (ADR-004 §5).
	 *
	 * PLG-09
	 */
	public function test_the_guard_does_not_query_the_database(): void {
		global $wpdb;

		// La huella se autocarga porque la guarda la lee en cada request; el
		// emparejamiento tiene que escribirla así (ADR-001 §1).
		update_option( Store::OPTION_FINGERPRINT, Store::fingerprint(), true );
		Installer::refresh_schema_status();

		// Sin esto el test mide su propia memoria: las dos opciones quedaron cacheadas
		// al escribirlas, y la lectura posterior no consultaría aunque no se autocargaran.
		wp_cache_delete( Store::OPTION_FINGERPRINT, 'options' );
		wp_cache_delete( Installer::OPTION_SCHEMA_HEALTHY, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_load_alloptions();

		$queries_before = $wpdb->num_queries;

		Plugin::suspension_reason();

		$this->assertSame( $queries_before, $wpdb->num_queries );
	}

	/**
	 * PLG-05 — Un sitio suspendido avisa en el admin.
	 */
	public function test_a_suspended_site_shows_an_admin_notice(): void {
		update_option( Store::OPTION_FINGERPRINT, hash( 'sha256', 'https://otra.test' ) );

		ob_start();
		Plugin::notice_suspended();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'copia', $output );
	}

	/**
	 * Los tests llaman a `boot()` a mano, así que ninguno se entera si el archivo
	 * principal deja de engancharlo. Sin estos dos `add_action`, el plugin no arranca y
	 * la suite sigue entera en verde.
	 *
	 * PLG-14
	 */
	public function test_the_main_file_hooks_the_plugin_up(): void {
		$this->assertNotFalse(
			has_action( 'plugins_loaded', array( Plugin::class, 'boot' ) ),
			'plugins_loaded tiene que llamar a boot()'
		);
		$this->assertNotFalse(
			has_action( 'activate_' . plugin_basename( WSS_PLUGIN_FILE ), array( Plugin::class, 'activate' ) ),
			'la activación tiene que llamar a activate()'
		);
	}

	/**
	 * PLG-15 — Un sitio suspendido engancha el aviso, no sólo sabe que lo está.
	 */
	public function test_boot_hooks_the_suspension_notice(): void {
		update_option( Store::OPTION_FINGERPRINT, hash( 'sha256', 'https://otra.test' ) );

		remove_action( 'admin_notices', array( Plugin::class, 'notice_suspended' ) );
		$this->assertFalse( has_action( 'admin_notices', array( Plugin::class, 'notice_suspended' ) ) );

		Plugin::boot();

		$this->assertNotFalse( has_action( 'admin_notices', array( Plugin::class, 'notice_suspended' ) ) );
	}

	/**
	 * PLG-10 — Sin WooCommerce, el plugin avisa en el admin.
	 */
	public function test_it_warns_when_woocommerce_is_missing(): void {
		ob_start();
		Plugin::notice_woocommerce_missing();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'WooCommerce', $output );
	}

	/**
	 * Con WooCommerce presente, boot() pasa de largo el aviso y llega a migrar el
	 * esquema. Sin el stub del bootstrap, esta mitad de boot() no la alcanza nadie.
	 *
	 * PLG-11
	 */
	public function test_boot_migrates_the_schema(): void {
		global $wpdb;

		foreach ( array( 'movements', 'links', 'nonces' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		update_option( Installer::OPTION_VERSION, 0, false );

		Plugin::boot();

		$this->assertSame( Installer::SCHEMA_VERSION, (int) get_option( Installer::OPTION_VERSION ) );

		// Y que las tablas hayan vuelto: install() escribe la versión aunque dbDelta no
		// haya creado nada, así que la opción sola no prueba que migró.
		foreach ( array( 'movements', 'links', 'nonces' ) as $name ) {
			$table = Installer::table( $name );

			$this->assertSame(
				$table,
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), // phpcs:ignore WordPress.DB
				$name
			);
		}
	}

	/**
	 * ADR-002 fija que la identidad se genera **antes que nada**: es lo que numera los
	 * movimientos, así que tiene que existir antes que la tabla que los guarda.
	 *
	 * Verificar un orden no se puede con el estado final —las dos cosas terminan
	 * existiendo igual— así que se mira el momento: cuando sale el primer CREATE TABLE,
	 * ¿ya había identidad?
	 *
	 * PLG-12
	 */
	public function test_the_identity_exists_before_the_first_table(): void {
		global $wpdb;

		$identity_when_creating = null;

		$watch = static function ( string $query ) use ( &$identity_when_creating ): string {
			if ( null === $identity_when_creating && str_contains( $query, 'CREATE TABLE' ) ) {
				$identity_when_creating = Store::id();
			}

			return $query;
		};

		delete_option( Store::OPTION_ID );

		foreach ( array( 'movements', 'links', 'nonces' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		add_filter( 'query', $watch );
		Plugin::activate();
		remove_filter( 'query', $watch );

		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			(string) $identity_when_creating
		);
	}

	/**
	 * PLG-13 — Con las tablas rotas, el aviso habla de las tablas y no del clon.
	 */
	public function test_a_broken_schema_shows_its_own_notice(): void {
		global $wpdb;

		update_option( Store::OPTION_FINGERPRINT, Store::fingerprint() );

		$table = Installer::table( 'movements' );
		$wpdb->query( "ALTER TABLE {$table} ENGINE=MyISAM" ); // phpcs:ignore WordPress.DB
		Installer::refresh_schema_status();

		ob_start();
		Plugin::notice_suspended();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'tablas', $output );
		$this->assertStringNotContainsString( 'copia', $output );
	}

	/**
	 * PLG-06 — Un sitio sano no avisa nada.
	 */
	public function test_a_healthy_site_shows_no_notice(): void {
		update_option( Store::OPTION_FINGERPRINT, Store::fingerprint() );

		ob_start();
		Plugin::notice_suspended();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * ADR-002 fija que la identidad se genera antes que nada: es lo que numera los
	 * movimientos, así que tiene que existir antes que la tabla que los guarda.
	 *
	 * PLG-07
	 */
	public function test_activate_leaves_identity_and_schema_in_place(): void {
		global $wpdb;

		delete_option( Store::OPTION_ID );

		foreach ( array( 'movements', 'links', 'nonces' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		Plugin::activate();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', Store::id() );
		$this->assertSame( Installer::SCHEMA_VERSION, (int) get_option( Installer::OPTION_VERSION ) );
	}

	/**
	 * El plugin puede llegar sin pasar por activate(): copiado por FTP, o con la opción
	 * borrada a mano. Sin identidad, el libro numeraría movimientos de nadie.
	 *
	 * PLG-08
	 */
	public function test_boot_generates_a_missing_identity(): void {
		delete_option( Store::OPTION_ID );

		Plugin::boot();

		$this->assertNotSame( '', Store::id() );
	}

	/**
	 * PLG-16 — Un sitio sano engancha el detector.
	 */
	public function test_boot_hooks_the_detector(): void {
		update_option( Store::OPTION_FINGERPRINT, Store::fingerprint() );

		Plugin::boot();

		$this->assertNotFalse( has_action( 'woocommerce_product_set_stock', array( Detector::class, 'after_change' ) ) );
	}

	/**
	 * Un clon que emitiera movimientos le descontaría stock a la tienda real con las
	 * ventas de prueba de la copia (ADR-001 §1).
	 *
	 * PLG-17
	 */
	public function test_a_suspended_site_does_not_hook_the_detector(): void {
		update_option( Store::OPTION_FINGERPRINT, hash( 'sha256', 'https://otra.test' ) );

		// El bootstrap o un test anterior pudieron dejarlo enganchado.
		remove_action( 'woocommerce_product_set_stock', array( Detector::class, 'after_change' ) );
		$this->assertFalse( has_action( 'woocommerce_product_set_stock', array( Detector::class, 'after_change' ) ) );

		Plugin::boot();

		$this->assertFalse( has_action( 'woocommerce_product_set_stock', array( Detector::class, 'after_change' ) ) );
	}

	/**
	 * PLG-18 — Un sitio sano recibe movimientos.
	 */
	public function test_boot_hooks_the_movements_endpoint(): void {
		update_option( Store::OPTION_FINGERPRINT, Store::fingerprint() );

		Plugin::boot();

		$this->assertNotFalse( has_action( 'rest_api_init', array( Movements::class, 'register_routes' ) ) );
	}

	/**
	 * Un clon que aceptara movimientos le aplicaría a la copia las ventas de la tienda
	 * real, y respondería el ACK en su lugar (ADR-001 §1).
	 *
	 * PLG-19
	 */
	public function test_a_suspended_site_does_not_hook_the_movements_endpoint(): void {
		update_option( Store::OPTION_FINGERPRINT, hash( 'sha256', 'https://otra.test' ) );

		remove_action( 'rest_api_init', array( Movements::class, 'register_routes' ) );

		Plugin::boot();

		$this->assertFalse( has_action( 'rest_api_init', array( Movements::class, 'register_routes' ) ) );
	}

	/**
	 * Un clon trae la acción agendada: sin callback, Action Scheduler la marcaría fallida
	 * cada minuto. El callback se engancha igual, y la pasada decide no hacer nada.
	 *
	 * PLG-20
	 */
	public function test_a_suspended_site_still_hooks_the_pass(): void {
		update_option( Store::OPTION_FINGERPRINT, hash( 'sha256', 'https://otra.test' ) );

		remove_action( Pass::HOOK, array( Pass::class, 'run' ) );
		remove_action( 'action_scheduler_init', array( Pass::class, 'maybe_ensure_scheduled' ) );

		Plugin::boot();

		$this->assertNotFalse( has_action( Pass::HOOK, array( Pass::class, 'run' ) ) );
		$this->assertFalse( has_action( 'action_scheduler_init', array( Pass::class, 'maybe_ensure_scheduled' ) ), 'pero no agenda' );
	}

	/**
	 * PLG-21 — Un sitio sano engancha la pasada y su agendado.
	 */
	public function test_boot_hooks_the_pass_and_its_scheduling(): void {
		update_option( Store::OPTION_FINGERPRINT, Store::fingerprint() );

		Plugin::boot();

		$this->assertNotFalse( has_action( Pass::HOOK, array( Pass::class, 'run' ) ) );
		$this->assertNotFalse( has_action( 'action_scheduler_init', array( Pass::class, 'maybe_ensure_scheduled' ) ) );
	}

	/**
	 * PLG-22 — El archivo principal engancha la desactivación.
	 */
	public function test_the_main_file_hooks_deactivation(): void {
		$this->assertNotFalse(
			has_action( 'deactivate_' . plugin_basename( WSS_PLUGIN_FILE ), array( Plugin::class, 'deactivate' ) )
		);
	}

	/**
	 * Un sitio suspendido responde `health` igual: es lo que le dice a la otra tienda por qué
	 * no le llegan movimientos.
	 *
	 * PLG-23
	 */
	public function test_a_suspended_site_still_hooks_health(): void {
		update_option( Store::OPTION_FINGERPRINT, hash( 'sha256', 'https://otra.test' ) );

		remove_action( 'rest_api_init', array( Health::class, 'register_routes' ) );

		Plugin::boot();

		$this->assertNotFalse( has_action( 'rest_api_init', array( Health::class, 'register_routes' ) ) );
	}

	/**
	 * PLG-24 — Un sitio suspendido también prohíbe la caché: responde `health`.
	 */
	public function test_a_suspended_site_still_forbids_caching(): void {
		update_option( Store::OPTION_FINGERPRINT, hash( 'sha256', 'https://otra.test' ) );

		remove_filter( 'rest_post_dispatch', array( Auth::class, 'no_cache' ), 10 );

		Plugin::boot();

		$this->assertNotFalse( has_filter( 'rest_post_dispatch', array( Auth::class, 'no_cache' ) ) );
	}

	/**
	 * Re-emparejar es la forma de salir de la suspensión por un cambio de URL: un sitio
	 * suspendido tiene que poder canjear.
	 *
	 * PLG-25
	 */
	public function test_a_suspended_site_still_hooks_pairing(): void {
		update_option( Store::OPTION_FINGERPRINT, hash( 'sha256', 'https://otra.test' ) );

		remove_action( 'rest_api_init', array( Pair::class, 'register_routes' ) );

		Plugin::boot();

		$this->assertNotFalse( has_action( 'rest_api_init', array( Pair::class, 'register_routes' ) ) );
	}

	/**
	 * PLG-26 — Un sitio sano contesta las preguntas de la reconciliación.
	 */
	public function test_boot_hooks_the_reconcile_endpoint(): void {
		update_option( Store::OPTION_FINGERPRINT, Store::fingerprint() );

		Plugin::boot();

		$this->assertNotFalse( has_action( 'rest_api_init', array( Reconcile::class, 'register_routes' ) ) );
	}

	/**
	 * PLG-27 — Un clon no las contesta: confirmaría como propios movimientos de la tienda real,
	 * y la otra dejaría de reenviárselos.
	 */
	public function test_a_suspended_site_does_not_hook_the_reconcile_endpoint(): void {
		update_option( Store::OPTION_FINGERPRINT, hash( 'sha256', 'https://otra.test' ) );

		remove_action( 'rest_api_init', array( Reconcile::class, 'register_routes' ) );

		Plugin::boot();

		$this->assertFalse( has_action( 'rest_api_init', array( Reconcile::class, 'register_routes' ) ) );
	}

	/**
	 * PLG-28 — Los callbacks de la noche y del cierre se enganchan aunque el sitio esté
	 * suspendido: un clon trae las acciones agendadas, y sin callback Action Scheduler las
	 * marcaría fallidas. Los callbacks deciden no hacer nada.
	 */
	public function test_a_suspended_site_still_hooks_the_reconciliation_callbacks(): void {
		update_option( Store::OPTION_FINGERPRINT, hash( 'sha256', 'https://otra.test' ) );

		remove_action( Schedule::NIGHTLY, array( Schedule::class, 'nightly' ) );
		remove_action( Schedule::IMPORT, array( Schedule::class, 'import_closed' ) );
		remove_action( Audit::CONFIRM, array( Audit::class, 'confirm' ) );

		Plugin::boot();

		$this->assertNotFalse( has_action( Schedule::NIGHTLY, array( Schedule::class, 'nightly' ) ) );
		$this->assertNotFalse( has_action( Schedule::IMPORT, array( Schedule::class, 'import_closed' ) ) );
		$this->assertNotFalse( has_action( Audit::CONFIRM, array( Audit::class, 'confirm' ) ) );
	}
}
