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
	 * El movimiento que acaba de escribir cada producto, para que el pedido lo estampe.
	 *
	 * Cada `set` lo pisa o lo borra: si el cambio del pedido no se registró —lo anotó el
	 * guard, o el producto no está vinculado— no queda una fila anterior a mano para
	 * estamparle un motivo que no es suyo.
	 *
	 * @var array<int, int>
	 */
	private static array $recorded = array();

	/**
	 * Engancha los cuatro hooks de stock y los tres que estampan el motivo.
	 */
	public static function register(): void {
		foreach ( array( 'product', 'variation' ) as $type ) {
			add_action( "woocommerce_{$type}_before_set_stock", array( self::class, 'before_change' ) );
			add_action( "woocommerce_{$type}_set_stock", array( self::class, 'after_change' ) );
		}

		add_action( 'woocommerce_reduce_order_item_stock', array( self::class, 'stamp_sale' ), 10, 3 );
		add_action( 'woocommerce_restore_order_item_stock', array( self::class, 'stamp_restore' ), 10, 4 );
		add_action( 'woocommerce_restock_refunded_item', array( self::class, 'stamp_refund' ), 10, 5 );
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
		unset( self::$before[ $product_id ], self::$recorded[ $product_id ] );

		// Se consume antes de cualquier otro descarte: una anotación que sobreviviera
		// taparía el próximo cambio de este producto, que ya no es nuestro.
		if ( Guard::consume( $product_id ) ) {
			return;
		}

		if ( ! $product->managing_stock() ) {
			return;
		}

		$movement_id = Ledger::record( $product_id, self::stock_in_db( $product_id ) - $before );

		if ( null !== $movement_id ) {
			self::$recorded[ $product_id ] = $movement_id;
		}
	}

	/**
	 * Un pedido descontó el stock de una línea (ADR-006 §2).
	 *
	 * @param \WC_Order_Item_Product $item   La línea.
	 * @param array<string, mixed>   $change Lo que cambió; no se usa.
	 * @param \WC_Order              $order  El pedido.
	 */
	public static function stamp_sale( $item, $change, $order ): void {
		if ( $item instanceof \WC_Order_Item_Product ) {
			self::stamp( $item->get_product(), 'sale', $order );
		}
	}

	/**
	 * Un pedido cancelado devolvió el stock de una línea.
	 *
	 * @param \WC_Order_Item_Product $item      La línea.
	 * @param int                    $new_stock No se usa.
	 * @param int                    $old_stock No se usa.
	 * @param \WC_Order              $order     El pedido.
	 */
	public static function stamp_restore( $item, $new_stock, $old_stock, $order ): void {
		if ( $item instanceof \WC_Order_Item_Product ) {
			self::stamp( $item->get_product(), 'refund', $order );
		}
	}

	/**
	 * Un reembolso repuso stock.
	 *
	 * @param int         $product_id No se usa: el producto viene entero al final.
	 * @param int         $old_stock  No se usa.
	 * @param int         $new_stock  No se usa.
	 * @param \WC_Order   $order      El pedido reembolsado.
	 * @param \WC_Product $product    El producto.
	 */
	public static function stamp_refund( $product_id, $old_stock, $new_stock, $order, $product ): void {
		self::stamp( $product, 'refund', $order );
	}

	/**
	 * Completa el motivo del movimiento que acaba de escribir el producto.
	 *
	 * Se busca por el que **lleva** el stock: en una variación que lo toma del padre, la
	 * fila es del padre.
	 *
	 * @param mixed  $product El producto de la línea.
	 * @param string $reason  El motivo.
	 * @param mixed  $order   El pedido.
	 */
	private static function stamp( $product, string $reason, $order ): void {
		if ( ! $product instanceof \WC_Product || ! $order instanceof \WC_Order ) {
			return;
		}

		$product_id = (int) $product->get_stock_managed_by_id();

		if ( ! isset( self::$recorded[ $product_id ] ) ) {
			return;
		}

		Ledger::stamp( self::$recorded[ $product_id ], $reason, 'pedido #' . $order->get_order_number() );

		unset( self::$recorded[ $product_id ] );
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
	 * Descarta lo que quedó del request: los `before` sin su `set` y los movimientos sin
	 * estampar. Para los tests, como `Guard::forget_all()`.
	 */
	public static function forget_all(): void {
		self::$before   = array();
		self::$recorded = array();
	}
}
