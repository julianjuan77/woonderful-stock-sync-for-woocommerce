<?php
/**
 * El endpoint que recibe los movimientos de la otra tienda.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Rest;

use Woonderful\StockSync\Ledger\Ledger;
use Woonderful\StockSync\Transport\Nonces;
use Woonderful\StockSync\Transport\Peers;
use Woonderful\StockSync\Transport\Signature;

defined( 'ABSPATH' ) || exit;

/**
 * `POST /wss/v1/movements`: recibe un lote y responde un ACK por movimiento (ADR-001 §4).
 */
final class Movements {

	/**
	 * El namespace de la API entre tiendas.
	 */
	public const NAMESPACE = 'wss/v1';

	/**
	 * Engancha el registro de la ruta.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Registra la ruta.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/movements',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'receive' ),
				'permission_callback' => array( self::class, 'authenticate' ),
			)
		);
	}

	/**
	 * Autentica el request con la firma, en el orden de ADR-001 §4.
	 *
	 * La tienda primero, porque sin ella no hay secreto con qué verificar. El timestamp
	 * antes que la firma, para que un reloj desfasado se diagnostique como tal y no como
	 * una firma mala. Y el nonce al final: anotarlo antes dejaría llenar la tabla sin el
	 * secreto.
	 *
	 * @param \WP_REST_Request $request El request.
	 *
	 * @return true|\WP_Error
	 */
	public static function authenticate( \WP_REST_Request $request ) {
		$store  = (string) $request->get_header( 'X-WSS-Store' );
		$secret = Peers::secret( $store );

		if ( '' === $secret ) {
			return self::reject( 'wss_unknown_store', __( 'La tienda no está emparejada.', 'woonderful-stock-sync-for-woocommerce' ), 401 );
		}

		$now       = time();
		$timestamp = (string) $request->get_header( 'X-WSS-Timestamp' );

		if ( ! ctype_digit( $timestamp ) || abs( $now - (int) $timestamp ) > Signature::WINDOW ) {
			return self::reject( 'wss_stale_timestamp', __( 'El timestamp está fuera de la ventana de 5 minutos: revisá el reloj del servidor.', 'woonderful-stock-sync-for-woocommerce' ), 401 );
		}

		$nonce = (string) $request->get_header( 'X-WSS-Nonce' );

		$signed = Signature::verify(
			$secret,
			$request->get_method(),
			$request->get_route(),
			$request->get_query_params(),
			(int) $timestamp,
			$nonce,
			$request->get_body(),
			(string) $request->get_header( 'X-WSS-Signature' ),
			$now
		);

		if ( ! $signed ) {
			return self::reject( 'wss_bad_signature', __( 'La firma no coincide.', 'woonderful-stock-sync-for-woocommerce' ), 401 );
		}

		if ( ! Nonces::claim( $nonce, $now ) ) {
			return self::reject( 'wss_replayed_nonce', __( 'Este request ya se recibió.', 'woonderful-stock-sync-for-woocommerce' ), 401 );
		}

		return true;
	}

