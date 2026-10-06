<?php
/**
 * La fase 2 de la reconciliación: el stock contra el propio libro.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Reconciliation;

use Woonderful\StockSync\Detector\Detector;
use Woonderful\StockSync\Detector\Guard;
use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Ledger\Ledger;
use Woonderful\StockSync\Linking\Links;
use Woonderful\StockSync\Plugin;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Pass;

defined( 'ABSPATH' ) || exit;

/**
 * Cada tienda compara su stock con lo que dice su libro (ADR-010).
 *
 * Nunca compara una tienda con la otra: una venta en camino dejaría los dos números distintos
 * sin que nada esté mal. Y no arregla nada: no sabe cuál de los dos números es el bueno.
 */
final class Audit {

	/**
	 * La segunda revisión de lo que no cerró.
	 */
	public const CONFIRM = 'wss_audit_confirm';

	/**
	 * Cuánto espera la segunda revisión, en segundos (ADR-010 §3).
	 */
	public const CONFIRM_AFTER = 120;

	/**
	 * Lo que dice el libro: el stock base, más lo propio, más lo recibido que se aplicó, todo
	 * de ese vínculo y su generación (ADR-010 §1).
	 *
	 * Lo propio entra entero, se haya entregado o no: ya cambió el stock de acá. De lo
	 * recibido, sólo lo aplicado: lo retenido o descartado no lo tocó.
	 *
	 * @param \stdClass $link El vínculo.
	 */
	public static function expected( \stdClass $link ): int {
		global $wpdb;

		$table = Installer::table( 'movements' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sum = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( delta ), 0 ) FROM {$table}
				  WHERE link_id = %d AND generation = %d
				    AND ( origin_store = %s OR application = %s )",
				(int) $link->id,
				(int) $link->generation,
				Store::id(),
				Ledger::APPLIED
			)
		);
		// phpcs:enable

		return (int) $link->base_stock + (int) $sum;
	}

	/**
	 * Cuánto se aparta el stock del libro —lo que hay menos lo esperado—, o null si ese
	 * producto no se revisa (ADR-010 §2).
	 *
	 * No se revisa si tiene movimientos retenidos, si su bootstrap sigue sin resolver, o si el
	 * producto de acá no lleva su propio stock: en los tres casos el stock no tiene por qué
	 * coincidir.
	 *
	 * @param \stdClass            $link       El vínculo.
	 * @param array<int, int>|null $unresolved Las generaciones sin resolver, por clave, si ya
	 *                                         se buscaron: al revisar todos se buscan una vez.
	 *
	 * @return array{expected: int, found: int}|null
	 */
	public static function measure( \stdClass $link, ?array $unresolved = null ): ?array {
		$product = wc_get_product( (int) $link->local_product );

		if ( ! $product instanceof \WC_Product || 'trash' === $product->get_status() || ! Links::holds_own_stock( $product ) ) {
			return null;
		}

		$unresolved = $unresolved ?? array_flip( Books::unresolved_generations() );

		if ( isset( $unresolved[ (int) $link->generation ] ) || self::has_held( (int) $link->id ) ) {
			return null;
		}

		return array(
			'expected' => self::expected( $link ),
			'found'    => Detector::stock_in_db( (int) $link->local_product ),
		);
	}

	/**
	 * Revisa todos los vínculos.
	 *
	 * - Si da bien y había una abierta, la cierra.
	 * - Si una abierta sigue con la misma diferencia, anota que se la vio.
	 * - Si no da, o una abierta cambió de diferencia, **no abre nada todavía**: la vuelve a
	 *   revisar en 2 minutos, para no tomar por discrepancia una venta que se está anotando
	 *   (ADR-010 §3).
	 *
	 * @return array{checked: int, closed: int, suspected: int} Cuántos se revisaron, cuántas
	 *         se cerraron y cuántas quedaron para la segunda revisión.
	 */
	public static function run(): array {
		$summary   = array(
			'checked'   => 0,
			'closed'    => 0,
			'suspected' => 0,
		);
		$suspected  = array();
		$unresolved = array_flip( Books::unresolved_generations() );

		foreach ( self::all_links() as $link ) {
			$measure = self::measure( $link, $unresolved );

			if ( null === $measure ) {
				continue;
			}

			++$summary['checked'];

			$difference = $measure['found'] - $measure['expected'];
			$open       = null !== $link->expected_stock;

			if ( 0 === $difference ) {
				if ( $open ) {
					self::close( (int) $link->id );
					++$summary['closed'];
				}

				continue;
			}

			if ( $open && (int) $link->found_stock - (int) $link->expected_stock === $difference ) {
				self::write( (int) $link->id, $measure, false );

				continue;
			}

			$suspected[] = array( (int) $link->id, $difference );
		}

		if ( array() !== $suspected ) {
			$summary['suspected'] = count( $suspected );

			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + self::CONFIRM_AFTER, self::CONFIRM, array( $suspected ), Pass::GROUP );
			}
		}

		return $summary;
	}

	/**
	 * La segunda revisión: abre la discrepancia de los que siguen con la misma diferencia.
	 *
	 * Lo que llega viene de los argumentos guardados en Action Scheduler: se revisa como
	 * cualquier entrada.
	 *
	 * @param mixed $suspected Pares de vínculo y diferencia de la primera revisión.
	 */
	public static function confirm( $suspected ): void {
		if ( null !== Plugin::suspension_reason() || ! is_array( $suspected ) ) {
			return;
		}

		foreach ( $suspected as $pair ) {
			$link = is_array( $pair ) ? Links::find( (int) ( $pair[0] ?? 0 ) ) : null;

			if ( null === $link ) {
				continue;
			}

			$measure  = self::measure( $link );
			$expected = (int) ( $pair[1] ?? 0 );

			if ( null === $measure || 0 === $expected || ( $measure['found'] - $measure['expected'] ) !== $expected ) {
				continue;
			}

			// Una abierta con otra diferencia es otra discrepancia: arranca de nuevo.
			$same = null !== $link->expected_stock && (int) $link->found_stock - (int) $link->expected_stock === $expected;

			self::write( (int) $link->id, $measure, ! $same );
		}
	}

	/**
	 * "El stock de acá está bien": anota la diferencia como un ajuste, sin tocar el stock de
	 * acá. El ajuste viaja a la otra tienda como cualquier otro (ADR-010 §6).
	 *
	 * @param int $product Producto de esta tienda.
	 *
	 * @return true|\WP_Error
	 */
	public static function accept( int $product ) {
		$checked = self::recheck( $product );

		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		list( $link, $difference ) = $checked;

		if ( null === Ledger::record( $product, $difference, 'adjustment', __( 'discrepancia aceptada', 'woonderful-stock-sync-for-woocommerce' ) ) ) {
			return new \WP_Error( 'wss_not_recorded', __( 'No se pudo anotar el ajuste.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		self::close( (int) $link->id );

		return true;
	}

	/**
	 * "El libro está bien": le suma al stock de acá la diferencia, sin anotar un movimiento.
	 * La otra tienda no se entera: ya tenía el número bueno (ADR-010 §6).
	 *
	 * **Suma la diferencia; no escribe el número esperado.** Escribirlo pisaría una venta que
	 * entrara justo en el medio: quedaría en el libro y no en el stock. Con `increase` o
	 * `decrease`, WooCommerce escribe `meta_value = meta_value + x`, y las dos se suman.
	 *
	 * @param int $product Producto de esta tienda.
	 *
	 * @return true|\WP_Error
	 */
	public static function restore( int $product ) {
		$checked = self::recheck( $product );

		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		list( $link, $difference ) = $checked;

		// El guard hace que el detector no lo anote: no es un movimiento, es volver al libro.
		Guard::expect( $product );
		wc_update_product_stock( $product, abs( $difference ), $difference < 0 ? 'increase' : 'decrease' );

		self::close( (int) $link->id );

		return true;
	}

	/**
	 * Vuelve a hacer la cuenta antes de resolver una discrepancia.
	 *
	 * No usa los números guardados, que pueden tener días: vuelve a mirar las exclusiones, a
	 * calcular, y **se niega si la diferencia no es la que vio el dueño**. Así tampoco se
	 * anota un ajuste equivocado si justo entró una venta.
	 *
	 * @param int $product Producto de esta tienda.
	 *
	 * @return array{0: \stdClass, 1: int}|\WP_Error El vínculo y la diferencia —lo que hay
	 *                                              menos lo esperado—.
	 */
	private static function recheck( int $product ) {
		if ( null !== Plugin::suspension_reason() ) {
			return new \WP_Error( 'wss_suspended', __( 'La sincronización está suspendida en esta tienda.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		$link = Links::for_product( $product );

		if ( null === $link || null === $link->expected_stock ) {
			return new \WP_Error( 'wss_no_discrepancy', __( 'Ese producto no tiene una discrepancia abierta.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		$measure = self::measure( $link );

		if ( null === $measure ) {
			return new \WP_Error( 'wss_not_checkable', __( 'Ese producto no se puede revisar ahora: tiene movimientos retenidos, el vínculo sin confirmar, o no lleva su propio stock.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		$difference = $measure['found'] - $measure['expected'];

		// Una abierta nunca guarda diferencia cero: si coincide con la guardada, no es cero.
		if ( $difference !== (int) $link->found_stock - (int) $link->expected_stock ) {
			return new \WP_Error( 'wss_changed', __( 'La diferencia cambió desde que la viste. Mirala de nuevo con wp wss discrepancies.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		return array( $link, $difference );
	}

	/**
	 * Las discrepancias abiertas de esta tienda, para mostrarlas.
	 *
	 * @return array<int, array{product: int, expected: int, found: int, since: string, seen_at: string}>
	 */
	public static function open(): array {
		global $wpdb;

		$table = Installer::table( 'links' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( "SELECT local_product, expected_stock, found_stock, discrepancy_since, discrepancy_seen_at FROM {$table} WHERE expected_stock IS NOT NULL ORDER BY local_product" );

		return array_map(
			static fn ( $row ) => array(
				'product'  => (int) $row->local_product,
				'expected' => (int) $row->expected_stock,
				'found'    => (int) $row->found_stock,
				'since'    => (string) $row->discrepancy_since,
				'seen_at'  => (string) $row->discrepancy_seen_at,
			),
			$rows
		);
	}

	/**
	 * Cierra la discrepancia de un vínculo.
	 *
	 * @param int $link_id El vínculo.
	 */
	public static function close( int $link_id ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Installer::table( 'links' ),
			array(
				'expected_stock'      => null,
				'found_stock'         => null,
				'discrepancy_since'   => null,
				'discrepancy_seen_at' => null,
			),
			array( 'id' => $link_id )
		);
	}

	/**
	 * Escribe los números de una discrepancia.
	 *
	 * @param int                              $link_id El vínculo.
	 * @param array{expected: int, found: int} $measure Lo que se midió.
	 * @param bool                             $opening Si es nueva: entonces también "desde".
	 */
	private static function write( int $link_id, array $measure, bool $opening ): void {
		global $wpdb;

		$now     = gmdate( 'Y-m-d H:i:s' );
		$columns = array(
			'expected_stock'      => $measure['expected'],
			'found_stock'         => $measure['found'],
			'discrepancy_seen_at' => $now,
		);

		if ( $opening ) {
			$columns['discrepancy_since'] = $now;
		}

		$wpdb->update( Installer::table( 'links' ), $columns, array( 'id' => $link_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Si el vínculo tiene movimientos recibidos retenidos.
	 *
	 * @param int $link_id El vínculo.
	 */
	private static function has_held( int $link_id ): bool {
		global $wpdb;

		$table = Installer::table( 'movements' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE link_id = %d AND application = %s LIMIT 1", $link_id, Ledger::HELD ) );
		// phpcs:enable

		return null !== $found;
	}

	/**
	 * Todos los vínculos.
	 *
	 * @return \stdClass[]
	 */
	private static function all_links(): array {
		global $wpdb;

		$table = Installer::table( 'links' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id" );
	}
}
