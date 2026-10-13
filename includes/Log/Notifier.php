<?php
/**
 * Los avisos por email.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Log;

use Woonderful\StockSync\Admin\Page;
use Woonderful\StockSync\Catalog\Rows;
use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Peers;
use Woonderful\StockSync\Transport\Pause;

defined( 'ABSPATH' ) || exit;

/**
 * Avisa por email de lo que pasa cuando no hay nadie mirando (ADR-012).
 *
 * Cada tienda avisa de lo suyo. Cada aviso sale de un evento que ya está en el log: si no hay
 * nada nuevo, no sale nada. Como los de woonderful-sync: texto plano, con el nombre del plugin
 * en el asunto y el link al panel al final.
 */
final class Notifier {

	/**
	 * Si se mandan emails. Por defecto, sí.
	 */
	public const OPTION_ENABLED = 'wss_notify_enabled';

	/**
	 * A quién. Por defecto, el administrador del sitio.
	 */
	public const OPTION_EMAIL = 'wss_notify_email';

	/**
	 * Cuántas horas sin vincular antes de avisar. Por defecto, 24.
	 */
	public const OPTION_AFTER = 'wss_notify_after_hours';

	/**
	 * Las ventanas por causa, lo que se juntó, hasta dónde se leyó el log, y la cuenta del día.
	 */
	public const OPTION_STATE = 'wss_notify_state';

	/**
	 * La ventana de silencio de cada causa, en segundos (UC-08).
	 */
	public const WINDOW = 600;

	/**
	 * Cuánto tiene que durar una caída o un rechazo para avisarlo, en segundos (ADR-012 §2).
	 */
	public const PEER_AFTER = 1800;

	/**
	 * Cuántos emails por día, como mucho (ADR-012 §5).
	 */
	public const DAILY_CAP = 10;

	/**
	 * Cuántos productos se listan en un email; del resto, la cuenta.
	 */
	public const LIST_LIMIT = 50;

	/**
	 * Cómo empieza el asunto, como `[Woonderful Sync]` en el otro plugin.
	 */
	public const SUBJECT_PREFIX = '[Woonderful Stock Sync] ';

	/**
	 * Cuántos eventos lee el tick de una vez.
	 */
	private const BATCH = 1000;

	/**
	 * Los eventos que se avisan en el momento, y la causa con que se agrupan.
	 */
	private const NOTIFIABLE = array(
		Events::LINK_FAILED     => 'link_failed',
		Events::LINK_UNRESOLVED => 'link_failed',
		Events::PAIR_BROKEN     => 'pair_broken',
	);

	/**
	 * Lo que corre cada minuto, con la pasada: mira el reloj (ADR-012 §2).
	 *
	 * Un evento se escribe sólo cuando algo cambia; si después no pasa nada, nadie vuelve a
	 * mirar. El tick avisa la caída que ya duró lo suficiente, junta lo nuevo del log por
	 * causa, y manda lo de cada ventana que se cerró.
	 *
	 * @param int|null $now Ahora. Para los tests.
	 */
	public static function tick( ?int $now = null ): void {
		$now   = $now ?? time();
		$state = self::state();

		// Apagado, los errores no se guardan para después: al encender no tiene que llegar
		// una caída que ya pasó (ADR-012 §6).
		if ( ! self::enabled() ) {
			$state['cursor']  = self::last_event_id();
			$state['windows'] = array();
			self::save( $state );

			// Tampoco una vuelta que quedó pendiente: al encender sería una noticia vieja.
			Peer::take_back_pending();

			return;
		}

		// En pausa no se le habla a la otra: que no conteste no es noticia (ADR-015 §1).
		if ( ! Pause::is_on() ) {
			self::check_peer( $now, $state );
		}

		self::collect( $state );
		self::flush( $now, $state );

		self::save( $state );
	}

