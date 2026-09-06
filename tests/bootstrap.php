<?php
/**
 * Bootstrap de los tests de integración.
 *
 * Carga WordPress de verdad, con MySQL detrás. Ver ADR-002: las invariantes del plugin las
 * hace cumplir la base, así que un $wpdb simulado no las puede probar.
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

// El plugin tiene que cargarse dentro del WordPress de tests, no antes: en este punto
// todavía no existen ni $wpdb ni las funciones de WordPress.
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/woonderful-stock-sync-for-woocommerce.php';
	}
);

require $wss_tests_dir . '/includes/bootstrap.php';

/*
 * Las tablas se crean una vez, acá, y no en cada test: dbDelta emite DDL, y en MySQL el DDL
 * hace commit implícito, lo que rompería la transacción con la que WP_UnitTestCase revierte
 * cada test. Los INSERT/UPDATE de los tests sí quedan dentro de esa transacción.
 */
\Woonderful\StockSync\Db\Schema::create_tables();
