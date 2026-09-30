<?php
/**
 * El endpoint que dice cómo está esta tienda.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Rest;

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Plugin;
use Woonderful\StockSync\Transport\Pairing;

use const Woonderful\StockSync\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /wss/v1/health`: identidad, versión y si está suspendida (ADR-001 §4).
 */
final class Health {

	/**
	 * Engancha el registro de la ruta.
	 *
	 * Antes de la guarda: un sitio suspendido responde igual, para que la otra tienda
	 * pueda explicar por qué no le llegan movimientos.
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
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'respond' ),
				'permission_callback' => array( self::class, 'authenticate' ),
			)
		);
	}

	/**
	 * Autentica sin anotar el nonce: reenviar un `health` no hace nada, y tiene que
	 * responder aunque la tabla de nonces esté rota.
	 *
	 * Mientras esta tienda canjea un código con esa maestra, verifica con el código: es el
	 * callback del paso 4 (ADR-001 §1). Aunque ya tenga un secreto suyo: si no, re-emparejar
	 * —que es también la rotación— no pasaría nunca el callback.
	 *
	 * @param \WP_REST_Request $request El request.
	 *
	 * @return true|\WP_Error
	 */
	public static function authenticate( \WP_REST_Request $request ) {
		$code = Pairing::redeeming_code_for( (string) $request->get_header( 'X-WSS-Store' ) );

		if ( '' !== $code ) {
			return Auth::verify_with( $request, $code, false );
		}

		return Auth::authenticate( $request, false );
	}

	/**
	 * Cómo está esta tienda.
	 */
	public static function respond(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'store'     => Store::id(),
				'label'     => Store::label(),
				'version'   => VERSION,
				'api'       => Movements::NAMESPACE,
				'suspended' => Plugin::suspension_reason(),
			),
			200
		);
	}
}
