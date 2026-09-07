<?php
/**
 * Acceso al snapshot de estado por SKU.
 *
 * @package Woonderful\StockSync
 */

namespace Woonderful\StockSync\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lectura y escritura de la tabla wss_product_state.
 *
 * Guarda por SKU el stock base (`base_qty`) y hasta qué id de movimiento está incluido en
 * ese base (`base_up_to`). No calcula el stock esperado: eso combina esta tabla con
 * MovementRepository::sum_applied_after(). Ver docs/ARCHITECTURE.md §1.
 */
class ProductStateRepository {

	/**
	 * Fija el stock base de un SKU. Si ya existe, reemplaza sus valores en vez de fallar,
	 * para poder corregir el base tras un recuento físico.
	 */
	public function bootstrap( string $sku, int $qty, int $up_to_id ): bool {
		global $wpdb;

		$table = Schema::product_state_table();

		$rows = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $table (sku, base_qty, base_up_to, bootstrapped_at)
				 VALUES (%s, %d, %d, %s)
				 ON DUPLICATE KEY UPDATE
					base_qty = VALUES(base_qty),
					base_up_to = VALUES(base_up_to),
					bootstrapped_at = VALUES(bootstrapped_at)",
				$sku,
				$qty,
				$up_to_id,
				current_time( 'mysql', true )
			)
		);

		return false !== $rows;
	}

	/**
	 * Suma $absorbed_delta a `base_qty` y mueve `base_up_to` a $new_up_to. Devuelve true si
	 * modificó la fila.
	 *
	 * $absorbed_delta es la suma de los `delta` de los movimientos que se van a borrar, y
	 * $new_up_to el id del último de ellos. Se llama justo antes de
	 * MovementRepository::delete_applied_up_to() con el mismo corte.
	 *
	 * El UPDATE suma en vez de fijar un total, y el WHERE exige que $new_up_to sea mayor al
	 * `base_up_to` actual: así repetir la llamada no vuelve a restar los mismos deltas.
	 */
	public function absorb( string $sku, int $absorbed_delta, int $new_up_to ): bool {
		global $wpdb;

		$table = Schema::product_state_table();

		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table
					SET base_qty = base_qty + %d,
						base_up_to = %d
				 WHERE sku = %s AND base_up_to < %d",
				$absorbed_delta,
				$new_up_to,
				$sku,
				$new_up_to
			)
		);

		return 1 === (int) $rows;
	}

	/** Fila de wss_product_state del SKU, o null si no tiene ninguna. */
	public function get( string $sku ): ?object {
		global $wpdb;

		$table = Schema::product_state_table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM $table WHERE sku = %s", $sku )
		);

		return $row instanceof \stdClass ? $row : null;
	}

	/** Devuelve true si el SKU ya tiene fila en wss_product_state. */
	public function is_bootstrapped( string $sku ): bool {
		return null !== $this->get( $sku );
	}

	/**
	 * SKUs con fila en wss_product_state, en orden alfabético. Paginado porque la
	 * reconciliación recorre el catálogo completo.
	 *
	 * @return array<int, string>
	 */
	public function all_skus( int $limit = 500, int $offset = 0 ): array {
		global $wpdb;

		$table = Schema::product_state_table();

		$skus = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT sku FROM $table ORDER BY sku ASC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);

		return is_array( $skus ) ? $skus : array();
	}

	/** Borra la fila del SKU en wss_product_state. Sus movimientos quedan intactos. */
	public function forget( string $sku ): bool {
		global $wpdb;

		$rows = $wpdb->delete( Schema::product_state_table(), array( 'sku' => $sku ), array( '%s' ) );

		return 1 === (int) $rows;
	}
}
