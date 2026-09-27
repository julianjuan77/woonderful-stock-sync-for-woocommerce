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
 * Se anota **el producto y nada más**, de un solo uso. No los valores: el hook de WooCommerce
 * entrega un número calculado antes de escribir y no releído, así que bajo concurrencia no
 * coincide con lo anotado y el cambio propio se emitiría como una venta — el eco. Lo que
 * elegimos es certero; lo que medimos, no (ADR-006 §3).
 *
 * Y no una bandera de "estoy aplicando": una bandera ciega al detector para todos los
 * productos, y el SPEC pide que no tape una venta local que caiga en el mismo request.
 */
final class Guard {

	/**
	 * Los cambios que el plugin está por provocar.
	 *
	 * Es estado de este request y nada más: al terminar muere con él, así que una
	 * expectativa que nadie consumió —porque la aplicación falló a mitad— no sobrevive
	 * para tapar el cambio de la página siguiente.
	 *
	 * @var int[]
	 */
	private static array $expected = array();

	/**
	 * Anota que el próximo cambio de este producto lo provoca el propio plugin.
	 *
	 * Una anotación por cambio: un lote con dos movimientos del mismo producto anota dos
	 * veces.
	 *
	 * @param int $product_id Producto que gestiona el stock.
	 */
	public static function expect( int $product_id ): void {
		self::$expected[] = $product_id;
	}

	/**
	 * Si este cambio lo provocó el plugin, consume la anotación y responde que sí.
	 *
	 * @param int $product_id Producto que cambió.
	 */
	public static function consume( int $product_id ): bool {
		$index = array_search( $product_id, self::$expected, true );

		if ( false === $index ) {
			return false;
		}

		unset( self::$expected[ $index ] );

		return true;
	}

	/**
	 * Descarta lo que quedó sin consumir.
	 */
	public static function forget_all(): void {
		self::$expected = array();
	}
}
