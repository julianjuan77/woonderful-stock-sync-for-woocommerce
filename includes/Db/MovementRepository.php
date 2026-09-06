<?php
/**
 * Acceso a la tabla de movimientos.
 *
 * @package Woonderful\StockSync
 */

namespace Woonderful\StockSync\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * El libro de movimientos. Ver ADR-001 y ARCHITECTURE.md §1.
 *
 * Las dos invariantes las hace cumplir MySQL, no este código: la idempotencia con el índice
 * único, y la ejecución única con el UPDATE condicionado de claim().
 */
class MovementRepository {

	public const STATUS_PENDING  = 'pending';
	public const STATUS_CLAIMED  = 'claimed';
	public const STATUS_APPLIED  = 'applied';
	public const STATUS_REJECTED = 'rejected';

	/**
	 * Razones válidas. Son identificadores, no texto: la pantalla de UC-05 los mapea a
	 * etiquetas traducidas. `refund` cubre devoluciones y cancelaciones (UC-02).
	 */
	public const REASONS = array( 'bootstrap', 'sale', 'refund', 'adjustment', 'reconciliation' );

	/** Tras esto, un claim se considera un job muerto y otro puede retomarlo. */
	public const STALE_CLAIM_SECONDS = 300;

	/**
	 * Registra un movimiento. Devuelve el ID insertado, o 0 si era duplicado.
	 *
	 * @param array{origin_store: string, sequence: int, sku: string, delta: int, reason: string, reference?: ?string, created_at?: string} $movement Movimiento.
	 *
	 * @throws \InvalidArgumentException Si la razón no es una de las conocidas.
	 * @throws \RuntimeException         Si la base falla por algo que no es el duplicado.
	 */
	public function record( array $movement ): int {
		global $wpdb;

		// Una razón desconocida es un bug del emisor; guardarla contamina el libro, que es
		// la fuente de verdad para auditar.
		if ( ! in_array( $movement['reason'], self::REASONS, true ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Unknown movement reason: %s', $movement['reason'] )
			);
		}

		$table = Schema::movements_table();
		$now   = current_time( 'mysql', true );

		/*
		 * INSERT IGNORE: el duplicado es un caso esperado —el mismo movimiento puede llegar
		 * dos veces si se reintenta un envío— y no un error. No hay SELECT previo porque
		 * tendría una carrera entre la consulta y el INSERT.
		 *
		 * El costo es que IGNORE degrada otros errores a warning, así que después se mira
		 * last_error para no confundir un fallo real con un duplicado.
		 */
		$wpdb->last_error = '';

		$rows = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO $table
					(origin_store, `sequence`, sku, delta, reason, reference, created_at, received_at, status)
				 VALUES (%s, %d, %s, %d, %s, %s, %s, %s, %s)",
				$movement['origin_store'],
				$movement['sequence'],
				$movement['sku'],
				$movement['delta'],
				$movement['reason'],
				$movement['reference'] ?? null,
				$movement['created_at'] ?? $now,
				$now,
				self::STATUS_PENDING
			)
		);

		if ( false === $rows ) {
			throw new \RuntimeException( sprintf( 'Could not record movement: %s', $wpdb->last_error ) );
		}

		return 0 === (int) $rows ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Toma un movimiento para aplicarlo, en exclusión mutua. true si este proceso se lo quedó.
	 *
	 * Dos pasadas concurrentes ejecutan el mismo UPDATE y MySQL garantiza que solo una
	 * afecte la fila. Quien ve 0 filas sabe que otro se lo llevó, sin bloqueos.
	 */
	public function claim( int $id, int $stale_after = self::STALE_CLAIM_SECONDS ): bool {
		global $wpdb;

		$table  = Schema::movements_table();
		$now    = current_time( 'mysql', true );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $stale_after );

		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table
					SET status = %s, claimed_at = %s
				 WHERE id = %d
				   AND ( status = %s OR ( status = %s AND claimed_at < %s ) )",
				self::STATUS_CLAIMED,
				$now,
				$id,
				self::STATUS_PENDING,
				self::STATUS_CLAIMED,
				$cutoff
			)
		);

		return 1 === (int) $rows;
	}

	/** Cierra un movimiento tomado como aplicado. */
	public function mark_applied( int $id ): bool {
		return $this->close( $id, self::STATUS_APPLIED );
	}

	/** Cierra un movimiento tomado como rechazado. */
	public function mark_rejected( int $id ): bool {
		return $this->close( $id, self::STATUS_REJECTED );
	}

	/** Cierra un movimiento tomado, con el estado final que corresponda. */
	private function close( int $id, string $status ): bool {
		global $wpdb;

		$table = Schema::movements_table();

		// El WHERE exige 'claimed': cerrar algo que no se tomó es un bug de secuencia y
		// tiene que devolver false en vez de pasar desapercibido.
		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table
					SET status = %s, applied_at = %s, claimed_at = NULL
				 WHERE id = %d AND status = %s",
				$status,
				current_time( 'mysql', true ),
				$id,
				self::STATUS_CLAIMED
			)
		);

		return 1 === (int) $rows;
	}

	/**
	 * Movimientos con trabajo disponible, en orden de llegada.
	 *
	 * Incluye los claims vencidos: si no aparecieran, un job que murió a mitad dejaría su
	 * movimiento sin aplicar para siempre.
	 *
	 * @return array<int, object>
	 */
	public function pending( int $limit = 50, int $stale_after = self::STALE_CLAIM_SECONDS ): array {
		global $wpdb;

		$table  = Schema::movements_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $stale_after );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table
				 WHERE status = %s
				    OR ( status = %s AND claimed_at < %s )
				 ORDER BY id ASC
				 LIMIT %d",
				self::STATUS_PENDING,
				self::STATUS_CLAIMED,
				$cutoff,
				$limit
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Suma de los deltas aplicados de un SKU posteriores a un ID.
	 *
	 * Solo cuenta lo aplicado: lo pendiente todavía no movió stock local, e incluirlo haría
	 * que la reconciliación reporte desvíos que no existen.
	 */
	public function sum_applied_after( string $sku, int $after_id ): int {
		global $wpdb;

		$table = Schema::movements_table();

		$sum = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( delta ), 0 ) FROM $table
				 WHERE sku = %s AND id > %d AND status = %s",
				$sku,
				$after_id,
				self::STATUS_APPLIED
			)
		);

		return (int) $sum;
	}

	/** El ID aplicado más alto de un SKU: hasta dónde puede consolidar el purgado. */
	public function max_applied_id( string $sku ): int {
		global $wpdb;

		$table = Schema::movements_table();

		$max = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( MAX( id ), 0 ) FROM $table
				 WHERE sku = %s AND status = %s",
				$sku,
				self::STATUS_APPLIED
			)
		);

		return (int) $max;
	}

	/**
	 * Borra los movimientos aplicados de un SKU hasta un ID inclusive. Devuelve cuántos.
	 *
	 * ⚠️ Peligroso solo: hay que consolidar el base con ProductStateRepository::absorb()
	 * ANTES de borrar. Al revés, el stock esperado cambia de valor y la reconciliación
	 * reporta discrepancias falsas en todos los productos con historia.
	 */
	public function delete_applied_up_to( string $sku, int $up_to_id ): int {
		global $wpdb;

		$table = Schema::movements_table();

		$rows = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table
				 WHERE sku = %s AND id <= %d AND status = %s",
				$sku,
				$up_to_id,
				self::STATUS_APPLIED
			)
		);

		return (int) $rows;
	}

	/**
	 * Historial de un SKU, lo más reciente primero. Alimenta la pantalla de UC-05.
	 *
	 * @return array<int, object>
	 */
	public function history( string $sku, int $limit = 50 ): array {
		global $wpdb;

		$table = Schema::movements_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table
				 WHERE sku = %s
				 ORDER BY id DESC
				 LIMIT %d",
				$sku,
				$limit
			)
		);

		return is_array( $rows ) ? $rows : array();
	}
}
