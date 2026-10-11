<?php
/**
 * La comparación de la noche entre las dos tiendas.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Reconciliation;

use Woonderful\StockSync\Detector\Detector;
use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Linking\Links;
use Woonderful\StockSync\Log\Events;
use Woonderful\StockSync\Log\Notifier;
use Woonderful\StockSync\Plugin;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Catalog;
use Woonderful\StockSync\Transport\Pass;
use Woonderful\StockSync\Transport\Pause;
use Woonderful\StockSync\Transport\Peers;

defined( 'ABSPATH' ) || exit;

/**
 * La auditoría compara cada tienda con su propio libro, nunca con la otra (ADR-010): una
 * diferencia entre las dos deja los dos libros cerrando. Ésta las compara, en la maestra, y
 * avisa lo que no coincide (ADR-021).
 */
final class Crosscheck {

	/**
	 * Las diferencias ya avisadas: `{link_id: {here, there, at}}`.
	 */
	public const OPTION = 'wss_cross_diffs';

	/**
	 * La segunda medida.
	 */
	public const CONFIRM = 'wss_crosscheck_confirm';

	/**
	 * Compara los pares y deja la segunda medida agendada para los que no coinciden.
	 *
	 * Cierra las avisadas que ya coinciden. Las que no coinciden no se avisan todavía: una
	 * venta que se está anotando las separa un momento (ADR-010 §3).
	 *
	 * @return array{compared: int, suspected: int, closed: int}|null Null si esa noche no se
	 *         compara: no es la maestra, está en pausa, o la otra no respondió.
	 */
	public static function run(): ?array {
		$measured = self::measure( self::candidates() );

		if ( null === $measured ) {
			return null;
		}

		$known     = self::known();
		$suspected = array();
		$closed    = 0;

		foreach ( $measured as $link_id => $stocks ) {
			if ( $stocks['here'] !== $stocks['there'] ) {
				$suspected[] = array( $link_id, $stocks['here'], $stocks['there'] );
				continue;
			}

			if ( isset( $known[ $link_id ] ) ) {
				unset( $known[ $link_id ] );
				Events::record( Events::CROSS_DIFF_CLOSED, Events::INFO, $stocks['product'] );
				++$closed;
			}
		}

		// Lo avisado de un vínculo que ya no está no se cierra nunca: se olvida.
		$known = array_intersect_key( $known, array_flip( array_map( static fn ( \stdClass $link ) => (int) $link->id, Links::all() ) ) );

		self::save( $known );

		if ( array() !== $suspected && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + Audit::CONFIRM_AFTER, self::CONFIRM, array( $suspected ), Pass::GROUP );
		}