	/**
	 * El resumen de la noche: lo que no sincroniza, una vez por producto (ADR-012 §3).
	 *
	 * @param int|null $now Ahora. Para los tests.
	 */
	public static function digest( ?int $now = null ): void {
		if ( ! self::enabled() ) {
			return;
		}

		$now      = $now ?? time();
		$pending  = self::unsynced( $now );
		$new      = array_values( array_filter( $pending, static fn ( array $product ) => ! $product['notified'] ) );
		$state    = self::state();
		$products = array_column( $new, 'id' );

		if ( array() === $new ) {
			return;
		}

		$lines = array_map( static fn ( array $product ) => sprintf( '- %s (#%d)', $product['name'], $product['id'] ), array_slice( $new, 0, self::LIST_LIMIT ) );

		if ( count( $new ) > self::LIST_LIMIT ) {
			/* translators: %d: cuántos más. */
			$lines[] = sprintf( __( '…y otros %d.', 'woonderful-stock-sync-for-woocommerce' ), count( $new ) - self::LIST_LIMIT );
		}

		$peer  = Peers::describe();
		$other = null === $peer ? __( 'la otra tienda', 'woonderful-stock-sync-for-woocommerce' ) : $peer['label'];

		$intro = sprintf(
			/* translators: 1: cuántos, 2: esta tienda, 3: la otra tienda. */
			_n(
				'En %2$s hay %1$d producto que todavía no está vinculado con un producto de %3$s.',
				'En %2$s hay %1$d productos que todavía no están vinculados con un producto de %3$s.',
				count( $new ),
				'woonderful-stock-sync-for-woocommerce'
			),
			count( $new ),
			Store::label(),
			$other
		);

		$why = _n(
			'Mientras no esté vinculado, su stock no se sincroniza: si se vende en una tienda, el stock de la otra no se descuenta.',
			'Mientras no estén vinculados, su stock no se sincroniza: si se vende uno en una tienda, el stock de la otra no se descuenta.',
			count( $new ),
			'woonderful-stock-sync-for-woocommerce'
		);

		$which = sprintf(
			/* translators: %s: el plazo, como "24 horas". */
			_n( 'Es el que lleva más de %s sin vincular:', 'Son los que llevan más de %s sin vincular:', count( $new ), 'woonderful-stock-sync-for-woocommerce' ),
			self::hours_text( self::after_hours() )
		);

		$how = _n(
			'Para vincularlo, entrá a Stock Sync → Productos y elegí el filtro "Sin vincular".',
			'Para vincularlos, entrá a Stock Sync → Productos y elegí el filtro "Sin vincular".',
			count( $new ),
			'woonderful-stock-sync-for-woocommerce'
		);

		$once = __( 'Si está bien que no se sincronicen, podés ignorar este mensaje. Este email llega una sola vez por producto: no se repetirá, sólo avisaremos si encontramos productos nuevos sin sincronizar.', 'woonderful-stock-sync-for-woocommerce' );

		$body = $intro . ' ' . $why . "\n\n" . $which . "\n" . implode( "\n", $lines ) . "\n\n" . $how . "\n\n" . $once;

		// El total es el de la tarjeta de Resumen: un solo número de lo que no sincroniza.
		$subject = sprintf(
			/* translators: 1: cuántos nuevos, 2: cuántos en total. */
			_n( '%1$d producto nuevo sin vincular (%2$d en total)', '%1$d productos nuevos sin vincular (%2$d en total)', count( $new ), 'woonderful-stock-sync-for-woocommerce' ),
			count( $new ),
			Rows::linking()['unlinked']
		);
		$sent    = self::send( $state, $subject, $body, 'unsynced', $now );

		self::save( $state );

		if ( $sent ) {
			foreach ( $products as $product ) {
				Events::record( Events::NOTIFIED, Events::INFO, (int) $product );
			}

			Events::record(
				Events::DIGEST_SENT,
				Events::INFO,
				null,
				array(
					'count' => count( $new ),
					'total' => count( $pending ),
				)
			);
		}
	}

