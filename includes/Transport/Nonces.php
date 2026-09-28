<?php
/**
 * El almacén de nonces.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Transport;

use Woonderful\StockSync\Schema\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Rechaza un request reenviado: cada nonce se acepta una sola vez (ADR-001 §1, ADR-004 §3).
 *
 * Se llama **después** de que la firma verifica. Antes, cualquiera sin el secreto podría
 * llenar la tabla con requests de firma inventada.
 */
final class Nonces {

	/**
	 * Cuánto se guarda un nonce, en segundos: dos ventanas.
	 *
	 * La ventana acepta un timestamp adelantado hasta 5 minutos, y ese request la sigue
	 * pasando 5 minutos más: hasta 10 después de recibido. Con menos, el reenvío de un
	 * emisor con el reloj adelantado encontraría el nonce ya borrado (ADR-001 §1).
	 */
	public const RETENTION = 2 * Signature::WINDOW;

	/**
	 * El largo de la columna.
	 */
	private const MAX_LENGTH = 64;

	/**
	 * Anota el nonce y responde si era nuevo.
	 *
	 * Una sola sentencia decide: la clave primaria deja entrar al primero y frena al
	 * segundo, aunque los dos lleguen a la vez (ADR-004 §3). `INSERT IGNORE` y no un
	 * `INSERT` pelado porque el repetido no es un error: sin él, cada reenvío dejaría un
	 * error de base de datos en el log.
	 *
	 * @param string $nonce El de la cabecera.
	 * @param int    $now   El reloj propio.
	 */
	public static function claim( string $nonce, int $now ): bool {
		// Más largo que la columna, `INSERT IGNORE` lo recortaría en silencio: dos nonces
		// distintos con el mismo principio chocarían.
		if ( '' === $nonce || strlen( $nonce ) > self::MAX_LENGTH ) {
			return false;
		}

		global $wpdb;

		$table = Installer::table( 'nonces' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$written = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (nonce, seen_at) VALUES (%s, %s)",
				$nonce,
				gmdate( 'Y-m-d H:i:s', $now )
			)
		);
		// phpcs:enable

		// false es un error de verdad —la tabla no está—, y cuenta como visto: ante la
		// duda, el request se rechaza.
		return 1 === $written;
	}

	/**
	 * Borra los nonces que ya no pueden servir.
	 *
	 * @param int $now El reloj propio.
	 *
	 * @return int Cuántos borró.
	 */
	public static function purge( int $now ): int {
		global $wpdb;

		$table = Installer::table( 'nonces' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE seen_at < %s",
				gmdate( 'Y-m-d H:i:s', $now - self::RETENTION )
			)
		);
		// phpcs:enable

		return (int) $deleted;
	}
}
