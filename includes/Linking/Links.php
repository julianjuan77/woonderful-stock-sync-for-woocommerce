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
 * `wss_links`: el par, su generación y su stock base (ADR-005).
 */
final class Links {

	/**
	 * El vínculo de un producto de la otra tienda, o null si no tiene.
	 *
	 * `record()` busca por el producto de acá; `apply()` recibe "el producto 456 de la
	 * tienda A" y necesita el de acá. Es para lo que ADR-004 creó el índice `remote`.
	 *
	 * @param string $remote_store   Identificador de la otra tienda.
	 * @param int    $remote_product ID del producto en esa tienda.
	 */
	public static function for_remote_product( string $remote_store, int $remote_product ): ?\stdClass {
		global $wpdb;

		$table = Installer::table( 'links' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE remote_store = %s AND remote_product = %d",
				$remote_store,
				$remote_product
			)
		);
		// phpcs:enable

		return $row instanceof \stdClass ? $row : null;
	}

	/**
	 * El vínculo de un producto local, o null si no tiene.
	 *
	 * @param int $product_id ID del producto en esta tienda.
	 *
	 * @return \stdClass|null Fila de wss_links.
	 */
	public static function for_product( int $product_id ): ?\stdClass {
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

	/**
	 * Crea un vínculo.
	 *
	 * @param int    $local_product  Producto de esta tienda.
	 * @param string $remote_store   La otra tienda.
	 * @param int    $remote_product Producto de la otra tienda.
	 * @param int    $generation     La secuencia del bootstrap que lo abre (ADR-005 §4).
	 * @param int    $base_stock     Con cuánto arranca el pool.
	 *
	 * @return int|null El ID del vínculo, o null si no se pudo escribir.
	 */
	public static function create( int $local_product, string $remote_store, int $remote_product, int $generation, int $base_stock ): ?int {
		global $wpdb;

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB
			Installer::table( 'links' ),
			array(
				'local_product'  => $local_product,
				'remote_store'   => $remote_store,
				'remote_product' => $remote_product,
				'generation'     => $generation,
				'base_stock'     => $base_stock,
				'base_set_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		return false === $inserted ? null : (int) $wpdb->insert_id;
	}

	/**
	 * Borra un vínculo. No hay baja lógica (ADR-005).
	 *
	 * @param int $link_id ID del vínculo.
	 */
	public static function delete( int $link_id ): bool {
		global $wpdb;

		return false !== $wpdb->delete( Installer::table( 'links' ), array( 'id' => $link_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * El vínculo que abrió un bootstrap, por su generación, o null.
	 *
	 * @param int $generation La secuencia del bootstrap.
	 */
	public static function for_generation( int $generation ): ?\stdClass {
		global $wpdb;

		$table = Installer::table( 'links' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE generation = %d", $generation ) );
		// phpcs:enable

		return $row instanceof \stdClass ? $row : null;
	}
}
