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
 * El snapshot del libro. Ver ADR-001 y ARCHITECTURE.md §1.
 *
 * Guarda la parte fija del stock (`base_qty`) y hasta dónde está consolidada
 * (`base_up_to`). La parte móvil la calcula MovementRepository; componer las dos es
 * trabajo del reconciliador, no de los repositorios.
 */
class ProductStateRepository {

	/** Fija el stock base de un SKU que entra a la malla (UC-07). Upsert: un recuento físico corrige el base. */
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
	 * Avanza el base absorbiendo los deltas que están por purgarse.
	 *
	 * El stock esperado tiene que dar el mismo número antes y después de purgar: ésa es la
	 * restricción dura de ADR-001. Por eso el UPDATE es relativo (`base_qty + %d`) y no
	 * absoluto, y el WHERE exige avanzar: aplicar dos veces la misma consolidación
	 * restaría stock inventado.
	 *
	 * `$absorbed_delta` es la suma de los deltas que se van a borrar; `$new_up_to`, el
	 * último ID que queda consolidado.
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

	/** Estado de un SKU, o null si nunca entró a la malla. */
	public function get( string $sku ): ?object {
		global $wpdb;

		$table = Schema::product_state_table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM $table WHERE sku = %s", $sku )
		);

		return $row instanceof \stdClass ? $row : null;
	}

	/** Un cambio de stock en un SKU sin bootstrap no se propaga: se bootstrapea (UC-07). */
	public function is_bootstrapped( string $sku ): bool {
		return null !== $this->get( $sku );
	}

	/**
	 * Todos los SKU de la malla. Paginado: la reconciliación recorre el catálogo entero.
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

	/** Saca un SKU de la malla. No borra sus movimientos: el libro es inmutable. */
	public function forget( string $sku ): bool {
		global $wpdb;

		$rows = $wpdb->delete( Schema::product_state_table(), array( 'sku' => $sku ), array( '%s' ) );

		return 1 === (int) $rows;
	}
}
