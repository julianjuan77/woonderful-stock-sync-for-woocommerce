<?php
/**
 * Arranque del plugin.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync;

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Schema\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Punto de entrada: activación y arranque en cada request.
 */
final class Plugin {

	/**
	 * Corre una sola vez, al activar.
	 */
	public static function activate(): void {
		Store::ensure_identity();
		Installer::install();
	}

	/**
	 * Corre en cada request, en plugins_loaded.
	 */
	public static function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( self::class, 'notice_woocommerce_missing' ) );

			return;
		}

		Installer::maybe_upgrade();
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
}
