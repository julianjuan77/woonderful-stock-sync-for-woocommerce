<?php
/**
 * Configuración de la suite de tests de WordPress.
 *
 * ⚠️ La suite ejecuta DROP DATABASE sobre DB_NAME cada vez que arranca, para instalar un
 * WordPress limpio. DB_NAME tiene que ser SIEMPRE una base dedicada a tests: apuntarla a la
 * base de un sitio destruye ese sitio.
 *
 * @package Woonderful\StockSync
 */

/** Devuelve la variable de entorno $name, o $default si no está definida o está vacía. */
function wss_test_env( string $name, string $default ): string {
	$value = getenv( $name );

	return ( false === $value || '' === $value ) ? $default : $value;
}

$wss_db_name = wss_test_env( 'WSS_TESTS_DB_NAME', 'wss_tests' );

// Aborta si la base de tests tiene el nombre de una base de sitio. No cubre todos los
// nombres posibles: es una red contra el error distraído, no una validación completa.
if ( in_array( $wss_db_name, array( 'wordpress', 'wp', 'mysql' ), true ) ) {
	fwrite(
		STDERR,
		"\n\033[31m✗ La base de tests no puede ser '$wss_db_name': la suite la borra entera.\n"
		. "  Usá una base dedicada (WSS_TESTS_DB_NAME).\033[0m\n\n"
	);
	exit( 1 );
}

/*
 * Orden de precedencia de cada credencial: variable WSS_TESTS_* > variable WORDPRESS_DB_* >
 * default. Las WORDPRESS_DB_* las define el contenedor del sitio, así que dentro de él los
 * tests funcionan sin configurar nada y sin ninguna contraseña escrita en el repo.
 *
 * Es el mismo usuario de MySQL que usa el sitio; lo que cambia es la base a la que apunta.
 * Ese usuario necesita permisos sobre la base de tests (GRANT ALL ON wss_tests.*).
 */
define( 'DB_NAME', $wss_db_name );
define( 'DB_USER', wss_test_env( 'WSS_TESTS_DB_USER', wss_test_env( 'WORDPRESS_DB_USER', 'wpdev' ) ) );
define( 'DB_PASSWORD', wss_test_env( 'WSS_TESTS_DB_PASSWORD', wss_test_env( 'WORDPRESS_DB_PASSWORD', '' ) ) );
define( 'DB_HOST', wss_test_env( 'WSS_TESTS_DB_HOST', wss_test_env( 'WORDPRESS_DB_HOST', 'mysql' ) ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

// Prefijo de las tablas que crea la suite. Distinto del wp_ de los sitios, para que sea
// evidente en un SHOW TABLES cuáles son de tests.
$table_prefix = 'wptests_';

// Raíz del WordPress que la suite carga. El default es la ruta dentro del contenedor del
// sitio, que es donde se corren los tests.
define( 'ABSPATH', wss_test_env( 'WSS_TESTS_ABSPATH', '/var/www/html/' ) );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Woonderful Stock Sync Tests' );
define( 'WP_PHP_BINARY', 'php' );

define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', true );