	/**
	 * Las diferencias de stock entre las dos tiendas que la noche confirmó (ADR-021).
	 *
	 * @param array<int, array{product: int, here: int, there: int}> $diffs Las nuevas.
	 */
	public static function cross_diffs( array $diffs ): void {
		if ( ! self::enabled() || array() === $diffs ) {
			return;
		}

		$peer  = Peers::describe();
		$other = null === $peer ? __( 'la otra tienda', 'woonderful-stock-sync-for-woocommerce' ) : $peer['label'];
		$lines = array();

		foreach ( array_slice( $diffs, 0, self::LIST_LIMIT ) as $diff ) {
			$product = wc_get_product( $diff['product'] );

			$lines[] = sprintf(
				/* translators: 1: producto, 2: su ID, 3: esta tienda, 4: su stock, 5: la otra tienda, 6: su stock. */
				__( '- %1$s (#%2$d): %3$s tiene %4$d, %5$s tiene %6$d.', 'woonderful-stock-sync-for-woocommerce' ),
				$product instanceof \WC_Product ? $product->get_name() : '#' . $diff['product'],
				$diff['product'],
				Store::label(),
				$diff['here'],
				$other,
				$diff['there']
			);
		}

		if ( count( $diffs ) > self::LIST_LIMIT ) {
			/* translators: %d: cuántos más. */
			$lines[] = sprintf( __( '…y otros %d.', 'woonderful-stock-sync-for-woocommerce' ), count( $diffs ) - self::LIST_LIMIT );
		}

		$intro = _n(
			'Este producto vinculado tiene un stock distinto en cada tienda. Como las dos venden del mismo depósito, uno de los dos números está mal:',
			'Estos productos vinculados tienen un stock distinto en cada tienda. Como las dos venden del mismo depósito, en cada uno hay un número que está mal:',
			count( $diffs ),
			'woonderful-stock-sync-for-woocommerce'
		);

		$how = sprintf(
			/* translators: %s: la tienda maestra. */
			__( 'Corregirlo a mano en una de las dos no alcanza: el cambio se manda a la otra como diferencia, y las dos se mueven lo mismo. Para igualarlas, entrá a Stock Sync → Productos en %s (la maestra), abrí el menú del producto y elegí «Igualar stock»: te muestra los dos números y elegís cuál vale.', 'woonderful-stock-sync-for-woocommerce' ),
			Store::label()
		);

		$once = __( 'Este aviso llega una vez por diferencia: si mañana sigue igual, no se repite.', 'woonderful-stock-sync-for-woocommerce' );

		$subject = sprintf(
			/* translators: %d: cuántos productos. */
			_n( '%d producto con stock distinto entre las tiendas', '%d productos con stock distinto entre las tiendas', count( $diffs ), 'woonderful-stock-sync-for-woocommerce' ),
			count( $diffs )
		);

		$state = self::state();

		self::send( $state, $subject, $intro . "\n\n" . implode( "\n", $lines ) . "\n\n" . $how . "\n\n" . $once, 'cross_diff', time() );
		self::save( $state );
	}

