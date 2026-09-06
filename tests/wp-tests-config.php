<?php
/**
 * Configuración de la suite de tests de WordPress.
 *
 * ⚠️ La base de datos de acá **se borra entera** cada vez que arranca la suite. Por eso
 * DB_NAME es siempre una base dedicada y nunca la del sitio. Ver ADR-002.
 *
 * @package Woonderful\StockSync
 */

/**
 * Lee una variable de entorno con default.
 *
 * @param string $name    Nombre.
 * @param string $default Valor si no está definida.
 */
function wss_test_env( string $name, string $default ): string {
	$value = getenv( $name );

	return ( false === $value || '' === $value ) ? $default : $value;
}

$wss_db_name = wss_test_env( 'WSS_TESTS_DB_NAME', 'wss_tests' );

// Guarda de último recurso: si alguien apunta esto a una base de sitio, no arranca.
if ( in_array( $wss_db_name, array( 'wordpress', 'wp', 'mysql' ), true ) ) {
	fwrite(
		STDERR,
		"\n\033[31m✗ La base de tests no puede ser '$wss_db_name': la suite la borra entera.\n"
		. "  Usá una base dedicada (WSS_TESTS_DB_NAME).\033[0m\n\n"
	);
	exit( 1 );
}

/*
 * Las credenciales salen del entorno del contenedor (WORDPRESS_DB_*), no del repo. Es el
 * mismo usuario que el sitio, pero apuntado a otra base: tiene permiso sobre wss_tests y
 * ninguna contraseña queda versionada.
 */
define( 'DB_NAME', $wss_db_name );
define( 'DB_USER', wss_test_env( 'WSS_TESTS_DB_USER', wss_test_env( 'WORDPRESS_DB_USER', 'wpdev' ) ) );
define( 'DB_PASSWORD', wss_test_env( 'WSS_TESTS_DB_PASSWORD', wss_test_env( 'WORDPRESS_DB_PASSWORD', '' ) ) );
define( 'DB_HOST', wss_test_env( 'WSS_TESTS_DB_HOST', wss_test_env( 'WORDPRESS_DB_HOST', 'mysql' ) ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'ABSPATH', wss_test_env( 'WSS_TESTS_ABSPATH', '/var/www/html/' ) );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Woonderful Stock Sync Tests' );
define( 'WP_PHP_BINARY', 'php' );

define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', true );
