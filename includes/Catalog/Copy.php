<?php
/**
 * La copia del catálogo de la otra tienda.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Catalog;

use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * `wss_remote_products`: el catálogo de la otra, para que la pantalla filtre y cuente sobre todo
 * él sin preguntarle en cada página (ADR-014 §1).
 *
 * Puede estar unos minutos atrasada: todo lo que escribe vuelve a mirar los dos lados en el
 * momento.
 */
final class Copy {

	/**
	 * Cuándo empezó el refresco completo en curso: lo que no se vea desde entonces, ya no está.
	 */
	public const OPTION_RUN = 'wss_catalog_run';

	/**
	 * Cuándo terminó el último refresco completo.
	 */
	public const OPTION_REFRESHED = 'wss_catalog_refreshed_at';

	/**
	 * Con más de esto, la pantalla la refresca al abrirse.
	 */
	public const MAX_AGE = 600;

	/**
	 * Productos por página: con 5.000 son 50 requests cortos, no uno largo que se corte.
	 */
	public const PER_PAGE = 100;

	/**
	 * Una página del refresco. La 1 empieza uno nuevo; la última borra lo que no se vio.
	 *
	 * Un refresco cortado a mitad no borra nada: lo que quedó sin ver sigue, con su fecha vieja.
	 *
	 * @param int $page La página, desde 1.
	 *
	 * @return array{page: int, pages: int, total: int, done: bool}|\WP_Error
	 */
	public static function refresh_page( int $page ) {
		if ( 1 === $page ) {
			update_option( self::OPTION_RUN, gmdate( 'Y-m-d H:i:s' ), false );
		}

		$run = (string) get_option( self::OPTION_RUN, '' );

		if ( '' === $run ) {
			return new \WP_Error( 'wss_refresh_not_started', __( 'El refresco del catálogo empieza por la página 1.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		$result = Catalog::fetch( $page, self::PER_PAGE );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::store( $result['products'], $run );

		$pages = max( 1, (int) ceil( $result['total'] / self::PER_PAGE ) );
		$done  = $page >= $pages;

		if ( $done ) {
			self::forget_unseen( $run );
			update_option( self::OPTION_REFRESHED, time(), false );
			delete_option( self::OPTION_RUN );
		}

		return array(
			'page'  => $page,
			'pages' => $pages,
			'total' => $result['total'],
			'done'  => $done,
		);
	}

	/**
	 * El refresco entero, de corrido: el de la noche.
	 *
	 * @return true|\WP_Error
	 */
	public static function refresh() {
		$page = 1;

		do {
			$result = self::refresh_page( $page++ );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		} while ( ! $result['done'] );

		return true;
	}

	/**
	 * Hace cuántos segundos terminó el último refresco completo, o null si nunca.
	 */
	public static function age(): ?int {
		$refreshed = (int) get_option( self::OPTION_REFRESHED, 0 );

		return 0 === $refreshed ? null : max( 0, time() - $refreshed );
	}

	/**
	 * Si la pantalla tiene que refrescarla al abrirse.
	 */
	public static function is_stale(): bool {
		$age = self::age();

		return null === $age || $age > self::MAX_AGE;
	}

	/**
	 * Busca en la copia, por nombre o SKU, el producto de la otra para vincular a mano: sólo
	 * vinculables —llevan su propio stock, fuera de la papelera— y sin par (ADR-014 §5).
	 *
	 * @param string $text  Lo buscado.
	 * @param int    $limit Cuántos, como mucho.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function search( string $text, int $limit = 20 ): array {
		global $wpdb;

		$text = trim( $text );

		if ( '' === $text ) {
			return array();
		}

		$table = Installer::table( Installer::REMOTE_PRODUCTS );
		$links = Installer::table( 'links' );
		$like  = '%' . $wpdb->esc_like( $text ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.remote_product, r.parent, r.type, r.sku, r.name, r.stock, r.status, p.name AS parent_name
				   FROM {$table} r
				   LEFT JOIN {$table} p ON p.remote_product = r.parent AND r.parent > 0
				  WHERE r.stock IS NOT NULL AND r.status <> 'trash' AND r.linked = 0
				    AND r.remote_product NOT IN ( SELECT remote_product FROM {$links} )
				    AND ( r.name LIKE %s OR r.sku LIKE %s OR p.name LIKE %s )
				  ORDER BY r.name
				  LIMIT %d",
				$like,
				$like,
				$like,
				$limit
			)
		);
		// phpcs:enable

		return array_map(
			static fn ( $row ) => array(
				'id'          => (int) $row->remote_product,
				'parent'      => (int) $row->parent,
				'parent_name' => null === $row->parent_name ? null : (string) $row->parent_name,
				'type'        => (string) $row->type,
				'sku'         => (string) $row->sku,
				'name'        => (string) $row->name,
				'stock'       => (int) $row->stock,
			),
			$rows
		);
	}

	/**
	 * Anota en la copia lo que un vínculo acaba de cambiar allá, sin esperar al próximo
	 * refresco: que el producto tiene par y, al vincular, el stock con que quedó —el de la
	 * maestra—. Si no, Productos mostraría el stock de antes de vincular hasta 10 minutos.
	 *
	 * @param int      $remote El producto de la otra tienda.
	 * @param bool     $linked Si quedó vinculado.
	 * @param int|null $stock  El stock con que quedó, o null para no tocarlo.
	 */
	public static function note( int $remote, bool $linked, ?int $stock = null ): void {
		global $wpdb;

		$columns = array( 'linked' => $linked ? 1 : 0 );

		if ( null !== $stock ) {
			$columns['stock'] = $stock;
		}

		$wpdb->update( Installer::table( Installer::REMOTE_PRODUCTS ), $columns, array( 'remote_product' => $remote ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * La vacía: es de la tienda con que estaba emparejada.
	 */
	public static function clear(): void {
		global $wpdb;

		$wpdb->query( 'DELETE FROM ' . Installer::table( Installer::REMOTE_PRODUCTS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		delete_option( self::OPTION_RUN );
		delete_option( self::OPTION_REFRESHED );
	}

	/**
	 * Guarda una página, pisando lo que había de cada producto. Un solo INSERT por página.
	 *
	 * @param array<int, array<string, mixed>> $products Los de `Catalog::fetch()`.
	 * @param string                           $run      Cuándo empezó el refresco.
	 */
	private static function store( array $products, string $run ): void {
		global $wpdb;

		if ( array() === $products ) {
			return;
		}

		$rows   = array();
		$values = array();

		foreach ( $products as $product ) {
			$rows[] = null === $product['stock'] ? '( %d, %d, %s, %s, %s, NULL, %s, %d, %s )' : '( %d, %d, %s, %s, %s, %d, %s, %d, %s )';

			array_push(
				$values,
				$product['id'],
				$product['parent'],
				substr( (string) $product['type'], 0, 32 ),
				mb_substr( (string) $product['sku'], 0, 100 ),
				mb_substr( (string) $product['name'], 0, 255 )
			);

			if ( null !== $product['stock'] ) {
				$values[] = $product['stock'];
			}

			array_push( $values, substr( (string) $product['status'], 0, 20 ), $product['linked'] ? 1 : 0, $run );
		}

		$table = Installer::table( Installer::REMOTE_PRODUCTS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared -- $rows son sólo placeholders armados acá.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} ( remote_product, parent, type, sku, name, stock, status, linked, seen_at ) VALUES " . implode( ', ', $rows ) . '
				 ON DUPLICATE KEY UPDATE parent = VALUES( parent ), type = VALUES( type ), sku = VALUES( sku ), name = VALUES( name ),
				   stock = VALUES( stock ), status = VALUES( status ), linked = VALUES( linked ), seen_at = VALUES( seen_at )',
				$values
			)
		);
		// phpcs:enable
	}

	/**
	 * Borra lo que el refresco no vio: ya no está en la otra tienda.
	 *
	 * @param string $run Cuándo empezó el refresco.
	 */
	private static function forget_unseen( string $run ): void {
		global $wpdb;

		$table = Installer::table( Installer::REMOTE_PRODUCTS );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE seen_at < %s", $run ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
