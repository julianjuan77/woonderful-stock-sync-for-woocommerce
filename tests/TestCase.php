<?php
/**
 * Base de los tests del plugin.
 *
 * @package Woonderful\StockSync
 */

namespace Woonderful\StockSync\Tests;

use WP_UnitTestCase;
use Woonderful\StockSync\Db\MovementRepository;
use Woonderful\StockSync\Db\ProductStateRepository;
use Woonderful\StockSync\Db\Schema;

/**
 * Base de los tests: vacía las dos tablas del plugin antes de cada caso y expone los
 * repositorios ya instanciados en $this->movements y $this->state.
 */
abstract class TestCase extends WP_UnitTestCase {

	protected MovementRepository $movements;

	protected ProductStateRepository $state;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		// TRUNCATE y no DELETE: además de vaciar la tabla reinicia el AUTO_INCREMENT. Hace
		// falta porque WP_UnitTestCase revierte los datos de cada test pero no el contador,
		// y varios casos comparan IDs concretos.
		$movements     = Schema::movements_table();
		$product_state = Schema::product_state_table();

		$wpdb->query( "TRUNCATE TABLE $movements" );
		$wpdb->query( "TRUNCATE TABLE $product_state" );

		$this->movements = new MovementRepository();
		$this->state     = new ProductStateRepository();
	}

	/**
	 * Arma el array de un movimiento válido, listo para pasarle a record().
	 *
	 * $overrides pisa los campos que el test necesite cambiar; el resto quedan en valores
	 * por defecto que no son significativos para ningún caso.
	 *
	 * @param array<string, mixed> $overrides Campos a pisar.
	 *
	 * @return array<string, mixed>
	 */
	protected function movement( array $overrides = array() ): array {
		return array_merge(
			array(
				'origin_store' => 'tienda-a',
				'sequence'     => 1,
				'sku'          => 'SKU-1',
				'delta'        => -1,
				'reason'       => 'sale',
				'reference'    => 'pedido-100',
			),
			$overrides
		);
	}

	/**
	 * Retrasa $seconds el claimed_at de un movimiento, para simular un job que murió
	 * mientras lo tenía tomado.
	 *
	 * Se escribe en la tabla en vez de llamar a claim() con stale_after = 0, porque claim()
	 * compara claimed_at < (ahora - stale_after): con 0 el corte es "ahora" y un claim hecho
	 * en el mismo segundo no es anterior a ahora, así que nunca se retomaría.
	 */
	protected function backdate_claim( int $id, int $seconds ): void {
		global $wpdb;

		$table = Schema::movements_table();

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET claimed_at = %s WHERE id = %d",
				gmdate( 'Y-m-d H:i:s', time() - $seconds ),
				$id
			)
		);
	}

	/** Registra un movimiento y lo lleva hasta status 'applied'. Devuelve su ID. */
	protected function apply_movement( array $overrides = array() ): int {
		$id = $this->movements->record( $this->movement( $overrides ) );

		$this->movements->claim( $id );
		$this->movements->mark_applied( $id );

		return $id;
	}
}
