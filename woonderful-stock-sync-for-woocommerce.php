<?php
/**
 * Plugin Name:       Woonderful Stock Sync for WooCommerce
 * Plugin URI:        https://github.com/julianjuan77/woonderful-stock-sync-for-woocommerce
 * Description:       Sincroniza el stock entre dos tiendas WooCommerce que despachan del mismo depósito. Pool único, sin reparto.
 * Version:           1.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.1
 * Author:            Julián Juan
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woonderful-stock-sync-for-woocommerce
 * Domain Path:       /languages
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync;

defined( 'ABSPATH' ) || exit;

const VERSION = '1.1.0';

define( 'WSS_PLUGIN_FILE', __FILE__ );
define( 'WSS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// El zip de distribución trae vendor/; un checkout de git, no.
if ( ! is_readable( WSS_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__(
					'Woonderful Stock Sync: faltan las dependencias. Ejecutá «composer install» en la carpeta del plugin.',
					'woonderful-stock-sync-for-woocommerce'
				)
			);
		}
	);

	return;
}

require_once WSS_PLUGIN_DIR . 'vendor/autoload.php';

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );

// WooCommerce exige declarar la compatibilidad con HPOS antes de su propio init.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				WSS_PLUGIN_FILE,
				true
			);
		}
	}
);
