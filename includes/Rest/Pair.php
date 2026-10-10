<?php
/**
 * Los endpoints del emparejamiento: el canje del código, la pausa y el corte.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Rest;

use Woonderful\StockSync\Transport\Pairing;
use Woonderful\StockSync\Transport\Pause;
use Woonderful\StockSync\Transport\Peers;

defined( 'ABSPATH' ) || exit;

/**
 * `POST /wss/v1/pair`: la secundaria canjea el código por el secreto (ADR-001 §1).
 * `POST /wss/v1/pause` y `POST /wss/v1/unpair`: la otra pausó, reanudó o desemparejó, y ésta
 * queda igual (ADR-015 §2, §3). Firmados con el secreto.
 */
final class Pair {

	/**
	 * Engancha el registro de la ruta.
	 *
	 * Antes de la guarda: re-emparejar es la forma de salir de la suspensión por un cambio
	 * de URL.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Registra la ruta.
	 */
	public static function register_routes(): void {
		register_rest_route(
			Movements::NAMESPACE,
			'/pair',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'receive' ),
				'permission_callback' => array( self::class, 'authenticate' ),
			)
		);

		register_rest_route(
			Movements::NAMESPACE,
			Pause::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'pause' ),
				'permission_callback' => array( self::class, 'signed' ),
			)
		);

		register_rest_route(
			Movements::NAMESPACE,
			'/unpair',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'unpair' ),
				'permission_callback' => array( self::class, 'signed' ),
			)
		);
	}

	/**
	 * Autentica con el secreto de la otra, anotando el nonce.
	 *
	 * @param \WP_REST_Request $request El request.
	 *
	 * @return true|\WP_Error
	 */
	public static function signed( \WP_REST_Request $request ) {
		return Auth::authenticate( $request, true );
	}

	/**
	 * La otra pausó o reanudó: ésta queda igual.
	 *
	 * @param \WP_REST_Request $request `{"paused": bool}`.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function pause( \WP_REST_Request $request ) {
		$payload = json_decode( $request->get_body(), true );

		if ( ! is_array( $payload ) || ! is_bool( $payload['paused'] ?? null ) ) {
			return new \WP_Error( 'wss_bad_request', __( 'El cuerpo tiene que ser un JSON con "paused" verdadero o falso.', 'woonderful-stock-sync-for-woocommerce' ), array( 'status' => 400 ) );
		}

		Pause::set( $payload['paused'], Pause::PEER );

		return new \WP_REST_Response( array( 'paused' => Pause::is_on() ), 200 );
	}

	/**
	 * La otra desemparejó: ésta olvida lo mismo.
	 */
	public static function unpair(): \WP_REST_Response {
		Peers::forget( Pause::PEER );

		return new \WP_REST_Response( array( 'paired' => false ), 200 );
	}

	/**
	 * Autentica con el código que emitió esta tienda, no con un secreto.
	 *
	 * Un código que no existe, que venció, que ya se usó o con el que no coincide la firma
	 * son lo mismo para quien llama: `wss_pairing_invalid`.
	 *
	 * @param \WP_REST_Request $request El request.
	 *
	 * @return true|\WP_Error
	 */
	public static function authenticate( \WP_REST_Request $request ) {
		$invalid = Auth::reject( 'wss_pairing_invalid', __( 'El código no existe, venció o ya se usó.', 'woonderful-stock-sync-for-woocommerce' ) );
		$code    = Pairing::pending_code();

		if ( '' === $code ) {
			return $invalid;
		}

		$verified = Auth::verify_with( $request, $code, true );

		if ( is_wp_error( $verified ) && 'wss_bad_signature' === $verified->get_error_code() ) {
			return $invalid;
		}

		return $verified;
	}

	/**
	 * Recibe el canje: callback, secreto nuevo y la respuesta.
	 *
	 * @param \WP_REST_Request $request El request, ya autenticado.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function receive( \WP_REST_Request $request ) {
		$body     = json_decode( (string) $request->get_body(), true );
		$accepted = Pairing::accept( is_array( $body ) ? $body : array() );

		if ( is_wp_error( $accepted ) ) {
			return $accepted;
		}

		return new \WP_REST_Response( $accepted, 200 );
	}
}
