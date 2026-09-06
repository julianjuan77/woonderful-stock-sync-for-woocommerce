<?php
/**
 * TC-12 y TC-13 — las tablas y sus índices.
 *
 * Sin el índice único, TC-01 pasaría por casualidad. Este test es el que garantiza que la
 * garantía existe.
 *
 * @package Woonderful\StockSync
 */

namespace Woonderful\StockSync\Tests;

use Woonderful\StockSync\Db\Schema;

class SchemaTest extends TestCase {

	/**
	 * Índices de una tabla, agrupados por nombre.
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

	/** TC-12 — las dos tablas existen con sus índices. */
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

	/** TC-13 — create_tables() dos veces no rompe ni pierde datos. */
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
