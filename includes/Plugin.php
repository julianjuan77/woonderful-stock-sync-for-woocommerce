<?php
/**
 * Arranque del plugin.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync;

use Woonderful\StockSync\Cli\Command;
use Woonderful\StockSync\Detector\Detector;
use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Rest\Auth;
use Woonderful\StockSync\Rest\Health;
use Woonderful\StockSync\Rest\Movements;
use Woonderful\StockSync\Rest\Pair;
use Woonderful\StockSync\Rest\Products;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Pass;

defined( 'ABSPATH' ) || exit;

/**
 * Punto de entrada: activación, arranque y la guarda que suspende la sincronización.
 */
final class Plugin {

	/**
	 * El sitio no es aquel donde se emparejó: es una copia (ADR-001 §1).
	 */
	public const SUSPENDED_CLONE = 'cloned_site';

	/**
	 * Alguna tabla falta o no admite transacciones (ADR-004 §5).
	 */
	public const SUSPENDED_SCHEMA = 'unhealthy_schema';

	/**
	 * Corre una sola vez, al activar.
	 *
	 * La identidad va primero: es lo que numera los movimientos, así que tiene que
	 * existir antes que la tabla que los guarda (ADR-002).
	 */
	public static function activate(): void {
		Store::ensure_identity();
		Installer::install();
	}

	/**
	 * Corre una sola vez, al desactivar.
	 */
	public static function deactivate(): void {
		Pass::unschedule();
	}

	/**
	 * Corre en cada request, en plugins_loaded.
	 */
	public static function boot(): void {
		// activate() no corre si el plugin llega copiado o actualizado a mano.
		Store::ensure_identity();

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( self::class, 'notice_woocommerce_missing' ) );

			return;
		}

		Installer::maybe_upgrade();

		// Antes de la guarda, a propósito: ver el register() de cada uno.
		Pass::register();
		Health::register();
		Pair::register();
		Auth::register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'wss', Command::class );
		}

		if ( null !== self::suspension_reason() ) {
			add_action( 'admin_notices', array( self::class, 'notice_suspended' ) );

			return;
		}

		// Van después de la guarda a propósito: un plugin suspendido no emite ni acepta
		// movimientos. Falta la reconciliación.
		Detector::register();
		Movements::register();
		Products::register();
		Pass::register_scheduling();
	}

	/**
	 * Por qué está suspendida la sincronización, o null si no lo está.
	 *
	 * Las dos causas apagan lo mismo y se avisan igual, así que se responden juntas. El
	 * orden importa: un clon puede además tener las tablas mal, pero lo que hay que
	 * decirle al dueño es que está mirando una copia.
	 */
	public static function suspension_reason(): ?string {
		if ( Store::is_cloned_site() ) {
			return self::SUSPENDED_CLONE;
		}

		if ( ! Installer::schema_is_healthy() ) {
			return self::SUSPENDED_SCHEMA;
		}

		return null;
	}

	/**
	 * Aviso cuando WooCommerce no está activo.
	 */
	public static function notice_woocommerce_missing(): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__(
				'Woonderful Stock Sync necesita WooCommerce activo para funcionar.',
				'woonderful-stock-sync-for-woocommerce'
			)
		);
	}

	/**
	 * Aviso cuando la sincronización está suspendida.
	 */
	public static function notice_suspended(): void {
		$messages = array(
			self::SUSPENDED_CLONE           => __(
				'Woonderful Stock Sync: este sitio no es aquel donde se emparejó, así que parece una copia. La sincronización está suspendida hasta que vuelvas a emparejarlo.',
				'woonderful-stock-sync-for-woocommerce'
			),
			self::SUSPENDED_SCHEMA          => __(
				'Woonderful Stock Sync: alguna de sus tablas falta o no admite transacciones. La sincronización está suspendida para no dejar el stock mal contado.',
				'woonderful-stock-sync-for-woocommerce'
			),
		);

		$reason = self::suspension_reason();

		if ( null === $reason || ! isset( $messages[ $reason ] ) ) {
			return;
		}

		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $messages[ $reason ] ) );
	}
}
