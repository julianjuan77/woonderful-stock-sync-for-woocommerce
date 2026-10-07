<?php
/**
 * Las filas de Productos.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Catalog;

use Woonderful\StockSync\Linking\Links;
use Woonderful\StockSync\Linking\State;
use Woonderful\StockSync\Log\Events;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Peers;

defined( 'ABSPATH' ) || exit;

/**
 * Una fila por cada vinculable de esta tienda, con los dos lados: su estado, el motivo de un
 * sin vincular y la sugerencia por SKU (ADR-014 §3, §4).
 *
 * Se calcula sobre todo el catálogo —con la copia del de la otra— y se devuelve de a 50: los
 * filtros y los conteos no se contestan mirando una página.
 */
final class Rows {

	/**
	 * Filas por página.
	 */
	public const PER_PAGE = 50;

	/**
	 * El dueño descartó la sugerencia.
	 */
	public const DISCARDED = 'discarded';

	/**
	 * Está en la papelera.
	 */
	public const TRASHED = 'trashed';

	/**
	 * El de acá no tiene SKU.
	 */
	public const NO_SKU = 'no_sku';

	/**
	 * El SKU se repite entre los vinculables de acá, o entre los de allá.
	 */
	public const DUPLICATE_SKU = 'duplicate_sku';

	/**
	 * Ningún vinculable sin vincular de allá tiene ese SKU.
	 */
	public const NO_MATCH = 'no_match';

	/**
	 * Uno solo de allá tiene ese SKU, sin vincular.
	 */
	public const SUGGESTION = 'suggestion';

	/**
	 * Un variable que lleva el stock en el padre: sus variaciones no tienen nada que vincular.
	 */
	public const STOCK_IN_PARENT = 'stock_in_parent';

	/**
	 * Los estados del filtro, además de los de `State`.
	 */
	public const STATES = array( 'all', self::SUGGESTION, State::UNLINKED, State::LINKED, State::SUSPENDED );

	/**
	 * Los estados de un producto que se listan: también la papelera, que es un motivo.
	 */
	private const STATUSES = array( 'publish', 'private', 'draft', 'pending', 'future', 'trash' );

	/**
	 * Una página de filas.
	 *
	 * @param array<string, mixed> $filters `state` (uno de `STATES`), `motive`, `search` y
	 *                                      `show_discarded`.
	 * @param int                  $page    La página, desde 1.
	 *
	 * @return array{rows: array<int, array<string, mixed>>, total: int, counts: array<string, int>}
	 */
	public static function page( array $filters, int $page = 1 ): array {
		$rows = self::all();

		if ( true !== ( $filters['show_discarded'] ?? false ) ) {
			$rows = array_filter( $rows, static fn ( array $row ) => self::DISCARDED !== $row['motive'] );
		}

		$search = self::normalize( (string) ( $filters['search'] ?? '' ) );

		if ( '' !== $search ) {
			$rows = array_filter( $rows, static fn ( array $row ) => self::matches( $row, $search ) );
		}

		$counts = array_fill_keys( self::STATES, 0 );

		foreach ( $rows as $row ) {
			++$counts['all'];
			++$counts[ $row['state'] ];

			if ( self::SUGGESTION === $row['motive'] ) {
				++$counts[ self::SUGGESTION ];
			}
		}

		$state  = (string) ( $filters['state'] ?? 'all' );
		$motive = (string) ( $filters['motive'] ?? '' );
		$rows   = array_filter(
			$rows,
			static fn ( array $row ) => ( 'all' === $state || $state === $row['state'] || ( self::SUGGESTION === $state && self::SUGGESTION === $row['motive'] ) )
				&& ( '' === $motive || $motive === $row['motive'] )
		);

		return array(
			'rows'   => array_slice( array_values( $rows ), ( max( 1, $page ) - 1 ) * self::PER_PAGE, self::PER_PAGE ),
			'total'  => count( $rows ),
			'counts' => $counts,
		);
	}

