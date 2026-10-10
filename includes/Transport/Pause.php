<?php
/**
 * La pausa de la sincronización.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Transport;

use Woonderful\StockSync\Log\Events;

defined( 'ABSPATH' ) || exit;

/**
 * La pausa es del par (ADR-015 §1, §2): pausar acá le avisa a la otra con `POST /pause`, y
 * la otra se marca igual. En pausa el libro sigue anotando; nadie manda, aplica, reconcilia
 * ni vincula.
 */
final class Pause {

	/**
	 * La opción: `{at, by, user}`, o no existe.
	 */
	public const OPTION = 'wss_paused';

	/**
	 * La pausó alguien en esta tienda.
	 */
	public const HERE = 'here';

	/**
	 * La pausó la otra tienda.
	 */
	public const PEER = 'peer';

	/**
	 * La ruta en la otra tienda.
	 */
	public const ROUTE = '/pause';

	/**
	 * Si está en pausa.
	 */
	public static function is_on(): bool {
		return null !== self::current();
	}

	/**
	 * La pausa —cuándo, en UTC; quién la pidió, `here` o `peer`; y el usuario—, o null.
	 *
	 * @return array{at: string, by: string, user: int|null}|null
	 */
	public static function current(): ?array {
		$stored = get_option( self::OPTION );

		if ( ! is_array( $stored ) || ! is_string( $stored['at'] ?? null ) ) {
			return null;
		}

		return array(
			'at'   => $stored['at'],
			'by'   => self::PEER === ( $stored['by'] ?? null ) ? self::PEER : self::HERE,
			'user' => isset( $stored['user'] ) ? (int) $stored['user'] : null,
		);
	}

	/**
	 * Pausa o reanuda acá y se lo avisa a la otra.
	 *
	 * Si la otra no responde, queda igual acá: el `409 wss_paused` le avisa en su próximo
	 * envío. Al reanudar, la próxima pasada manda lo anotado.
	 *
	 * @param bool $paused Pausar o reanudar.
	 *
	 * @return bool Si la otra se enteró.
	 */
	public static function toggle( bool $paused ): bool {
		self::set( $paused, self::HERE );

		$peer = Peers::only();

		return null !== $peer && Peers::tell( $peer, self::ROUTE, array( 'paused' => $paused ) );
	}

	/**
	 * Guarda el estado y lo anota en el log, si cambió.
	 *
	 * @param bool   $paused Pausar o reanudar.
	 * @param string $by     `here` o `peer`.
	 *
	 * @return bool Si cambió.
	 */
	public static function set( bool $paused, string $by ): bool {
		if ( self::is_on() === $paused ) {
			return false;
		}

		if ( $paused ) {
			$user = get_current_user_id();

			update_option(
				self::OPTION,
				array(
					'at'   => gmdate( 'Y-m-d H:i:s' ),
					'by'   => $by,
					'user' => 0 === $user ? null : $user,
				),
				true
			);
		} else {
			delete_option( self::OPTION );
		}

		Events::record( $paused ? Events::PAUSED : Events::RESUMED, Events::INFO, null, array( 'by' => $by ) );

		return true;
	}

	/**
	 * Lo que contesta esta tienda en pausa a quien le manda algo.
	 */
	public static function refusal(): \WP_Error {
		return new \WP_Error( 'wss_paused', __( 'La sincronización está en pausa.', 'woonderful-stock-sync-for-woocommerce' ), array( 'status' => 409 ) );
	}
}
