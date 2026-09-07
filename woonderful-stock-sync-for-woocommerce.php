<?php
/**
 * Plugin Name:       Woonderful Stock Sync for WooCommerce
 * Plugin URI:        https://woonderful.agency/plugins/woonderful-stock-sync-for-woocommerce
 * Description:       Sincroniza el stock de un mismo depósito entre dos o más tiendas WooCommerce propias. Pool único, sin reparto: todas las tiendas publican el stock real completo.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Julian Juan
 * Author URI:        https://woonderful.agency
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woonderful-stock-sync-for-woocommerce
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   9.4
 *
 * @package Woonderful\StockSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WSS_VERSION', '0.1.0' );
define( 'WSS_FILE', __FILE__ );
define( 'WSS_DIR', plugin_dir_path( __FILE__ ) );
define( 'WSS_URL', plugin_dir_url( __FILE__ ) );

// Sin el autoloader de Composer ninguna clase del plugin se puede cargar. Se corta acá
// con un aviso en vez de dejar que reviente con un fatal en la primera llamada.
if ( ! is_readable( WSS_DIR . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__(
					'Woonderful Stock Sync: the Composer autoloader is missing. Run "composer install" in the plugin directory.',
					'woonderful-stock-sync-for-woocommerce'
				)
			);
		}
	);

	return;
}

require_once WSS_DIR . 'vendor/autoload.php';

// register_activation_hook() tiene que ejecutarse en cada carga del plugin, no solo al
// activar: es así como WordPress guarda a quién llamar cuando alguien lo active.
register_activation_hook( WSS_FILE, array( \Woonderful\StockSync\Activator::class, 'activate' ) );

/*
 * Declara compatibilidad con HPOS (los pedidos en tablas propias de WooCommerce en vez de
 * wp_posts). Es válido porque el plugin no lee ni escribe pedidos: se entera de las ventas
 * por los hooks de stock.
 *
 * Sin esta declaración WooCommerce marca el plugin como incompatible y desactiva HPOS en
 * el sitio.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				WSS_FILE,
				true
			);
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		// Sin WooCommerce el plugin no tiene stock que sincronizar. Avisa y no registra
		// ningún hook, pero no se autodesactiva: si lo hiciera, el aviso desaparecería con
		// él y no quedaría rastro de por qué dejó de funcionar.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__(
							'Woonderful Stock Sync requires WooCommerce to be active.',
							'woonderful-stock-sync-for-woocommerce'
						)
					);
				}
			);

			return;
		}

		( new \Woonderful\StockSync\Plugin() )->run();
	}
);