	/**
	 * Cambia los avisos (ADR-012 §6). Valida todo antes de guardar nada: con un dato malo, no
	 * cambia ninguno.
	 *
	 * @param array<string, mixed> $changes `enabled` (bool), `email` ('' vuelve al del
	 *                                      administrador) y `after` (horas, desde 1). Lo que no
	 *                                      viene, no se toca.
	 *
	 * @return true|\WP_Error
	 */
	public static function configure( array $changes ) {
		if ( isset( $changes['email'] ) ) {
			$changes['email'] = trim( (string) $changes['email'] );

			if ( '' !== $changes['email'] && ! is_email( $changes['email'] ) ) {
				/* translators: %s: lo que se pasó. */
				return new \WP_Error( 'wss_invalid_email', sprintf( __( '"%s" no es un email.', 'woonderful-stock-sync-for-woocommerce' ), $changes['email'] ) );
			}
		}

		if ( isset( $changes['after'] ) && ( ! is_numeric( $changes['after'] ) || (int) $changes['after'] < 1 || (string) (int) $changes['after'] !== (string) $changes['after'] ) ) {
			return new \WP_Error( 'wss_invalid_after', __( 'El plazo es una cantidad de horas, desde 1.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		if ( isset( $changes['enabled'] ) ) {
			update_option( self::OPTION_ENABLED, $changes['enabled'] ? 'yes' : 'no', false );
		}

		if ( isset( $changes['email'] ) ) {
			update_option( self::OPTION_EMAIL, $changes['email'], false );
		}

		if ( isset( $changes['after'] ) ) {
			update_option( self::OPTION_AFTER, (int) $changes['after'], false );
		}

		return true;
	}

	/**
	 * Un email de prueba al destinatario de los avisos: un hosting sin correo no avisa nada
	 * (ADR-013 §3). Sale aunque los avisos estén apagados, y no cuenta para el tope del día.
	 */
	public static function test(): bool {
		return self::mail(
			__( 'Email de prueba', 'woonderful-stock-sync-for-woocommerce' ),
			__( 'Si leés esto, los avisos de Woonderful Stock Sync llegan a esta casilla.', 'woonderful-stock-sync-for-woocommerce' )
		);
	}

	/**
	 * Si se mandan emails.
	 */
	public static function enabled(): bool {
		return 'no' !== get_option( self::OPTION_ENABLED, 'yes' );
	}

	/**
	 * A quién se mandan.
	 */
	public static function recipient(): string {
		$email = (string) get_option( self::OPTION_EMAIL, '' );

		return '' === $email ? (string) get_option( 'admin_email' ) : $email;
	}

	/**
	 * El plazo de lo que no sincroniza, en horas.
	 */
	public static function after_hours(): int {
		return max( 1, (int) get_option( self::OPTION_AFTER, 24 ) );
	}

	/**
	 * La caída o el rechazo de la otra, a los 30 minutos; y que volvió, si se avisó.
	 *
	 * Si la otra no está bien, primero le pregunta: sin ventas nadie le habla, y el estado
	 * diría "caída" de una tienda que volvió hace rato.
	 *
	 * @param int                  $now   Ahora.
	 * @param array<string, mixed> $state El estado de los avisos.
	 */
	private static function check_peer( int $now, array &$state ): void {
		if ( Peer::OK !== Peer::current()['state'] ) {
			Peer::probe();
		}

		$peer = Peer::current();

		$due = Peer::OK !== $peer['state'] && ! $peer['notified'] && $now - $peer['since'] >= self::PEER_AFTER;

		// Si el email no salió, se reintenta una vez por ventana, no cada minuto (ADR-012 §7).
		if ( $due && $now - $peer['tried_at'] >= self::WINDOW ) {
			Peer::mark_tried( $now );

			$unreachable = Peer::UNREACHABLE === $peer['state'];
			$since       = wp_date( 'Y-m-d H:i', $peer['since'] );

			$sent = $unreachable
				? self::send(
					$state,
					__( 'La otra tienda no responde', 'woonderful-stock-sync-for-woocommerce' ),
					sprintf(
						/* translators: 1: desde cuándo, 2: el error. */
						__( "La otra tienda no responde desde %1\$s (%2\$s).\n\nLas ventas de esta tienda se guardan y salen cuando vuelva: no se pierde nada. Mientras tanto, la otra no se entera de lo que se vende acá, y puede vender lo que ya no hay.\n\nSe avisa una sola vez. Cuando vuelva, llega otro aviso.", 'woonderful-stock-sync-for-woocommerce' ),
						$since,
						(string) $peer['error']
					),
					'peer',
					$now
				)
				: self::send(
					$state,
					__( 'La otra tienda rechaza los pedidos de ésta', 'woonderful-stock-sync-for-woocommerce' ),
					sprintf(
						/* translators: 1: desde cuándo, 2: el error. */
						__( "La otra tienda responde, pero rechaza los pedidos de ésta desde %1\$s (%2\$s).\n\nEsto no se arregla solo: puede ser un emparejamiento que quedó mal, o un reloj desfasado. Las ventas se guardan, pero no llegan a la otra.", 'woonderful-stock-sync-for-woocommerce' ),
						$since,
						(string) $peer['error']
					),
					'peer',
					$now
				);

			if ( $sent ) {
				Peer::mark_notified();
			}
		}

		if ( Peer::take_back_pending() ) {
			self::send(
				$state,
				__( 'La otra tienda volvió', 'woonderful-stock-sync-for-woocommerce' ),
				__( 'La otra tienda volvió a responder. Lo que quedó pendiente lo mandan la cola y la reconciliación: no hace falta hacer nada.', 'woonderful-stock-sync-for-woocommerce' ),
				'peer',
				$now
			);
		}
	}

	/**
	 * Junta lo nuevo del log en la ventana de su causa.
	 *
	 * No se junta lo que se atribuye a la caída o al rechazo de la otra: ya se avisó una vez.
	 * Ni lo que pasó mientras el dueño miraba: un intento de vincular que falla se lo dice el
	 * resultado, no el email (ADR-012 §2).
	 *
	 * @param array<string, mixed> $state El estado de los avisos.
	 */
	private static function collect( array &$state ): void {
		global $wpdb;

		$table = Installer::table( 'events' );
		$kinds = array_keys( self::NOTIFIABLE );

		// Hasta dónde se lee, fijado antes: un evento que se escribe en el medio queda para el
		// tick siguiente, no se saltea.
		$top = self::last_event_id();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, kind, product, cause, context FROM {$table}
				  WHERE id > %d AND id <= %d AND kind IN ( " . implode( ', ', array_fill( 0, count( $kinds ), '%s' ) ) . ' )
				  ORDER BY id LIMIT %d',
				array_merge( array( (int) $state['cursor'], $top ), $kinds, array( self::BATCH ) )
			)
		);
		// phpcs:enable

		// Con la tanda llena, lo que sigue se lee en el tick siguiente.
		$state['cursor'] = count( $rows ) === self::BATCH ? (int) end( $rows )->id : max( (int) $state['cursor'], $top );

		foreach ( $rows as $row ) {
			if ( in_array( $row->cause, array( Events::PEER_UNREACHABLE, Events::PEER_REJECTED ), true ) ) {
				continue;
			}

			$context = null === $row->context ? array() : (array) json_decode( (string) $row->context, true );

			if ( Events::LINK_FAILED === $row->kind && true !== ( $context['background'] ?? false ) ) {
				continue;
			}

			$cause = self::NOTIFIABLE[ (string) $row->kind ];

			$state['windows'][ $cause ]['pending'][] = array(
				'kind'    => (string) $row->kind,
				'product' => null === $row->product ? 0 : (int) $row->product,
				'reason'  => (string) ( $context['reason'] ?? '' ),
			);
		}
	}

	/**
	 * Manda lo de cada ventana que está cerrada: la primera de una causa sale en el momento,
	 * lo que llega después se junta hasta que la ventana se cierre (UC-08).
	 *
	 * @param int                  $now   Ahora.
	 * @param array<string, mixed> $state El estado de los avisos.
	 */
	private static function flush( int $now, array &$state ): void {
		foreach ( $state['windows'] as $cause => $window ) {
			$pending = (array) ( $window['pending'] ?? array() );

			if ( array() === $pending || $now < (int) ( $window['until'] ?? 0 ) ) {
				continue;
			}

			$sent = self::send( $state, self::subject_for( (string) $cause, count( $pending ) ), self::body_for( (string) $cause, $pending ), (string) $cause, $now );

			// Lo que no salió se reintenta al cerrarse la ventana siguiente, no cada minuto.
			$state['windows'][ $cause ] = array(
				'until'   => $now + self::WINDOW,
				'pending' => $sent ? array() : $pending,
			);
		}
	}

	/**
	 * Manda un email, respetando el tope del día.
	 *
	 * Al llegar al tope sale un último aviso que remite al log, y después nada hasta el día
	 * siguiente: lo que no sale queda en el log, y se da por avisado (ADR-012 §5).
	 *
	 * @param array<string, mixed> $state   El estado de los avisos.
	 * @param string               $subject El asunto, sin el prefijo.
	 * @param string               $body    El cuerpo.
	 * @param string               $about   De qué es, para el log si falla.
	 * @param int                  $now     Ahora: el día del tope sale de acá.
	 *
	 * @return bool Si se da por avisado: salió, o no sale por el tope.
	 */
	private static function send( array &$state, string $subject, string $body, string $about, int $now ): bool {
		$today = (string) wp_date( 'Y-m-d', $now );

		if ( $state['day'] !== $today ) {
			$state['day']    = $today;
			$state['sent']   = 0;
			$state['capped'] = false;
		}

		if ( $state['sent'] >= self::DAILY_CAP ) {
			if ( ! $state['capped'] ) {
				$state['capped'] = true;

				self::mail(
					__( 'Muchos avisos hoy', 'woonderful-stock-sync-for-woocommerce' ),
					sprintf(
						/* translators: %d: el tope. */
						__( 'Hoy ya salieron %d avisos, el máximo por día. Hasta mañana no sale ninguno más: lo que pase queda en el log de la tienda. Mirá el log para ver qué está pasando.', 'woonderful-stock-sync-for-woocommerce' ),
						self::DAILY_CAP
					)
				);
			}

			return true;
		}

		if ( ! self::mail( $subject, $body ) ) {
			// Una vez por incidente: hasta que un email de esto salga, no se repite.
			if ( ! isset( $state['failing'][ $about ] ) ) {
				Events::record( Events::NOTIFY_FAILED, Events::ERROR, null, array( 'about' => $about ) );
				$state['failing'][ $about ] = true;
			}

			return false;
		}

		unset( $state['failing'][ $about ] );
		++$state['sent'];

		return true;
	}

	/**
	 * Manda un email con `wp_mail()`, como los de woonderful-sync.
	 *
	 * @param string $subject El asunto, sin el prefijo.
	 * @param string $body    El cuerpo.
	 */
	private static function mail( string $subject, string $body ): bool {
		$footer = sprintf(
			/* translators: 1: la tienda, 2: el panel. */
			__( "Tienda: %1\$s\nPanel: %2\$s", 'woonderful-stock-sync-for-woocommerce' ),
			Store::label(),
			admin_url( 'admin.php?page=' . Page::SLUG )
		);

		return (bool) wp_mail( self::recipient(), self::SUBJECT_PREFIX . $subject, $body . "\n\n" . $footer );
	}

	/**
	 * El asunto del aviso de una causa.
	 *
	 * @param string $cause La causa.
	 * @param int    $count Cuántos.
	 */
	private static function subject_for( string $cause, int $count ): string {
		if ( 'pair_broken' === $cause ) {
			/* translators: %d: cuántos. */
			return sprintf( _n( 'Se rompió %d par', 'Se rompieron %d pares', $count, 'woonderful-stock-sync-for-woocommerce' ), $count );
		}

		/* translators: %d: cuántos. */
		return sprintf( _n( 'No se pudo vincular %d producto', 'No se pudieron vincular %d productos', $count, 'woonderful-stock-sync-for-woocommerce' ), $count );
	}

	/**
	 * El cuerpo del aviso de una causa: qué pasó, y la lista.
	 *
	 * @param string                           $cause   La causa.
	 * @param array<int, array<string, mixed>> $pending Lo que se juntó.
	 */
	private static function body_for( string $cause, array $pending ): string {
		$lines = array();

		foreach ( array_slice( $pending, 0, self::LIST_LIMIT ) as $item ) {
			$product = wc_get_product( (int) $item['product'] );
			$name    = $product instanceof \WC_Product ? $product->get_name() : '';
			$lines[] = trim( sprintf( '- %s (#%d) %s', $name, (int) $item['product'], '' === $item['reason'] ? '' : '— ' . Events::reason_text( $item['reason'] ) ) );
		}

		if ( count( $pending ) > self::LIST_LIMIT ) {
			/* translators: %d: cuántos más. */
			$lines[] = sprintf( __( '…y otros %d.', 'woonderful-stock-sync-for-woocommerce' ), count( $pending ) - self::LIST_LIMIT );
		}

		$intro = 'pair_broken' === $cause
			? __( 'Estos productos estaban vinculados y ya no pueden sincronizar: se borraron, están en la papelera o dejaron de llevar su propio stock. Sus ventas quedan retenidas en la otra tienda hasta que vuelvan.', 'woonderful-stock-sync-for-woocommerce' )
			: __( 'Estos productos no se pudieron vincular, o no se sabe si la otra tienda los vinculó. El vínculo no se reintenta solo: volvé a vincularlos.', 'woonderful-stock-sync-for-woocommerce' );

		return $intro . "\n\n" . implode( "\n", $lines );
	}

	/**
	 * Los productos de esta tienda que no sincronizan desde hace más del plazo, y si ya se
	 * avisó de cada uno desde la última vez que quedó sin vincular (ADR-012 §3).
	 *
	 * Lleva su propio stock, está publicado, no está vinculado, no está descartado, y pasó el
	 * plazo desde que se creó o desde que se desvinculó.
	 *
	 * @param int $now Ahora.
	 *
	 * @return array<int, array{id: int, name: string, notified: bool}>
	 */
	public static function unsynced( int $now ): array {
		global $wpdb;

		$links  = Installer::table( 'links' );
		$events = Installer::table( 'events' );
		$cutoff = gmdate( 'Y-m-d H:i:s', $now - self::after_hours() * HOUR_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// También lo que acá no lleva su stock y allá sí (ADR-024): para que sincronice hay que
		// activarlo en una de las dos.
		$mismatched = implode( ',', array_map( 'intval', Rows::mismatched() ) );
		$mismatched = '' === $mismatched ? '0' : $mismatched;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title FROM {$wpdb->posts} p
				   LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_manage_stock'
				  WHERE ( m.meta_value = 'yes' OR p.ID IN ( {$mismatched} ) )
				    AND p.post_type IN ( 'product', 'product_variation' )
				    AND p.post_status = 'publish'
				    AND p.post_date_gmt <= %s
				    AND p.ID NOT IN ( SELECT local_product FROM {$links} )
				  ORDER BY p.ID",
				$cutoff
			)
		);

		if ( array() === $rows ) {
			return array();
		}

		$ids  = array_map( static fn ( $row ) => (int) $row->ID, $rows );
		$last = array();

		$remembered = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product, kind, MAX( id ) AS id, MAX( created_at ) AS at FROM {$events}
				  WHERE kind IN ( 'unlinked', 'discarded', 'undiscarded', 'notified' )
				    AND product IN ( " . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ' )
				  GROUP BY product, kind',
				$ids
			)
		);

