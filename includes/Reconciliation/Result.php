<?php
/**
 * El resultado de la última reconciliación.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Reconciliation;

defined( 'ABSPATH' ) || exit;

/**
 * Cómo terminó la última reconciliación, en cada tienda (ADR-009 §7).
 *
 * Las dos lo guardan, cada una con lo suyo: así la secundaria lo puede mostrar aunque no la
 * lance.
 */
final class Result {

	/**
	 * La opción. No autocargada: sólo la lee la pantalla.
	 */
	public const OPTION = 'wss_last_reconciliation';

	/**
	 * La lanzó la maestra, de noche.
	 */
	public const NIGHTLY = 'nightly';

	/**
	 * La lanzó el dueño.
	 */
	public const MANUAL = 'manual';

	/**
	 * La lanzó el cierre de una importación.
	 */
	public const IMPORT = 'import';

	/**
	 * La lanzó la otra tienda: ésta contestó.
	 */
	public const PEER = 'peer';

	/**
	 * Corrió entera.
	 */
	public const DONE = 'done';

	/**
	 * La otra tienda no respondió: no se pudo mirar, que no es lo mismo que no había nada.
	 */
	public const UNREACHABLE = 'unreachable';

	/**
	 * Había otra en curso.
	 */
	public const BUSY = 'busy';

	/**
	 * Guarda el resultado.
	 *
	 * @param string               $trigger Quién la lanzó.
	 * @param string               $outcome Cómo terminó.
	 * @param array<string, mixed> $details Lo que hizo: cuántas reencoló, cuántas confirmó.
	 */
	public static function save( string $trigger, string $outcome, array $details = array() ): void {
		update_option(
			self::OPTION,
			array(
				'at'      => gmdate( 'Y-m-d H:i:s' ),
				'trigger' => $trigger,
				'outcome' => $outcome,
				'details' => $details,
			),
			false
		);
	}

	/**
	 * El de la última, o null si nunca corrió.
	 *
	 * @return array{at: string, trigger: string, outcome: string, details: array<string, mixed>}|null
	 */
	public static function last(): ?array {
		$value = get_option( self::OPTION, null );

		if ( ! is_array( $value ) || ! isset( $value['at'], $value['trigger'], $value['outcome'] ) ) {
			return null;
		}

		return array(
			'at'      => (string) $value['at'],
			'trigger' => (string) $value['trigger'],
			'outcome' => (string) $value['outcome'],
			'details' => is_array( $value['details'] ?? null ) ? $value['details'] : array(),
		);
	}
}
