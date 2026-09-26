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

if ( ! get_option( 'wss_delete_data_on_uninstall', false ) ) {
	return;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ídem.
$wpdb->query( "DROP TABLE IF EXISTS {$wss_prefix}movements" );
$wpdb->query( "DROP TABLE IF EXISTS {$wss_prefix}links" );
// phpcs:enable

foreach ( array(
	'wss_store_id',
	'wss_store_label',
	'wss_site_fingerprint',
	'wss_peers',
	'wss_schema_version',
	'wss_engines_ok',
	'wss_delete_data_on_uninstall',
) as $wss_option ) {
	delete_option( $wss_option );
}
