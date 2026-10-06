<?php
/**
 * Los pares rotos de esta tienda.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Log;

use Woonderful\StockSync\Linking\Links;
use Woonderful\StockSync\Schema\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Registra cuándo un par se rompe y cuándo vuelve (ADR-011 §7).
 *
 * Nada del plugin observa la papelera: el estado se calcula al mirarlo. Así que lo mira la
 * noche, en cada tienda y sólo sus propios productos —lo del otro lado lo registra la otra—, y
 * recuerda lo que vio en `broken_since`, no en el log, que se purga.
 */
final class Pairs {

	/**
	 * Revisa todos los vínculos de esta tienda.
	 *
	 * @return array{broken: int, restored: int} Cuántos se rompieron y cuántos volvieron.
	 */
	public static function check(): array {
		global $wpdb;

		$table   = Installer::table( 'links' );
		$summary = array(
			'broken'   => 0,
			'restored' => 0,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$links = (array) $wpdb->get_results( "SELECT id, local_product, broken_since FROM {$table}" );

		foreach ( $links as $link ) {
			$reason = self::broken_because( (int) $link->local_product );
			$was    = null !== $link->broken_since;

			if ( null !== $reason && ! $was ) {
				$wpdb->update( $table, array( 'broken_since' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $link->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				Events::record( Events::PAIR_BROKEN, Events::WARNING, (int) $link->local_product, array( 'reason' => $reason ) );
				++$summary['broken'];
			} elseif ( null === $reason && $was ) {
				$wpdb->update( $table, array( 'broken_since' => null ), array( 'id' => (int) $link->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				Events::record( Events::PAIR_RESTORED, Events::INFO, (int) $link->local_product );
				++$summary['restored'];
			}
		}

		return $summary;
	}

	/**
	 * Por qué el producto de acá rompe el par, o null si no lo rompe: los mismos motivos de
	 * este lado que usa el estado (ADR-005 §1).
	 *
	 * @param int $product Producto de esta tienda.
	 */
	private static function broken_because( int $product ): ?string {
		$local = wc_get_product( $product );

		if ( ! $local instanceof \WC_Product ) {
			return 'local_missing';
		}

		if ( 'trash' === $local->get_status() ) {
			return 'local_trashed';
		}

		return Links::holds_own_stock( $local ) ? null : 'local_not_managing_stock';
	}
}
