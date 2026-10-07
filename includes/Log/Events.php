<?php
/**
 * El log: lo que pasa y no es un movimiento de stock.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Log;

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Schema\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Escribe y lee `wss_events` (ADR-011).
 *
 * Los movimientos de stock no van acá: ya están en el libro. El texto no se guarda: cada
 * evento guarda su tipo y sus datos, y la frase se arma al mostrarlo.
 */
final class Events {

	public const INFO    = 'info';
	public const WARNING = 'warning';
	public const ERROR   = 'error';

	public const LINKED               = 'linked';
	public const LINK_FAILED          = 'link_failed';
	public const UNLINKED             = 'unlinked';
	public const LINK_UNRESOLVED      = 'link_unresolved';
	public const DISCARDED            = 'discarded';
	public const UNDISCARDED          = 'undiscarded';
	public const PEER_UNREACHABLE     = 'peer_unreachable';
	public const PEER_BACK            = 'peer_back';
	public const PEER_REJECTED        = 'peer_rejected';
	public const PEER_ACCEPTED        = 'peer_accepted';
	public const ABANDONED            = 'abandoned';
	public const LEDGER_ERROR         = 'ledger_error';
	public const RECONCILED           = 'reconciled';
	public const DISCREPANCY_OPENED   = 'discrepancy_opened';
	public const DISCREPANCY_CLOSED   = 'discrepancy_closed';
	public const DISCREPANCY_ACCEPTED = 'discrepancy_accepted';
	public const DISCREPANCY_RESTORED = 'discrepancy_restored';
	public const LINK_REMOVED         = 'link_removed';
	public const PAIR_BROKEN          = 'pair_broken';
	public const PAIR_RESTORED        = 'pair_restored';
	public const NOTIFIED             = 'notified';
	public const NOTIFY_FAILED        = 'notify_failed';

	/**
	 * Lo que la purga no borra: decisiones y memorias, no eventos (ADR-011 §6).
	 */
	public const KEPT = array( self::DISCARDED, self::UNDISCARDED, self::NOTIFIED );

	/**
	 * Cuántos días se guarda el resto: como el libro.
	 */
	public const RETENTION_DAYS = 90;

	/**
	 * Registra un evento.
	 *
	 * **Si escribir falla, no corta nada**: el log cuenta lo que pasó, no puede impedir que
	 * pase. Una venta no deja de propagarse porque no se pudo escribir una línea.
	 *
	 * @param string               $kind    Qué pasó.
	 * @param string               $level   `info`, `warning` o `error`.
	 * @param int|null             $product El producto de esta tienda, si es de uno.
	 * @param array<string, mixed> $context Los datos del evento.
	 * @param string|null          $cause   A qué se atribuye, si a algo.
	 */
	public static function record( string $kind, string $level, ?int $product = null, array $context = array(), ?string $cause = null ): void {
		global $wpdb;

		// WP-CLI sin --user da 0: sin persona, NULL.
		$user = get_current_user_id();

		$suppressed = $wpdb->suppress_errors( true );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Installer::table( 'events' ),
			array(
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'level'      => $level,
				'kind'       => $kind,
				'product'    => $product,
				'user_id'    => 0 === $user ? null : $user,
				'cause'      => $cause,
				'context'    => array() === $context ? null : (string) wp_json_encode( $context ),
			)
		);

