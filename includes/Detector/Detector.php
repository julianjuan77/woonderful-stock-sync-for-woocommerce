<?php
/**
 * El detector de cambios de stock.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Detector;

use Woonderful\StockSync\Ledger\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * Convierte cada cambio de stock local en un movimiento del libro (ADR-006 §1).
 *
 * Escucha los pares `before`/`set` de producto y de variación. El delta sale de leer la
 * base en los dos, **no del objeto que entrega el hook**: en una venta el objeto puede
 * venir desactualizado, y cuando se guarda un producto el `before` ya trae el valor nuevo.
 */
final class Detector {

	/**
	 * El stock que había en la base antes de cada cambio en curso, por producto.
	 *
	 * Cada `before` pisa el anterior en vez de apilarse: un guardado que no cambia el valor
	 * dispara `before` pero no `set`, y lo que dejó no puede usarse para el cambio siguiente.
	 *
	 * @var array<int, int>
	 */
	private static array $before = array();

	/**
	 * Engancha los cuatro hooks.
	 */
	public static function register(): void {
		foreach ( array( 'product', 'variation' ) as $type ) {
			add_action( "woocommerce_{$type}_before_set_stock", array( self::class, 'before_change' ) );
			add_action( "woocommerce_{$type}_set_stock", array( self::class, 'after_change' ) );
		}
	}

	/**
	 * Anota el stock de la base antes de que WooCommerce escriba.
	 *
	 * @param \WC_Product $product El objeto que lleva el stock.
	 */
	public static function before_change( $product ): void {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		self::$before[ $product->get_id() ] = self::stock_in_db( $product->get_id() );
	}

	/**
	 * Registra el cambio, si es asunto de la malla.
	 *
	 * @param \WC_Product $product El objeto que lleva el stock.
	 */
	public static function after_change( $product ): void {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$product_id = $product->get_id();

		if ( ! array_key_exists( $product_id, self::$before ) ) {
			return;
		}

		$before = self::$before[ $product_id ];
		unset( self::$before[ $product_id ] );

		// Se consume antes de cualquier otro descarte: una anotación que sobreviviera
		// taparía el próximo cambio de este producto, que ya no es nuestro.
		if ( Guard::consume( $product_id ) ) {
			return;
		}

		if ( ! $product->managing_stock() ) {
			return;
		}

		Ledger::record( $product_id, self::stock_in_db( $product_id ) - $before );
	}

	/**
	 * El stock de un producto leído de la base.
	 *
	 * Consulta directa y no `get_post_meta()`: la caché de meta del request pudo cargarse
	 * antes de que otro proceso cambiara el stock (ADR-006 §1).
	 *
	 * @param int $product_id Producto.
	 */
	private static function stock_in_db( int $product_id ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock'",
				$product_id
			)
		);
		// phpcs:enable

		return (int) wc_stock_amount( $value );
	}

	/**
	 * Descarta los `before` que quedaron sin su `set`.
	 */
	public static function forget_all(): void {
		self::$before = array();
	}
}
