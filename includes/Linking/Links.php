<?php
/**
 * Los vínculos entre productos de las dos tiendas.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Linking;

use Woonderful\StockSync\Schema\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Lectura de `wss_links` (ADR-005).
 */
final class Links {

	/**
	 * El vínculo de un producto local, o null si no tiene.
	 *
	 * @param int $product_id ID del producto en esta tienda.
	 *
	 * @return object|null Fila de wss_links.
	 */
	public static function for_product( int $product_id ): ?object {
		global $wpdb;

		$table = Installer::table( 'links' );

		// El nombre de la tabla no puede ir como placeholder; sale de $wpdb->prefix y no
		// de una entrada. El dato sí va preparado.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE local_product = %d", $product_id )
		);
		// phpcs:enable

		return $row instanceof \stdClass ? $row : null;
	}
}
