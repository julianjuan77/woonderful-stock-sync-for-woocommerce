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
use Woonderful\StockSync\Transport\Peers;

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
	 * Si `apply()` tiene una transacción abierta.
	 *
	 * Un hook de terceros puede cambiar el stock de otro producto vinculado mientras
	 * `apply()` corre, y el detector llama a `record()` adentro. Un `START TRANSACTION` ahí
	 * confirmaría la de `apply()` a mitad de camino; con la marca, `record()` usa un
	 * `SAVEPOINT` y queda adentro de la misma.
	 *
	 * @var bool
	 */
	private static bool $in_transaction = false;

	/**
	 * Los productos cuyo stock cambió adentro de la transacción de `apply()`.
	 *
	 * Si hay `ROLLBACK`, la base vuelve atrás pero la caché de objetos no: con una
	 * persistente, WooCommerce seguiría mostrando y vendiendo con el stock deshecho. Se
	 * anotan todos, no sólo el que se aplica: un hook de terceros puede cambiar otro.
	 *
	 * @var array<int, true>
	 */
	private static array $touched = array();

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
	 * El movimiento que fija un par y su stock inicial (ADR-005 §2, ADR-008).
	 */
	public const BOOTSTRAP = 'bootstrap';

	/**
	 * Un bootstrap propio en camino: se mandó y se espera la respuesta (ADR-008 §1).
	 */
	public const SYNCING = 'syncing';

	/**
	 * Un bootstrap propio sin respuesta: hay que preguntar (ADR-008 §5).
	 */
	public const UNCERTAIN = 'uncertain';

	/**
	 * El movimiento que deshace un par (ADR-005 §3).
	 */
	public const UNLINK = 'unlink';

	/**
	 * Los motivos con que la secundaria rechaza un bootstrap (ADR-008 §3). No se reintentan:
	 * es el fallo "ahora" de UC-07, y el dueño vuelve a confirmar mirando los números nuevos.
	 */
	public const BOOTSTRAP_REJECTIONS = array( 'not_master', 'target_missing', 'target_not_managing_stock', 'stock_changed' );

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
	 * Días que se conservan los movimientos terminados (§5 del SPEC).
	 */
	public const RETENTION_DAYS = 90;

	/**
	 * Purga los movimientos terminados y viejos, consolidando el stock base.
	 *
	 * Tres reglas duras (ADR-004 §4):
	 *
	 * - **Sólo lo terminado.** Lo que todavía no llegó —`abandoned`, `pending`— se queda:
	 *   borrarlo le quitaría a la reconciliación la única forma de repararlo, y el desvío
	 *   entre las dos tiendas quedaría permanente y silencioso. Lo mismo un `held`, que
	 *   espera su turno.
	 * - **El stock base absorbe lo purgado**, en la misma transacción. Sin eso la fórmula
	 *   `base + movimientos` deja de dar el mismo número y la fase 2 reportaría
	 *   discrepancias falsas en todos los productos con historia.
	 * - **Queda la marca de hasta dónde se purgó** de cada tienda remota. Purgar una fila
	 *   recibida borra el registro que la deduplica: sin la marca, la fase 1 se la vuelve
	 *   a ofrecer y se aplicaría dos veces.
	 *
	 * @param int $days Días de retención.
	 *
	 * @return int Movimientos purgados.
	 */
	public static function purge( int $days = self::RETENTION_DAYS ): int {
		global $wpdb;

		$table  = Installer::table( 'movements' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$links = Installer::table( 'links' );

		// La generación del vínculo viaja en la consulta: si un re-bootstrap reutilizó la
		// fila, su stock base nuevo ya absorbió lo de antes, y sumarle los movimientos de
		// la generación anterior lo correría dos veces (ADR-005 §4).
		$purgeable = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.id, m.origin_store, m.origin_seq, m.link_id, m.delta, m.application,
				        m.generation, l.generation AS link_generation
				   FROM {$table} m
				   LEFT JOIN {$links} l ON l.id = m.link_id
				  WHERE m.created_at < %s
				    AND ( m.delivery = 'confirmed' OR m.application IN ( 'applied', 'discarded' ) )",
				$cutoff
			)
		);

		if ( array() === $purgeable ) {
			return 0;
		}

		$ids          = array();
		$consolidate  = array();
		$purged_upto  = array();
		$own_store    = Store::id();

		foreach ( $purgeable as $row ) {
			$ids[] = (int) $row->id;

			// Sólo se consolida lo que está reflejado en el stock local. Una recibida
			// descartada nunca se aplicó: sumarla correría el stock base justo por los
			// movimientos que decidimos no aplicar.
			if ( null !== $row->link_id
				&& self::DISCARDED !== $row->application
				&& (int) $row->generation === (int) $row->link_generation
			) {
				$link_id                 = (int) $row->link_id;
				$consolidate[ $link_id ] = ( $consolidate[ $link_id ] ?? 0 ) + (int) $row->delta;
			}

			if ( (string) $row->origin_store !== $own_store ) {
				$store                 = (string) $row->origin_store;
				$purged_upto[ $store ] = max( $purged_upto[ $store ] ?? 0, (int) $row->origin_seq );
			}
		}

		$wpdb->query( 'START TRANSACTION' );

		// Nada de esto puede quedar a medias: el stock base consolidado con las filas
		// todavía en el libro contaría esos movimientos dos veces, y borrar las filas sin
		// mover la marca haría que la fase 1 se las vuelva a ofrecer (ADR-004 §4).
		foreach ( $consolidate as $link_id => $delta ) {
			$updated = $wpdb->query(
				$wpdb->prepare( "UPDATE {$links} SET base_stock = base_stock + %d WHERE id = %d", $delta, $link_id )
			);

			if ( false === $updated ) {
				self::rollback_purge();

				return 0;
			}
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		$deleted = $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE id IN ( {$placeholders} )", $ids )
		);

		if ( false === $deleted ) {
			self::rollback_purge();

			return 0;
		}

		// La marca, adentro de la transacción: `wp_options` es InnoDB como las nuestras,
		// así que un rollback se la lleva también.
		self::remember_purged( $purged_upto );

		$wpdb->query( 'COMMIT' );
		// phpcs:enable

		return count( $ids );
	}

	/**
	 * Anota hasta qué secuencia se purgó de cada tienda remota.
	 *
	 * @param array<string, int> $purged_upto Tienda => última secuencia purgada.
	 */
	private static function remember_purged( array $purged_upto ): void {
		if ( array() === $purged_upto ) {
			return;
		}

		$peers = (array) get_option( 'wss_peers', array() );

		foreach ( $purged_upto as $store => $seq ) {
			$peer                     = (array) ( $peers[ $store ] ?? array() );
			$peer['purged_through']   = max( (int) ( $peer['purged_through'] ?? 0 ), $seq );
			$peers[ $store ]          = $peer;
		}

		update_option( 'wss_peers', $peers, false );
	}

	/**
	 * Deshace la transacción y limpia la caché de la opción que quedó escrita en memoria.
	 */
	private static function rollback_purge(): void {
		global $wpdb;

		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB

		wp_cache_delete( 'wss_peers', 'options' );
	}

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
	 * @return \stdClass[] Las filas reclamadas en esta llamada, de `get_results()`.
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

		// Paso 3 — reclamar el lote, sin lo que cuelga de un bootstrap sin resolver. Una
		// venta de esos segundos sale con el vínculo nuevo (ADR-008 §4): entregada antes de
		// que la otra tienda arme el suyo, la descartaría con ACK y se perdería. Cuando el
		// bootstrap se resuelve sale sola.
		//
		// Por vínculo y no por generación: después de invertir los roles, la generación de un
		// vínculo que esta tienda recibió puede coincidir con la secuencia de un bootstrap
		// propio. Y en una consulta aparte, no como subconsulta del UPDATE: MySQL no deja
		// leer ahí la tabla que se está cambiando (error 1093), y envolverla en una tabla
		// derivada depende de que el optimizador no la funda, que según la versión lo hace.
		$unresolved = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT link_id FROM {$table}
				  WHERE origin_store = %s
				    AND reason = %s
				    AND delivery IN ( %s, %s )
				    AND link_id IS NOT NULL",
				Store::id(),
				self::BOOTSTRAP,
				self::SYNCING,
				self::UNCERTAIN
			)
		);
		$held       = array_map( 'intval', (array) $unresolved );

		$not_held = array() === $held ? '' : ' AND ( link_id IS NULL OR link_id NOT IN ( ' . implode( ',', $held ) . ' ) )';

		//
		// El intento se cuenta acá y no al anotar el resultado: si el worker muere, nadie
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
				    AND next_attempt_at <= UTC_TIMESTAMP()" . $not_held . '
				  ORDER BY next_attempt_at, id
				  LIMIT %d',
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

		// Antes de buscar el vínculo: el bootstrap lo crea y el unlink lo borra. Con la
		// búsqueda de siempre, el bootstrap se descartaría por no tener vínculo, se
		// confirmaría con ACK, y el par no se crearía nunca (ADR-001 §3).
		$reason = (string) ( $movement['reason'] ?? '' );

		if ( self::BOOTSTRAP === $reason ) {
			return self::apply_bootstrap( $movement );
		}

		if ( self::UNLINK === $reason ) {
			return self::apply_unlink( $movement );
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

		$guarded = self::APPLIED === $state && $local instanceof \WC_Product ? (int) $local->get_stock_managed_by_id() : 0;

		return self::applying(
			static function () use ( $wpdb, $origin, $seq, $movement, $delta, $link, $product, $state, $local, $guarded ): array {
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
					return array( false, null );
				}

				if ( 0 !== $guarded && $local instanceof \WC_Product ) {
					// El cambio de stock lo provoca el plugin: sin esto el detector lo vería
					// como una venta y lo emitiría de vuelta (ADR-006 §3).
					//
					// Se anota el que **lleva** el stock, no el que se pidió: en una variación
					// que lo toma del padre, quien cambia es el padre, y es su ID el que va a
					// llegar al hook.
					Guard::expect( $guarded );

					wc_update_product_stock( $local, abs( $delta ), $delta < 0 ? 'decrease' : 'increase' );
				}

				return array( true, $state );
			},
			$guarded,
			$origin,
			$seq
		);
	}

	/**
	 * Aplica un bootstrap recibido: revisa, y si todo está bien fija el par y el stock.
	 *
	 * Las revisiones de ADR-008 §3, en orden. Si alguna falla no se escribe nada —ni la
	 * fila— y se devuelve el motivo, que vuelve como `error`: el bootstrap es sincrónico y
	 * hay alguien mirando.
	 *
	 * Si pasan, en una sola transacción: la fila recibida con los campos del bootstrap en
	 * `details`, el vínculo con el par al revés, y el stock de la maestra. Esa escritura la
	 * anota el guard: sin él, el detector la emitiría de vuelta como un ajuste.
	 *
	 * @param array<string, mixed> $movement El bootstrap, con sus campos en `details`.
	 *
	 * @return string|null `applied`, un motivo de rechazo, o null si no se pudo guardar.
	 */
	private static function apply_bootstrap( array $movement ): ?string {
		global $wpdb;

		$origin  = (string) ( $movement['origin'] ?? '' );
		$seq     = (int) ( $movement['seq'] ?? 0 );
		$details = (array) ( $movement['details'] ?? array() );
		$target  = (int) ( $details['target_product'] ?? 0 );
		$base    = (int) ( $details['base_stock'] ?? 0 );
		$local   = wc_get_product( $target );

		if ( ! Peers::is_master( $origin ) ) {
			return 'not_master';
		}

		if ( ! $local instanceof \WC_Product || 'trash' === $local->get_status() ) {
			return 'target_missing';
		}

		// Que gestione el stock él mismo: en una variación que lo toma del padre, el número
		// que se fijaría es el del padre, y el par sería otro.
		if ( ! $local->managing_stock() || (int) $local->get_stock_managed_by_id() !== $target ) {
			return 'target_not_managing_stock';
		}

		return self::applying(
			static function () use ( $wpdb, $origin, $seq, $movement, $details, $target, $base, $local ): array {
				// Revisar y escribir en el mismo paso (ADR-008 §3): con la fila bloqueada, una
				// venta que llegue ahora espera el COMMIT, encuentra el vínculo y viaja.
				// Revisada antes de la transacción, en el medio podía entrar una venta que el
				// `set` de después pisaba sin dejar rastro.
				if ( self::locked_stock( $target ) !== (int) ( $details['target_previous_stock'] ?? PHP_INT_MIN ) ) {
					return array( false, 'stock_changed' );
				}

				// Un vínculo que choque —con este producto o con el de la maestra— es un resto de
				// un desvincular que no llegó: la maestra revisó que en su lado los dos estén
				// libres, y es la única que vincula. Se reemplaza en vez de rechazarse, o ese
				// producto no se podría volver a vincular nunca (ADR-008 §3).
				if ( false === Links::delete_conflicting( $target, $origin, (int) ( $movement['product'] ?? 0 ) ) ) {
					return array( false, null );
				}

				$inserted = $wpdb->insert(
					Installer::table( 'movements' ),
					array(
						'origin_store'   => $origin,
						'origin_seq'     => $seq,
						'generation'     => $seq,
						'delta'          => 0,
						'reason'         => self::BOOTSTRAP,
						'reference'      => $movement['reference'] ?? null,
						'details'        => wp_json_encode( $details ),
						'created_at'     => gmdate( 'Y-m-d H:i:s' ),
						'origin_product' => (int) ( $movement['product'] ?? 0 ),
						'application'    => self::APPLIED,
						'applied_at'     => gmdate( 'Y-m-d H:i:s' ),
					)
				);

				if ( false === $inserted ) {
					return array( false, null );
				}

				$movement_id = (int) $wpdb->insert_id;

				// El par al revés: el local es el `target_product` que vino (ADR-005 §2), y
				// la generación, la secuencia del bootstrap (ADR-005 §4).
				$link_id = Links::create( $target, $origin, (int) ( $movement['product'] ?? 0 ), $seq, $base );

				if ( null === $link_id ) {
					return array( false, null );
				}

				$wpdb->update( Installer::table( 'movements' ), array( 'link_id' => $link_id ), array( 'id' => $movement_id ) );

				Guard::expect( $target );
				wc_update_product_stock( $local, $base, 'set' );

				return array( true, self::APPLIED );
			},
			$target,
			$origin,
			$seq
		);
	}

	/**
	 * Aplica un `unlink` recibido: borra el par, si es de la generación vigente.
	 *
	 * Uno que no viene de la maestra, o de una generación que ya no corre, se guarda
	 * `discarded` y se confirma: va por la cola, y un `error` se reintentaría 6 horas sin
	 * que reintentar lo arregle nunca (ADR-008 §3).
	 *
	 * @param array<string, mixed> $movement El unlink.
	 */
	private static function apply_unlink( array $movement ): ?string {
		global $wpdb;

		$origin     = (string) ( $movement['origin'] ?? '' );
		$seq        = (int) ( $movement['seq'] ?? 0 );
		$product    = (int) ( $movement['product'] ?? 0 );
		$generation = (int) ( $movement['generation'] ?? 0 );
		$link       = Links::for_remote_product( $origin, $product );
		$current    = null !== $link && (int) $link->generation === $generation && Peers::is_master( $origin );
		$state      = $current ? self::APPLIED : self::DISCARDED;

		return self::applying(
			static function () use ( $wpdb, $origin, $seq, $generation, $product, $link, $current, $state ): array {
				$inserted = $wpdb->insert(
					Installer::table( 'movements' ),
					array(
						'origin_store'   => $origin,
						'origin_seq'     => $seq,
						'generation'     => $generation,
						'delta'          => 0,
						'reason'         => self::UNLINK,
						'created_at'     => gmdate( 'Y-m-d H:i:s' ),
						'link_id'        => null === $link ? null : (int) $link->id,
						'origin_product' => $product,
						'application'    => $state,
						'applied_at'     => $current ? gmdate( 'Y-m-d H:i:s' ) : null,
					)
				);

				if ( false === $inserted ) {
					return array( false, null );
				}

				if ( $current && null !== $link && ! Links::delete( (int) $link->id ) ) {
					return array( false, null );
				}

				return array( true, $state );
			},
			0,
			$origin,
			$seq
		);
	}

	/**
	 * El estado de aplicación de un movimiento recibido, para quien pregunta por él.
	 *
	 * Es lo que responde `GET /movements/<origen>/<secuencia>`: preguntar no escribe nada
	 * (ADR-008 §5).
	 *
	 * @param string $origin Tienda que lo emitió.
	 * @param int    $seq    Su número en esa tienda.
	 *
	 * @return string `applied`, `held`, `discarded`, o `unknown` si nunca llegó.
	 */
	public static function state_of( string $origin, int $seq ): string {
		return self::application_state_of( $origin, $seq ) ?? 'unknown';
	}

	/**
	 * Corre una aplicación adentro de una transacción, y no deja nada a medias.
	 *
	 * La comparten el `apply()` de siempre, el bootstrap y el `unlink` recibidos. `$work`
	 * devuelve si confirmar y con qué estado. Si el INSERT de la fila falló, no confirma: si
	 * otro request lo escribió en el medio, el UNIQUE lo rechazó y el movimiento **está**
	 * guardado, así que se responde su estado; si falló por otra cosa —un error de la base—
	 * no hay nada guardado, y devolver null es lo que impide un ACK que lo perdería.
	 *
	 * Una excepción de un hook de terceros dejaría la transacción abierta, y el siguiente
	 * `START TRANSACTION` del lote confirmaría la mitad hecha. `\Throwable` y no
	 * `\Exception`: un `TypeError` no es una `Exception`.
	 *
	 * @param callable $work    Lo que se aplica. Devuelve `array( bool $commit, ?string $state )`;
	 *                          sin confirmar y con estado, es un rechazo con ese motivo.
	 * @param int      $guarded El producto que el guard anotó, o 0: si la falla cae antes de
	 *                          que el `set` la consuma, la anotación taparía su próximo cambio.
	 * @param string   $origin  Tienda que lo emitió.
	 * @param int      $seq     Su número en esa tienda.
	 */
	private static function applying( callable $work, int $guarded, string $origin, int $seq ): ?string {
		global $wpdb;

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB

		self::$in_transaction = true;
		self::take_touched();

		// En el `before`, que corre antes de escribir: si la falla cae entre la escritura
		// y el `set`, el producto ya quedó anotado.
		add_action( 'woocommerce_product_before_set_stock', array( self::class, 'note_touched' ), PHP_INT_MIN );
		add_action( 'woocommerce_variation_before_set_stock', array( self::class, 'note_touched' ), PHP_INT_MIN );

		try {
			list( $commit, $state ) = $work();

			if ( ! $commit ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB

				// Un rechazo trae su motivo; un INSERT que falló, no.
				return $state ?? self::application_state_of( $origin, $seq );
			}

			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB

			return $state;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB

			if ( 0 !== $guarded ) {
				Guard::consume( $guarded );
			}

			foreach ( self::take_touched() as $product_id ) {
				clean_post_cache( $product_id );
				wc_delete_product_transients( $product_id );
			}

			return null;
		} finally {
			remove_action( 'woocommerce_product_before_set_stock', array( self::class, 'note_touched' ), PHP_INT_MIN );
			remove_action( 'woocommerce_variation_before_set_stock', array( self::class, 'note_touched' ), PHP_INT_MIN );

			self::$in_transaction = false;
			self::take_touched();
		}
	}

	/**
	 * Los productos anotados, y vacía la lista.
	 *
	 * @return int[]
	 */
	private static function take_touched(): array {
		$touched       = array_keys( self::$touched );
		self::$touched = array();

		return $touched;
	}

	/**
	 * Anota un producto cuyo stock va a cambiar adentro de la transacción de `apply()`.
	 *
	 * Público porque lo llama WooCommerce; sólo está enganchado mientras `apply()` corre.
	 *
	 * @param mixed $product El objeto que lleva el stock.
	 */
	public static function note_touched( $product ): void {
		if ( $product instanceof \WC_Product ) {
			self::$touched[ $product->get_id() ] = true;
		}
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
	 * Completa el motivo y la referencia de un movimiento propio (ADR-006 §2).
	 *
	 * Sólo mientras siga `pending`: si la cola ya se lo llevó, está viajando con el motivo
	 * que tenía, y cambiarlo acá dejaría las dos tiendas con historiales distintos.
	 *
	 * @param int    $movement_id ID del movimiento.
	 * @param string $reason      El motivo.
	 * @param string $reference   Con qué se audita: el número de pedido.
	 */
	public static function stamp( int $movement_id, string $reason, string $reference ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB
			Installer::table( 'movements' ),
			array(
				'reason'    => $reason,
				// Recortado al tamaño de la columna, como el motivo de un fallo: un número
				// de pedido filtrado a algo muy largo haría fallar el UPDATE entero.
				'reference' => mb_substr( $reference, 0, 100 ),
			),
			array(
				'id'           => $movement_id,
				'origin_store' => Store::id(),
				'delivery'     => 'pending',
			)
		);
	}

	/**
	 * Escribe un bootstrap propio y su vínculo, juntos. Del lado de la maestra (ADR-008 §4).
	 *
	 * El vínculo se arma **antes** de mandar el bootstrap, para que una venta de esos
	 * segundos se registre con la generación nueva; `claim()` la retiene hasta que el
	 * bootstrap se resuelva. La fila nace `syncing`, que la cola no reclama: el bootstrap se
	 * manda en el momento, no por la cola (ADR-008 §1).
	 *
	 * @param int                $product   Producto de esta tienda.
	 * @param string             $remote    La otra tienda.
	 * @param array<string, int> $details   `target_product`, `base_stock`,
	 *                                      `origin_previous_stock`, `target_previous_stock`.
	 * @param string|null        $reference Quién lo vinculó.
	 *
	 * @return int|string|null ID del movimiento —que es también la generación del par—,
	 *                         `stock_changed` si el stock ya no es el que vio el dueño, o null.
	 */
	public static function record_bootstrap( int $product, string $remote, array $details, ?string $reference ) {
		global $wpdb;

		$table = Installer::table( 'movements' );
		$now   = gmdate( 'Y-m-d H:i:s' );

		self::begin();

		// Revisar y escribir en el mismo paso (ADR-008 §3): con la fila bloqueada, una venta
		// que llegue ahora espera el COMMIT, encuentra el vínculo y se registra. Revisada
		// antes, en el medio podía entrar una venta sin vínculo, que no se registraba, y la
		// secundaria arrancaba con el número de antes.
		if ( self::locked_stock( $product ) !== (int) $details['origin_previous_stock'] ) {
			self::rollback();

			return 'stock_changed';
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'origin_store'    => Store::id(),
				'delta'           => 0,
				'reason'          => self::BOOTSTRAP,
				'reference'       => $reference,
				'details'         => wp_json_encode( $details ),
				'created_at'      => $now,
				'origin_product'  => $product,
				'delivery'        => self::SYNCING,
				'next_attempt_at' => $now,
				'first_attempt_at' => $now,
				'attempts'        => 1,
			)
		);

		if ( false === $inserted ) {
			self::rollback();

			return null;
		}

		$movement_id = (int) $wpdb->insert_id;
		$link_id     = Links::create( $product, $remote, (int) $details['target_product'], $movement_id, (int) $details['base_stock'] );

		$updated = null === $link_id ? false : $wpdb->update(
			$table,
			array(
				'origin_seq' => $movement_id,
				'generation' => $movement_id,
				'link_id'    => $link_id,
			),
			array( 'id' => $movement_id )
		);

		if ( false === $updated ) {
			self::rollback();

			return null;
		}

		self::commit();

		return $movement_id;
	}

	/**
	 * El stock de un producto, leído de la base y con la fila bloqueada hasta el COMMIT.
	 *
	 * @param int $product_id Producto.
	 */
	private static function locked_stock( int $product_id ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock' FOR UPDATE",
				$product_id
			)
		);
		// phpcs:enable

		return (int) wc_stock_amount( $value );
	}

	/**
	 * Anota cómo terminó un bootstrap propio. Si falló, borra el vínculo (ADR-008 §1).
	 *
	 * @param int         $movement_id El bootstrap.
	 * @param string      $delivery    `confirmed`, `failed` o `uncertain`.
	 * @param string|null $error       El motivo, si falló.
	 */
	public static function resolve_bootstrap( int $movement_id, string $delivery, ?string $error = null ): void {
		global $wpdb;

		$table  = Installer::table( 'movements' );
		$fields = array( 'delivery' => $delivery );

		if ( 'confirmed' === $delivery ) {
			$fields['confirmed_at'] = gmdate( 'Y-m-d H:i:s' );
		}

		if ( null !== $error ) {
			$fields['last_error'] = mb_substr( $error, 0, 255 );
		}

		self::begin();

		// Sólo uno que sigue sin resolver: dos pasadas a la vez podrían pisar un `confirmed`
		// con un `uncertain`.
		$sets   = implode( ', ', array_map( static fn ( string $column ) => "{$column} = %s", array_keys( $fields ) ) );
		$values = array_merge( array_values( $fields ), array( $movement_id, self::SYNCING, self::UNCERTAIN ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$sets} WHERE id = %d AND delivery IN ( %s, %s )", ...$values ) );
		// phpcs:enable

		if ( 1 === $updated && 'failed' === $delivery ) {
			// Por el `link_id` de la fila y no por la generación: después de invertir los
			// roles, un vínculo que esta tienda recibió puede tener la generación igual a la
			// secuencia de este bootstrap.
			$link_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT link_id FROM {$table} WHERE id = %d", $movement_id ) ); // phpcs:ignore WordPress.DB

			if ( 0 !== $link_id && ! Links::delete( $link_id ) ) {
				self::rollback();

				return;
			}
		}

		// En la misma transacción: si el proceso muriera entre marcar `failed` y borrar el
		// vínculo, la maestra se quedaría con un par sin pareja.
		self::commit();
	}

	/**
	 * Los bootstraps propios que hay que averiguar: `uncertain`, o `syncing` de un proceso
	 * que murió (ADR-008 §5). Sólo los que tienen más de `$age` segundos: uno que la otra
	 * tienda todavía esté procesando no se toma por perdido.
	 *
	 * @param int $age Segundos desde el envío.
	 *
	 * @return \stdClass[]
	 */
	public static function unresolved_bootstraps( int $age ): array {
		global $wpdb;

		$table = Installer::table( 'movements' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				  WHERE reason = %s
				    AND delivery IN ( %s, %s )
				    AND created_at < %s
				  ORDER BY id",
				self::BOOTSTRAP,
				self::SYNCING,
				self::UNCERTAIN,
				gmdate( 'Y-m-d H:i:s', time() - $age )
			)
		);
		// phpcs:enable

		return (array) $rows;
	}

	/**
	 * Escribe un `unlink` propio y borra el vínculo, juntos (ADR-005 §3, ADR-008 §6).
	 *
	 * Primero el movimiento, con la generación del vínculo, y después el borrado: al revés
	 * que ADR-005 §3, porque el movimiento necesita el vínculo para saber su generación. Va
	 * por la cola: una venta anterior tiene que llegar antes que la baja.
	 *
	 * @param int $product Producto de esta tienda.
	 *
	 * @return int|null ID del movimiento, o null si no había vínculo o no se pudo escribir.
	 */
	public static function record_unlink( int $product ): ?int {
		global $wpdb;

		$link = Links::for_product( $product );

		if ( null === $link ) {
			return null;
		}

		$table = Installer::table( 'movements' );
		$now   = time();

		self::begin();

		$inserted = $wpdb->insert(
			$table,
			array(
				'origin_store'    => Store::id(),
				'generation'      => (int) $link->generation,
				'delta'           => 0,
				'reason'          => self::UNLINK,
				'created_at'      => gmdate( 'Y-m-d H:i:s', $now ),
				'link_id'         => (int) $link->id,
				'origin_product'  => $product,
				'delivery'        => 'pending',
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', $now + self::DELIVERY_DELAY ),
			)
		);

		if ( false === $inserted ) {
			self::rollback();

			return null;
		}

		$movement_id = (int) $wpdb->insert_id;
		$updated     = $wpdb->update( $table, array( 'origin_seq' => $movement_id ), array( 'id' => $movement_id ) );

		if ( false === $updated || ! Links::delete( (int) $link->id ) ) {
			self::rollback();

			return null;
		}

		self::commit();

		return $movement_id;
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

		self::begin();

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
			self::rollback();

			return null;
		}

		$movement_id = (int) $wpdb->insert_id;

		$updated = $wpdb->update( $table, array( 'origin_seq' => $movement_id ), array( 'id' => $movement_id ) );

		// La transacción está para que las dos escrituras vayan juntas o no vaya ninguna:
		// confirmar con la secuencia sin llenar deja en el libro la fila que la
		// transacción tenía que impedir, pendiente de entregar y sin identificador.
		if ( false === $updated ) {
			self::rollback();

			return null;
		}

		self::commit();

		return $movement_id;
	}

	/**
	 * Abre la transacción de `record()`, o un `SAVEPOINT` si ya hay una abierta.
	 */
	private static function begin(): void {
		global $wpdb;

		$wpdb->query( self::$in_transaction ? 'SAVEPOINT wss_record' : 'START TRANSACTION' ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Deshace lo de `record()`, sin tocar la transacción de afuera si la hay.
	 */
	private static function rollback(): void {
		global $wpdb;

		$wpdb->query( self::$in_transaction ? 'ROLLBACK TO SAVEPOINT wss_record' : 'ROLLBACK' ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Confirma lo de `record()`. Adentro de otra transacción sólo suelta el `SAVEPOINT`: la
	 * confirma quien la abrió.
	 */
	private static function commit(): void {
		global $wpdb;

		$wpdb->query( self::$in_transaction ? 'RELEASE SAVEPOINT wss_record' : 'COMMIT' ); // phpcs:ignore WordPress.DB
	}
}
