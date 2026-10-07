<?php
/**
 * Desinstalación (ADR-003).
 *
 * El secreto compartido se borra siempre. El resto sólo si el dueño lo pidió: el libro es
 * el historial de stock del negocio y perderlo por desinstalar un rato es irreversible.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wss_prefix = $wpdb->prefix . 'wss_';

// El secreto se va igual: re-emparejar son dos clics, y una credencial viva en un plugin
// desinstalado no la rota ni la mira nadie.
$wss_peers = get_option( 'wss_peers', array() );

if ( is_array( $wss_peers ) ) {
	foreach ( $wss_peers as $wss_key => $wss_peer ) {
		unset( $wss_peer['secret'] );
		$wss_peers[ $wss_key ] = $wss_peer;
	}

	update_option( 'wss_peers', $wss_peers, false );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- no hay API de WordPress para borrar una tabla, y el nombre sale de $wpdb->prefix, no de una entrada.
$wpdb->query( "DROP TABLE IF EXISTS {$wss_prefix}nonces" );

// La copia del catálogo de la otra tampoco es un dato del dueño: se vuelve a traer (ADR-014).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ídem.
$wpdb->query( "DROP TABLE IF EXISTS {$wss_prefix}remote_products" );

// Ni la hora del control de la pasada, ni la del último envío fallido, ni la reconciliación
// —su candado y su último resultado—, ni el estado de la otra tienda o de los avisos, ni un
// código de emparejamiento vivo son datos del dueño (ADR-003).
delete_option( 'wss_pass_checked_at' );
delete_transient( 'wss_send_failed' );
delete_option( 'wss_reconciling' );
delete_option( 'wss_last_reconciliation' );
delete_option( 'wss_peer_state' );
delete_option( 'wss_notify_state' );
delete_option( 'wss_pairing' );
delete_option( 'wss_redeeming' );
delete_option( 'wss_catalog_run' );
delete_option( 'wss_catalog_refreshed_at' );

if ( ! get_option( 'wss_delete_data_on_uninstall', false ) ) {
	return;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ídem.
$wpdb->query( "DROP TABLE IF EXISTS {$wss_prefix}movements" );
$wpdb->query( "DROP TABLE IF EXISTS {$wss_prefix}links" );
$wpdb->query( "DROP TABLE IF EXISTS {$wss_prefix}events" );
// phpcs:enable

foreach ( array(
	'wss_store_id',
	'wss_store_label',
	'wss_site_fingerprint',
	'wss_peers',
	'wss_schema_version',
	'wss_schema_healthy',
	'wss_delete_data_on_uninstall',
	'wss_notify_enabled',
	'wss_notify_email',
	'wss_notify_after_hours',
) as $wss_option ) {
	delete_option( $wss_option );
}
