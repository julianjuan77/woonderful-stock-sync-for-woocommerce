<?php
/**
 * El libro de movimientos.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Ledger;

use Woonderful\StockSync\Detector\Guard;
use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Linking\Links;
use Woonderful\StockSync\Schema\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Escribe y lee los movimientos (ADR-001, ADR-004).
 */
final class Ledger {

	/**
	 * Segundos que un movimiento espera antes de que la cola pueda entregarlo.
	 *
	 * Le da tiempo al hook del pedido a estampar el motivo, y hace que una ráfaga de
	 * importación se agrupe sola en vez de salir de a uno (ADR-006 §4).
	 */
	public const DELIVERY_DELAY = 10;

	/**
	 * Tope de movimientos por lote. El receptor rechaza uno más grande (ADR-001 §4).
	 */
	public const MAX_BATCH = 200;

	/**
	 * Cuánto se sigue reintentando un movimiento, desde su primer intento (ADR-001 §2).
	 */
	public const DELIVERY_CEILING = '6 HOUR';

	/**
	 * Espera máxima entre reintentos, en segundos.
	 *
	 * Sin tope, la espera del último intento se comería buena parte de las 6 horas.
	 */
	public const MAX_BACKOFF = 1800;

	/**
	 * Cuánto esperar antes del próximo intento.
	 *
	 * Duplicando desde un minuto: 1, 2, 4, 8, 16, 30, 30… En las 6 horas del techo entran
	 * unos catorce intentos. Es una constante y no configuración, por lo mismo que el
	 * retardo de entrega: nadie pidió moverla (ADR-006 §4).
	 *
	 * @param int $attempts Intentos hechos hasta ahora, contando el que falló.
	 */
	public static function backoff_for( int $attempts ): int {
		$seconds = 60 * ( 2 ** max( 0, $attempts - 1 ) );

		return (int) min( $seconds, self::MAX_BACKOFF );
	}

	/**
	 * Anota el resultado de cada fila entregada.
	 *
	 * Dos caminos: confirmada, que cierra la fila; y fallida, que vuelve a la cola con el
	 * próximo intento más adelante. El techo de las 6 horas no se mira acá sino al
	 * reclamar: un worker que muere no llega a llamar a esta función, y sus filas tienen
	 * que poder abandonarse igual.
	 *
	 * Cada escritura exige además que la fila **siga en vuelo y sea de este worker**. Sin
	 * eso, una pasada lenta que anota después de que su claim venció pisa el trabajo de la
	 * que se llevó la fila; y un id equivocado —el de un movimiento recibido, que no tiene
	 * entrega— lo metería en la cola de salida (ADR-001 §3).
	 *
	 * Un `true` es una confirmación; cualquier texto es un fallo, y ese texto es el motivo
	 * que contestó el receptor. Se guarda en la fila porque es lo que después explica un
	 * movimiento abandonado: el log tiene la causa del lote, la fila tiene la suya
	 * (ADR-001 §2).
	 *
	 * @param string                  $worker  El token con el que se reclamaron las filas.
	 * @param array<int, bool|string> $results ID del movimiento => true, o el motivo del fallo.
	 */
	public static function ack( string $worker, array $results ): void {
		global $wpdb;

		$table = Installer::table( 'movements' );

		foreach ( $results as $movement_id => $result ) {
			$owned = array(
				'id'         => (int) $movement_id,
				'delivery'   => 'in_flight',
				'claimed_by' => $worker,
			);

			if ( true === $result ) {
				$wpdb->update( // phpcs:ignore WordPress.DB
					$table,
					array(
						'delivery'     => 'confirmed',
						'confirmed_at' => gmdate( 'Y-m-d H:i:s' ),
						'claimed_by'   => null,
						'claimed_at'   => null,
					),
					$owned
				);

				continue;
			}

			$attempts = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
				$wpdb->prepare( "SELECT attempts FROM {$table} WHERE id = %d", (int) $movement_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);

			$wpdb->update( // phpcs:ignore WordPress.DB
				$table,
				array(
					'delivery'        => 'pending',
					'claimed_by'      => null,
					'claimed_at'      => null,
					'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + self::backoff_for( $attempts ) ),
					// Recortado al tamaño de la columna: un motivo más largo haría fallar
					// el UPDATE entero y la fila quedaría en vuelo hasta que venza su claim.
					'last_error'      => mb_substr( is_string( $result ) ? $result : 'error', 0, 255 ),
				),
				$owned
			);
		}
	}

