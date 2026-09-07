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
 * Lectura y escritura de la tabla wss_movements.
 *
 * Estructura de la tabla e invariantes que garantiza: docs/ARCHITECTURE.md §1.
 */
class MovementRepository {

	public const STATUS_PENDING  = 'pending';
	public const STATUS_CLAIMED  = 'claimed';
	public const STATUS_APPLIED  = 'applied';
	public const STATUS_REJECTED = 'rejected';

	/**
	 * Valores admitidos en la columna `reason`. Son identificadores, no texto para mostrar.
	 * `refund` cubre tanto devoluciones como cancelaciones.
	 */
	public const REASONS = array( 'bootstrap', 'sale', 'refund', 'adjustment', 'reconciliation' );

	/** Segundos desde `claimed_at` tras los cuales claim() vuelve a entregar el movimiento. */
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

		if ( ! in_array( $movement['reason'], self::REASONS, true ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Unknown movement reason: %s', $movement['reason'] )
			);
		}

		$table = Schema::movements_table();
		$now   = current_time( 'mysql', true );

		/*
		 * INSERT IGNORE convierte el choque contra el índice único (origin_store, sequence)
		 * en 0 filas afectadas y sin error, en vez de un fallo. Pero también degrada otros
		 * errores a warning, así que se limpia last_error antes para poder distinguir
		 * después un duplicado de un fallo real.
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
	 * Marca el movimiento como 'claimed' si está disponible. Devuelve true si lo tomó este
	 * proceso.
	 *
	 * Disponible significa: status 'pending', o status 'claimed' con `claimed_at` anterior a
	 * ahora menos $stale_after. El UPDATE lleva esa condición en el WHERE, así que si dos
	 * procesos lo ejecutan a la vez MySQL deja que solo uno afecte la fila; por eso el
	 * resultado se decide comparando las filas afectadas contra 1.
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

		// El WHERE exige status 'claimed': cerrar un movimiento que nadie tomó devuelve
		// false en vez de escribir.
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
	 * Movimientos disponibles para trabajar, del más viejo al más nuevo.
	 *
	 * Devuelve los de status 'pending' y también los 'claimed' con `claimed_at` vencido —
	 * los que quedaron tomados por un proceso que no llegó a cerrarlos.
	 *
	 * @return array<int, object> Filas completas de wss_movements.
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
	 * Suma los `delta` del SKU con id mayor a $after_id, contando solo los de status
	 * 'applied'.
	 *
	 * Los pendientes quedan afuera porque todavía no modificaron el stock de WooCommerce.
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

	/** Id más alto entre los movimientos 'applied' del SKU, o 0 si no tiene ninguno. */
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
	 * Borra los movimientos 'applied' del SKU con id menor o igual a $up_to_id. Devuelve
	 * cuántas filas borró.
	 *
	 * ⚠️ Llamar SIEMPRE después de ProductStateRepository::absorb() con el mismo corte. Los
	 * deltas que se borran acá son parte del stock esperado, y si no se sumaron antes a
	 * `base_qty` el número cambia. Ver docs/ARCHITECTURE.md §1.
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
	 * Movimientos del SKU, del más reciente al más viejo, sin filtrar por status.
	 *
	 * @return array<int, object> Filas completas de wss_movements.
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
