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

/** Menú y pantallas del plugin. La pantalla de estado real llega con UC-05. */
class Admin {

	/** Se pide manage_woocommerce y no manage_options: quien administra la tienda no siempre es admin del sitio. */
	private const CAPABILITY = 'manage_woocommerce';

	private const MENU_SLUG = 'wss-status';

	/** Cuelga la pantalla de estado del menú de WooCommerce. */
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

	/** Renderiza la pantalla de estado. */
	public function render_status_page(): void {
		// Repetido a propósito: add_submenu_page() oculta el item del menú, pero no
		// impide entrar por la URL directa.
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
