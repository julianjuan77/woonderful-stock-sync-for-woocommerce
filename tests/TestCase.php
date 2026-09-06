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

/** Deja las tablas vacías antes de cada test y da los repositorios armados. */
abstract class TestCase extends WP_UnitTestCase {

	protected MovementRepository $movements;

	protected ProductStateRepository $state;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		// WP_UnitTestCase revierte cada test, pero el AUTO_INCREMENT no vuelve atrás y
		// varios casos comparan IDs. TRUNCATE lo reinicia y deja cada test en el mismo
		// punto de partida.
		$movements     = Schema::movements_table();
		$product_state = Schema::product_state_table();

		$wpdb->query( "TRUNCATE TABLE $movements" );
		$wpdb->query( "TRUNCATE TABLE $product_state" );

		$this->movements = new MovementRepository();
		$this->state     = new ProductStateRepository();
	}

	/**
	 * Un movimiento con valores por defecto razonables.
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
	 * Envejece el claim de un movimiento, como si el job que lo tomó hubiera muerto.
	 *
	 * Se toca la base en vez de pasar stale_after = 0: con 0 el corte es "ahora" y un claim
	 * recién hecho no es anterior a ahora, así que no probaría nada.
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

	/** Registra un movimiento y lo deja en 'applied'. Devuelve su ID. */
	protected function apply_movement( array $overrides = array() ): int {
		$id = $this->movements->record( $this->movement( $overrides ) );

		$this->movements->claim( $id );
		$this->movements->mark_applied( $id );

		return $id;
	}
}
