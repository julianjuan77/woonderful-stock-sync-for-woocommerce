<?php
/**
 * Tests de la guarda que suspende la sincronización.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Plugin;
use Woonderful\StockSync\Schema\Installer;

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
}