	/**
	 * Todas las filas, con su estado y su motivo, agrupadas por padre.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$local  = self::local();
		$remote = self::remote();
		$linked = self::linked_remotes();

		$linkable = array_filter( $local, static fn ( array $item ) => $item['own_stock'] );
		$states   = State::from_copy( array_keys( $linkable ), $remote );
		$links    = self::links_of( array_keys( $linkable ) );
		$skus     = self::sku_counts( $linkable );
		$by_sku   = self::remote_by_sku( $remote );
		$discards = Events::discarded_among( array_keys( $linkable ) );
		$rows     = array();

		foreach ( $linkable as $id => $item ) {
			$state = $states[ $id ];
			$row   = self::row( $item, $local );

			$row['state']  = $state['state'];
			$row['reason'] = null === $state['reason'] ? null : Events::reason_text( (string) $state['reason'] );

			if ( State::UNLINKED === $state['state'] ) {
				list( $row['motive'], $row['suggestion'] ) = self::motive( $item, isset( $discards[ $id ] ), $skus, $by_sku, $linked );
			} elseif ( isset( $links[ $id ] ) ) {
				$row['remote'] = $remote[ $links[ $id ] ] ?? array( 'id' => $links[ $id ] );
			}

			$rows[] = $row;
		}

		foreach ( $local as $item ) {
			if ( $item['stock_in_parent'] ) {
				$row           = self::row( $item, $local );
				$row['state']  = State::UNLINKED;
				$row['motive'] = self::STOCK_IN_PARENT;
				$rows[]        = $row;
			}
		}

		// Las variaciones, juntas y debajo de su padre.
		usort( $rows, static fn ( array $a, array $b ) => array( $a['group'], $a['parent'] > 0, $a['id'] ) <=> array( $b['group'], $b['parent'] > 0, $b['id'] ) );

		return $rows;
	}

	/**
	 * La fila de un producto, como queda después de una acción: la pantalla la reemplaza con lo
	 * que dice el servidor, no con lo que esperaba (UC-05). Null si no es un vinculable.
	 *
	 * @param int $product Producto de esta tienda.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function one( int $product ): ?array {
		foreach ( self::all() as $row ) {
			if ( $product === $row['id'] ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * El SKU como se compara: sin espacios a los costados y sin distinguir mayúsculas
	 * (ADR-014 §3).
	 *
	 * @param string $sku El SKU.
	 */
	public static function normalize( string $sku ): string {
		return mb_strtolower( trim( $sku ) );
	}

	/**
	 * Una fila, sin estado todavía.
	 *
	 * @param array<string, mixed>             $item  El producto.
	 * @param array<int, array<string, mixed>> $local Todos, para el nombre del padre.
	 *
	 * @return array<string, mixed>
	 */
	private static function row( array $item, array $local ): array {
		return array(
			'id'          => $item['id'],
			'parent'      => $item['parent'],
			'parent_name' => $item['parent'] > 0 ? ( $local[ $item['parent'] ]['name'] ?? null ) : null,
			'group'       => $item['parent'] > 0 ? $item['parent'] : $item['id'],
			'name'        => $item['name'],
			'sku'         => $item['sku'],
			'stock'       => $item['stock'],
			'status'      => $item['status'],
			'state'       => null,
			'reason'      => null,
			'motive'      => null,
			'remote'      => null,
			'suggestion'  => null,
		);
	}

	/**
	 * Por qué no está vinculado, en el orden de ADR-014 §3, y la sugerencia si la hay.
	 *
	 * @param array<string, mixed>                            $item      El producto.
	 * @param bool                                            $discarded Si el dueño la descartó.
	 * @param array<string, int>                              $skus      Cuántos de acá tienen cada SKU.
	 * @param array<string, array<int, array<string, mixed>>> $by_sku    Los vinculables de allá, por SKU.
	 * @param array<int, true>                                $linked    Los de allá que ya tienen par.
	 *
	 * @return array{0: string, 1: array<string, mixed>|null}
	 */
	private static function motive( array $item, bool $discarded, array $skus, array $by_sku, array $linked ): array {
		if ( 'trash' === $item['status'] ) {
			return array( self::TRASHED, null );
		}

		if ( $discarded ) {
			return array( self::DISCARDED, null );
		}

		$sku = self::normalize( $item['sku'] );

		if ( '' === $sku ) {
			return array( self::NO_SKU, null );
		}

		$there = $by_sku[ $sku ] ?? array();

		if ( ( $skus[ $sku ] ?? 0 ) > 1 || count( $there ) > 1 ) {
			return array( self::DUPLICATE_SKU, null );
		}

		if ( array() === $there || isset( $linked[ (int) $there[0]['id'] ] ) ) {
			return array( self::NO_MATCH, null );
		}

		return array( self::SUGGESTION, $there[0] );
	}

