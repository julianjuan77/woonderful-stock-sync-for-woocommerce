<?php
/**
 * TC-08 — purgar no cambia el stock esperado.
 *
 * El test que más importa de la suite: es la restricción dura de ADR-001, y su modo de
 * falla es silencioso. Si esto se rompe, la reconciliación empieza a reportar diferencias
 * que no existen en todos los productos con historia, y nada más se ve raro.
 *
 * @package Woonderful\StockSync
 */

namespace Woonderful\StockSync\Tests;

class PurgeConsolidationTest extends TestCase {

	/** El stock esperado: la parte fija del snapshot más los deltas aplicados posteriores. */
	private function stock_esperado( string $sku ): int {
		$fila = $this->state->get( $sku );

		if ( null === $fila ) {
			return 0;
		}

		return (int) $fila->base_qty + $this->movements->sum_applied_after( $sku, (int) $fila->base_up_to );
	}

	/** TC-08 — consolidar y después borrar deja el mismo número. */
	public function test_purgar_no_cambia_el_stock_esperado(): void {
		$this->state->bootstrap( 'SKU-1', 10, 0 );

		$this->apply_movement( array( 'sequence' => 1, 'delta' => -2 ) );
		$corte = $this->apply_movement( array( 'sequence' => 2, 'delta' => -3 ) );
		$this->apply_movement( array( 'sequence' => 3, 'delta' => 1 ) );

		$antes = $this->stock_esperado( 'SKU-1' );
		$this->assertSame( 6, $antes, '10 − 2 − 3 + 1' );

		// La secuencia correcta, y el orden no es negociable.
		$absorbido = $this->movements->sum_applied_after( 'SKU-1', 0 )
			- $this->movements->sum_applied_after( 'SKU-1', $corte );

		$this->assertTrue( $this->state->absorb( 'SKU-1', $absorbido, $corte ) );
		$this->assertSame( 2, $this->movements->delete_applied_up_to( 'SKU-1', $corte ) );

		$this->assertSame( $antes, $this->stock_esperado( 'SKU-1' ), 'El número no puede moverse.' );
	}

	/** El modo de falla que TC-08 existe para atrapar: borrar sin consolidar. */
	public function test_purgar_sin_consolidar_corrompe_el_stock_esperado(): void {
		$this->state->bootstrap( 'SKU-1', 10, 0 );

		$this->apply_movement( array( 'sequence' => 1, 'delta' => -2 ) );
		$corte = $this->apply_movement( array( 'sequence' => 2, 'delta' => -3 ) );

		$antes = $this->stock_esperado( 'SKU-1' );

		// A propósito: borrar sin llamar a absorb() antes.
		$this->movements->delete_applied_up_to( 'SKU-1', $corte );

		$this->assertNotSame(
			$antes,
			$this->stock_esperado( 'SKU-1' ),
			'Si esto pasa a ser igual, alguien "arregló" algo y TC-08 dejó de probar lo que cree.'
		);
	}

	/** Purgar todo el historial también tiene que preservar el número. */
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
