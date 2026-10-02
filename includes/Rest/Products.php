<?php
/**
 * El endpoint que muestra el catálogo de esta tienda a la otra.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Rest;

use Woonderful\StockSync\Linking\Links;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /wss/v1/products`: el catálogo, de sólo lectura (ADR-005 §5).
 */
final class Products {

	/**
	 * Tope de productos por página: una página no tiene que hacerle timeout a la otra tienda.
	 */
	public const MAX_PER_PAGE = 100;

	/**
	 * Lo que se puede vincular, más el padre que gestiona el stock de sus variaciones.
	 */
	private const TYPES = array( 'simple', 'variable', 'variation' );

	/**
	 * Todos los estados, la papelera incluida: es lo que deja decir "suspendido" (UC-03).
	 */
	private const STATUSES = array( 'publish', 'private', 'draft', 'pending', 'future', 'trash' );

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
			Movements::NAMESPACE,
			'/products',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'respond' ),
				'permission_callback' => array( self::class, 'authenticate' ),
			)
		);
	}

	/**
	 * Autentica sin anotar el nonce: sólo lee, como `GET /health`.
	 *
	 * @param \WP_REST_Request $request El request.
	 *
	 * @return true|\WP_Error
	 */
	public static function authenticate( \WP_REST_Request $request ) {
		return Auth::authenticate( $request, false );
	}

	/**
	 * Una página del catálogo, ordenada por ID.
	 *
	 * @param \WP_REST_Request $request El request, ya autenticado.
	 */
	public static function respond( \WP_REST_Request $request ): \WP_REST_Response {
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );
		$per_page = min( self::MAX_PER_PAGE, max( 1, (int) ( $request->get_param( 'per_page' ) ?? self::MAX_PER_PAGE ) ) );
		$include  = self::ids( (string) ( $request->get_param( 'include' ) ?? '' ) );

		$args = array(
			'type'     => self::TYPES,
			'status'   => self::STATUSES,
			'limit'    => $per_page,
			'page'     => $page,
			'orderby'  => 'ID',
			'order'    => 'ASC',
			'paginate' => true,
		);

		if ( null !== $include ) {
			// Ninguno que valga: sin esto, un `include` vacío devolvería todo el catálogo.
			$args['include'] = array() === $include ? array( 0 ) : $include;
		}

		$found    = wc_get_products( $args );
		$products = is_object( $found ) && isset( $found->products ) ? (array) $found->products : array();
		$linked   = Links::linked_among( array_map( static fn ( $product ) => (int) $product->get_id(), $products ) );

		return new \WP_REST_Response(
			array(
				'products' => array_map(
					static fn ( \WC_Product $product ) => self::shape( $product, $linked ),
					$products
				),
				'total'    => is_object( $found ) && isset( $found->total ) ? (int) $found->total : 0,
			),
			200
		);
	}

	/**
	 * Un producto como viaja.
	 *
	 * @param \WC_Product $product El producto.
	 * @param int[]       $linked  Los IDs de los productos vinculados de esta página.
	 *
	 * @return array<string, mixed>
	 */
	private static function shape( \WC_Product $product, array $linked ): array {
		$id = (int) $product->get_id();

		// Sólo el que gestiona su propio stock: una variación que lo toma del padre mostraría
		// el número del padre, y ése no es el suyo para vincular.
		$own_stock = $product->managing_stock() && (int) $product->get_stock_managed_by_id() === $id;

		return array(
			'id'     => $id,
			'sku'    => (string) $product->get_sku( 'edit' ),
			'name'   => (string) $product->get_name( 'edit' ),
			'stock'  => $own_stock ? (int) wc_stock_amount( (float) ( $product->get_stock_quantity( 'edit' ) ?? 0 ) ) : null,
			'status' => (string) $product->get_status( 'edit' ),
			'linked' => in_array( $id, $linked, true ),
		);
	}

	/**
	 * Los IDs de `include`, o null si no se pidió. Texto separado por comas: la firma no
	 * admite listas (ADR-001 §1).
	 *
	 * @param string $include Lo que vino.
	 *
	 * @return int[]|null
	 */
	private static function ids( string $include ): ?array {
		if ( '' === trim( $include ) ) {
			return null;
		}

		return array_values( array_filter( array_map( 'intval', explode( ',', $include ) ), static fn ( int $id ) => $id > 0 ) );
	}
}
