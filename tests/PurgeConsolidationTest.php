<?php
/**
 * TC-08 — el stock esperado no cambia al purgar el libro.
 *
 * El stock esperado se calcula como base_qty + suma de los deltas aplicados posteriores a
 * base_up_to. Purgar borra parte de esos deltas, así que antes hay que sumarlos a base_qty
 * y avanzar base_up_to. Estos tests comparan el número antes y después.
 *
 * Por qué la restricción existe: ver docs/ARCHITECTURE.md §1 y ADR-001.
 *
 * @package Woonderful\StockSync
 */

namespace Woonderful\StockSync\Tests;

class PurgeConsolidationTest extends TestCase {

	/** Calcula el stock esperado de un SKU: base_qty + deltas aplicados con id > base_up_to. */
	private function stock_esperado( string $sku ): int {
		$fila = $this->state->get( $sku );

		if ( null === $fila ) {
			return 0;
		}

		return (int) $fila->base_qty + $this->movements->sum_applied_after( $sku, (int) $fila->base_up_to );
	}

	/** TC-08 — absorb() y después delete_applied_up_to() dejan el stock esperado igual. */
	public function test_purgar_no_cambia_el_stock_esperado(): void {
		$this->state->bootstrap( 'SKU-1', 10, 0 );

		$this->apply_movement( array( 'sequence' => 1, 'delta' => -2 ) );
		$corte = $this->apply_movement( array( 'sequence' => 2, 'delta' => -3 ) );
		$this->apply_movement( array( 'sequence' => 3, 'delta' => 1 ) );

		$antes = $this->stock_esperado( 'SKU-1' );
		$this->assertSame( 6, $antes, '10 − 2 − 3 + 1' );

		// Delta a absorber: lo que suman los movimientos que se van a borrar, es decir el
		// total menos lo que queda después del corte.
		$absorbido = $this->movements->sum_applied_after( 'SKU-1', 0 )
			- $this->movements->sum_applied_after( 'SKU-1', $corte );

		$this->assertTrue( $this->state->absorb( 'SKU-1', $absorbido, $corte ) );
		$this->assertSame( 2, $this->movements->delete_applied_up_to( 'SKU-1', $corte ) );

		$this->assertSame( $antes, $this->stock_esperado( 'SKU-1' ), 'El número no puede moverse.' );
	}

	/** Verifica que borrar sin absorber sí altera el número — es lo que TC-08 previene. */
	public function test_purgar_sin_consolidar_corrompe_el_stock_esperado(): void {
		$this->state->bootstrap( 'SKU-1', 10, 0 );

		$this->apply_movement( array( 'sequence' => 1, 'delta' => -2 ) );
		$corte = $this->apply_movement( array( 'sequence' => 2, 'delta' => -3 ) );

		$antes = $this->stock_esperado( 'SKU-1' );

		// Sin absorb() antes: los deltas borrados desaparecen del cálculo.
		$this->movements->delete_applied_up_to( 'SKU-1', $corte );

		$this->assertNotSame(
			$antes,
			$this->stock_esperado( 'SKU-1' ),
			'Si esto pasa a ser igual, alguien "arregló" algo y TC-08 dejó de probar lo que cree.'
		);
	}

	/** TC-08b — purgar el historial completo tampoco cambia el stock esperado. */
	public function test_purgar_todo_el_historial_preserva_el_numero(): void {
		$this->state->bootstrap( 'SKU-1', 10, 0 );

		$this->apply_movement( array( 'sequence' => 1, 'delta' => -4 ) );
		$ultimo = $this->apply_movement( array( 'sequence' => 2, 'delta' => -1 ) );

		$antes = $this->stock_esperado( 'SKU-1' );

		$absorbido = $this->movements->sum_applied_after( 'SKU-1', 0 );
		$this->state->absorb( 'SKU-1', $absorbido, $ultimo );
		$this->movements->delete_applied_up_to( 'SKU-1', $ultimo );

		$this->assertSame( $antes, $this->stock_esperado( 'SKU-1' ) );
		$this->assertSame( 5, $this->stock_esperado( 'SKU-1' ), 'Todo el historial vive ahora en base_qty.' );
	}
}
