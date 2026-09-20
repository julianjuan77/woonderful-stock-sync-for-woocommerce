<?php
/**
 * Tests del esquema (ADR-004).
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Schema\Installer;

/**
 * El instalador crea las tres tablas como las describe ADR-004.
 */
class Test_Installer extends WP_UnitTestCase {

	/**
	 * WP_UnitTestCase reescribe los CREATE TABLE como CREATE TEMPORARY TABLE, para que
	 * cada test arranque limpio. Una tabla temporal existe sólo para esa conexión y es
	 * invisible para SHOW TABLES y para information_schema, así que estos tests —que
	 * justamente miran el motor y los índices— necesitan tablas de verdad.
	 *
	 * El síntoma cuando no se sabe: dbDelta dice "Created table" y listar las tablas
	 * devuelve vacío.
	 */
	public function set_up(): void {
		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Y como son de verdad, hay que borrarlas: CREATE TABLE hace COMMIT implícito en
	 * MySQL, así que el rollback por test no las deshace.
	 */
	public function tear_down(): void {
		global $wpdb;

		foreach ( array( 'movements', 'links', 'nonces' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		delete_option( Installer::OPTION_VERSION );

		parent::tear_down();
	}

	/**
	 * Nombres de tabla existentes en la base.
	 *
	 * @return string[]
	 */
	private function existing_tables(): array {
		global $wpdb;

		// Sólo las del prefijo de test: en la misma base están también las del sitio.
		return (array) $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}wss\_%'" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * SCH-01 — install() sobre una base limpia crea las tres tablas.
	 */
	public function test_install_crea_las_tres_tablas(): void {
		Installer::install();

		$tablas = $this->existing_tables();
		sort( $tablas );

		// Por nombre y no por cantidad: una tabla creada con el nombre equivocado sigue
		// siendo una tabla, y contar tres no lo detecta.
		$this->assertSame(
			array(
				Installer::table( 'links' ),
				Installer::table( 'movements' ),
				Installer::table( 'nonces' ),
			),
			$tablas
		);
	}

	/**
	 * SCH-02 — Las tres tablas se crean en InnoDB.
	 */
	public function test_las_tres_tablas_son_innodb(): void {
		Installer::install();

		$this->assertSame( array(), Installer::tables_without_transactions() );
	}

	/**
	 * SCH-03 — El libro tiene exactamente los tres índices de la decisión, más la primaria.
	 */
	public function test_el_libro_tiene_los_tres_indices_de_la_decision(): void {
		global $wpdb;

		Installer::install();

		$table   = Installer::table( 'movements' );
		$indexes = array_unique(
			wp_list_pluck( (array) $wpdb->get_results( "SHOW INDEX FROM {$table}" ), 'Key_name' ) // phpcs:ignore WordPress.DB
		);

		sort( $indexes );

		$this->assertSame( array( 'PRIMARY', 'by_link', 'dedup', 'delivery_queue' ), $indexes );
	}

	/**
	 * La secuencia propia es el AUTO_INCREMENT, que no existe hasta después del INSERT.
	 * Que dos filas puedan estar en NULL a la vez es lo que hace viable ese diseño.
	 *
	 * SCH-04
	 */
	public function test_dos_movimientos_propios_conviven_con_la_secuencia_en_null(): void {
		global $wpdb;

		Installer::install();

		$this->assertNotFalse( $this->insert_movement( 'tienda-a', null ) );
		$this->assertNotFalse( $this->insert_movement( 'tienda-a', null ) );

		$table = Installer::table( 'movements' );

		$this->assertSame( 2, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * La deduplicación de §3 del SPEC vive en el índice, no en código PHP.
	 *
	 * SCH-05
	 */
	public function test_un_movimiento_recibido_repetido_choca_contra_el_unique(): void {
		Installer::install();

		$this->assertNotFalse( $this->insert_movement( 'tienda-b', 77 ) );
		$this->assertFalse( $this->insert_movement( 'tienda-b', 77 ) );
	}

	/**
	 * SCH-06 — La misma secuencia de dos tiendas distintas no choca.
	 */
	public function test_el_mismo_numero_de_dos_tiendas_distintas_no_choca(): void {
		Installer::install();

		$this->assertNotFalse( $this->insert_movement( 'tienda-b', 77 ) );
		$this->assertNotFalse( $this->insert_movement( 'tienda-c', 77 ) );
	}

	/**
	 * SCH-07 — maybe_upgrade() no ejecuta nada si la versión ya está al día.
	 */
	public function test_maybe_upgrade_no_toca_nada_si_la_version_esta_al_dia(): void {
		Installer::install();

		update_option( Installer::OPTION_VERSION, Installer::SCHEMA_VERSION, false );

		global $wpdb;
		$queries_antes = $wpdb->num_queries;

		Installer::maybe_upgrade();

		$this->assertSame( $queries_antes, $wpdb->num_queries );
	}

	/**
	 * SCH-08 — maybe_upgrade() reinstala si la versión quedó atrás.
	 */
	public function test_maybe_upgrade_reinstala_si_la_version_quedo_atras(): void {
		update_option( Installer::OPTION_VERSION, 0, false );

		Installer::maybe_upgrade();

		$this->assertCount( 3, $this->existing_tables() );
		$this->assertSame( Installer::SCHEMA_VERSION, (int) get_option( Installer::OPTION_VERSION ) );
	}

	/**
	 * SCH-09 — Una tabla en MyISAM se reporta como tabla sin transacciones.
	 */
	public function test_detecta_una_tabla_sin_transacciones(): void {
		global $wpdb;

		Installer::install();

		$table = Installer::table( 'nonces' );
		$wpdb->query( "ALTER TABLE {$table} ENGINE=MyISAM" ); // phpcs:ignore WordPress.DB

		$this->assertSame( array( $table ), Installer::tables_without_transactions() );
	}

	/**
	 * Inserta un movimiento mínimo.
	 *
	 * @param string   $store Identificador de la tienda de origen.
	 * @param int|null $seq   Secuencia, o null para una fila propia recién insertada.
	 *
	 * @return int|false Filas insertadas, o false si la base lo rechazó.
	 */
	private function insert_movement( string $store, ?int $seq ) {
		global $wpdb;

		$wpdb->suppress_errors( true );

		$result = $wpdb->insert(
			Installer::table( 'movements' ),
			array(
				'origin_store'   => $store,
				'origin_seq'     => $seq,
				'generation'     => 1,
				'delta'          => -1,
				'reason'         => 'sale',
				'created_at'     => current_time( 'mysql', true ),
				'origin_product' => 123,
			)
		);

		$wpdb->suppress_errors( false );

		return $result;
	}
}
