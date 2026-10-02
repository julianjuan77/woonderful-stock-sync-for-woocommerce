<?php
/**
 * El envío al final del request de un pedido.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Transport;

defined( 'ABSPATH' ) || exit;

/**
 * Lo que un pedido estampó sale al terminar su request, sin esperar la pasada (ADR-006 §5).
 */
final class Express {

	/**
	 * Cuánto se espera la respuesta, en segundos.
	 *
	 * Corto: el proceso de PHP-FPM no atiende a nadie más mientras espera. Si no contesta,
	 * las filas vuelven a la cola como cualquier envío fallido.
	 */
	public const TIMEOUT = 5;

	/**
	 * Los movimientos de este request que esperan salir.
	 *
	 * @var array<int, true>
	 */
	private static array $queued = array();

	/**
	 * Anota un movimiento para mandarlo al final del request.
	 *
	 * Con la prioridad más baja de `shutdown`: otros plugins imprimen ahí —Query Monitor, con
	 * 9—, y cerrar la conexión antes les cortaría la salida.
	 *
	 * @param int $movement_id El movimiento.
	 */
	public static function queue( int $movement_id ): void {
		self::$queued[ $movement_id ] = true;

		add_action( 'shutdown', array( self::class, 'on_shutdown' ), PHP_INT_MAX );
	}

	/**
	 * Cierra la conexión del cliente y manda.
	 *
	 * Sin PHP-FPM no hay cómo cerrarla, y el cliente esperaría el envío: ahí lo lleva la
	 * pasada, como antes.
	 */
	public static function on_shutdown(): void {
		if ( ! function_exists( 'fastcgi_finish_request' ) ) {
			self::$queued = array();

			return;
		}

		fastcgi_finish_request();

		self::send();
	}

	/**
	 * Manda lo anotado, en un lote.
	 *
	 * @return array{sent: int, confirmed: int, failed: int, error: string|null}
	 */
	public static function send(): array {
		$ids          = array_keys( self::$queued );
		self::$queued = array();

		return Sender::run( $ids, self::TIMEOUT );
	}

	/**
	 * Los movimientos anotados. Para los tests.
	 *
	 * @return int[]
	 */
	public static function queued(): array {
		return array_keys( self::$queued );
	}

	/**
	 * Descarta lo anotado. Para los tests, como `Detector::forget_all()`.
	 */
	public static function forget_all(): void {
		self::$queued = array();
	}
}
