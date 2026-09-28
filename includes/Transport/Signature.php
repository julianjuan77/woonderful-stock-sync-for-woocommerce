<?php
/**
 * La firma de los requests entre tiendas.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Transport;

defined( 'ABSPATH' ) || exit;

/**
 * Firma y verifica con HMAC-SHA256 y el secreto compartido (ADR-001 §1).
 *
 * Sólo la firma y la ventana del reloj. Que el nonce no se haya visto antes lo decide el
 * almacén de nonces, y que la tienda esté emparejada, quien busca su secreto.
 */
final class Signature {

	/**
	 * Cuánto puede diferir el timestamp del request del reloj propio, en segundos.
	 */
	public const WINDOW = 300;

	/**
	 * Las cabeceras de un request firmado.
	 *
	 * @param string                $store  Identificador de la tienda que firma.
	 * @param string                $secret El secreto compartido con la otra.
	 * @param string                $method Método HTTP.
	 * @param string                $route  Ruta REST, como `/wss/v1/movements`.
	 * @param array<string, scalar> $query  Parámetros de la query.
	 * @param string                $body   Cuerpo crudo, tal como se va a mandar.
	 *
	 * @return array<string, string>
	 */
	public static function headers( string $store, string $secret, string $method, string $route, array $query, string $body ): array {
		$timestamp = time();
		$nonce     = bin2hex( random_bytes( 16 ) );

		return array(
			'X-WSS-Store'     => $store,
			'X-WSS-Timestamp' => (string) $timestamp,
			'X-WSS-Nonce'     => $nonce,
			'X-WSS-Signature' => self::sign( $secret, $method, $route, $query, $timestamp, $nonce, $body ),
		);
	}

	/**
	 * La firma de un request.
	 *
	 * @param string                $secret    El secreto compartido.
	 * @param string                $method    Método HTTP.
	 * @param string                $route     Ruta REST.
	 * @param array<string, scalar> $query     Parámetros de la query.
	 * @param int                   $timestamp Segundos.
	 * @param string                $nonce     El nonce del request.
	 * @param string                $body      Cuerpo crudo.
	 *
	 * @throws \InvalidArgumentException Si un parámetro de la query no es escalar.
	 */
	public static function sign( string $secret, string $method, string $route, array $query, int $timestamp, string $nonce, string $body ): string {
		$text = self::canonical( $method, $route, $query, $timestamp, $nonce, $body );

		if ( null === $text ) {
			throw new \InvalidArgumentException( 'Los parámetros de la query tienen que ser escalares (ADR-001 §1).' );
		}

		return hash_hmac( 'sha256', $text, $secret );
	}

	/**
	 * Si la firma corresponde al request y el timestamp está dentro de la ventana.
	 *
	 * @param string              $secret    El secreto de la tienda que dice firmar.
	 * @param string              $method    Método HTTP.
	 * @param string              $route     Ruta REST, de `get_route()`.
	 * @param array<mixed, mixed> $query     Parámetros de la query, de `get_query_params()`.
	 * @param int                 $timestamp El de la cabecera.
	 * @param string              $nonce     El de la cabecera.
	 * @param string              $body      Cuerpo crudo, de `get_body()`.
	 * @param string              $signature La de la cabecera.
	 * @param int                 $now       El reloj propio.
	 */
	public static function verify( string $secret, string $method, string $route, array $query, int $timestamp, string $nonce, string $body, string $signature, int $now ): bool {
		if ( abs( $now - $timestamp ) > self::WINDOW ) {
			return false;
		}

		$text = self::canonical( $method, $route, $query, $timestamp, $nonce, $body );

		if ( null === $text ) {
			return false;
		}

		return hash_equals( hash_hmac( 'sha256', $text, $secret ), $signature );
	}

	/**
	 * El texto que se firma: seis líneas (ADR-001 §1).
	 *
	 * @param string              $method    Método HTTP.
	 * @param string              $route     Ruta REST.
	 * @param array<mixed, mixed> $query     Parámetros de la query.
	 * @param int                 $timestamp Segundos.
	 * @param string              $nonce     El nonce.
	 * @param string              $body      Cuerpo crudo.
	 *
	 * @return string|null Null si la query tiene un parámetro que no es escalar.
	 */
	public static function canonical( string $method, string $route, array $query, int $timestamp, string $nonce, string $body ): ?string {
		$canonical_query = self::canonical_query( $query );

		if ( null === $canonical_query ) {
			return null;
		}

		return implode(
			"\n",
			array(
				strtoupper( $method ),
				$route,
				$canonical_query,
				(string) $timestamp,
				$nonce,
				hash( 'sha256', $body ),
			)
		);
	}

	/**
	 * La query ordenada y codificada, sin `rest_route`.
	 *
	 * `rest_route` lo agrega WordPress a la query con permalinks sin formato, y es la ruta,
	 * que ya va en su propia línea: firmarlo haría que el mismo request dé dos firmas según
	 * los permalinks del receptor.
	 *
	 * @param array<mixed, mixed> $query Parámetros de la query.
	 *
	 * @return string|null Null si un parámetro no es escalar.
	 */
	public static function canonical_query( array $query ): ?string {
		unset( $query['rest_route'] );

		$pairs = array();

		foreach ( $query as $name => $value ) {
			if ( ! is_scalar( $value ) ) {
				return null;
			}

			$pairs[ (string) $name ] = rawurlencode( (string) $name ) . '=' . rawurlencode( (string) $value );
		}

		// SORT_STRING compara con strcmp(), en orden de bytes: sin él, un nombre numérico
		// se ordenaría como número y los dos lados podrían no coincidir.
		ksort( $pairs, SORT_STRING );

		return implode( '&', $pairs );
	}
}
