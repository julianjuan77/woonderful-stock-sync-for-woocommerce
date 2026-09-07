<?php
/**
 * Tests de Schema — TC-12 y TC-13 de tests/TEST-CASES.md.
 *
 * Verifican la estructura de las tablas, no su contenido. TC-12 comprueba que el índice
 * origin_sequence existe y es UNIQUE, que es lo que TC-01 asume para probar idempotencia.
 *
 * @package Woonderful\StockSync
 */

namespace Woonderful\StockSync\Tests;

use Woonderful\StockSync\Db\Schema;

class SchemaTest extends TestCase {

	/**
	 * Lee los índices de una tabla con SHOW INDEX y los agrupa por nombre de índice, con sus
	 * columnas en orden.
	 *
	 * @return array<string, array{unique: bool, columns: array<int, string>}>
	 */
	private function indices( string $table ): array {
		global $wpdb;

		$out = array();

		foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM $table" ) as $fila ) {
			$nombre = $fila->Key_name;

			if ( ! isset( $out[ $nombre ] ) ) {
				$out[ $nombre ] = array(
					'unique'  => '0' === (string) $fila->Non_unique,
					'columns' => array(),
				);
			}

			$out[ $nombre ]['columns'][] = $fila->Column_name;
		}

		return $out;
	}

	/** TC-12 — las dos tablas existen, y wss_movements tiene sus tres índices con las columnas correctas. */
	public function test_las_tablas_se_crean_con_sus_indices(): void {
		global $wpdb;

		$movements = Schema::movements_table();
		$this->assertSame( $movements, $wpdb->get_var( "SHOW TABLES LIKE '$movements'" ) );

		$indices = $this->indices( $movements );

		$this->assertArrayHasKey( 'origin_sequence', $indices );
		$this->assertTrue( $indices['origin_sequence']['unique'], 'Es LA garantía de idempotencia.' );
		$this->assertSame( array( 'origin_store', 'sequence' ), $indices['origin_sequence']['columns'] );

		$this->assertSame( array( 'sku', 'id' ), $indices['sku_id']['columns'] );
		$this->assertSame( array( 'status', 'claimed_at' ), $indices['status_claimed']['columns'] );

		$product_state = Schema::product_state_table();
		$this->assertSame( $product_state, $wpdb->get_var( "SHOW TABLES LIKE '$product_state'" ) );
		$this->assertSame( array( 'sku' ), $this->indices( $product_state )['PRIMARY']['columns'] );
	}

	/** TC-13 — llamar create_tables() sobre tablas que ya existen no borra sus filas. */
	public function test_create_tables_es_idempotente(): void {
		global $wpdb;

		$this->state->bootstrap( 'SKU-1', 42, 0 );

		Schema::create_tables();

		$fila = $this->state->get( 'SKU-1' );
		$this->assertNotNull( $fila, 'Los datos sobreviven a una reactivación.' );
		$this->assertSame( 42, (int) $fila->base_qty );

		$movements = Schema::movements_table();
		$this->assertSame( $movements, $wpdb->get_var( "SHOW TABLES LIKE '$movements'" ) );
	}

	public function test_la_version_del_esquema_queda_registrada(): void {
		$this->assertSame( Schema::VERSION, Schema::installed_version() );
	}
}