	/**
	 * Se aplicó al stock local.
	 */
	public const APPLIED = 'applied';

	/**
	 * Guardado sin aplicar, a la espera de que el par vuelva (UC-03).
	 */
	public const HELD = 'held';

	/**
	 * No se va a aplicar nunca: sin vínculo, o de una generación que ya no corre.
	 */
	public const DISCARDED = 'discarded';

	/**
	 * Cuánto puede quedar una fila en vuelo antes de volver a estar disponible.
	 *
	 * Un worker que muere a mitad la dejaría tomada para siempre (ADR-001 §2).
	 */
	public const CLAIM_TIMEOUT = '5 MINUTE';

	/**
	 * Libera lo vencido y reclama un lote de movimientos para entregar.
	 *
	 * Son los dos primeros pasos de la pasada (ADR-001 §2). El segundo es un `UPDATE`
	 * condicional y no un `SELECT` seguido de un `UPDATE`: dos pasadas simultáneas que
	 * leyeran las mismas filas se llevarían el mismo lote.
	 *
	 * El token lo genera esta función y no lo recibe: si dos pasadas usaran el mismo, la
	 * segunda se llevaría también las filas que la primera está enviando —el lote pasaría
	 * de 200 y los ACK caerían sobre filas ajenas—, que es justo lo que el claim evita.
	 * Cada fila devuelta trae su `claimed_by`.
	 *
	 * @param int $limit Tope de movimientos por lote.
	 *
	 * @return object[] Las filas reclamadas en esta llamada.
	 */
	public static function claim( int $limit = self::MAX_BATCH ): array {
		global $wpdb;

		$worker = wp_generate_uuid4();

		$table = Installer::table( 'movements' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		// Paso 1 — liberar lo que quedó colgado de una pasada anterior.
		//
		// UTC_TIMESTAMP() y no NOW(): las fechas se guardan en UTC y `NOW()` devuelve la
		// hora del servidor MySQL en su zona, así que en un servidor que no esté en UTC la
		// entrega se adelantaría o se atrasaría esa diferencia.
		$wpdb->query(
			"UPDATE {$table}
			    SET delivery = 'pending', claimed_by = NULL, claimed_at = NULL
			  WHERE delivery = 'in_flight'
			    AND claimed_at < UTC_TIMESTAMP() - INTERVAL " . self::CLAIM_TIMEOUT
		);

		// Paso 2 — abandonar lo que pasó el techo. **Sólo lo que está esperando, nunca lo
		// que está en camino**: cancelar una fila que otra pasada está entregando haría
		// que su ACK llegue a una fila abandonada y la reconciliación la reenvíe sin
		// necesidad.
		//
		// Va después del paso 1 a propósito: así la fila de un worker muerto se libera y
		// se abandona en la misma pasada. Y el techo se mira acá y no al anotar el
		// resultado porque un worker que muere nunca llama a `ack()` (ADR-001 §2).
		$wpdb->query(
			"UPDATE {$table}
			    SET delivery = 'abandoned', claimed_by = NULL, claimed_at = NULL
			  WHERE delivery = 'pending'
			    AND first_attempt_at IS NOT NULL
			    AND first_attempt_at < UTC_TIMESTAMP() - INTERVAL " . self::DELIVERY_CEILING
		);

		// Paso 3 — reclamar el lote. El intento se cuenta acá y no al anotar el resultado: si el worker muere, nadie
		// llega a anotarlo y el techo de 6 horas no avanzaría nunca (ADR-001 §2). Un lote
		// que siempre supera el tiempo de PHP se reintentaría para siempre.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				    SET delivery = 'in_flight',
				        claimed_by = %s,
				        claimed_at = UTC_TIMESTAMP(),
				        attempts = attempts + 1,
				        first_attempt_at = COALESCE( first_attempt_at, UTC_TIMESTAMP() )
				  WHERE delivery = 'pending'
				    AND next_attempt_at <= UTC_TIMESTAMP()
				  ORDER BY next_attempt_at, id
				  LIMIT %d",
				$worker,
				max( 1, min( $limit, self::MAX_BATCH ) )
			)
		);

