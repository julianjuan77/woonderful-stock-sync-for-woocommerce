<?php
/**
 * El guard anti-eco.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Detector;

defined( 'ABSPATH' ) || exit;

/**
 * Distingue el cambio de stock que provocó el propio plugin del que provocó una venta.
 *
 * Cuando se aplica un movimiento que vino de la otra tienda, el stock local cambia y el
 * detector lo ve como cualquier otro cambio: sin esto emitiría un movimiento nuevo hacia
 * allá, la otra tienda lo aplicaría, y el descuento se amplificaría en cada vuelta (§3 del
 * SPEC).
 *
 * La expectativa es **específica y de un solo uso** —producto, valor de origen y valor de
 * destino— y no una bandera de "estoy aplicando": una bandera taparía una venta local que
 * caiga en el mismo request, que es lo que ADR-006 §3 pide explícitamente evitar.
 */
final class Guard {

	/**
	 * Los cambios que el plugin está por provocar.
	 *
	 * Es estado de este request y nada más: al terminar muere con él, así que una
	 * expectativa que nadie consumió —porque la aplicación falló a mitad— no sobrevive
	 * para tapar el cambio de la página siguiente.
	 *
	 * @var array<int, array{product: int, from: int, to: int}>
	 */
	private static array $expected = array();

	/**
	 * Anota un cambio de stock que está por hacer el propio plugin.
	 *
	 * @param int $product_id Producto que gestiona el stock.
	 * @param int $from       Valor actual.
	 * @param int $to         Valor al que se lo lleva.
	 */
	public static function expect( int $product_id, int $from, int $to ): void {
		self::$expected[] = array(
			'product' => $product_id,
			'from'    => $from,
			'to'      => $to,
		);
	}

	/**
	 * Si este cambio lo provocó el plugin, lo consume y responde que sí.
	 *
	 * @param int $product_id Producto que cambió.
	 * @param int $from       Valor anterior.
	 * @param int $to         Valor nuevo.
	 */
	public static function consume( int $product_id, int $from, int $to ): bool {
		foreach ( self::$expected as $index => $expectation ) {
			if ( $expectation['product'] === $product_id
				&& $expectation['from'] === $from
				&& $expectation['to'] === $to
			) {
				unset( self::$expected[ $index ] );

				return true;
			}
		}

		return false;
	}

	/**
	 * Descarta lo que quedó sin consumir.
	 */
	public static function forget_all(): void {
		self::$expected = array();
	}
}
