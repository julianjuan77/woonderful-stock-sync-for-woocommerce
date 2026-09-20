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

// El plugin no se autocarga en el entorno de tests: se carga acá, como un mu-plugin.
tests_add_filter(
	'muplugins_loaded',
	static function () use ( $wss_root ): void {
		require $wss_root . '/woonderful-stock-sync-for-woocommerce.php';
	}
);

require $wss_suite . '/includes/bootstrap.php';
