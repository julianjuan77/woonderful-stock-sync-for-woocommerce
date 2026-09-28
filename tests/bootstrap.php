<?php
/**
 * Bootstrap de los tests.
 *
 * Usa la suite de tests de WordPress (WP_UnitTestCase, factories, rollback por test),
 * traída por Composer en vez de por el script de SVN: en un contenedor es una
 * dependencia más y no algo que descargar en cada build.
 *
 * Corre contra la base del sitio con el prefijo `wptests_`, que la suite borra y recrea
 * entera en cada arranque. Las tablas del sitio, con su propio prefijo, no se tocan.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

$wss_root = dirname( __DIR__ );

require_once $wss_root . '/vendor/autoload.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
require_once __DIR__ . '/wp-tests-config.php';

$wss_suite = getenv( 'WP_PHPUNIT__DIR' ) ?: $wss_root . '/vendor/wp-phpunit/wp-phpunit';

require_once $wss_suite . '/includes/functions.php';

// WooCommerce, de verdad y no un stub: el plugin le cambia el stock a productos reales y
// eso es lo único que promete. Se carga como mu-plugin, antes que el nuestro, para que
// `class_exists( 'WooCommerce' )` sea cierto cuando corra `boot()`.
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require_once WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
	}
);

// El plugin no se autocarga en el entorno de tests: se carga acá, como un mu-plugin.
tests_add_filter(
	'muplugins_loaded',
	static function () use ( $wss_root ): void {
		require $wss_root . '/woonderful-stock-sync-for-woocommerce.php';
	}
);

// WooCommerce necesita sus propias tablas en el prefijo de test. Su instalador las crea.
tests_add_filter(
	'setup_theme',
	static function (): void {
		WC_Install::install();

		// Los roles se cachean antes de que WooCommerce agregue los suyos.
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		wp_roles();
	}
);

require $wss_suite . '/includes/bootstrap.php';

/**
 * Desengancha el detector.
 *
 * `boot()` lo engancha, y enganchado se queda para los tests que siguen: consume el guard
 * antes que los espías del libro y escribe movimientos que esos tests no esperan.
 */
function wss_unhook_detector(): void {
	foreach ( array( 'product', 'variation' ) as $type ) {
		remove_action( "woocommerce_{$type}_before_set_stock", array( \Woonderful\StockSync\Detector\Detector::class, 'before_change' ) );
		remove_action( "woocommerce_{$type}_set_stock", array( \Woonderful\StockSync\Detector\Detector::class, 'after_change' ) );
	}

	remove_action( 'woocommerce_reduce_order_item_stock', array( \Woonderful\StockSync\Detector\Detector::class, 'stamp_sale' ), 10 );
	remove_action( 'woocommerce_restore_order_item_stock', array( \Woonderful\StockSync\Detector\Detector::class, 'stamp_restore' ), 10 );
	remove_action( 'woocommerce_restock_refunded_item', array( \Woonderful\StockSync\Detector\Detector::class, 'stamp_refund' ), 10 );
}
