<?php
/**
 * El candado de la reconciliación.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Reconciliation;

defined( 'ABSPATH' ) || exit;

/**
 * Una reconciliación por vez, con un candado que vence solo (ADR-009 §4).
 *
 * Vive en una opción, pero se lee y se escribe con SQL directo: con una caché persistente,
 * `get_option()` podría devolver una copia vieja y dos procesos creerían tenerlo.
 */
final class Lock {

	/**
	 * La opción. Guarda `<token>|<vence>`.
	 */
	public const OPTION = 'wss_reconciling';

	/**
	 * Cuánto dura, en segundos. Si la reconciliación muere a mitad, la siguiente arranca
	 * cuando vence.
	 */
	public const TTL = 900;

	/**
	 * Toma el candado.
	 *
	 * Primero un `INSERT IGNORE`, que no escribe nada si la opción existe: el nombre es
	 * único. Si existe y venció, un `UPDATE` que sólo pisa ese valor vencido: si otro proceso
	 * lo tomó en el medio, el valor ya es otro y no se pisa.
	 *
	 * @param int|null $now La hora. Para los tests.
	 *
	 * @return string|null Lo que hace falta para soltarlo, o null si lo tiene otro.
	 */
	public static function acquire( ?int $now = null ): ?string {
		global $wpdb;

		$now  = $now ?? time();
		$mine = wp_generate_uuid4() . '|' . ( $now + self::TTL );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
				self::OPTION,
				$mine
			)
		);

		if ( 1 !== $inserted ) {
			$current = self::current();

			if ( null === $current || self::expires( $current ) > $now ) {
				return null;
			}

			$taken = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					$mine,
					self::OPTION,
					$current
				)
			);

			if ( 1 !== $taken ) {
				return null;
			}
		}
		// phpcs:enable

		wp_cache_delete( self::OPTION, 'options' );

		return $mine;
	}

	/**
	 * Suelta el candado, sólo si sigue siendo el propio.
	 *
	 * Una reconciliación que pasó los 15 minutos ya no lo tiene: si lo borrara sin mirar,
	 * le sacaría el candado a la que vino después.
	 *
	 * @param string $mine Lo que devolvió `acquire()`.
	 *
	 * @return bool Si era el propio y se soltó.
	 */
	public static function release( string $mine ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::OPTION,
				$mine
			)
		);

		wp_cache_delete( self::OPTION, 'options' );

		return 1 === $deleted;
	}

	/**
	 * Si alguien lo tiene, y no venció. Es lo que mira esta tienda cuando la otra le pregunta:
	 * si está reconciliando, contesta que está ocupada (ADR-009 §4).
	 *
	 * @param int|null $now La hora. Para los tests.
	 */
	public static function is_taken( ?int $now = null ): bool {
		$current = self::current();

		return null !== $current && self::expires( $current ) > ( $now ?? time() );
	}

	/**
	 * El valor guardado, leído de la base.
	 */
	private static function current(): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION )
		);

		return null === $value ? null : (string) $value;
	}

	/**
	 * Cuándo vence un valor guardado. Uno ilegible se toma como vencido: si no, nadie podría
	 * volver a tomarlo.
	 *
	 * @param string $value El valor.
	 */
	private static function expires( string $value ): int {
		$parts = explode( '|', $value );

		return 2 === count( $parts ) ? (int) $parts[1] : 0;
	}
}
