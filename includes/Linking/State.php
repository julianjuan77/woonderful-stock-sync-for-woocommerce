<?php
/**
 * El estado de sincronización de un producto.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Linking;

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Ledger\Ledger;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * El estado es una vista, no un dato: se calcula al mostrarlo (ADR-005 §1, UC-03).
 *
 * Guardarlo obligaría a escribir "suspendido" cuando el producto del otro lado se va a la
 * papelera y "vinculado" cuando vuelve, y nada en el plugin observa la papelera.
 */
final class State {

	/**
	 * Nadie fijó su par.
	 */
	public const UNLINKED = 'unlinked';

	/**
	 * Tiene par y stock base, y aplica deltas.
	 */
	public const LINKED = 'linked';

	/**
	 * Tenía par y el par se rompió: un producto del par fue borrado o está en la papelera.
	 */
	public const SUSPENDED = 'suspended';

	/**
	 * Su bootstrap no tuvo respuesta: no se sabe si la otra tienda lo fijó (ADR-008 §5).
	 */
	public const UNCERTAIN = 'uncertain';

	/**
	 * El estado de esos productos de acá.
	 *
	 * Al catálogo de la otra tienda se le pregunta una sola vez, por todos los vinculados. Si
	 * no responde, los vinculados **siguen vinculados**: una caída es temporal y no rompió
	 * ningún par, y se avisa una vez y no producto por producto (UC-03, UC-05).
	 *
	 * @param int[] $products Productos de esta tienda.
	 *
	 * @return array{states: array<int, array{state: string, reason: string|null}>, remote_reachable: bool}
	 */
	public static function of( array $products ): array {
		$links     = array();
		$uncertain = self::uncertain_links();

		foreach ( array_unique( array_map( 'intval', $products ) ) as $product ) {
			$links[ $product ] = Links::for_product( $product );
		}

		$remote_ids = array();

		foreach ( $links as $link ) {
			if ( null !== $link && ! isset( $uncertain[ (int) $link->id ] ) ) {
				$remote_ids[] = (int) $link->remote_product;
			}
		}

		$catalog          = Catalog::fetch( 1, 100, $remote_ids );
		$remote_reachable = ! is_wp_error( $catalog );
		$remote           = $remote_reachable ? array_column( $catalog['products'], null, 'id' ) : array();

		$states = array();

		foreach ( $links as $product => $link ) {
			$states[ $product ] = self::state_of( $product, $link, $uncertain, $remote_reachable, $remote );
		}

		return array(
			'states'           => $states,
			'remote_reachable' => $remote_reachable,
		);
	}

	/**
	 * El estado de un producto.
	 *
	 * @param int                              $product          Producto de esta tienda.
	 * @param \stdClass|null                   $link             Su vínculo.
	 * @param array<int, true>                 $uncertain        Los vínculos con el bootstrap sin resolver.
	 * @param bool                             $remote_reachable Si la otra tienda respondió.
	 * @param array<int, array<string, mixed>> $remote           Sus productos, por ID.
	 *
	 * @return array{state: string, reason: string|null}
	 */
	private static function state_of( int $product, ?\stdClass $link, array $uncertain, bool $remote_reachable, array $remote ): array {
		if ( null === $link ) {
			return self::state( self::UNLINKED );
		}

		if ( isset( $uncertain[ (int) $link->id ] ) ) {
			return self::state( self::UNCERTAIN );
		}

		// El par se rompe igual si el que falta es el de acá.
		$local = wc_get_product( $product );

		if ( ! $local instanceof \WC_Product ) {
			return self::state( self::SUSPENDED, 'local_missing' );
		}

		if ( 'trash' === $local->get_status() ) {
			return self::state( self::SUSPENDED, 'local_trashed' );
		}

		if ( ! $remote_reachable ) {
			return self::state( self::LINKED );
		}

		$other = $remote[ (int) $link->remote_product ] ?? null;

		// Con `include`, uno que no vuelve ya no existe (ADR-005 §5).
		if ( null === $other ) {
			return self::state( self::SUSPENDED, 'remote_missing' );
		}

		if ( 'trash' === $other['status'] ) {
			return self::state( self::SUSPENDED, 'remote_trashed' );
		}

		return self::state( self::LINKED );
	}

	/**
	 * Un estado, con su motivo.
	 *
	 * @param string      $state  El estado.
	 * @param string|null $reason Por qué, si hace falta decirlo.
	 *
	 * @return array{state: string, reason: string|null}
	 */
	private static function state( string $state, ?string $reason = null ): array {
		return array(
			'state'  => $state,
			'reason' => $reason,
		);
	}

	/**
	 * Los vínculos cuyo bootstrap propio sigue sin resolver.
	 *
	 * @return array<int, true>
	 */
	private static function uncertain_links(): array {
		global $wpdb;

		$table = Installer::table( 'movements' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT link_id FROM {$table}
				  WHERE origin_store = %s AND reason = %s AND delivery IN ( %s, %s ) AND link_id IS NOT NULL",
				Store::id(),
				Ledger::BOOTSTRAP,
				Ledger::SYNCING,
				Ledger::UNCERTAIN
			)
		);
		// phpcs:enable

		return array_fill_keys( array_map( 'intval', (array) $ids ), true );
	}
}
