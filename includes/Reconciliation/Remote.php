<?php
/**
 * Las preguntas a la otra tienda.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Reconciliation;

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Log\Peer;
use Woonderful\StockSync\Transport\Sender;
use Woonderful\StockSync\Transport\Signature;

defined( 'ABSPATH' ) || exit;

/**
 * Le hace a la otra tienda una pregunta de `POST /reconcile/digest`, firmada.
 */
final class Remote {

	/**
	 * La ruta REST, relativa a la base de la API.
	 */
	public const ROUTE = '/wss/v1/reconcile/digest';

	/**
	 * Hace la pregunta.
	 *
	 * @param array{store: string, rest_url: string, secret: string, role: string} $peer La otra tienda.
	 * @param array<string, mixed>                                                 $body La pregunta.
	 *
	 * @return array<string, mixed>|\WP_Error La respuesta, o por qué no hubo: `wss_reconciling`
	 *                                        si la otra está ocupada, y el código que haya
	 *                                        contestado o el error de la red si no.
	 */
	public static function ask( array $peer, array $body ) {
		$json = (string) wp_json_encode( $body );

		$response = wp_remote_post(
			untrailingslashit( $peer['rest_url'] ) . '/reconcile/digest',
			array(
				'headers'     => array_merge(
					array( 'Content-Type' => 'application/json' ),
					Signature::headers( Store::id(), $peer['secret'], 'POST', self::ROUTE, array(), $json )
				),
				'body'        => $json,
				'timeout'     => Sender::TIMEOUT,
				// Un POST redirigido llega como GET, o no llega: mejor que falle y se vea.
				'redirection' => 0,
			)
		);

		Peer::observe( $response );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 === $status && is_array( $payload ) ) {
			return $payload;
		}

		$code = is_array( $payload ) && is_string( $payload['code'] ?? null ) ? $payload['code'] : 'wss_reconcile_failed';

		return new \WP_Error( $code, 'HTTP ' . $status . ' ' . $code );
	}
}
