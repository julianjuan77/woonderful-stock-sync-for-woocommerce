<?php
/**
 * Lo que esta tienda sabe de los libros, para compararlos con la otra.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Reconciliation;

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Ledger\Ledger;
use Woonderful\StockSync\Linking\Links;
use Woonderful\StockSync\Log\Events;
use Woonderful\StockSync\Schema\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * La fase 1 de la reconciliación, del lado de cada tienda (ADR-009 §1–§3).
 *
 * Las dos preguntas usan las mismas dos piezas: listar lo que esta tienda tiene de la otra, y
 * comparar lo propio contra lo que la otra dice tener.
 */
final class Books {

	/**
	 * Cuántas secuencias van por tanda.
	 */
	public const PAGE = 5000;

	/**
	 * La última secuencia propia: el techo de esta tienda para la pasada (ADR-009 §2).
	 */
	public static function ceiling(): int {
		global $wpdb;

		$table = Installer::table( 'movements' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX( origin_seq ) FROM {$table} WHERE origin_store = %s", Store::id() ) );
	}

	/**
	 * Si queda algo propio hasta ese techo que todavía no salió: lo que nunca se intentó
	 * mandar, o lo que está en camino. Es lo que espera el cierre de una importación.
	 *
	 * Lo que falló y espera su reintento no cuenta: puede tardar hasta 6 horas, y la
	 * reconciliación igual no le haría nada a una fila pendiente (ADR-009 §5).
	 *
	 * @param int $ceiling El techo de cuando se agendó el cierre.
	 */
	public static function unsent_through( int $ceiling ): bool {
		global $wpdb;

		$table = Installer::table( 'movements' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				  WHERE origin_store = %s AND origin_seq <= %d
				    AND ( ( delivery = 'pending' AND attempts = 0 ) OR delivery = 'in_flight' )
				  LIMIT 1",
				Store::id(),
				$ceiling
			)
		);
		// phpcs:enable

		return null !== $found;
	}

