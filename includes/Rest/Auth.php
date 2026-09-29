<?php
/**
 * La autenticación de los requests entre tiendas.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Rest;

use Woonderful\StockSync\Transport\Nonces;
use Woonderful\StockSync\Transport\Peers;
use Woonderful\StockSync\Transport\Signature;

defined( 'ABSPATH' ) || exit;

/**
 * Autentica un request firmado con el secreto, en el orden de ADR-001 §4.
 */
final class Auth {

	/**
	 * Engancha los encabezados que prohíben guardar las respuestas en caché.
	 */
	public static function register(): void {
		add_filter( 'rest_post_dispatch', array( self::class, 'no_cache' ), 10, 3 );
	}

	/**
	 * Que ninguna caché guarde una respuesta de `wss/v1`, ni las de error.
	 *
	 * WordPress manda estos encabezados sólo con un usuario logueado, y estos requests vienen
	 * firmados, no logueados. Sin ellos, una caché que guarda por URL —Varnish, la de Nginx,
	 * una regla "cache everything" de Cloudflare— le daría la respuesta de un request firmado
	 * a cualquiera que pida la misma URL sin firma, y la autenticación ni correría.
	 *
	 * @param \WP_HTTP_Response|mixed $response La respuesta.
	 * @param mixed                   $server   El servidor REST; no se usa.
	 * @param \WP_REST_Request|mixed  $request  El request.
	 *
	 * @return \WP_HTTP_Response|mixed
	 */
	public static function no_cache( $response, $server, $request ) {
		if ( ! $response instanceof \WP_HTTP_Response || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}

		if ( ! str_starts_with( $request->get_route(), '/' . Movements::NAMESPACE . '/' ) ) {
			return $response;
		}

		foreach ( wp_get_nocache_headers() as $name => $value ) {
			// Uno vacío WordPress lo saca en vez de mandarlo: `Last-Modified` viene en false.
			if ( ! empty( $value ) ) {
				$response->header( $name, (string) $value );
			}
		}

		return $response;
	}

	/**
	 * Autentica el request.
	 *
	 * La tienda primero, porque sin ella no hay secreto con qué verificar. El timestamp
	 * antes que la firma, para que un reloj desfasado se diagnostique como tal y no como
	 * una firma mala. Y el nonce al final: anotarlo antes dejaría llenar la tabla sin el
	 * secreto.
	 *
	 * @param \WP_REST_Request $request     El request.
	 * @param bool             $claim_nonce Si se anota el nonce. `GET /health` no lo anota:
	 *                                      sólo lee, y tiene que responder aunque la tabla
	 *                                      de nonces esté rota.
	 *
	 * @return true|\WP_Error
	 */
	public static function authenticate( \WP_REST_Request $request, bool $claim_nonce ) {
		$store  = (string) $request->get_header( 'X-WSS-Store' );
		$secret = Peers::secret( $store );

		if ( '' === $secret ) {
			return self::reject( 'wss_unknown_store', __( 'La tienda no está emparejada.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		$now       = time();
		$timestamp = (string) $request->get_header( 'X-WSS-Timestamp' );

		if ( ! ctype_digit( $timestamp ) || abs( $now - (int) $timestamp ) > Signature::WINDOW ) {
			return self::reject( 'wss_stale_timestamp', __( 'El timestamp está fuera de la ventana de 5 minutos: revisá el reloj del servidor.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		$nonce = (string) $request->get_header( 'X-WSS-Nonce' );

		$signed = Signature::verify(
			$secret,
			$request->get_method(),
			$request->get_route(),
			$request->get_query_params(),
			(int) $timestamp,
			$nonce,
			(string) $request->get_body(),
			(string) $request->get_header( 'X-WSS-Signature' ),
			$now
		);

		if ( ! $signed ) {
			return self::reject( 'wss_bad_signature', __( 'La firma no coincide.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		if ( $claim_nonce && ! Nonces::claim( $nonce, $now ) ) {
			return self::reject( 'wss_replayed_nonce', __( 'Este request ya se recibió.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		return true;
	}

	/**
	 * Un rechazo de autenticación: siempre 401.
	 *
	 * @param string $code    El código del contrato.
	 * @param string $message Para quien lea el log del emisor.
	 */
	private static function reject( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => 401 ) );
	}
}
