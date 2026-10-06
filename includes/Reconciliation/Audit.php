<?php
/**
 * La fase 2 de la reconciliación: el stock contra el propio libro.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Reconciliation;

use Woonderful\StockSync\Detector\Detector;
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
