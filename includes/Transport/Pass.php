<?php
/**
 * La pasada recurrente que vacía los pendientes hacia la otra tienda.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Transport;

use Woonderful\StockSync\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Una sola acción recurrente de Action Scheduler: no una por movimiento (ADR-001 §2).
 */
final class Pass {

	/**
	 * El hook de la acción.
	 */
	public const HOOK = 'wss_deliver';

	/**
	 * El grupo, para encontrarla en la pantalla de Action Scheduler.
	 */
	public const GROUP = 'woonderful-stock-sync';

	/**
	 * Cada cuánto corre, en segundos.
	 *
	 * Lo más frecuente que Action Scheduler corre con confianza: lo dispara WP-Cron, que
	 * corre cuando alguien visita el sitio. Una constante, como el resto de los tiempos de
	 * la entrega (ADR-001 §2).
	 */
	public const INTERVAL = 60;

	/**
	 * La hora del último control de que la acción esté agendada. **Autocargada**: se lee en
	 * cada request sin consultar la base (ADR-003, revisado el 2026-09-29).
	 */
	public const OPTION_CHECKED = 'wss_pass_checked_at';

	/**
	 * Cada cuánto se controla, en segundos.
	 *
	 * Es lo que tarda, como mucho, en volver a agendarse una pasada que se perdió.
	 */
	public const CHECK_EVERY = 3600;

	/**
	 * Engancha el callback de la acción.
	 *
	 * Siempre, aunque la sincronización esté suspendida: un clon trae la acción agendada
	 * en su base, y sin callback Action Scheduler la marcaría fallida cada minuto. La que
	 * decide no hacer nada es la pasada.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	/**
	 * Engancha el control del agendado.
	 *
	 * En cualquier request y no sólo en el admin. Action Scheduler agenda la próxima vuelta
	 * de una acción recurrente **después** de ejecutarla: si el proceso muere en el medio
	 * —sin memoria, o PHP-FPM lo mata—, o si la acción falló varias veces seguidas, no hay
	 * próxima vuelta. Esperar a que el dueño entre al admin podía dejar la tienda días sin
	 * mandar nada. En `action_scheduler_init`, que corre cuando ya se puede agendar.
	 */
	public static function register_scheduling(): void {
		add_action( 'action_scheduler_init', array( self::class, 'maybe_ensure_scheduled' ) );
	}

	/**
	 * Controla el agendado, a lo sumo una vez por hora.
	 *
	 * Mirar si está agendada es una consulta, y esto corre en cada página de la tienda: la
	 * hora del último control va en una opción autocargada, que se lee sin ir a la base.
	 */
	public static function maybe_ensure_scheduled(): void {
		$now = time();

		if ( $now - (int) get_option( self::OPTION_CHECKED, 0 ) < self::CHECK_EVERY ) {
			return;
		}

		// Antes de agendar: si agendar fallara, no se reintenta en cada request.
		update_option( self::OPTION_CHECKED, $now, true );

		self::ensure_scheduled();
	}

	/**
	 * Una pasada: vacía un lote hacia la otra tienda y purga los nonces viejos.
	 */
	public static function run(): void {
		if ( null !== Plugin::suspension_reason() ) {
			return;
		}

		Sender::run();
		Nonces::purge( time() );
	}

	/**
	 * Agenda la acción si no está agendada.
	 *
	 * `unique` es el que no deja agendar una segunda: Action Scheduler no la crea si ya hay
	 * una pendiente o corriendo con el mismo hook y grupo. Existe desde Action Scheduler
	 * 3.6.0, anterior al que trae WooCommerce 9.1.
	 */
	public static function ensure_scheduled(): void {
		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			as_schedule_recurring_action( time(), self::INTERVAL, self::HOOK, array(), self::GROUP, true );
		}
	}

	/**
	 * Desagenda la acción. Al desactivar el plugin.
	 *
	 * Alcanza con la desactivación: WordPress no deja desinstalar un plugin activo. Si
	 * WooCommerce se desactivó antes, Action Scheduler no está y la acción queda agendada;
	 * no rompe nada, porque al volver WooCommerce el callback está enganchado.
	 *
	 * Se borra también la hora del control, para que al reactivar se agende enseguida.
	 */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}

		delete_option( self::OPTION_CHECKED );
	}
}