		$wpdb->suppress_errors( $suppressed );
	}

	/**
	 * Los eventos, de lo más nuevo a lo más viejo, filtrados y paginados.
	 *
	 * @param array<string, mixed> $filters  `product`, `kind`, `level`, `cause`, `after_id`.
	 * @param int                  $page     La página, desde 1.
	 * @param int                  $per_page Cuántos, hasta 100.
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public static function list( array $filters = array(), int $page = 1, int $per_page = 50 ): array {
		global $wpdb;

		$table  = Installer::table( 'events' );
		$where  = array( '1=1' );
		$values = array();

		foreach ( array( 'kind', 'level', 'cause' ) as $column ) {
			if ( isset( $filters[ $column ] ) && '' !== $filters[ $column ] ) {
				$where[]  = "{$column} = %s";
				$values[] = (string) $filters[ $column ];
			}
		}

		if ( isset( $filters['product'] ) ) {
			$where[]  = 'product = %d';
			$values[] = (int) $filters['product'];
		}

		if ( isset( $filters['after_id'] ) ) {
			$where[]  = 'id > %d';
			$values[] = (int) $filters['after_id'];
		}

		$per_page = max( 1, min( 100, $per_page ) );
		$sql      = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$total = (int) $wpdb->get_var( array() === $values ? "SELECT COUNT(*) FROM {$table} WHERE {$sql}" : $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$sql}", $values ) );
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$sql} ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( $values, array( $per_page, ( max( 1, $page ) - 1 ) * $per_page ) )
			)
		);
		// phpcs:enable

		return array(
			'items' => array_map( array( self::class, 'row' ), $rows ),
			'total' => $total,
		);
	}

	/**
	 * El historial de un producto: sus movimientos del libro y sus eventos, en una sola línea
	 * de tiempo, de lo más nuevo a lo más viejo (UC-03).
	 *
	 * Los movimientos propios se buscan por producto; los recibidos, por su vínculo vigente: un
	 * recibido de un vínculo que ya se borró no dice de qué producto de acá era.
	 *
	 * @param int $product Producto de esta tienda.
	 * @param int $limit   Cuántas entradas.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function history( int $product, int $limit = 50 ): array {
		global $wpdb;

		$movements = Installer::table( 'movements' );
		$links     = Installer::table( 'links' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.created_at, m.origin_store, m.delta, m.reason, m.reference, m.delivery, m.application
				   FROM {$movements} m
				  WHERE ( m.origin_store = %s AND m.origin_product = %d )
				     OR ( m.origin_store <> %s AND m.link_id IN ( SELECT id FROM {$links} WHERE local_product = %d ) )
				  ORDER BY m.created_at DESC, m.id DESC
				  LIMIT %d",
				Store::id(),
				$product,
				Store::id(),
				$product,
				$limit
			)
		);
		// phpcs:enable

		$entries = array_map(
			static fn ( $row ) => array(
				'type'        => 'movement',
				'at'          => (string) $row->created_at,
				'own'         => Store::id() === (string) $row->origin_store,
				'delta'       => (int) $row->delta,
				'reason'      => (string) $row->reason,
				'reference'   => null === $row->reference ? null : (string) $row->reference,
				'delivery'    => null === $row->delivery ? null : (string) $row->delivery,
				'application' => null === $row->application ? null : (string) $row->application,
			),
			$rows
		);

		foreach ( self::list( array( 'product' => $product ), 1, $limit )['items'] as $event ) {
			$entries[] = array( 'type' => 'event' ) + $event;
		}

		usort( $entries, static fn ( $a, $b ) => strcmp( (string) $b['at'], (string) $a['at'] ) );

		return array_slice( $entries, 0, $limit );
	}

	/**
	 * Borra lo de más de 90 días, salvo lo que no se borra nunca (ADR-011 §6).
	 *
	 * @param int $days Días.
	 *
	 * @return int Cuántos borró.
	 */
	public static function purge( int $days = self::RETENTION_DAYS ): int {
		global $wpdb;

		$table = Installer::table( 'events' );
		$kept  = implode( ', ', array_fill( 0, count( self::KEPT ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s AND kind NOT IN ( {$kept} )",
				array_merge( array( gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ), self::KEPT )
			)
		);
		// phpcs:enable

		return (int) $deleted;
	}

	/**
	 * La frase de un evento, para quien lo lee.
	 *
	 * @param array<string, mixed> $event Un evento de `list()`.
	 */
	public static function describe( array $event ): string {
		$context = is_array( $event['context'] ?? null ) ? $event['context'] : array();
		$product = null === $event['product'] ? '' : (string) $event['product'];

		switch ( $event['kind'] ) {
			case self::LINKED:
				/* translators: 1: producto de acá, 2: producto de la otra tienda. */
				return sprintf( __( 'Se vinculó el producto %1$s con el %2$s de la otra tienda.', 'woonderful-stock-sync-for-woocommerce' ), $product, (string) ( $context['remote'] ?? '?' ) );
			case self::LINK_FAILED:
				/* translators: 1: producto, 2: motivo. */
				return sprintf( __( 'No se pudo vincular el producto %1$s: %2$s.', 'woonderful-stock-sync-for-woocommerce' ), $product, self::reason_text( (string) ( $context['reason'] ?? '' ) ) );
			case self::UNLINKED:
				/* translators: %s: producto. */
				return sprintf( __( 'Se desvinculó el producto %s.', 'woonderful-stock-sync-for-woocommerce' ), $product );
			case self::LINK_UNRESOLVED:
				/* translators: %s: producto. */
				return sprintf( __( 'No se sabe si la otra tienda vinculó el producto %s, y la pregunta falla: sus ventas quedan retenidas hasta que se resuelva.', 'woonderful-stock-sync-for-woocommerce' ), $product );
			case self::DISCARDED:
				/* translators: %s: producto. */
				return sprintf( __( 'Se descartó la sugerencia del producto %s.', 'woonderful-stock-sync-for-woocommerce' ), $product );
			case self::UNDISCARDED:
				/* translators: %s: producto. */
				return sprintf( __( 'Se deshizo el descarte del producto %s.', 'woonderful-stock-sync-for-woocommerce' ), $product );
			case self::PEER_UNREACHABLE:
				/* translators: %s: el error. */
				return sprintf( __( 'La otra tienda dejó de responder (%s).', 'woonderful-stock-sync-for-woocommerce' ), (string) ( $context['error'] ?? '?' ) );
			case self::PEER_BACK:
				return __( 'La otra tienda volvió a responder.', 'woonderful-stock-sync-for-woocommerce' );
			case self::PEER_REJECTED:
				/* translators: %s: el código del rechazo. */
				return sprintf( __( 'La otra tienda rechaza los pedidos de ésta (%s).', 'woonderful-stock-sync-for-woocommerce' ), (string) ( $context['error'] ?? '?' ) );
			case self::PEER_ACCEPTED:
				return __( 'La otra tienda dejó de rechazar los pedidos de ésta.', 'woonderful-stock-sync-for-woocommerce' );
			case self::ABANDONED:
				/* translators: %d: cuántos. */
				return sprintf( __( 'Se abandonaron %d movimientos por pasar 6 horas sin entregarse.', 'woonderful-stock-sync-for-woocommerce' ), (int) ( $context['count'] ?? 0 ) );
			case self::LEDGER_ERROR:
				/* translators: %s: el error de la base. */
				return sprintf( __( 'La base rechazó una escritura del libro: %s.', 'woonderful-stock-sync-for-woocommerce' ), (string) ( $context['error'] ?? '?' ) );
			case self::RECONCILED:
				/* translators: 1: quién la lanzó, 2: cómo terminó. */
				return sprintf( __( 'Reconciliación %1$s: %2$s.', 'woonderful-stock-sync-for-woocommerce' ), self::trigger_text( (string) ( $context['trigger'] ?? '' ) ), self::outcome_text( (string) ( $context['outcome'] ?? '' ) ) );
			case self::DISCREPANCY_OPENED:
				/* translators: 1: producto, 2: lo que dice el libro, 3: lo que hay. */
				return sprintf( __( 'Discrepancia en el producto %1$s: el libro dice %2$d, hay %3$d.', 'woonderful-stock-sync-for-woocommerce' ), $product, (int) ( $context['expected'] ?? 0 ), (int) ( $context['found'] ?? 0 ) );
			case self::DISCREPANCY_CLOSED:
				/* translators: %s: producto. */
				return sprintf( __( 'Se cerró sola la discrepancia del producto %s.', 'woonderful-stock-sync-for-woocommerce' ), $product );
			case self::DISCREPANCY_ACCEPTED:
				/* translators: %s: producto. */
				return sprintf( __( 'Se aceptó el stock del producto %s: la diferencia viaja como ajuste.', 'woonderful-stock-sync-for-woocommerce' ), $product );
			case self::DISCREPANCY_RESTORED:
				/* translators: %s: producto. */
				return sprintf( __( 'El stock del producto %s volvió a lo que dice el libro.', 'woonderful-stock-sync-for-woocommerce' ), $product );
			case self::LINK_REMOVED:
				/* translators: %s: producto. */
				return sprintf( __( 'Se borró el vínculo del producto %s: la maestra no lo tenía.', 'woonderful-stock-sync-for-woocommerce' ), $product );
			case self::PAIR_BROKEN:
				/* translators: 1: producto, 2: por qué. */
				return sprintf( __( 'Se rompió el par del producto %1$s: %2$s.', 'woonderful-stock-sync-for-woocommerce' ), $product, self::reason_text( (string) ( $context['reason'] ?? '' ) ) );
			case self::PAIR_RESTORED:
				/* translators: %s: producto. */
				return sprintf( __( 'El par del producto %s volvió a estar bien.', 'woonderful-stock-sync-for-woocommerce' ), $product );
			case self::NOTIFIED:
				/* translators: %s: producto. */
				return sprintf( __( 'Se avisó por email que el producto %s no sincroniza.', 'woonderful-stock-sync-for-woocommerce' ), $product );
			case self::NOTIFY_FAILED:
				/* translators: %s: de qué era el aviso. */
				return sprintf( __( 'No se pudo mandar un email de aviso (%s).', 'woonderful-stock-sync-for-woocommerce' ), (string) ( $context['about'] ?? '?' ) );
		}

		return (string) $event['kind'];
	}

	/**
	 * Un motivo, para quien lo lee. Uno que no se conoce va como vino: es mejor que nada.
	 *
	 * @param string $reason El motivo, como lo guarda el evento.
	 */
	public static function reason_text( string $reason ): string {
		$texts = array(
			'local_trashed'             => __( 'está en la papelera', 'woonderful-stock-sync-for-woocommerce' ),
			'local_missing'             => __( 'se borró', 'woonderful-stock-sync-for-woocommerce' ),
			'local_not_managing_stock'  => __( 'dejó de llevar su propio stock', 'woonderful-stock-sync-for-woocommerce' ),
			'not_applied: unknown'      => __( 'a la otra tienda nunca le llegó', 'woonderful-stock-sync-for-woocommerce' ),
			'not_applied: held'         => __( 'la otra tienda lo retuvo', 'woonderful-stock-sync-for-woocommerce' ),
			'not_applied: discarded'    => __( 'la otra tienda lo descartó', 'woonderful-stock-sync-for-woocommerce' ),
			'stock_changed'             => __( 'el stock cambió desde que se miró', 'woonderful-stock-sync-for-woocommerce' ),
			'already_linked'            => __( 'alguno de los dos productos ya estaba vinculado', 'woonderful-stock-sync-for-woocommerce' ),
			'product_missing'           => __( 'el producto no existe', 'woonderful-stock-sync-for-woocommerce' ),
			'product_not_managing_stock' => __( 'el producto no lleva su propio stock', 'woonderful-stock-sync-for-woocommerce' ),
			'target_missing'            => __( 'el producto de la otra tienda no existe', 'woonderful-stock-sync-for-woocommerce' ),
			'target_not_managing_stock' => __( 'el producto de la otra tienda no lleva su propio stock', 'woonderful-stock-sync-for-woocommerce' ),
		);

		return $texts[ $reason ] ?? ( '' === $reason ? '?' : $reason );
	}

	/**
	 * Quién lanzó una reconciliación, para quien lo lee.
	 *
	 * @param string $trigger Una de las constantes de `Result`.
	 */
	private static function trigger_text( string $trigger ): string {
		$texts = array(
			'nightly' => __( 'de la noche', 'woonderful-stock-sync-for-woocommerce' ),
			'manual'  => __( 'manual', 'woonderful-stock-sync-for-woocommerce' ),
			'import'  => __( 'al cerrar una importación', 'woonderful-stock-sync-for-woocommerce' ),
			'peer'    => __( 'lanzada por la otra tienda', 'woonderful-stock-sync-for-woocommerce' ),
		);

		return $texts[ $trigger ] ?? $trigger;
	}

	/**
	 * Cómo terminó una reconciliación, para quien lo lee.
	 *
	 * @param string $outcome Una de las constantes de `Result`.
	 */
	private static function outcome_text( string $outcome ): string {
		$texts = array(
			'done'        => __( 'completa', 'woonderful-stock-sync-for-woocommerce' ),
			'unreachable' => __( 'la otra tienda no respondió, no se pudo mirar', 'woonderful-stock-sync-for-woocommerce' ),
			'busy'        => __( 'había otra en curso', 'woonderful-stock-sync-for-woocommerce' ),
		);

		return $texts[ $outcome ] ?? $outcome;
	}

	/**
	 * Una fecha del log, guardada en UTC, en la hora del sitio.
	 *
	 * @param string $utc La fecha en UTC.
	 */
	public static function local_time( string $utc ): string {
		$time = strtotime( $utc . ' UTC' );

		return false === $time ? $utc : (string) wp_date( 'Y-m-d H:i:s', $time );
	}

	/**
	 * Una fila, como la leen los demás.
	 *
	 * @param \stdClass $row La fila.
	 *
	 * @return array<string, mixed>
	 */
	private static function row( \stdClass $row ): array {
		$context = null === $row->context ? array() : json_decode( (string) $row->context, true );

		return array(
			'id'      => (int) $row->id,
			'at'      => (string) $row->created_at,
			'level'   => (string) $row->level,
			'kind'    => (string) $row->kind,
			'product' => null === $row->product ? null : (int) $row->product,
			'user_id' => null === $row->user_id ? null : (int) $row->user_id,
			'cause'   => null === $row->cause ? null : (string) $row->cause,
			'context' => is_array( $context ) ? $context : array(),
		);
	}
}
