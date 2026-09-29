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
	 * Engancha el agendado.
	 *
	 * En `admin_init` y no en cada request: mirar si está agendada es una consulta, y
	 * `boot()` corre en cada página de la tienda. El admin cubre lo que la activación no:
	 * un plugin actualizado a mano, o una acción que alguien borró.
	 */
	public static function register_scheduling(): void {
		add_action( 'admin_init', array( self::class, 'ensure_scheduled' ) );
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
	 * Alcanza con la desactivación: WordPress no deja desinstalar un plugin activo.
	 */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
	}
}
