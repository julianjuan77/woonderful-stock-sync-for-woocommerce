<?php
/**
 * El catálogo de la otra tienda.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Transport;

use Woonderful\StockSync\Identity\Store;

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
	 * Una página del catálogo de la otra tienda, o sólo esos productos.
	 *
	 * @param int        $page     La página.
	 * @param int        $per_page Cuántos, hasta 100.
	 * @param int[]|null $include  Sólo estos IDs, o null para todos.
	 *
	 * @return array{products: array<int, array<string, mixed>>, total: int}|\WP_Error
	 */
	public static function fetch( int $page = 1, int $per_page = 100, ?array $include = null ) {
		$peer = Peers::only();

		if ( null === $peer ) {
			return new \WP_Error( 'wss_not_paired', __( 'Esta tienda no está emparejada con otra.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		// En texto: la firma no admite listas ni booleanos (ADR-001 §1). La query que se firma
		// es la misma que viaja.
		$query = array(
			'page'     => (string) $page,
			'per_page' => (string) $per_page,
		);

		if ( null !== $include ) {
			$query['include'] = implode( ',', array_map( 'intval', $include ) );
		}

		$response = wp_remote_get(
			add_query_arg( array_map( 'rawurlencode', $query ), untrailingslashit( $peer['rest_url'] ) . '/products' ),
			array(
				'headers'     => Signature::headers( Store::id(), $peer['secret'], 'GET', self::ROUTE, $query, '' ),
				'timeout'     => Sender::TIMEOUT,
				'redirection' => 0,
			)
		);

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
			'products' => array_values( $payload['products'] ),
			'total'    => (int) ( $payload['total'] ?? 0 ),
		);
	}
}
