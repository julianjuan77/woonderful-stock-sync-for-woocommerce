<?php
/**
 * El catálogo de la otra tienda.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Transport;

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Log\Peer;

defined( 'ABSPATH' ) || exit;

/**
 * Lee `GET /products` de la otra tienda: en vivo, no un espejo (ADR-005 §5).
 */
final class Catalog {

	/**
	 * La ruta REST, relativa a la base de la API.
	 */
	public const ROUTE = '/wss/v1/products';

	/**
	 * Hasta cuántos productos se piden por request con `include`.
	 */
	public const MAX_INCLUDE = 100;

	/**
	 * Una página del catálogo de la otra tienda, o sólo esos productos.
	 *
	 * Con `include`, vuelve cada producto pedido que exista, de a 100 por request: uno que no
	 * vuelve ya no existe (ADR-005 §5). Una lista vacía no pide nada: el endpoint la tomaría
	 * como "no se pidió" y devolvería el catálogo entero.
	 *
	 * @param int        $page     La página, si se recorre el catálogo.
	 * @param int        $per_page Cuántos, hasta 100, si se recorre el catálogo.
	 * @param int[]|null $include  Sólo estos IDs, o null para recorrer el catálogo.
	 *
	 * @return array{products: array<int, array<string, mixed>>, total: int}|\WP_Error
	 */
	public static function fetch( int $page = 1, int $per_page = 100, ?array $include = null ) {
		if ( null === $include ) {
			return self::request(
				array(
					'page'     => (string) $page,
					'per_page' => (string) $per_page,
				)
			);
		}

		$products = array();

		foreach ( array_chunk( array_values( array_unique( array_map( 'intval', $include ) ) ), self::MAX_INCLUDE ) as $batch ) {
			$result = self::request( array( 'include' => implode( ',', $batch ) ) );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$products = array_merge( $products, $result['products'] );
		}

		return array(
			'products' => $products,
			'total'    => count( $products ),
		);
	}

	/**
	 * Un request firmado a `GET /products`.
	 *
	 * @param array<string, string> $query Los parámetros, en texto: la firma no admite listas
	 *                                     ni booleanos (ADR-001 §1), y es la misma query que
	 *                                     viaja.
	 *
	 * @return array{products: array<int, array<string, mixed>>, total: int}|\WP_Error
	 */
	private static function request( array $query ) {
		$peer = Peers::only();

		if ( null === $peer ) {
			return new \WP_Error( 'wss_not_paired', __( 'Esta tienda no está emparejada con otra.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		$response = wp_remote_get(
			add_query_arg( array_map( 'rawurlencode', $query ), untrailingslashit( $peer['rest_url'] ) . '/products' ),
			array(
				'headers'     => Signature::headers( Store::id(), $peer['secret'], 'GET', self::ROUTE, $query, '' ),
				'timeout'     => Sender::TIMEOUT,
				'redirection' => 0,
			)
		);

		Peer::observe( $response );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status || ! is_array( $payload ) || ! is_array( $payload['products'] ?? null ) ) {
			$code = is_array( $payload ) && is_string( $payload['code'] ?? null ) ? ' ' . $payload['code'] : '';

			return new \WP_Error( 'wss_catalog_failed', 'HTTP ' . $status . $code );
		}

		return array(
			'products' => array_map( array( self::class, 'with_defaults' ), array_values( array_filter( $payload['products'], array( self::class, 'is_well_formed' ) ) ) ),
			'total'    => (int) ( $payload['total'] ?? 0 ),
		);
	}

	/**
	 * El tipo y el padre, que suma ADR-014 §2: una versión vieja del otro lado no los manda, y
	 * se toman como un producto suelto de tipo desconocido.
	 *
	 * @param array<string, mixed> $product Una entrada bien formada.
	 *
	 * @return array<string, mixed>
	 */
	private static function with_defaults( array $product ): array {
		$product['type']   = is_string( $product['type'] ?? null ) ? $product['type'] : '';
		$product['parent'] = is_int( $product['parent'] ?? null ) && $product['parent'] > 0 ? $product['parent'] : 0;

		return $product;
	}

	/**
	 * Si una entrada trae lo que dice ADR-005 §5. Las que no, se descartan: quien la use no
	 * tiene que desconfiar de cada campo.
	 *
	 * @param mixed $product Lo que vino.
	 */
	private static function is_well_formed( $product ): bool {
		return is_array( $product )
			&& is_int( $product['id'] ?? null ) && $product['id'] > 0
			&& is_string( $product['sku'] ?? null )
			&& is_string( $product['name'] ?? null )
			&& ( null === ( $product['stock'] ?? null ) || is_int( $product['stock'] ) )
			&& is_string( $product['status'] ?? null )
			&& is_bool( $product['linked'] ?? null );
	}
}