		foreach ( $remembered as $row ) {
			$last[ (int) $row->product ][ (string) $row->kind ] = array( (int) $row->id, (string) $row->at );
		}
		// phpcs:enable

		$products = array();

		foreach ( $rows as $row ) {
			$id   = (int) $row->ID;
			$seen = $last[ $id ] ?? array();

			$discarded   = $seen[ Events::DISCARDED ][0] ?? 0;
			$undiscarded = $seen[ Events::UNDISCARDED ][0] ?? 0;
			$unlinked    = $seen[ Events::UNLINKED ] ?? array( 0, '' );
			$notified    = $seen[ Events::NOTIFIED ][0] ?? 0;

			if ( $discarded > $undiscarded || ( '' !== $unlinked[1] && $unlinked[1] > $cutoff ) ) {
				continue;
			}

			$products[] = array(
				'id'       => $id,
				'name'     => (string) $row->post_title,
				'notified' => $notified > $unlinked[0],
			);
		}

		return $products;
	}

	/**
	 * Un plazo en horas, en castellano: "1 hora", "24 horas".
	 *
	 * @param int $hours Las horas.
	 */
	public static function hours_text( int $hours ): string {
		/* translators: %d: horas. */
		return sprintf( _n( '%d hora', '%d horas', $hours, 'woonderful-stock-sync-for-woocommerce' ), $hours );
	}

	/**
	 * El último evento del log.
	 */
	private static function last_event_id(): int {
		global $wpdb;

		$table = Installer::table( 'events' );

		return (int) $wpdb->get_var( "SELECT MAX( id ) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * El estado de los avisos.
	 *
	 * @return array{cursor: int, windows: array<string, array<string, mixed>>, day: string, sent: int, capped: bool, failing: array<string, bool>}
	 */
	private static function state(): array {
		$stored = get_option( self::OPTION_STATE, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'cursor'  => (int) ( $stored['cursor'] ?? 0 ),
			'windows' => is_array( $stored['windows'] ?? null ) ? $stored['windows'] : array(),
			'day'     => (string) ( $stored['day'] ?? '' ),
			'sent'    => (int) ( $stored['sent'] ?? 0 ),
			'capped'  => true === ( $stored['capped'] ?? false ),
			'failing' => is_array( $stored['failing'] ?? null ) ? $stored['failing'] : array(),
		);
	}

	/**
	 * Guarda el estado de los avisos.
	 *
	 * @param array<string, mixed> $state El estado.
	 */
	private static function save( array $state ): void {
		update_option( self::OPTION_STATE, $state, false );
	}
}
