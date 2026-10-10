<?php
/**
 * El estado de la otra tienda: anda, está caída, o rechaza.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Log;

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Transport\Pairing;
use Woonderful\StockSync\Transport\Pause;
use Woonderful\StockSync\Transport\Peers;
use Woonderful\StockSync\Transport\Signature;

defined( 'ABSPATH' ) || exit;

/**
 * Lo que dicen las respuestas de la otra tienda, guardado en `wss_peer_state` (ADR-011 §4).
 *
 * Sólo un cambio escribe una línea en el log: una caída de un día son dos, la caída y la
 * vuelta, no una por pasada.
 */
final class Peer {

	/**
	 * La opción.
	 */
	public const OPTION = 'wss_peer_state';

	/**
	 * Lo que contesta una tienda en pausa (ADR-015 §2).
	 */
	public const PAUSED_CODE = 'wss_paused';

	/**
	 * Anda.
	 */
	public const OK = 'ok';

	/**
	 * No responde: un error de red, un timeout o un 5xx. Esperar lo arregla.
	 */
	public const UNREACHABLE = 'unreachable';

	/**
	 * Responde pero dice que no: un 4xx. Esperar no lo arregla.
	 */
	public const REJECTED = 'rejected';

	/**
	 * La ruta del estado de la otra, relativa a la base de su API.
	 */
	public const HEALTH_ROUTE = '/wss/v1/health';

	/**
	 * Anota lo que dice una respuesta de la otra tienda.
	 *
	 * La llaman todos los que le hablan: la pasada, el envío al final del request, la
	 * reconciliación, la vinculación y el catálogo.
	 *
	 * @param array<string, mixed>|\WP_Error $response Lo que devolvió `wp_remote_*()`.
	 */
	public static function observe( $response ): void {
		// La otra está en pausa y el aviso no llegó: el par queda parejo (ADR-015 §2).
		if ( self::PAUSED_CODE === self::code_of( $response ) ) {
			Pause::set( true, Pause::PEER );

			return;
		}

		$seen = self::classify( $response );

		if ( null === $seen ) {
			return;
		}

		list( $state, $error ) = $seen;

		$before = self::current();

		if ( $state === $before['state'] ) {
			return;
		}

		update_option(
			self::OPTION,
			array(
				'state'        => $state,
				'since'        => time(),
				'error'        => $error,
				'notified'     => false,
				// Volvió de algo que se avisó: el aviso de "volvió" lo manda el tick.
				'back_pending' => self::OK === $state && $before['notified'],
			),
			false
		);

		self::record( $before['state'], $state, $error, $before['since'] );
	}