		$claimed = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE delivery = 'in_flight' AND claimed_by = %s ORDER BY id",
				$worker
			)
		);
		// phpcs:enable

		return (array) $claimed;
	}

	/**
	 * Aplica un movimiento que llegó de la otra tienda.
	 *
	 * Los cinco caminos, en este orden: si ya está en el libro no se toca nada —un reenvío
	 * no puede descontar dos veces—; sin vínculo o con una generación que ya no corre se
	 * guarda como `discarded`; si el producto local no está se guarda como `held`, que es
	 * reintentable; y si todo está bien se descuenta el stock.
	 *
	 * En los cinco casos el emisor recibe su confirmación: el ACK dice "lo tengo", no "lo
	 * apliqué" (ADR-001 §3).
	 *
	 * @param array<string, mixed> $movement El movimiento como viene en el JSON.
	 *
	 * @return string|null Estado de aplicación, o null si no se pudo guardar: sin fila no
	 *                     hay nada que confirmar.
	 */
	public static function apply( array $movement ): ?string {
		global $wpdb;

		$origin  = (string) ( $movement['origin'] ?? '' );
		$seq     = (int) ( $movement['seq'] ?? 0 );
		$product = (int) ( $movement['product'] ?? 0 );
		$delta   = (int) ( $movement['delta'] ?? 0 );

		$known = self::application_state_of( $origin, $seq );

		if ( null !== $known ) {
			return $known;
		}

		$link       = Links::for_remote_product( $origin, $product );
		$local_id   = null === $link ? 0 : (int) $link->local_product;
		$local      = 0 === $local_id ? null : wc_get_product( $local_id );
		$generation = null === $link ? 0 : (int) $link->generation;

		if ( null === $link || (int) ( $movement['generation'] ?? 0 ) !== $generation ) {
			// Sin vínculo no hay a qué producto aplicarlo; de una generación vieja, no se
			// va a aplicar nunca (ADR-005 §4).
			$state = self::DISCARDED;
		} elseif ( ! $local instanceof \WC_Product || 'trash' === $local->get_status() || ! $local->managing_stock() ) {
			// Guardado y reintentable: descartarlo perdería stock en silencio (UC-03).
			//
			// La papelera cuenta como ausente: `wc_get_product()` devuelve el producto
			// igual, con `managing_stock()` en true, así que hay que mirar el estado o se
			// le descontaría stock a algo que el dueño mandó a la papelera.
			$state = self::HELD;
		} else {
			$state = self::APPLIED;
		}

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB

		// La fila primero: es el registro que deduplica. Si se cambiara el stock y el
		// proceso muriera antes de escribirla, el movimiento se reentregaría y se
		// aplicaría de nuevo.
		$inserted = $wpdb->insert(
			Installer::table( 'movements' ),
			array(
				'origin_store'   => $origin,
				'origin_seq'     => $seq,
				'generation'     => (int) ( $movement['generation'] ?? 0 ),
				'delta'          => $delta,
				'reason'         => (string) ( $movement['reason'] ?? 'adjustment' ),
				'reference'      => $movement['reference'] ?? null,
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
				'link_id'        => null === $link ? null : (int) $link->id,
				'origin_product' => $product,
				'application'    => $state,
				'applied_at'     => self::APPLIED === $state ? gmdate( 'Y-m-d H:i:s' ) : null,
			)
		);

		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB

			// Si otro request lo escribió entre la consulta y el INSERT, el UNIQUE lo
			// rechazó y el movimiento **está** guardado: se puede confirmar.
			//
			// Si falló por otra cosa —un error de la base, un campo más largo que su
			// columna— no hay nada guardado, y confirmarlo haría que el emisor deje de
			// reintentar: el movimiento se perdería en las dos tiendas. Devolver null es
			// lo que impide ese ACK.
			return self::application_state_of( $origin, $seq );
		}

		if ( self::APPLIED === $state && $local instanceof \WC_Product ) {
			// El cambio de stock lo provoca el plugin: sin esto el detector lo vería como
			// una venta y lo emitiría de vuelta (ADR-006 §3).
			//
			// Se anota el que **lleva** el stock, no el que se pidió: en una variación que
			// lo toma del padre, quien cambia es el padre, y es su ID el que va a llegar
			// al hook.
			Guard::expect( (int) $local->get_stock_managed_by_id() );

			wc_update_product_stock( $local, abs( $delta ), $delta < 0 ? 'decrease' : 'increase' );
		}

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB

		return $state;
	}

	/**
	 * El estado de aplicación de un movimiento que ya está en el libro, o null si no está.
	 *
	 * @param string $origin Tienda que lo emitió.
	 * @param int    $seq    Su número en esa tienda.
	 */
	private static function application_state_of( string $origin, int $seq ): ?string {
		global $wpdb;

		$table = Installer::table( 'movements' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$state = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT application FROM {$table} WHERE origin_store = %s AND origin_seq = %d",
				$origin,
				$seq
			)
		);
		// phpcs:enable

		return null === $state ? null : (string) $state;
	}

	/**
	 * Registra un movimiento propio.
	 *
	 * @param int         $product_id ID del producto en esta tienda.
	 * @param int         $delta      Unidades, con signo.
	 * @param string      $reason     Motivo; el detector lo estampa después si lo sabe.
	 * @param string|null $reference  Con qué se audita: el número de pedido, etc.
	 *
	 * @return int|null ID del movimiento, o null si no había nada que registrar.
	 */
	public static function record( int $product_id, int $delta, string $reason = 'adjustment', ?string $reference = null ): ?int {
		if ( 0 === $delta ) {
			return null;
		}

		$link = Links::for_product( $product_id );

		// Un producto sin vincular no está en la malla: no hay a quién contarle el cambio
		// ni contra qué generación contarlo (UC-03).
		if ( null === $link ) {
			return null;
		}

		global $wpdb;

		$table = Installer::table( 'movements' );
		$now   = time();

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB

		// origin_seq queda en NULL a propósito: la secuencia de un movimiento propio es su
		// AUTO_INCREMENT, que no existe hasta después del INSERT (ADR-004 §1).
		$inserted = $wpdb->insert(
			$table,
			array(
				'origin_store'    => Store::id(),
				'generation'      => (int) $link->generation,
				'delta'           => $delta,
				'reason'          => $reason,
				'reference'       => $reference,
				'created_at'      => gmdate( 'Y-m-d H:i:s', $now ),
				'link_id'         => (int) $link->id,
				'origin_product'  => $product_id,
				'delivery'        => 'pending',
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', $now + self::DELIVERY_DELAY ),
			)
		);

		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB

			return null;
		}

		$movement_id = (int) $wpdb->insert_id;

		$updated = $wpdb->update( $table, array( 'origin_seq' => $movement_id ), array( 'id' => $movement_id ) );

		// La transacción está para que las dos escrituras vayan juntas o no vaya ninguna:
		// confirmar con la secuencia sin llenar deja en el libro la fila que la
		// transacción tenía que impedir, pendiente de entregar y sin identificador.
		if ( false === $updated ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB

			return null;
		}

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB

		return $movement_id;
	}
}
