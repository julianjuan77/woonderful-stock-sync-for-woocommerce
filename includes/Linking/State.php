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
use Woonderful\StockSync\Transport\Peers;

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
	 * Al catálogo de la otra tienda se le pregunta por los vinculados en tandas de 100. Si no
	 * responde —o no hay socia—, esos productos **siguen vinculados**: una caída no rompió
	 * ningún par, y se avisa una vez, en `remote_error`, no producto por producto (UC-03).
	 *
	 * @param int[] $products Productos de esta tienda.
	 *
	 * @return array{states: array<int, array{state: string, reason: string|null}>, remote_reachable: bool, remote_error: string|null}
	 */
	public static function of( array $products ): array {
		$products  = array_values( array_unique( array_map( 'intval', $products ) ) );
		$links     = Links::for_products( $products );
		$uncertain = self::uncertain_links();
		$peer      = Peers::only();

		// Por los que hay que preguntarle a la socia: vinculados, resueltos, y de ella. Uno de
		// una tienda que ya no es la socia no se le pregunta: un ID igual sería otro producto.
		$asked = array();

		foreach ( $links as $link ) {
			if ( null !== $peer && $link->remote_store === $peer['store'] && ! isset( $uncertain[ (int) $link->id ] ) ) {
				$asked[] = (int) $link->remote_product;
			}
		}

		list( $remote, $unknown, $error ) = self::ask( null === $peer, $asked );

		$states = array();

		foreach ( $products as $product ) {
			$states[ $product ] = self::state_of( $product, $links[ $product ] ?? null, $uncertain, $peer, $remote, $unknown );
		}

		return array(
			'states'           => $states,
			'remote_reachable' => null === $error,
			'remote_error'     => $error,
		);
	}

	/**
	 * Le pregunta a la socia por esos productos suyos, de a 100.
	 *
	 * Una tanda que falla deja sin saber sólo a los suyos: el resto usa lo que respondió.
	 *
	 * @param bool  $unpaired Si no hay socia.
	 * @param int[] $ids      Sus productos.
	 *
	 * @return array{0: array<int, array<string, mixed>>, 1: array<int, true>, 2: string|null} Lo
	 *         que respondió por ID, los que quedaron sin saber, y el primer error.
	 */
	private static function ask( bool $unpaired, array $ids ): array {
		if ( $unpaired ) {
			return array( array(), array(), 'wss_not_paired' );
		}

		$remote  = array();
		$unknown = array();
		$error   = null;

		foreach ( array_chunk( array_values( array_unique( $ids ) ), Catalog::MAX_INCLUDE ) as $batch ) {
			$result = Catalog::fetch( 1, Catalog::MAX_INCLUDE, $batch );

			if ( is_wp_error( $result ) ) {
				$unknown += array_fill_keys( $batch, true );
				$error    = $error ?? (string) $result->get_error_code();

				continue;
			}

			foreach ( $result['products'] as $other ) {
				$remote[ (int) $other['id'] ] = $other;
			}
		}

		return array( $remote, $unknown, $error );
	}

	/**
	 * El estado de un producto: el mismo criterio con que `apply()` retiene (ADR-005 §1).
	 *
	 * @param int                              $product   Producto de esta tienda.
	 * @param \stdClass|null                   $link      Su vínculo.
	 * @param array<int, true>                 $uncertain Los vínculos con el bootstrap sin resolver.
	 * @param array<string, string>|null       $peer      La socia.
	 * @param array<int, array<string, mixed>> $remote    Sus productos, por ID.
	 * @param array<int, true>                 $unknown   Los suyos que no se pudieron consultar.
	 *
	 * @return array{state: string, reason: string|null}
	 */
	private static function state_of( int $product, ?\stdClass $link, array $uncertain, ?array $peer, array $remote, array $unknown ): array {
		if ( null === $link ) {
			return self::state( self::UNLINKED );
		}

		// Lo primero que falta resolver es el bootstrap: no se miran las otras causas.
		if ( isset( $uncertain[ (int) $link->id ] ) ) {
			return self::state( self::UNCERTAIN );
		}

		$local = wc_get_product( $product );

		if ( ! $local instanceof \WC_Product ) {
			return self::state( self::SUSPENDED, 'local_missing' );
		}

		if ( 'trash' === $local->get_status() ) {
			return self::state( self::SUSPENDED, 'local_trashed' );
		}

		if ( ! Links::holds_own_stock( $local ) ) {
			return self::state( self::SUSPENDED, 'local_not_managing_stock' );
		}

		if ( null !== $peer && $link->remote_store !== $peer['store'] ) {
			return self::state( self::SUSPENDED, 'peer_changed' );
		}

		$remote_id = (int) $link->remote_product;

		// Sin socia, o sin respuesta: de allá no se sabe, y no se suspende por eso.
		if ( null === $peer || isset( $unknown[ $remote_id ] ) ) {
			return self::state( self::LINKED );
		}

		$other = $remote[ $remote_id ] ?? null;

		// Con `include`, uno que no vuelve ya no existe (ADR-005 §5).
		if ( null === $other ) {
			return self::state( self::SUSPENDED, 'remote_missing' );
		}

		if ( 'trash' === $other['status'] ) {
			return self::state( self::SUSPENDED, 'remote_trashed' );
		}

		if ( null === $other['stock'] ) {
			return self::state( self::SUSPENDED, 'remote_not_managing_stock' );
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
