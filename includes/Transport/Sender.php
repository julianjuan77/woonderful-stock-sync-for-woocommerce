<?php
/**
 * El envío de los movimientos propios a la otra tienda.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Transport;

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Ledger\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * Una pasada del emisor: reclama un lote, lo manda firmado y anota cada resultado (ADR-001 §2).
 */
final class Sender {

	/**
	 * La ruta del receptor, relativa a la base de su API.
	 */
	public const ROUTE = '/wss/v1/movements';

	/**
	 * El error de una pasada sin tienda a quien mandar.
	 *
	 * Distinto de no tener nada que mandar: es lo que dice que la tienda quedó sin emparejar.
	 */
	public const NO_PEER = 'no_peer';

	/**
	 * Cuánto se espera la respuesta, en segundos.
	 *
	 * Muy por debajo de los 5 minutos del claim: si se acercara, el claim podría vencer con
	 * el lote todavía en camino y otra pasada se lo llevaría.
	 */
	public const TIMEOUT = 30;

	/**
	 * Hace una pasada.
	 *
	 * @param int[]|null $only    Sólo estos movimientos, aunque no les toque todavía (ADR-006 §5).
	 * @param int        $timeout Cuánto se espera la respuesta.
	 *
	 * @return array{sent: int, confirmed: int, failed: int, error: string|null} Lo que pasó,
	 *         para el log de la pasada.
	 */
	public static function run( ?array $only = null, int $timeout = self::TIMEOUT ): array {
		$summary = array(
			'sent'      => 0,
			'confirmed' => 0,
			'failed'    => 0,
			'error'     => null,
		);

		// Sin tienda a quien mandar no se reclama nada: reclamar gastaría intentos contra el
		// techo de 6 horas sin haber intentado nada.
		$peer = Peers::only();

		if ( null === $peer ) {
			$summary['error'] = self::NO_PEER;

			return $summary;
		}

		$rows = Ledger::claim( Ledger::MAX_BATCH, $only );

		if ( array() === $rows ) {
			return $summary;
		}

		$worker   = (string) $rows[0]->claimed_by;
		$response = self::post( $peer, array_map( array( self::class, 'movement_of' ), $rows ), $timeout );

		$results = self::results_for( $rows, $response );

		Ledger::ack( $worker, $results );

		$summary['sent']      = count( $rows );
		$summary['confirmed'] = count( array_filter( $results, static fn ( $result ) => true === $result ) );
		$summary['failed']    = $summary['sent'] - $summary['confirmed'];
		$summary['error']     = self::request_error( $response );

		return $summary;
	}

	/**
	 * Manda un lote firmado a `POST /movements` de la otra tienda.
	 *
	 * Lo usan la pasada y el bootstrap, que se manda en el momento (ADR-008 §1).
	 *
	 * @param array{store: string, rest_url: string, secret: string, role: string} $peer      La otra tienda.
	 * @param array<int, array<string, mixed>>                                     $movements Los movimientos, como viajan.
	 * @param int                                                                  $timeout   Cuánto se espera la respuesta.
	 *
	 * @return array<string, mixed>|\WP_Error Lo que devolvió `wp_remote_post()`.
	 */
	public static function post( array $peer, array $movements, int $timeout = self::TIMEOUT ) {
		$body = (string) wp_json_encode( array( 'movements' => $movements ) );

		return wp_remote_post(
			untrailingslashit( $peer['rest_url'] ) . '/movements',
			array(
				'headers'     => array_merge(
					array( 'Content-Type' => 'application/json' ),
					Signature::headers( Store::id(), $peer['secret'], 'POST', self::ROUTE, array(), $body )
				),
				'body'        => $body,
				'timeout'     => $timeout,
				// Un POST redirigido llega como GET, o no llega: mejor que falle y se vea.
				'redirection' => 0,
			)
		);
	}

	/**
	 * Un movimiento como viaja (ADR-001 §4).
	 *
	 * @param \stdClass $row La fila del libro.
	 *
	 * @return array<string, mixed>
	 */
	private static function movement_of( \stdClass $row ): array {
		return array(
			'origin'     => Store::id(),
			'seq'        => (int) $row->origin_seq,
			'generation' => (int) $row->generation,
			'delta'      => (int) $row->delta,
			'product'    => (int) $row->origin_product,
			'reason'     => (string) $row->reason,
			'reference'  => null === $row->reference ? null : (string) $row->reference,
		);
	}

	/**
	 * El resultado de cada fila, para `ack()`: true si se confirmó, el motivo si no.
	 *
	 * Se empareja **por posición** (ADR-001 §4). Si el resultado trae un `seq` y no es el
	 * de la fila, no se confirma: un receptor que desordenara la respuesta haría confirmar
	 * filas ajenas.
	 *
	 * @param \stdClass[]                    $rows     Las filas enviadas, en orden.
	 * @param array<string, mixed>|\WP_Error $response Lo que devolvió `wp_remote_post()`.
	 *
	 * @return array<int, bool|string>
	 */
	private static function results_for( array $rows, $response ): array {
		$error   = self::request_error( $response );
		$answers = array();

		if ( null === $error ) {
			$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$answers = is_array( $payload ) && is_array( $payload['results'] ?? null ) ? array_values( $payload['results'] ) : array();
		}

		$results = array();

		foreach ( $rows as $position => $row ) {
			$movement_id = (int) $row->id;

			if ( null !== $error ) {
				$results[ $movement_id ] = $error;

				continue;
			}

			$answer = $answers[ $position ] ?? null;

			if ( ! is_array( $answer ) ) {
				$results[ $movement_id ] = 'missing_result';
			} elseif ( isset( $answer['seq'] ) && (int) $answer['seq'] !== (int) $row->origin_seq ) {
				$results[ $movement_id ] = 'result_mismatch';
			} elseif ( 'received' === ( $answer['status'] ?? null ) ) {
				$results[ $movement_id ] = true;
			} else {
				$results[ $movement_id ] = is_string( $answer['reason'] ?? null ) ? $answer['reason'] : 'error';
			}
		}

		return $results;
	}

	/**
	 * Por qué falló el request entero, o null si llegó un 200.
	 *
	 * @param array<string, mixed>|\WP_Error $response Lo que devolvió `wp_remote_post()`.
	 */
	private static function request_error( $response ): ?string {
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $status ) {
			return null;
		}

		// El código del receptor dice qué pasó —un reloj desfasado, una firma mala— y es lo
		// que hace falta para diagnosticar.
		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$code    = is_array( $payload ) && is_string( $payload['code'] ?? null ) ? ' ' . $payload['code'] : '';

		return 'HTTP ' . $status . $code;
	}
}
