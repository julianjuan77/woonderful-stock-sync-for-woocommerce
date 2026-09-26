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
	public function test_install_creates_the_three_tables(): void {
		Installer::install();

		$tables = $this->existing_tables();
		sort( $tables );

		// Por nombre y no por cantidad: una tabla creada con el nombre equivocado sigue
		// siendo una tabla, y contar tres no lo detecta.
		$this->assertSame(
			array(
				Installer::table( 'links' ),
				Installer::table( 'movements' ),
				Installer::table( 'nonces' ),
			),
			$tables
		);
	}

	/**
	 * Consulta el motor de cada tabla sin pasar por el código bajo test.
	 *
	 * @return array<string,string> Nombre de tabla => motor.
	 */
	private function engines(): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT TABLE_NAME AS name, ENGINE AS engine FROM information_schema.TABLES
			 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '{$wpdb->prefix}wss\_%'"
		);

		$engines = array();

		foreach ( $rows as $row ) {
			$engines[ (string) $row->name ] = (string) $row->engine;
		}

		ksort( $engines );

		return $engines;
	}

	/**
	 * Por motor de cada tabla y no por "la lista de problemas está vacía": una función
	 * rota que siempre devuelve array() cumple eso último sin mirar nada.
	 *
	 * SCH-02
	 */
	public function test_the_three_tables_are_innodb(): void {
		Installer::install();

		$this->assertSame(
			array(
				Installer::table( 'links' )     => 'InnoDB',
				Installer::table( 'movements' ) => 'InnoDB',
				Installer::table( 'nonces' )    => 'InnoDB',
			),
			$this->engines()
		);
	}

	/**
	 * ADR-004 §5 exige InnoDB **explícito**, no el default del host. En un host cuyo
	 * default ya es InnoDB las dos cosas se ven idénticas en la base, así que lo único
	 * que distingue "declarado" de "heredado" es el SQL.
	 *
	 * SCH-10
	 */
	public function test_the_definitions_declare_the_engine(): void {
		// Por reflexión y no contando texto en el archivo: un comentario que nombre el
		// motor también cuenta, y de hecho hizo fallar la primera versión de este test.
		$definitions = new ReflectionMethod( Installer::class, 'definitions' );
		$definitions->setAccessible( true );

		$statements = (array) $definitions->invoke( null, '' );

		$this->assertCount( 3, $statements );

		foreach ( $statements as $sql ) {
			$this->assertStringContainsString( 'ENGINE=InnoDB', (string) $sql );
		}
	}

	/**
	 * SCH-03 — El libro tiene exactamente los tres índices de la decisión, más la primaria.
	 */
	public function test_the_ledger_has_the_decided_indexes(): void {
		global $wpdb;

		Installer::install();

		$table = Installer::table( 'movements' );
		$rows  = (array) $wpdb->get_results( "SHOW INDEX FROM {$table}" ); // phpcs:ignore WordPress.DB

		$indexes = array();

		foreach ( $rows as $row ) {
			$indexes[ (string) $row->Key_name ]['columnas'][ (int) $row->Seq_in_index ] = (string) $row->Column_name; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$indexes[ (string) $row->Key_name ]['unico']                                = ( '0' === (string) $row->Non_unique ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		}

		foreach ( $indexes as $index_name => $data ) {
			ksort( $indexes[ $index_name ]['columnas'] );
			$indexes[ $index_name ]['columnas'] = array_values( $indexes[ $index_name ]['columnas'] );
		}

		ksort( $indexes );

		// Por columnas y no por nombre: agregarle `generation` al índice `dedup` rompe la
		// deduplicación de §3 del SPEC y deja el nombre igual.
		$this->assertSame(
			array(
				'PRIMARY'        => array(
					'columnas' => array( 'id' ),
					'unico'    => true,
				),
				'by_link'        => array(
					'columnas' => array( 'link_id', 'generation' ),
					'unico'    => false,
				),
				'dedup'          => array(
					'columnas' => array( 'origin_store', 'origin_seq' ),
					'unico'    => true,
				),
				'delivery_queue' => array(
					'columnas' => array( 'delivery', 'next_attempt_at' ),
					'unico'    => false,
				),
			),
			$indexes
		);
	}

	/**
	 * La secuencia propia es el AUTO_INCREMENT, que no existe hasta después del INSERT.
	 * Que dos filas puedan estar en NULL a la vez es lo que hace viable ese diseño.
	 *
	 * SCH-04
	 */
	public function test_two_own_movements_coexist_with_a_null_sequence(): void {
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
	public function test_a_repeated_received_movement_hits_the_unique(): void {
		Installer::install();

		$this->assertNotFalse( $this->insert_movement( 'tienda-b', 77 ) );
		$this->assertFalse( $this->insert_movement( 'tienda-b', 77 ) );
	}

	/**
	 * SCH-06 — La misma secuencia de dos tiendas distintas no choca.
	 */
	public function test_the_same_sequence_from_two_stores_does_not_collide(): void {
		Installer::install();

		$this->assertNotFalse( $this->insert_movement( 'tienda-b', 77 ) );
		$this->assertNotFalse( $this->insert_movement( 'tienda-c', 77 ) );
	}

	/**
	 * SCH-07 — maybe_upgrade() no ejecuta nada si la versión ya está al día.
	 */
	public function test_maybe_upgrade_does_nothing_when_the_version_is_current(): void {
		Installer::install();

		update_option( Installer::OPTION_VERSION, Installer::SCHEMA_VERSION, false );

		global $wpdb;
		$queries_before = $wpdb->num_queries;

		Installer::maybe_upgrade();

		$this->assertSame( $queries_before, $wpdb->num_queries );
	}

	/**
	 * SCH-08 — maybe_upgrade() reinstala si la versión quedó atrás.
	 */
	public function test_maybe_upgrade_reinstalls_when_the_version_is_behind(): void {
		update_option( Installer::OPTION_VERSION, 0, false );

		Installer::maybe_upgrade();

		$tables = $this->existing_tables();
		sort( $tables );

		$this->assertSame(
			array(
				Installer::table( 'links' ),
				Installer::table( 'movements' ),
				Installer::table( 'nonces' ),
			),
			$tables
		);
		$this->assertSame( Installer::SCHEMA_VERSION, (int) get_option( Installer::OPTION_VERSION ) );
	}

	/**
	 * Una tabla que no existe no aparece en information_schema, así que hay que buscarla
	 * por ausencia: recorrer sólo las filas que la consulta devuelve la deja pasar, y el
	 * plugin sincronizaría sin esa tabla.
	 *
	 * SCH-11
	 */
	public function test_a_missing_table_is_reported(): void {
		global $wpdb;

		Installer::install();

		$table = Installer::table( 'nonces' );
		$wpdb->query( "DROP TABLE {$table}" ); // phpcs:ignore WordPress.DB

		$this->assertSame( array( $table ), Installer::unhealthy_tables() );
	}

	/**
	 * SCH-09 — Una tabla en MyISAM se reporta como tabla sin transacciones.
	 */
	public function test_it_detects_a_table_without_transactions(): void {
		global $wpdb;

		Installer::install();

		$table = Installer::table( 'nonces' );
		$wpdb->query( "ALTER TABLE {$table} ENGINE=MyISAM" ); // phpcs:ignore WordPress.DB

		$this->assertSame( array( $table ), Installer::unhealthy_tables() );
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