	/**
	 * Una tanda de las secuencias de esa tienda que tiene esta, en orden.
	 *
	 * Tenerla es tener la fila, en cualquier estado: aplicada, retenida o descartada.
	 *
	 * @param string $store La otra tienda.
	 * @param int    $after Desde después de esta secuencia.
	 * @param int    $until Hasta esta, incluida.
	 *
	 * @return array{seqs: int[], covered_to: int, more: bool} Las secuencias, hasta dónde
	 *         llega esta tanda, y si queda más. Una tanda llena cubre hasta su última
	 *         secuencia; la última, hasta `$until`.
	 */
	public static function held_from( string $store, int $after, int $until ): array {
		global $wpdb;

		$table = Installer::table( 'movements' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$seqs = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT origin_seq FROM {$table}
					  WHERE origin_store = %s AND origin_seq > %d AND origin_seq <= %d
					  ORDER BY origin_seq
					  LIMIT %d",
					$store,
					$after,
					$until,
					self::PAGE
				)
			)
		);
		// phpcs:enable

		$full = count( $seqs ) === self::PAGE;

		return array(
			'seqs'       => $seqs,
			'covered_to' => $full ? (int) end( $seqs ) : $until,
			'more'       => $full && (int) end( $seqs ) < $until,
		);
	}

	/**
	 * Compara lo propio de un tramo contra lo que la otra tienda dice tener (ADR-009 §3).
	 *
	 * - Lo que la otra **no** tiene se vuelve a poner en la cola, con el reloj en cero: si
	 *   está abandonado, siempre; si está confirmado, sólo por encima de la marca de purga de
	 *   la otra, porque por debajo lo tuvo y lo borró (§2).
	 * - Lo que la otra **sí** tiene y acá estaba pendiente o abandonado, se confirma: se
	 *   perdió el ACK, pero llegó.
	 *
	 * Lo que está en camino no se toca, y el bootstrap tampoco: no va por la cola. Cada
	 * cambio es un `UPDATE` sobre el estado esperado, no una lectura seguida de una
	 * escritura: la pasada podría llevarse la fila en el medio.
	 *
	 * @param int   $after      Desde después de esta secuencia propia.
	 * @param int   $until      Hasta esta, incluida.
	 * @param int[] $they_have  Las secuencias propias que la otra tiene en ese tramo.
	 * @param int   $purge_mark Hasta dónde purgó la otra lo de esta tienda.
	 *
	 * @return array{reopened: int, confirmed: int}
	 */
	public static function compare_own( int $after, int $until, array $they_have, int $purge_mark ): array {
		global $wpdb;

		$table = Installer::table( 'movements' );
		$have  = array_fill_keys( array_map( 'intval', $they_have ), true );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$own = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, origin_seq FROM {$table}
				  WHERE origin_store = %s AND origin_seq > %d AND origin_seq <= %d
				    AND reason <> %s
				    AND delivery IN ( 'pending', 'abandoned', 'confirmed' )",
				Store::id(),
				$after,
				$until,
				Ledger::BOOTSTRAP
			)
		);

		// Qué estado se toca lo deciden los `UPDATE`, no esta separación.
		$missing = array();
		$arrived = array();

		foreach ( $own as $row ) {
			if ( isset( $have[ (int) $row->origin_seq ] ) ) {
				$arrived[] = (int) $row->id;
			} else {
				$missing[] = (int) $row->id;
			}
		}

		$reopened  = 0;
		$confirmed = 0;

		if ( array() !== $missing ) {
			$reopened = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					    SET delivery = 'pending', attempts = 0, first_attempt_at = NULL, last_error = NULL,
					        next_attempt_at = UTC_TIMESTAMP(), claimed_by = NULL, claimed_at = NULL, confirmed_at = NULL
					  WHERE id IN ( " . implode( ',', $missing ) . " )
					    AND ( delivery = 'abandoned' OR ( delivery = 'confirmed' AND origin_seq > %d ) )",
					$purge_mark
				)
			);
		}

		if ( array() !== $arrived ) {
			$confirmed = (int) $wpdb->query(
				"UPDATE {$table}
				    SET delivery = 'confirmed', confirmed_at = UTC_TIMESTAMP(), last_error = NULL
				  WHERE id IN ( " . implode( ',', $arrived ) . " )
				    AND delivery IN ( 'pending', 'abandoned' )"
			);
		}
		// phpcs:enable

		return array(
			'reopened'  => $reopened,
			'confirmed' => $confirmed,
		);
	}

	/**
	 * Los vínculos con esa tienda hasta un techo, como viajan en la reconciliación.
	 *
	 * @param string $store   La otra tienda.
	 * @param int    $ceiling Sólo los de generación hasta acá: los armados después de que
	 *                        arrancó la pasada quedan para la siguiente (ADR-009 §8).
	 *
	 * @return array<int, array{local: int, remote: int, generation: int}>
	 */
	public static function links_with( string $store, int $ceiling ): array {
		global $wpdb;

		$table = Installer::table( 'links' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT local_product, remote_product, generation FROM {$table}
				  WHERE remote_store = %s AND generation <= %d
				  ORDER BY generation",
				$store,
				$ceiling
			)
		);
		// phpcs:enable

		return array_map(
			static fn ( $row ) => array(
				'local'      => (int) $row->local_product,
				'remote'     => (int) $row->remote_product,
				'generation' => (int) $row->generation,
			),
			$rows
		);
	}

	/**
	 * Compara los vínculos de esta tienda —la secundaria— con los de la maestra (ADR-009 §8).
	 *
	 * Un vínculo es el mismo si tiene la misma generación y los mismos productos, al revés.
	 * Sólo se miran los de esta tienda hasta el techo de la maestra.
	 *
	 * - Uno de acá que la maestra no tiene, y que no comparte ningún producto con uno de
	 *   ella, **se borra**: sólo vincula la maestra, así que no tiene respaldo.
	 * - Uno de acá que comparte un producto con otro de la maestra es el mismo par con otra
	 *   generación: **se informa**, no se toca.
	 * - Uno de la maestra que acá no está, **se informa**: arreglarlo sería un bootstrap, y lo
	 *   decide el dueño.
	 *
	 * @param string                                                      $master  La maestra.
	 * @param array<int, array{local: int, remote: int, generation: int}> $theirs  Sus vínculos, como los ve ella.
	 * @param int                                                         $ceiling Su techo.
	 *
	 * @return array{deleted: int[], missing: int[], mismatched: int[]} Los productos de acá
	 *         cuyo vínculo se borró, las generaciones de allá que acá faltan, y los productos
	 *         de acá vinculados con otra generación.
	 */
	public static function compare_links( string $master, array $theirs, int $ceiling ): array {
		$ours = self::links_with( $master, $ceiling );

		$theirs_by_generation = array();
		$their_locals         = array();
		$their_remotes        = array();

		foreach ( $theirs as $link ) {
			$theirs_by_generation[ $link['generation'] ] = $link;
			$their_locals[ $link['local'] ]              = true;
			$their_remotes[ $link['remote'] ]            = true;
		}

		$deleted    = array();
		$mismatched = array();
		$matched    = array();

		foreach ( $ours as $link ) {
			$same = $theirs_by_generation[ $link['generation'] ] ?? null;

			if ( null !== $same && $same['local'] === $link['remote'] && $same['remote'] === $link['local'] ) {
				$matched[ $link['generation'] ] = true;

				continue;
			}

			if ( isset( $their_locals[ $link['remote'] ] ) || isset( $their_remotes[ $link['local'] ] ) ) {
				$mismatched[] = $link['local'];

				continue;
			}

			$row = Links::for_product( $link['local'] );

			if ( null !== $row && (int) $row->generation === $link['generation'] && Links::delete( (int) $row->id ) ) {
				$deleted[] = $link['local'];
				Events::record( Events::LINK_REMOVED, Events::WARNING, $link['local'], array( 'generation' => $link['generation'] ) );
			}
		}

		// Uno de allá cuyo producto está vinculado acá con otra generación ya se informó.
		$our_remotes = array_flip( array_column( $ours, 'remote' ) );
		$missing     = array();

		foreach ( $theirs_by_generation as $generation => $link ) {
			if ( ! isset( $matched[ $generation ] ) && ! isset( $our_remotes[ $link['local'] ] ) ) {
				$missing[] = (int) $generation;
			}
		}

		return array(
			'deleted'    => $deleted,
			'missing'    => $missing,
			'mismatched' => $mismatched,
		);
	}

	/**
	 * Las generaciones de los bootstraps propios que siguen sin resolver: sus vínculos todavía
	 * no se sabe si existen del otro lado (ADR-008 §5).
	 *
	 * @return int[]
	 */
	public static function unresolved_generations(): array {
		global $wpdb;

		$table = Installer::table( 'movements' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$seqs = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT origin_seq FROM {$table}
				  WHERE origin_store = %s AND reason = %s AND delivery IN ( %s, %s )",
				Store::id(),
				Ledger::BOOTSTRAP,
				Ledger::SYNCING,
				Ledger::UNCERTAIN
			)
		);
		// phpcs:enable

		return array_map( 'intval', $seqs );
	}
}