		return array(
			'compared'  => count( $measured ),
			'suspected' => count( $suspected ),
			'closed'    => $closed,
		);
	}

	/**
	 * La segunda medida: avisa lo que sigue con los mismos números y no se avisó así.
	 *
	 * Lo que llega viene de los argumentos guardados en Action Scheduler: se revisa como
	 * cualquier entrada.
	 *
	 * @param mixed $suspected `[link_id, acá, allá]` de la primera medida.
	 */
	public static function confirm( $suspected ): void {
		if ( null !== Plugin::suspension_reason() || ! is_array( $suspected ) ) {
			return;
		}

		$first = array();

		foreach ( $suspected as $entry ) {
			if ( is_array( $entry ) && isset( $entry[0], $entry[1], $entry[2] ) ) {
				$first[ (int) $entry[0] ] = array( (int) $entry[1], (int) $entry[2] );
			}
		}

		$links = array_filter( self::candidates(), static fn ( \stdClass $link ) => isset( $first[ (int) $link->id ] ) );
		$again = self::measure( $links );

		if ( null === $again ) {
			return;
		}

		$known = self::known();
		$new   = array();

		foreach ( $again as $link_id => $stocks ) {
			if ( array( $stocks['here'], $stocks['there'] ) !== $first[ $link_id ] ) {
				continue;
			}

			// La misma diferencia ya avisada no se repite; una distinta, sí.
			if ( isset( $known[ $link_id ] ) && $known[ $link_id ]['here'] === $stocks['here'] && $known[ $link_id ]['there'] === $stocks['there'] ) {
				continue;
			}

			$known[ $link_id ] = array(
				'here'  => $stocks['here'],
				'there' => $stocks['there'],
				'at'    => gmdate( 'Y-m-d H:i:s' ),
			);

			Events::record(
				Events::CROSS_DIFF,
				Events::WARNING,
				$stocks['product'],
				array(
					'here'  => $stocks['here'],
					'there' => $stocks['there'],
				)
			);

			$new[] = $stocks;
		}

		self::save( $known );

		if ( array() !== $new ) {
			Notifier::cross_diffs( $new );
		}
	}

	/**
	 * Los vínculos que se comparan esta noche, por ID.
	 *
	 * Sólo la maestra, fuera de pausa, y sólo pares con la otra emparejada. Sin movimientos
	 * propios por entregar: el número de allá todavía no los tiene.
	 *
	 * @return array<int, \stdClass>
	 */
	private static function candidates(): array {
		$peer = Peers::only();

		if ( null === $peer || ! Peers::this_is_master() || Pause::is_on() ) {
			return array();
		}

		$undelivered = self::undelivered();
		$unresolved  = array_flip( Books::unresolved_generations() );
		$links       = array();

		foreach ( Links::all() as $link ) {
			if ( $link->remote_store !== $peer['store'] || isset( $undelivered[ (int) $link->id ] ) ) {
				continue;
			}

			// Lo que la auditoría no revisa tampoco se compara: retenido, sin resolver, o sin
			// su propio stock.
			if ( null === Audit::measure( $link, $unresolved ) ) {
				continue;
			}

			$links[ (int) $link->id ] = $link;
		}

		return $links;
	}

	/**
	 * El stock de acá y el de allá de cada vínculo, pedido a la otra en el momento.
	 *
	 * @param array<int, \stdClass> $links Los vínculos.
	 *
	 * @return array<int, array{product: int, here: int, there: int}>|null Null si no hay qué
	 *         comparar o la otra no respondió.
	 */
	private static function measure( array $links ): ?array {
		if ( array() === $links ) {
			return null;
		}

		$answer = Catalog::fetch( 1, 100, array_map( static fn ( \stdClass $link ) => (int) $link->remote_product, array_values( $links ) ) );

		if ( is_wp_error( $answer ) ) {
			return null;
		}

		$theirs = array_column( $answer['products'], null, 'id' );
		$result = array();

		foreach ( $links as $link_id => $link ) {
			$other = $theirs[ (int) $link->remote_product ] ?? null;

			// Borrado, en la papelera o sin su stock allá: es un par roto, no una diferencia.
			if ( null === $other || 'trash' === ( $other['status'] ?? null ) || null === ( $other['stock'] ?? null ) ) {
				continue;
			}

			$result[ $link_id ] = array(
				'product' => (int) $link->local_product,
				'here'    => Detector::stock_in_db( (int) $link->local_product ),
				'there'   => (int) $other['stock'],
			);
		}

		return $result;
	}

	/**
	 * Los vínculos con movimientos propios todavía no confirmados por la otra.
	 *
	 * @return array<int, true>
	 */
	private static function undelivered(): array {
		global $wpdb;

		$table = Installer::table( 'movements' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT link_id FROM {$table} WHERE origin_store = %s AND delivery IN ( 'pending', 'in_flight', 'abandoned' ) AND link_id IS NOT NULL", Store::id() ) );

		return array_fill_keys( array_map( 'intval', (array) $ids ), true );
	}

	/**
	 * Las diferencias avisadas.
	 *
	 * @return array<int, array{here: int, there: int, at: string}>
	 */
	public static function known(): array {
		$stored = get_option( self::OPTION );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Guarda las avisadas.
	 *
	 * @param array<int, array{here: int, there: int, at: string}> $known Las avisadas.
	 */
	private static function save( array $known ): void {
		if ( array() === $known ) {
			delete_option( self::OPTION );
			return;
		}

		update_option( self::OPTION, $known, false );
	}
}
