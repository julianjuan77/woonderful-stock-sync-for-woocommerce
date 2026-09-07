<?php
/**
 * Pantallas de administración.
 *
 * @package Woonderful\StockSync
 */

namespace Woonderful\StockSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menú y pantallas del plugin dentro del admin.
 *
 * La pantalla de estado todavía no tiene contenido: solo está reservado su lugar en el
 * menú.
 */
class Admin {

	/** Capability requerida para ver cualquier pantalla del plugin. */
	private const CAPABILITY = 'manage_woocommerce';

	private const MENU_SLUG = 'wss-status';

	/** Registra la pantalla de estado como submenú de WooCommerce. */
	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Stock sync', 'woonderful-stock-sync-for-woocommerce' ),
			__( 'Stock sync', 'woonderful-stock-sync-for-woocommerce' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_status_page' )
		);
	}

	/** Imprime la pantalla de estado. */
	public function render_status_page(): void {
		// Se vuelve a comprobar acá porque add_submenu_page() solo oculta el item del menú:
		// la URL de la pantalla sigue siendo accesible directamente.
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this screen.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		printf(
			'<div class="wrap"><h1>%s</h1><p>%s</p></div>',
			esc_html__( 'Stock sync', 'woonderful-stock-sync-for-woocommerce' ),
			esc_html__( 'The status screen is not implemented yet.', 'woonderful-stock-sync-for-woocommerce' )
		);
	}
}
