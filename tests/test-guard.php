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
	 * GRD-01 — Sin nada anotado, un cambio de stock no es nuestro.
	 */
	public function test_an_unexpected_change_is_not_ours(): void {
		$this->assertFalse( Guard::consume( 123, 10, 9 ) );
	}

	/**
	 * GRD-02 — El cambio que anotamos es nuestro.
	 */
	public function test_the_expected_change_is_ours(): void {
		Guard::expect( 123, 10, 9 );

		$this->assertTrue( Guard::consume( 123, 10, 9 ) );
	}

	/**
	 * De un solo uso: si el mismo producto vuelve a moverse de 10 a 9 en el mismo
	 * request, eso ya es otra cosa y tiene que emitirse.
	 *
	 * GRD-03
	 */
	public function test_it_is_consumed_only_once(): void {
		Guard::expect( 123, 10, 9 );

		$this->assertTrue( Guard::consume( 123, 10, 9 ) );
		$this->assertFalse( Guard::consume( 123, 10, 9 ) );
	}

	/**
	 * GRD-04 — La expectativa de un producto no tapa el cambio de otro.
	 */
	public function test_it_does_not_cover_another_product(): void {
		Guard::expect( 123, 10, 9 );

		$this->assertFalse( Guard::consume( 456, 10, 9 ) );
	}

	/**
	 * El caso que el SPEC pide explícitamente: una venta local que cae en el mismo
	 * request mientras se aplica un movimiento remoto **tiene que emitirse**.
	 *
	 * GRD-05
	 */
	public function test_a_local_sale_in_the_same_request_is_not_swallowed(): void {
		Guard::expect( 123, 10, 9 );

		$this->assertFalse( Guard::consume( 123, 9, 8 ) );
		$this->assertTrue( Guard::consume( 123, 10, 9 ) );
	}

	/**
	 * GRD-06 — Un lote deja varias expectativas y cada una se consume por su cuenta.
	 */
	public function test_it_holds_several_expectations_at_once(): void {
		Guard::expect( 123, 10, 9 );
		Guard::expect( 456, 5, 7 );

		$this->assertTrue( Guard::consume( 456, 5, 7 ) );
		$this->assertTrue( Guard::consume( 123, 10, 9 ) );
	}

	/**
	 * Una expectativa que nadie consumió —porque la aplicación falló a mitad— no puede
	 * quedar tapando un cambio posterior (ADR-006 §3).
	 *
	 * GRD-07
	 */
	public function test_an_unconsumed_expectation_can_be_dropped(): void {
		Guard::expect( 123, 10, 9 );

		Guard::forget_all();

		$this->assertFalse( Guard::consume( 123, 10, 9 ) );
	}
}
