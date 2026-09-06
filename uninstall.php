<?php
/**
 * Borrado de datos al desinstalar.
 *
 * WordPress ejecuta este archivo al eliminar el plugin, no al desactivarlo.
 *
 * @package Woonderful\StockSync
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Lista explícita y no un LIKE sobre wp_options: un LIKE mal escrito borra datos ajenos.
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

// Jobs encolados apuntando a callbacks que ya no existen: Action Scheduler los reintenta
// y llena el log de errores.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'wss_apply_movements' );
	as_unschedule_all_actions( 'wss_reconcile' );
}

/*
 * Si alguien borró vendor/ antes de desinstalar, las tablas quedan huérfanas. Es preferible
 * a un fatal a mitad de camino: WordPress elimina los archivos igual y el plugin quedaría a
 * medio desinstalar.
 */
if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';

	if ( class_exists( \Woonderful\StockSync\Db\Schema::class ) ) {
		\Woonderful\StockSync\Db\Schema::drop_tables();
	}
}
