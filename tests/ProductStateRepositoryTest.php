<?php
/**
 * TC-09 a TC-11 — el snapshot de estado.
 *
 * @package Woonderful\StockSync
 */

namespace Woonderful\StockSync\Tests;

use Woonderful\StockSync\Db\Schema;

class ProductStateRepositoryTest extends TestCase {

	/** TC-10 — re-bootstrapear reemplaza el base, no lo acumula. */
	public function test_re_bootstrapear_reemplaza_no_acumula(): void {
		$this->state->bootstrap( 'SKU-1', 10, 0 );
		$this->state->bootstrap( 'SKU-1', 7, 0 );

		$fila = $this->state->get( 'SKU-1' );

		$this->assertNotNull( $fila );
		$this->assertSame( 7, (int) $fila->base_qty, 'Un recuento físico corrige el base.' );
	}

	/** TC-09 — absorb() no retrocede: aplicar dos veces la misma consolidación restaría de más. */
	public function test_absorb_no_retrocede(): void {
		$this->state->bootstrap( 'SKU-1', 10, 0 );

		$this->assertTrue( $this->state->absorb( 'SKU-1', -3, 100 ) );
		$this->assertFalse( $this->state->absorb( 'SKU-1', -3, 100 ), 'El mismo corte, dos veces.' );
		$this->assertFalse( $this->state->absorb( 'SKU-1', -3, 50 ), 'Un corte anterior.' );

		$fila = $this->state->get( 'SKU-1' );
		$this->assertSame( 7, (int) $fila->base_qty, 'El delta se aplicó una sola vez.' );
		$this->assertSame( 100, (int) $fila->base_up_to );
	}

	/** TC-11 — sacar un SKU de la malla no toca su libro. */
	public function test_forget_no_borra_el_libro(): void {
		global $wpdb;

		$this->state->bootstrap( 'SKU-1', 10, 0 );
		$this->apply_movement( array( 'sku' => 'SKU-1' ) );

		$this->assertTrue( $this->state->forget( 'SKU-1' ) );
		$this->assertNull( $this->state->get( 'SKU-1' ) );

		$table = Schema::movements_table();
		$this->assertSame(
			'1',
			$wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE sku = %s", 'SKU-1' ) ),
			'El libro es inmutable: es el registro de auditoría.'
		);
	}

	public function test_is_bootstrapped_distingue_lo_que_entro_a_la_malla(): void {
		$this->assertFalse( $this->state->is_bootstrapped( 'SKU-1' ) );

		$this->state->bootstrap( 'SKU-1', 10, 0 );

		$this->assertTrue( $this->state->is_bootstrapped( 'SKU-1' ) );
	}

	public function test_all_skus_pagina_en_orden(): void {
		foreach ( array( 'SKU-C', 'SKU-A', 'SKU-B' ) as $sku ) {
			$this->state->bootstrap( $sku, 1, 0 );
		}

		$this->assertSame( array( 'SKU-A', 'SKU-B' ), $this->state->all_skus( 2, 0 ) );
		$this->assertSame( array( 'SKU-C' ), $this->state->all_skus( 2, 2 ) );
	}
}