	/**
	 * Si una fila tiene el texto buscado: el nombre o el SKU, de los dos lados (ADR-014 §4).
	 *
	 * @param array<string, mixed> $row    La fila.
	 * @param string               $search Lo buscado, normalizado.
	 */
	private static function matches( array $row, string $search ): bool {
		$texts = array( $row['name'], $row['sku'], $row['parent_name'] );

		foreach ( array( 'remote', 'suggestion' ) as $side ) {
			if ( is_array( $row[ $side ] ) ) {
				$texts[] = $row[ $side ]['name'] ?? '';
				$texts[] = $row[ $side ]['sku'] ?? '';
			}
		}

		foreach ( $texts as $text ) {
			if ( str_contains( self::normalize( (string) $text ), $search ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Los productos de acá que se listan, por ID: simples, variaciones y padres variables,
	 * con si llevan su propio stock. Una consulta para todo el catálogo.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function local(): array {
		global $wpdb;

		$statuses = implode( ', ', array_fill( 0, count( self::STATUSES ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_parent, p.post_type, p.post_status, p.post_title,
				        MAX( CASE WHEN m.meta_key = '_sku' THEN m.meta_value END ) AS sku,
				        MAX( CASE WHEN m.meta_key = '_manage_stock' THEN m.meta_value END ) AS manage,
				        MAX( CASE WHEN m.meta_key = '_stock' THEN m.meta_value END ) AS stock,
				        MAX( t.slug ) AS type
				   FROM {$wpdb->posts} p
				   LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ( '_sku', '_manage_stock', '_stock' )
				   LEFT JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID AND p.post_type = 'product'
				   LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
				   LEFT JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				  WHERE p.post_type IN ( 'product', 'product_variation' ) AND p.post_status IN ( {$statuses} )
				  GROUP BY p.ID",
				self::STATUSES
			)
		);
		// phpcs:enable

		$items = array();

		foreach ( $rows as $row ) {
			$variation = 'product_variation' === $row->post_type;
			$type      = $variation ? 'variation' : (string) ( $row->type ?? 'simple' );

			$items[ (int) $row->ID ] = array(
				'id'              => (int) $row->ID,
				'parent'          => $variation ? (int) $row->post_parent : 0,
				'type'            => $type,
				'name'            => (string) $row->post_title,
				'sku'             => (string) $row->sku,
				'manage'          => 'yes' === $row->manage,
				'stock'           => null === $row->stock ? 0 : (int) wc_stock_amount( (float) $row->stock ),
				'status'          => (string) $row->post_status,
				'own_stock'       => 'yes' === $row->manage && ! in_array( $type, array( 'variable', 'grouped', 'external' ), true ),
				'stock_in_parent' => false,
			);
		}

		foreach ( $items as $id => $item ) {
			if ( ! $item['own_stock'] ) {
				$items[ $id ]['stock'] = null;
			}

			// Una variación sin el suyo, de un padre que lo lleva: el padre lo dice.
			if ( 'variation' === $item['type'] && ! $item['manage'] && isset( $items[ $item['parent'] ] ) && $items[ $item['parent'] ]['manage'] ) {
				$items[ $item['parent'] ]['stock_in_parent'] = true;
			}
		}

		return $items;
	}

	/**
	 * La copia del catálogo de la otra, por ID.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function remote(): array {
		global $wpdb;

		$table = Installer::table( Installer::REMOTE_PRODUCTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( "SELECT remote_product, parent, type, sku, name, stock, status, linked FROM {$table}" );

		$remote = array();

		foreach ( $rows as $row ) {
			$remote[ (int) $row->remote_product ] = array(
				'id'     => (int) $row->remote_product,
				'parent' => (int) $row->parent,
				'type'   => (string) $row->type,
				'sku'    => (string) $row->sku,
				'name'   => (string) $row->name,
				'stock'  => null === $row->stock ? null : (int) $row->stock,
				'status' => (string) $row->status,
				'linked' => '1' === (string) $row->linked,
			);
		}

		return $remote;
	}

	/**
	 * Los vinculables de allá, por SKU normalizado: llevan su propio stock y no están en la
	 * papelera.
	 *
	 * @param array<int, array<string, mixed>> $remote La copia.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function remote_by_sku( array $remote ): array {
		$by_sku = array();

		foreach ( $remote as $product ) {
			$sku = self::normalize( $product['sku'] );

			if ( '' !== $sku && null !== $product['stock'] && 'trash' !== $product['status'] ) {
				$by_sku[ $sku ][] = $product;
			}
		}

		return $by_sku;
	}

	/**
	 * Cuántos vinculables de acá, fuera de la papelera, tienen cada SKU normalizado.
	 *
	 * @param array<int, array<string, mixed>> $linkable Los vinculables.
	 *
	 * @return array<string, int>
	 */
	private static function sku_counts( array $linkable ): array {
		$counts = array();

		foreach ( $linkable as $item ) {
			$sku = self::normalize( $item['sku'] );

			if ( '' !== $sku && 'trash' !== $item['status'] ) {
				$counts[ $sku ] = ( $counts[ $sku ] ?? 0 ) + 1;
			}
		}

		return $counts;
	}

	/**
	 * Los de allá que ya tienen par: según la copia, o según los vínculos de acá, que pueden
	 * ser más nuevos que la copia.
	 *
	 * @return array<int, true>
	 */
	private static function linked_remotes(): array {
		global $wpdb;

		$links  = Installer::table( 'links' );
		$remote = Installer::table( Installer::REMOTE_PRODUCTS );
		$peer   = Peers::only();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = array_merge(
			null === $peer ? array() : (array) $wpdb->get_col( $wpdb->prepare( "SELECT remote_product FROM {$links} WHERE remote_store = %s", $peer['store'] ) ),
			(array) $wpdb->get_col( "SELECT remote_product FROM {$remote} WHERE linked = 1" )
		);
		// phpcs:enable

		return array_fill_keys( array_map( 'intval', $ids ), true );
	}

	/**
	 * El producto de allá de cada vínculo de acá.
	 *
	 * @param int[] $products Los de acá.
	 *
	 * @return array<int, int>
	 */
	private static function links_of( array $products ): array {
		$remote = array();

		foreach ( Links::for_products( $products ) as $product => $link ) {
			$remote[ (int) $product ] = (int) $link->remote_product;
		}

		return $remote;
	}
}
