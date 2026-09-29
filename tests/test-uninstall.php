<?php
/**
 * Tests de la desinstalación (ADR-003).
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Schema\Installer;

/**
 * Qué se borra y qué sobrevive según la casilla de los ajustes.
 */
class Test_Uninstall extends WP_UnitTestCase {

	private const OPTION_DELETE_ALL = 'wss_delete_data_on_uninstall';

	public function set_up(): void {
		global $wpdb;

		parent::set_up();

		// Con tablas temporales el DROP no se puede comprobar: no figuran en SHOW TABLES.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Installer::install();
		Store::ensure_identity();

		update_option( Store::OPTION_FINGERPRINT, Store::fingerprint() );

		// Con las tablas vacías, "siguen existiendo" lo cumple igual un TRUNCATE.
		$wpdb->insert(
			Installer::table( 'links' ),
			array(
				'local_product'  => 123,
				'remote_store'   => 'tienda-b',
				'remote_product' => 456,
				'generation'     => 1,
				'base_stock'     => 10,
				'base_set_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$wpdb->insert(
			Installer::table( 'movements' ),
			array(
				'origin_store'   => Store::id(),
				'origin_seq'     => 1,
				'generation'     => 1,
				'delta'          => -1,
				'reason'         => 'sale',
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
				'origin_product' => 123,
				'delivery'       => 'confirmed',
			)
		);
		update_option(
			'wss_peers',
			array(
				'tienda-b' => array(
					'url'    => 'https://tienda-b.test/wp-json/wss/v1',
					'id'     => 'tienda-b',
					'secret' => 'el-secreto-compartido',
					'paired' => '2026-09-21',
				),
			),
			false
		);
	}

	public function tear_down(): void {
		global $wpdb;

		foreach ( array( 'movements', 'links', 'nonces' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		foreach ( array( Installer::OPTION_VERSION, Store::OPTION_ID, Store::OPTION_LABEL, Store::OPTION_FINGERPRINT, 'wss_peers', self::OPTION_DELETE_ALL ) as $option ) {
			delete_option( $option );
		}

		parent::tear_down();
	}

	/**
	 * Corre uninstall.php como lo corre WordPress: con su constante definida.
	 */
	private function run_uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'woonderful-stock-sync-for-woocommerce/woonderful-stock-sync-for-woocommerce.php' );
		}

		include dirname( __DIR__ ) . '/uninstall.php';
	}

	/**
	 * Cuántas filas quedan en una tabla del plugin.
	 *
	 * @param string $name Nombre corto de la tabla.
	 */
	private function count_rows( string $name ): int {
		global $wpdb;

		$table = Installer::table( $name );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Nombres de las tablas del plugin que siguen existiendo.
	 *
	 * @return string[]
	 */
	private function surviving_tables(): array {
		global $wpdb;

		$tables = (array) $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}wss\_%'" ); // phpcs:ignore WordPress.DB
		sort( $tables );

		return $tables;
	}

	/**
	 * El secreto es material sensible y se va aunque el dueño pida conservar los datos:
	 * re-emparejar son dos clics, y una credencial viva en un plugin desinstalado no la
	 * rota ni la mira nadie.
	 *
	 * UNI-01
	 */
	public function test_the_shared_secret_is_always_deleted(): void {
		update_option( self::OPTION_DELETE_ALL, false );

		$this->run_uninstall();

		$peers = (array) get_option( 'wss_peers', array() );

		$this->assertArrayNotHasKey( 'secret', $peers['tienda-b'] );
		$this->assertSame( 'https://tienda-b.test/wp-json/wss/v1', $peers['tienda-b']['url'] );
		$this->assertSame( 'tienda-b', $peers['tienda-b']['id'] );
	}

	/**
	 * UNI-02 — La tabla de nonces se borra siempre; el libro y los vínculos no.
	 */
	public function test_only_the_nonce_table_goes_when_data_is_kept(): void {
		update_option( self::OPTION_DELETE_ALL, false );

		$this->run_uninstall();

		$this->assertSame(
			array(
				Installer::table( 'links' ),
				Installer::table( 'movements' ),
			),
			$this->surviving_tables()
		);

		$this->assertSame( 1, $this->count_rows( 'movements' ), 'el libro tiene que conservar sus filas' );
		$this->assertSame( 1, $this->count_rows( 'links' ), 'los vínculos tienen que conservar sus filas' );
	}

	/**
	 * UNI-03 — Con la casilla en falso, la identidad y los ajustes quedan.
	 */
	public function test_identity_and_options_survive_when_data_is_kept(): void {
		$identity = Store::id();

		update_option( self::OPTION_DELETE_ALL, false );

		$this->run_uninstall();

		$this->assertSame( $identity, Store::id() );
		$this->assertSame( Installer::SCHEMA_VERSION, (int) get_option( Installer::OPTION_VERSION ) );
		$this->assertSame( Store::fingerprint(), get_option( Store::OPTION_FINGERPRINT ) );
		$this->assertNotFalse( get_option( Store::OPTION_LABEL, false ) );
	}

	/**
	 * El estado de fábrica: nadie abrió los ajustes, así que la opción no existe. Es el
	 * caso normal, y el que decide un borrado irreversible (ADR-003).
	 *
	 * UNI-06
	 */
	public function test_nothing_is_deleted_when_the_box_was_never_touched(): void {
		delete_option( self::OPTION_DELETE_ALL );

		$this->run_uninstall();

		$this->assertSame(
			array(
				Installer::table( 'links' ),
				Installer::table( 'movements' ),
			),
			$this->surviving_tables()
		);
		$this->assertNotFalse( get_option( Store::OPTION_ID, false ) );
	}

	/**
	 * UNI-04 — Con la casilla marcada no queda ninguna tabla.
	 */
	public function test_every_table_goes_when_the_box_is_checked(): void {
		update_option( self::OPTION_DELETE_ALL, true );

		$this->run_uninstall();

		$this->assertSame( array(), $this->surviving_tables() );
	}

	/**
	 * UNI-05 — Con la casilla marcada no queda ninguna opción del plugin.
	 */
	public function test_every_option_goes_when_the_box_is_checked(): void {
		update_option( self::OPTION_DELETE_ALL, true );

		$this->run_uninstall();

		foreach ( array( Store::OPTION_ID, Store::OPTION_LABEL, Store::OPTION_FINGERPRINT, 'wss_peers', Installer::OPTION_VERSION, Installer::OPTION_SCHEMA_HEALTHY, self::OPTION_DELETE_ALL ) as $option ) {
			$this->assertFalse( get_option( $option, false ), $option . ' tendría que estar borrada' );
		}
	}

	/**
	 * La hora del control de la pasada no es un dato del dueño: se va aunque se conserven
	 * los datos (ADR-003, revisado el 2026-09-29).
	 *
	 * UNI-07
	 */
	public function test_the_pass_check_is_always_deleted(): void {
		update_option( self::OPTION_DELETE_ALL, false );
		update_option( 'wss_pass_checked_at', time(), true );

		$this->run_uninstall();

		$this->assertFalse( get_option( 'wss_pass_checked_at', false ) );
	}
}