	/**
	 * Recibe el lote: un resultado por movimiento, en el mismo orden.
	 *
	 * @param \WP_REST_Request $request El request, ya autenticado.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function receive( \WP_REST_Request $request ) {
		$payload   = json_decode( $request->get_body(), true );
		$movements = is_array( $payload ) ? ( $payload['movements'] ?? null ) : null;

		if ( ! is_array( $movements ) || ! array_is_list( $movements ) ) {
			return self::reject( 'wss_bad_request', __( 'El cuerpo tiene que ser un JSON con una lista "movements".', 'woonderful-stock-sync-for-woocommerce' ), 400 );
		}

		if ( count( $movements ) > Ledger::MAX_BATCH ) {
			return self::reject( 'wss_batch_too_large', __( 'El lote pasa de 200 movimientos.', 'woonderful-stock-sync-for-woocommerce' ), 413 );
		}

		$store   = (string) $request->get_header( 'X-WSS-Store' );
		$results = array();

		foreach ( $movements as $movement ) {
			$results[] = self::receive_one( $movement, $store );
		}

		return new \WP_REST_Response( array( 'results' => $results ), 200 );
	}

	/**
	 * El resultado de un movimiento.
	 *
	 * @param mixed  $movement Lo que vino en esa posición del lote.
	 * @param string $store    La tienda que firmó.
	 *
	 * @return array<string, mixed>
	 */
	private static function receive_one( $movement, string $store ): array {
		if ( ! is_array( $movement ) ) {
			return self::error( null, null, 'invalid_movement' );
		}

		$origin = is_string( $movement['origin'] ?? null ) ? $movement['origin'] : null;
		$seq    = is_int( $movement['seq'] ?? null ) ? $movement['seq'] : null;

		$problem = self::problem_with( $movement, $store );

		if ( null !== $problem ) {
			return self::error( $origin, $seq, $problem );
		}

		$state = Ledger::apply(
			array(
				'origin'     => $store,
				'seq'        => $movement['seq'],
				'generation' => $movement['generation'],
				'delta'      => $movement['delta'],
				'product'    => $movement['product'],
				// Se guarda como llega: una versión más nueva de la otra tienda puede
				// mandar un motivo que ésta no conoce, y rechazarlo perdería el delta.
				'reason'     => is_string( $movement['reason'] ?? null ) && '' !== $movement['reason'] ? mb_substr( $movement['reason'], 0, 20 ) : 'adjustment',
				'reference'  => is_string( $movement['reference'] ?? null ) ? mb_substr( $movement['reference'], 0, 100 ) : null,
			)
		);

		// Sin fila no hay nada que confirmar: el emisor tiene que reintentar.
		if ( null === $state ) {
			return self::error( $origin, $seq, 'not_stored' );
		}

		return array(
			'origin' => $origin,
			'seq'    => $seq,
			'status' => 'received',
		);
	}

	/**
	 * Lo que impide identificar o aplicar un movimiento, o null si no hay nada.
	 *
	 * Sólo esto se rechaza: un error hace que el emisor reintente 6 horas y lo abandone, y
	 * si reintentar no cambia nada el delta se pierde (ADR-001 §4).
	 *
	 * @param array<mixed, mixed> $movement El movimiento.
	 * @param string              $store    La tienda que firmó.
	 */
	private static function problem_with( array $movement, string $store ): ?string {
		// Un movimiento "de A" mandado por B chocaría contra la secuencia propia de A en el
		// libro, y la venta de A se perdería sin aviso.
		if ( ( $movement['origin'] ?? null ) !== $store ) {
			return 'origin_mismatch';
		}

		// El rango es el de la columna, no el de PHP. Sin modo estricto, MySQL guardaría un
		// delta fuera de rango recortado mientras WooCommerce aplica el real, y el libro y el
		// stock quedarían distintos. `seq` y `product` son BIGINT UNSIGNED: el entero de PHP
		// no llega a pasarse.
		$ranges = array(
			'seq'        => array( 1, PHP_INT_MAX ),
			'generation' => array( 0, 4294967295 ),
			'delta'      => array( -2147483648, 2147483647 ),
			'product'    => array( 1, PHP_INT_MAX ),
		);

		foreach ( $ranges as $field => list( $minimum, $maximum ) ) {
			$value = $movement[ $field ] ?? null;

			if ( ! is_int( $value ) || $value < $minimum || $value > $maximum ) {
				return 'invalid_' . $field;
			}
		}

		return null;
	}

	/**
	 * Un resultado con error.
	 *
	 * @param string|null $origin El origen, si se pudo leer.
	 * @param int|null    $seq    La secuencia, si se pudo leer.
	 * @param string      $reason Por qué.
	 *
	 * @return array<string, mixed>
	 */
	private static function error( ?string $origin, ?int $seq, string $reason ): array {
		return array(
			'origin' => $origin,
			'seq'    => $seq,
			'status' => 'error',
			'reason' => $reason,
		);
	}

	/**
	 * Un rechazo del request entero.
	 *
	 * @param string $code    El código del contrato.
	 * @param string $message Para quien lea el log del emisor.
	 * @param int    $status  El código HTTP.
	 */
	private static function reject( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
