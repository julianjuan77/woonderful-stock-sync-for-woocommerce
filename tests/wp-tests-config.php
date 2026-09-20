<?php
/**
 * Configuración de la suite de tests de WordPress.
 *
 * Los datos salen del entorno del contenedor. Los tests corren contra la MISMA base del
 * sitio pero con otro prefijo de tablas: la suite de WordPress borra y recrea las tablas
 * de su prefijo y no toca las demás, y el usuario de la base del contenedor no tiene
 * permiso para crear una base nueva.
 *
 * @package Woonderful\StockSync
 */

define( 'DB_NAME', getenv( 'WORDPRESS_DB_NAME' ) ?: 'wordpress' );
define( 'DB_USER', getenv( 'WORDPRESS_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WORDPRESS_DB_PASSWORD' ) ?: '' );
define( 'DB_HOST', getenv( 'WORDPRESS_DB_HOST' ) ?: 'mysql' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'ABSPATH', getenv( 'WSS_TEST_ABSPATH' ) ?: '/var/www/html/' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Tests de Woonderful Stock Sync' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WP_DEBUG', true );

$table_prefix = 'wptests_';
