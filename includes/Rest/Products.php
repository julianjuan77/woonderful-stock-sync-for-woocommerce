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
	 * Una página del catálogo, o los productos pedidos con `include`.
	 *
	 * @param \WP_REST_Request $request El request, ya autenticado.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function respond( \WP_REST_Request $request ) {
		$include = self::ids( (string) ( $request->get_param( 'include' ) ?? '' ) );

		if ( null !== $include && count( $include ) > self::MAX_PER_PAGE ) {
			return new \WP_Error( 'wss_bad_request', __( 'Se piden hasta 100 productos por vez.', 'woonderful-stock-sync-for-woocommerce' ), array( 'status' => 400 ) );
		}

		if ( null !== $include ) {
			$products = self::requested( $include );
			$total    = count( $products );
		} else {
			$found    = wc_get_products(
				array(
					'type'     => self::TYPES,
					'status'   => self::STATUSES,
					'limit'    => min( self::MAX_PER_PAGE, max( 1, (int) ( $request->get_param( 'per_page' ) ?? self::MAX_PER_PAGE ) ) ),
					'page'     => max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) ),
					'orderby'  => 'ID',
					'order'    => 'ASC',
					'paginate' => true,
				)
			);
			$products = is_object( $found ) && isset( $found->products ) ? (array) $found->products : array();
			$total    = is_object( $found ) && isset( $found->total ) ? (int) $found->total : 0;
		}

		$ids    = array_map( static fn ( $product ) => (int) $product->get_id(), $products );
		$linked = Links::linked_among( $ids );
		$stocks = self::stocks_in_db( $ids );

		return new \WP_REST_Response(
			array(
				'products' => array_map(
					static fn ( \WC_Product $product ) => self::shape( $product, $linked, $stocks ),
					$products
				),
				'total'    => $total,
			),
			200
		);
	}

	/**
	 * Los productos pedidos, de cualquier tipo y estado, en orden de ID.
	 *
	 * Por ID y no por tipo de WooCommerce: se puede vincular una suscripción, un producto con
	 * un estado de otro plugin, o uno cuyo tipo ya no está registrado. Si no volviera, la otra
	 * tienda lo daría por borrado (ADR-005 §5).
	 *
	 * @param int[] $ids Los IDs pedidos.
	 *
	 * @return \WC_Product[]
	 */
	private static function requested( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'        => array( 'product', 'product_variation' ),
				'post__in'         => $ids,
				'post_status'      => array_keys( get_post_stati() ),
				'numberposts'      => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);

		$products = array();

		foreach ( $posts as $post_id ) {
			$product = wc_get_product( (int) $post_id );

			if ( $product instanceof \WC_Product ) {
				$products[] = $product;
			}
		}

		return $products;
	}

	/**
	 * El `_stock` de esos productos, leído de la base. Una consulta para toda la página.
	 *
	 * De la base y no del objeto: es el número que el dueño copia en el bootstrap, y la
	 * secundaria lo compara contra la base (ADR-008 §3). Con una caché desactualizada, el
	 * dueño vería un número viejo, recibiría `stock_changed`, y volvería a ver el mismo.
	 *
	 * @param int[] $ids Los productos.
	 *
	 * @return array<int, int>
	 */
	private static function stocks_in_db( array $ids ): array {
		global $wpdb;

		if ( array() === $ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_stock' AND post_id IN ( {$placeholders} )", ...$ids ) );
		// phpcs:enable

		$stocks = array();

		foreach ( (array) $rows as $row ) {
			$stocks[ (int) $row->post_id ] = (int) wc_stock_amount( (float) $row->meta_value );
		}

		return $stocks;
	}

	/**
	 * Un producto como viaja.
	 *
	 * @param \WC_Product     $product El producto.
	 * @param int[]           $linked  Los IDs de los productos vinculados de esta página.
	 * @param array<int, int> $stocks  El `_stock` de la base, por producto.
	 *
	 * @return array<string, mixed>
	 */
	private static function shape( \WC_Product $product, array $linked, array $stocks ): array {
		$id = (int) $product->get_id();

		// Sólo el que gestiona su propio stock: una variación que lo toma del padre mostraría
		// el número del padre, y ése no es el suyo para vincular.
		$own_stock = Links::holds_own_stock( $product );

		return array(
			'id'     => $id,
			'sku'    => (string) $product->get_sku( 'edit' ),
			'name'   => (string) $product->get_name( 'edit' ),
			'stock'  => $own_stock ? ( $stocks[ $id ] ?? 0 ) : null,
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
