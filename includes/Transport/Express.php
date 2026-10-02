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
		$finish = self::finisher();

		if ( null === $finish || ! is_callable( $finish ) ) {
			self::$queued = array();

			return;
		}

		$finish();

		self::send();
	}

	/**
	 * La función que cierra la conexión del cliente, o null si este servidor no tiene.
	 *
	 * LiteSpeed tuvo `fastcgi_finish_request()` como alias y la sacó: la suya es la otra.
	 *
	 * @param callable(string): bool|null $exists Cómo se pregunta si existe. Para los tests.
	 */
	public static function finisher( ?callable $exists = null ): ?string {
		$exists = $exists ?? 'function_exists';

		foreach ( array( 'fastcgi_finish_request', 'litespeed_finish_request' ) as $function ) {
			if ( $exists( $function ) ) {
				return $function;
			}
		}

		return null;
	}

	/**
	 * Manda lo anotado, en un lote.
	 *
	 * Si un envío falló hace menos de una pasada, no: lo lleva la pasada (ADR-006 §5).
	 *
	 * @return array{sent: int, confirmed: int, failed: int, error: string|null}|null Lo que
	 *         pasó, o null si no se intentó.
	 */
	public static function send(): ?array {
		$ids          = array_keys( self::$queued );
		self::$queued = array();

		if ( false !== get_transient( Sender::FAILED ) ) {
			return null;
		}

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
