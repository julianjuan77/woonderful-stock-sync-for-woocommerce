<?php
/**
 * La página del plugin en el admin.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * El menú y la página que monta la app, como en woonderful-sync (ADR-013 §1).
 */
final class Page {

	/**
	 * El slug de la página.
	 */
	public const SLUG = 'woonderful-stock-sync';

	/**
	 * El permiso: el mismo que woonderful-sync y que pide UC-05.
	 */
	public const CAPABILITY = 'manage_woocommerce';

	/**
	 * Engancha el menú y los assets. Sólo en el admin.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * El menú propio, con el ícono de Woonderful, y un submenú por pantalla.
	 */
	public static function menu(): void {
		$icon_path = WSS_PLUGIN_DIR . 'assets/brand/woonderful-icon-menu.svg';
		$icon      = file_exists( $icon_path )
			? 'data:image/svg+xml;base64,' . base64_encode( (string) file_get_contents( $icon_path ) ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			: 'dashicons-update';

		add_menu_page(
			__( 'Woonderful Stock Sync', 'woonderful-stock-sync-for-woocommerce' ),
			__( 'Stock Sync', 'woonderful-stock-sync-for-woocommerce' ),
			self::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' ),
			$icon,
			57
		);

		$subpages = array(
			''            => __( 'Resumen', 'woonderful-stock-sync-for-woocommerce' ),
			'#/products'  => __( 'Productos', 'woonderful-stock-sync-for-woocommerce' ),
			'#/logs'      => __( 'Registros', 'woonderful-stock-sync-for-woocommerce' ),
			'#/settings'  => __( 'Configuración', 'woonderful-stock-sync-for-woocommerce' ),
		);

		foreach ( $subpages as $route => $title ) {
			add_submenu_page( self::SLUG, $title, $title, self::CAPABILITY, self::SLUG . $route, array( self::class, 'render' ) );
		}
	}

	/**
	 * Lo que la app reemplaza al montarse: la misma sidebar vacía, para que no salte la página.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tenés permisos para acceder a esta página.', 'woonderful-stock-sync-for-woocommerce' ) );
		}

		?>
		<div id="wss-admin-app">
			<div class="woon-root">
				<aside class="woon-sidebar"><div class="woon-sidebar__logo"></div></aside>
				<main class="woon-main">
					<div class="woon-page">
						<div class="woon-loading"><?php esc_html_e( 'Cargando Woonderful Stock Sync…', 'woonderful-stock-sync-for-woocommerce' ); ?></div>
					</div>
				</main>
			</div>
		</div>
		<?php
	}

	/**
	 * Encola la app, sólo en las páginas del plugin.
	 *
	 * @param string $hook_suffix La página actual del admin.
	 */
	public static function enqueue( $hook_suffix ): void {
		if ( ! is_string( $hook_suffix ) || false === strpos( $hook_suffix, self::SLUG ) ) {
			return;
		}

		wp_add_inline_style(
			'wp-admin',
			'#wss-admin-app { margin: 0 !important; } #wpbody-content > .wrap:has(#wss-admin-app) { margin: 0 !important; padding: 0 !important; }'
		);

		$asset_file = WSS_PLUGIN_DIR . 'build/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: array(
				'dependencies' => array(),
				'version'      => \Woonderful\StockSync\VERSION,
			);

		wp_enqueue_script( 'wss-admin', plugins_url( 'build/index.js', WSS_PLUGIN_FILE ), $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( 'wss-admin', plugins_url( 'build/style-index.css', WSS_PLUGIN_FILE ), array( 'wp-components' ), $asset['version'] );
		wp_set_script_translations( 'wss-admin', 'woonderful-stock-sync-for-woocommerce' );

		wp_localize_script(
			'wss-admin',
			'wssAdmin',
			array(
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'version' => \Woonderful\StockSync\VERSION,
			)
		);
	}
}