	/**
	 * El estado guardado.
	 *
	 * @return array{state: string, since: int, error: string|null, notified: bool, back_pending: bool, tried_at: int}
	 */
	public static function current(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'state'        => in_array( $stored['state'] ?? null, array( self::UNREACHABLE, self::REJECTED ), true ) ? (string) $stored['state'] : self::OK,
			'since'        => (int) ( $stored['since'] ?? 0 ),
			'error'        => is_string( $stored['error'] ?? null ) ? $stored['error'] : null,
			'notified'     => true === ( $stored['notified'] ?? false ),
			'back_pending' => true === ( $stored['back_pending'] ?? false ),
			'tried_at'     => (int) ( $stored['tried_at'] ?? 0 ),
		);
	}

	/**
	 * A qué atribuir lo que falla ahora: la caída o el rechazo de la otra, o nada.
	 *
	 * Es la `cause` de un evento (ADR-011 §5): lo que falla por esto no es un error del
	 * producto.
	 */
	public static function cause(): ?string {
		$state = self::current()['state'];

		if ( self::UNREACHABLE === $state ) {
			return Events::PEER_UNREACHABLE;
		}

		return self::REJECTED === $state ? Events::PEER_REJECTED : null;
	}

	/**
	 * Le pregunta a la otra si anda, con `GET /health`, y anota la respuesta.
	 *
	 * La usa el tick cuando el estado no está bien: sin ventas, nadie le habla a la otra, y
	 * el estado diría "caída" de una tienda que volvió hace rato (ADR-012 §2).
	 */
	public static function probe(): void {
		self::health();
	}

	/**
	 * Lo que dice `GET /health` de la otra —su nombre, su versión, dónde está su pantalla—, y
	 * anota la respuesta. La secundaria lo usa para el enlace a la maestra (ADR-013 §6).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function health() {
		$peer = Peers::only();

		if ( null === $peer ) {
			return new \WP_Error( 'wss_not_paired', __( 'Esta tienda no está emparejada con otra.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		$response = wp_remote_get(
			untrailingslashit( $peer['rest_url'] ) . '/health',
			array(
				'headers'     => Signature::headers( Store::id(), $peer['secret'], 'GET', self::HEALTH_ROUTE, array(), '' ),
				// Corto: mientras la otra está caída, el tick pregunta cada minuto.
				'timeout'     => Pairing::CALLBACK_TIMEOUT,
				'redirection' => 0,
			)
		);

		self::observe( $response );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_array( $payload ) ) {
			return new \WP_Error( 'wss_health_failed', 'HTTP ' . (int) wp_remote_retrieve_response_code( $response ) );
		}

		return $payload;
	}

	/**
	 * Anota cuándo se intentó avisar el estado actual: si el email no sale, no se reintenta
	 * antes de una ventana (ADR-012 §7).
	 *
	 * @param int $now Ahora.
	 */
	public static function mark_tried( int $now ): void {
		$state             = self::current();
		$state['tried_at'] = $now;

		update_option( self::OPTION, $state, false );
	}

	/**
	 * Anota que ya se avisó el estado actual por email.
	 */
	public static function mark_notified(): void {
		$state             = self::current();
		$state['notified'] = true;

		update_option( self::OPTION, $state, false );
	}

	/**
	 * Si hay que avisar que volvió, y lo da por avisado.
	 */
	public static function take_back_pending(): bool {
		$state = self::current();

		if ( ! $state['back_pending'] ) {
			return false;
		}

		$state['back_pending'] = false;
		update_option( self::OPTION, $state, false );

		return true;
	}

	/**
	 * El código de error de una respuesta, o ''.
	 *
	 * @param array<string, mixed>|\WP_Error $response La respuesta.
	 */
	private static function code_of( $response ): string {
		if ( is_wp_error( $response ) ) {
			return '';
		}

		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $payload ) && is_string( $payload['code'] ?? null ) ? $payload['code'] : '';
	}

	/**
	 * Qué dice una respuesta: el estado y el error, o null si no dice nada del estado.
	 *
	 * Un `409 wss_reconciling` no dice nada: la otra está en medio de su propia
	 * reconciliación, y eso es normal (ADR-011 §4).
	 *
	 * @param array<string, mixed>|\WP_Error $response La respuesta.
	 *
	 * @return array{0: string, 1: string|null}|null
	 */
	private static function classify( $response ): ?array {
		if ( is_wp_error( $response ) ) {
			return array( self::UNREACHABLE, $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 0 === $status || $status >= 500 ) {
			return array( self::UNREACHABLE, 'HTTP ' . $status );
		}

		if ( $status >= 200 && $status < 300 ) {
			return array( self::OK, null );
		}

		$code = self::code_of( $response );

		if ( 409 === $status && 'wss_reconciling' === $code ) {
			return null;
		}

		return array( self::REJECTED, trim( 'HTTP ' . $status . ' ' . $code ) );
	}

	/**
	 * Escribe el cambio en el log.
	 *
	 * @param string      $from  El estado de antes.
	 * @param string      $to    El de ahora.
	 * @param string|null $error Por qué, si no anda.
	 * @param int         $since Desde cuándo estaba como antes.
	 */
	private static function record( string $from, string $to, ?string $error, int $since ): void {
		if ( self::UNREACHABLE === $to ) {
			Events::record( Events::PEER_UNREACHABLE, Events::WARNING, null, array( 'error' => $error ) );

			return;
		}

		if ( self::REJECTED === $to ) {
			Events::record( Events::PEER_REJECTED, Events::ERROR, null, array( 'error' => $error ) );

			return;
		}

		Events::record(
			self::UNREACHABLE === $from ? Events::PEER_BACK : Events::PEER_ACCEPTED,
			Events::INFO,
			null,
			array( 'minutes' => (int) floor( ( time() - $since ) / 60 ) )
		);
	}
}
