<?php
/**
 * Cuándo se lanza la reconciliación sola.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Reconciliation;

use Woonderful\StockSync\Ledger\Ledger;
use Woonderful\StockSync\Plugin;
use Woonderful\StockSync\Transport\Pass;
use Woonderful\StockSync\Transport\Peers;

defined( 'ABSPATH' ) || exit;

/**
 * La pasada de la noche y el cierre de una importación (ADR-009 §5). La manual es
 * `wp wss reconcile`.
 */
final class Schedule {

	/**
	 * La acción de cada noche.
	 */
	public const NIGHTLY = 'wss_nightly';

	/**
	 * El cierre de una importación.
	 */
	public const IMPORT = 'wss_import_closed';

	/**
	 * La hora de la noche de la maestra, en la hora del sitio: reconcilia y después purga.
	 */
	public const NIGHT_HOUR = 3;

	/**
	 * La de la secundaria, que sólo purga: una hora después, para no purgar mientras la
	 * maestra reconcilia con ella. Purgar toma el candado, y con el candado tomado esta
	 * tienda le contesta a la otra que está ocupada.
	 */
	public const SECONDARY_NIGHT_HOUR = 4;

	/**
	 * Cuánto espera el cierre antes de mirar, y antes de volver a mirar, en segundos.
	 */
	public const IMPORT_WAIT = 300;

	/**
	 * Engancha los callbacks.
	 *
	 * Siempre, aunque la sincronización esté suspendida, por lo mismo que la pasada: sin
	 * callback, Action Scheduler marcaría fallida la acción que un clon trae agendada. Los
	 * callbacks deciden no hacer nada.
	 */
	public static function register(): void {
		add_action( self::NIGHTLY, array( self::class, 'nightly' ) );
		add_action( self::IMPORT, array( self::class, 'import_closed' ) );
		add_action( Audit::CONFIRM, array( Audit::class, 'confirm' ) );
	}

	/**
	 * Agenda la noche si no está agendada. Lo llama el control horario de la pasada.
	 *
	 * Se agenda en las dos tiendas: la noche también purga el libro, y eso lo hace cada una.
	 * Reconciliar, sólo la maestra.
	 */
	public static function ensure_nightly(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || as_has_scheduled_action( self::NIGHTLY, null, Pass::GROUP ) ) {
			return;
		}

		self::schedule( self::next_night( time(), self::night_hour() ), self::NIGHTLY, array() );
	}

	/**
	 * La hora de la noche de esta tienda: según sea maestra o secundaria.
	 *
	 * Si el rol cambia, la noche ya agendada corre una vez más a la hora vieja; la siguiente
	 * se agenda a la nueva.
	 */
	public static function night_hour(): int {
		return Peers::this_is_master() ? self::NIGHT_HOUR : self::SECONDARY_NIGHT_HOUR;
	}

	/**
	 * La próxima noche: hoy a esa hora del sitio, o mañana si ya pasó.
	 *
	 * Una acción por vez, y no una recurrente con cron: así la hora es la del sitio sin
	 * depender de cómo interpreta Action Scheduler la zona horaria de una expresión cron.
	 *
	 * @param int $now  Ahora.
	 * @param int $hour La hora.
	 */
	public static function next_night( int $now, int $hour ): int {
		$night = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() )->setTime( $hour, 0 );

		if ( $night->getTimestamp() <= $now ) {
			$night = $night->modify( '+1 day' );
		}

		return $night->getTimestamp();
	}

	/**
	 * La noche: agenda la siguiente; si esta tienda es la maestra, reconcilia; y purga el
	 * libro, en las dos (ADR-004 §4).
	 *
	 * La siguiente se agenda primero: si la reconciliación muriera a mitad, la noche de
	 * mañana igual corre. Sin `unique`, porque Action Scheduler cuenta como agendada a esta
	 * misma acción, que está corriendo.
	 */
	public static function nightly(): void {
		self::schedule( self::next_night( time(), self::night_hour() ), self::NIGHTLY, array() );

		if ( null !== Plugin::suspension_reason() ) {
			return;
		}

		if ( Peers::this_is_master() ) {
			Reconciler::run( Result::NIGHTLY );
		}

		self::purge();
	}

	/**
	 * Purga el libro, con el candado de la reconciliación.
	 *
	 * Si esta tienda purgara mientras reconcilia, su marca de purga se movería en medio de la
	 * pasada. Con el candado tomado por otra, no purga: la noche siguiente lo hace.
	 *
	 * Mientras purga, le contesta a la otra que está ocupada: por eso la secundaria purga una
	 * hora después de que la maestra reconcilia. Si igual coinciden —una reconciliación
	 * manual—, la de la otra se saltea y la cubre la siguiente.
	 */
	private static function purge(): void {
		$lock = Lock::acquire();

		if ( null === $lock ) {
			return;
		}

		try {
			Ledger::purge();
		} finally {
			Lock::release( $lock );
		}
	}

	/**
	 * Mira si la pasada que acaba de correr fue parte de una importación, y si lo fue agenda
	 * su cierre.
	 *
	 * Una importación se nota porque la pasada manda un lote lleno. El cierre se agenda con
	 * el techo de ahora, y uno solo: si ya hay uno agendado, no se agenda otro.
	 *
	 * @param array{sent: int, confirmed: int, failed: int, error: string|null} $summary Lo que hizo la pasada.
	 */
	public static function after_pass( array $summary ): void {
		if ( $summary['sent'] < Ledger::MAX_BATCH || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		if ( as_has_scheduled_action( self::IMPORT, null, Pass::GROUP ) ) {
			return;
		}

		self::schedule( time() + self::IMPORT_WAIT, self::IMPORT, array( Books::ceiling() ) );
	}

	/**
	 * El cierre de una importación: si ya salió todo lo de la ráfaga, reconcilia; si no,
	 * vuelve a mirar en 5 minutos.
	 *
	 * @param int $ceiling El techo de cuando se agendó.
	 */
	public static function import_closed( $ceiling ): void {
		if ( null !== Plugin::suspension_reason() ) {
			return;
		}

		if ( Books::unsent_through( (int) $ceiling ) ) {
			self::schedule( time() + self::IMPORT_WAIT, self::IMPORT, array( (int) $ceiling ) );

			return;
		}

		Reconciler::run( Result::IMPORT );
	}

	/**
	 * Agenda una acción, sin `unique`: quien llama ya miró si hacía falta.
	 *
	 * @param int        $at   Cuándo.
	 * @param string     $hook Cuál.
	 * @param array<int> $args Con qué.
	 */
	private static function schedule( int $at, string $hook, array $args ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $at, $hook, $args, Pass::GROUP );
		}
	}

	/**
	 * Desagenda la noche, el cierre y la segunda revisión de la fase 2. Al desactivar el
	 * plugin, con la pasada.
	 */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::NIGHTLY, array(), Pass::GROUP );
			as_unschedule_all_actions( self::IMPORT, null, Pass::GROUP );
			as_unschedule_all_actions( Audit::CONFIRM, null, Pass::GROUP );
		}
	}
}
