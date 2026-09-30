<?php
/**
 * El endpoint del canje del código de emparejamiento.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Rest;

use Woonderful\StockSync\Transport\Pairing;

defined( 'ABSPATH' ) || exit;

/**
 * `POST /wss/v1/pair`: la secundaria canjea el código por el secreto (ADR-001 §1).
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
