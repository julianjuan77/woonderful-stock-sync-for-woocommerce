<?php
/**
 * El libro de movimientos.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Ledger;

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Linking\Links;
use Woonderful\StockSync\Schema\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Escribe y lee los movimientos (ADR-001, ADR-004).
 */
final class Ledger {

	/**
	 * Segundos que un movimiento espera antes de que la cola pueda entregarlo.
	 *
	 * Le da tiempo al hook del pedido a estampar el motivo, y hace que una ráfaga de
	 * importación se agrupe sola en vez de salir de a uno (ADR-006 §4).
	 */
	public const DELIVERY_DELAY = 10;

	/**
	 * Registra un movimiento propio.
	 *
	 * @param int         $product_id ID del producto en esta tienda.
	 * @param int         $delta      Unidades, con signo.
	 * @param string      $reason     Motivo; el detector lo estampa después si lo sabe.
	 * @param string|null $reference  Con qué se audita: el número de pedido, etc.
	 *
	 * @return int|null ID del movimiento, o null si no había nada que registrar.
	 */
	public static function record( int $product_id, int $delta, string $reason = 'adjustment', ?string $reference = null ): ?int {
		if ( 0 === $delta ) {
			return null;
		}

		$link = Links::for_product( $product_id );

		// Un producto sin vincular no está en la malla: no hay a quién contarle el cambio
		// ni contra qué generación contarlo (UC-03).
		if ( null === $link ) {
			return null;
		}

		global $wpdb;

		$table = Installer::table( 'movements' );
		$now   = time();

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB

		// origin_seq queda en NULL a propósito: la secuencia de un movimiento propio es su
		// AUTO_INCREMENT, que no existe hasta después del INSERT (ADR-004 §1).
		$inserted = $wpdb->insert(
			$table,
			array(
				'origin_store'    => Store::id(),
				'generation'      => (int) $link->generation,
				'delta'           => $delta,
				'reason'          => $reason,
				'reference'       => $reference,
				'created_at'      => gmdate( 'Y-m-d H:i:s', $now ),
				'link_id'         => (int) $link->id,
				'origin_product'  => $product_id,
				'delivery'        => 'pending',
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', $now + self::DELIVERY_DELAY ),
			)
		);

		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB

			return null;
		}

		$movement_id = (int) $wpdb->insert_id;

		$wpdb->update( $table, array( 'origin_seq' => $movement_id ), array( 'id' => $movement_id ) );

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB

		return $movement_id;
	}
}
