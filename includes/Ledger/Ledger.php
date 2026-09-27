<?php
/**
 * El libro de movimientos.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Ledger;

use Woonderful\StockSync\Detector\Guard;
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
	 * Se aplicó al stock local.
	 */
	public const APPLIED = 'applied';

	/**
	 * Guardado sin aplicar, a la espera de que el par vuelva (UC-03).
	 */
	public const HELD = 'held';

	/**
	 * No se va a aplicar nunca: sin vínculo, o de una generación que ya no corre.
	 */
	public const DISCARDED = 'discarded';

	/**
	 * Aplica un movimiento que llegó de la otra tienda.
	 *
	 * Los cinco caminos, en este orden: si ya está en el libro no se toca nada —un reenvío
	 * no puede descontar dos veces—; sin vínculo o con una generación que ya no corre se
	 * guarda como `discarded`; si el producto local no está se guarda como `held`, que es
	 * reintentable; y si todo está bien se descuenta el stock.
	 *
	 * En los cinco casos el emisor recibe su confirmación: el ACK dice "lo tengo", no "lo
	 * apliqué" (ADR-001 §3).
	 *
	 * @param array<string, mixed> $movement El movimiento como viene en el JSON.
	 *
	 * @return string Estado de aplicación: applied, held o discarded.
	 */
	public static function apply( array $movement ): string {
		global $wpdb;

		$origin  = (string) ( $movement['origin'] ?? '' );
		$seq     = (int) ( $movement['seq'] ?? 0 );
		$product = (int) ( $movement['product'] ?? 0 );
		$delta   = (int) ( $movement['delta'] ?? 0 );

		$known = self::application_state_of( $origin, $seq );

		if ( null !== $known ) {
			return $known;
		}

		$link       = Links::for_remote_product( $origin, $product );
		$local_id   = null === $link ? 0 : (int) $link->local_product;
		$local      = 0 === $local_id ? null : wc_get_product( $local_id );
		$generation = null === $link ? 0 : (int) $link->generation;

		if ( null === $link || (int) ( $movement['generation'] ?? 0 ) !== $generation ) {
			// Sin vínculo no hay a qué producto aplicarlo; de una generación vieja, no se
			// va a aplicar nunca (ADR-005 §4).
			$state = self::DISCARDED;
		} elseif ( ! $local instanceof \WC_Product || ! $local->managing_stock() ) {
			// Guardado y reintentable: descartarlo perdería stock en silencio (UC-03).
			$state = self::HELD;
		} else {
			$state = self::APPLIED;
		}

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB

		// La fila primero: es el registro que deduplica. Si se cambiara el stock y el
		// proceso muriera antes de escribirla, el movimiento se reentregaría y se
		// aplicaría de nuevo.
		$inserted = $wpdb->insert(
			Installer::table( 'movements' ),
			array(
				'origin_store'   => $origin,
				'origin_seq'     => $seq,
				'generation'     => (int) ( $movement['generation'] ?? 0 ),
				'delta'          => $delta,
				'reason'         => (string) ( $movement['reason'] ?? 'adjustment' ),
				'reference'      => $movement['reference'] ?? null,
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
				'link_id'        => null === $link ? null : (int) $link->id,
				'origin_product' => $product,
				'application'    => $state,
				'applied_at'     => self::APPLIED === $state ? gmdate( 'Y-m-d H:i:s' ) : null,
			)
		);

		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB

			// Otro request lo escribió entre la consulta y el INSERT: el UNIQUE lo
			// rechazó, que es exactamente lo que tiene que pasar.
			return (string) ( self::application_state_of( $origin, $seq ) ?? self::DISCARDED );
		}

		if ( self::APPLIED === $state && $local instanceof \WC_Product ) {
			// El cambio de stock lo provoca el plugin: sin esto el detector lo vería como
			// una venta y lo emitiría de vuelta (ADR-006 §3).
			Guard::expect( $local_id );

			wc_update_product_stock( $local, abs( $delta ), $delta < 0 ? 'decrease' : 'increase' );
		}

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB

		return $state;
	}

	/**
	 * El estado de aplicación de un movimiento que ya está en el libro, o null si no está.
	 *
	 * @param string $origin Tienda que lo emitió.
	 * @param int    $seq    Su número en esa tienda.
	 */
	private static function application_state_of( string $origin, int $seq ): ?string {
		global $wpdb;

		$table = Installer::table( 'movements' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$state = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT application FROM {$table} WHERE origin_store = %s AND origin_seq = %d",
				$origin,
				$seq
			)
		);
		// phpcs:enable

		return null === $state ? null : (string) $state;
	}

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

		$updated = $wpdb->update( $table, array( 'origin_seq' => $movement_id ), array( 'id' => $movement_id ) );

		// La transacción está para que las dos escrituras vayan juntas o no vaya ninguna:
		// confirmar con la secuencia sin llenar deja en el libro la fila que la
		// transacción tenía que impedir, pendiente de entregar y sin identificador.
		if ( false === $updated ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB

			return null;
		}

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB

		return $movement_id;
	}
}
