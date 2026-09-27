<?php
/**
 * Tests del guard anti-eco.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Detector\Guard;

/**
 * El guard evita que aplicar un movimiento remoto emita uno nuevo (ADR-006 §3).
 */
class Test_Guard extends WP_UnitTestCase {

	public function tear_down(): void {
		Guard::forget_all();

		parent::tear_down();
	}

	/**
	 * GRD-01 — Un cambio que nadie anotó no es nuestro.
	 */
	public function test_an_unexpected_change_is_not_ours(): void {
		$this->assertFalse( Guard::consume( 123 ) );
	}

	/**
	 * GRD-02 — El cambio del producto anotado es nuestro.
	 */
	public function test_the_expected_product_is_ours(): void {
		Guard::expect( 123 );

		$this->assertTrue( Guard::consume( 123 ) );
	}

	/**
	 * De un solo uso: el segundo cambio del mismo producto en el mismo request ya es
	 * otra cosa y tiene que emitirse.
	 *
	 * GRD-03
	 */
	public function test_it_is_consumed_only_once(): void {
		Guard::expect( 123 );

		$this->assertTrue( Guard::consume( 123 ) );
		$this->assertFalse( Guard::consume( 123 ) );
	}

	/**
	 * El agujero que tiene una bandera global y el guard no: la venta de otro producto
	 * que cae mientras se aplica un movimiento remoto **tiene que emitirse**.
	 *
	 * GRD-04
	 */
	public function test_a_sale_of_another_product_is_not_swallowed(): void {
		Guard::expect( 123 );

		$this->assertFalse( Guard::consume( 456 ) );
		$this->assertTrue( Guard::consume( 123 ) );
	}

	/**
	 * Un lote puede traer dos movimientos del mismo producto: dos anotaciones, dos
	 * cambios, y el tercero ya no es nuestro.
	 *
	 * GRD-05
	 */
	public function test_two_changes_of_the_same_product_need_two_expectations(): void {
		Guard::expect( 123 );
		Guard::expect( 123 );

		$this->assertTrue( Guard::consume( 123 ) );
		$this->assertTrue( Guard::consume( 123 ) );
		$this->assertFalse( Guard::consume( 123 ) );
	}

	/**
	 * GRD-06 — Varias expectativas de productos distintos, cada una por su cuenta.
	 */
	public function test_it_holds_several_products_at_once(): void {
		Guard::expect( 123 );
		Guard::expect( 456 );

		$this->assertTrue( Guard::consume( 456 ) );
		$this->assertTrue( Guard::consume( 123 ) );
	}

	/**
	 * Una anotación que nadie consumió —porque la aplicación falló a mitad— no puede
	 * quedar tapando un cambio posterior (ADR-006 §3).
	 *
	 * GRD-07
	 */
	public function test_an_unconsumed_expectation_can_be_dropped(): void {
		Guard::expect( 123 );

		Guard::forget_all();

		$this->assertFalse( Guard::consume( 123 ) );
	}
}
