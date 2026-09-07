<?php
/**
 * Bootstrap de los tests de integración.
 *
 * Carga un WordPress real con MySQL detrás, usando la suite oficial de tests de WordPress
 * (paquete wp-phpunit). La base de datos y las credenciales salen de wp-tests-config.php.
 *
 * Por qué integración y no unitarios: ver docs/DECISIONS.md, ADR-002.
 *
 * @package Woonderful\StockSync
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

$wss_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';

if ( ! is_readable( $wss_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "\n✗ Falta la suite de tests de WordPress. Ejecutá: composer install\n\n" );
	exit( 1 );
}

require_once $wss_tests_dir . '/includes/functions.php';

// Carga el plugin dentro del WordPress de tests. En muplugins_loaded y no acá directamente
// porque en este punto del archivo todavía no existen $wpdb ni las funciones de WordPress,
// y el plugin las usa apenas se incluye.
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/woonderful-stock-sync-for-woocommerce.php';
	}
);

require $wss_tests_dir . '/includes/bootstrap.php';

/*
 * Crea las tablas del plugin una sola vez, antes de que corra ningún test.
 *
 * No puede ir en el set_up() de cada test: create_tables() usa dbDelta, que emite DDL
 * (CREATE/ALTER TABLE), y en MySQL el DDL hace commit implícito. Eso cerraría la transacción
 * con la que WP_UnitTestCase revierte cada test, y los datos de un test se filtrarían al
 * siguiente.
 */
\Woonderful\StockSync\Db\Schema::create_tables();
