<?php
/**
 * Borrado de datos al desinstalar.
 *
 * WordPress ejecuta este archivo al eliminar el plugin desde el admin, no al desactivarlo.
 *
 * @package Woonderful\StockSync
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Las opciones se listan una por una en vez de borrarlas con un LIKE 'wss\_%' sobre
// wp_options: un patrón mal escrito puede alcanzar opciones de otros plugins.
$wss_options = array(
	'wss_version',
	'wss_schema_version',
	'wss_store_id',
	'wss_peers',
	'wss_last_reconciliation',
);

foreach ( $wss_options as $wss_option ) {
	delete_option( $wss_option );
	delete_site_option( $wss_option );
}

// Desencola los jobs del plugin. Si quedaran encolados, Action Scheduler seguiría
// intentando llamar callbacks que ya no existen y llenaría su log de errores.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'wss_apply_movements' );
	as_unschedule_all_actions( 'wss_reconcile' );
}

/*
 * Borra las tablas a través de Schema, que es donde vive su definición.
 *
 * Si vendor/ no está —alguien lo borró antes de desinstalar— las tablas quedan huérfanas.
 * Se prefiere eso a un fatal: WordPress elimina los archivos del plugin igual, y un fatal
 * acá dejaría la desinstalación a medio hacer sin forma de retomarla.
 */
if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';

	if ( class_exists( \Woonderful\StockSync\Db\Schema::class ) ) {
		\Woonderful\StockSync\Db\Schema::drop_tables();
	}
}
