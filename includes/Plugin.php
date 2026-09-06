<?php
/**
 * Clase principal: arma los componentes y declara sus hooks.
 *
 * @package Woonderful\StockSync
 */

namespace Woonderful\StockSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Punto de entrada. Instanciarla no toca WordPress; los hooks se registran en run(). */
class Plugin {

	private Loader $loader;

	/** Arma los componentes y declara sus hooks. No registra nada. */
	public function __construct() {
		$this->loader = new Loader();

		$this->define_i18n_hooks();
		$this->define_admin_hooks();
	}

	/** Declara los hooks de traducciones. */
	private function define_i18n_hooks(): void {
		$this->loader->add_action( 'init', $this, 'load_textdomain' );
	}

	/** Declara los hooks del admin. */
	private function define_admin_hooks(): void {
		$admin = new Admin();

		$this->loader->add_action( 'admin_menu', $admin, 'register_menu' );

		// En admin_init y no en cada request: una actualización del plugin sin reactivarlo
		// no dispara el hook de activación, y el esquema quedaría atrasado.
		$this->loader->add_action( 'admin_init', $this, 'maybe_upgrade_schema' );
	}

	/** Carga las traducciones. En init: antes, WordPress 6.7+ avisa. */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'woonderful-stock-sync-for-woocommerce',
			false,
			dirname( plugin_basename( WSS_FILE ) ) . '/languages'
		);
	}

	/** Pasa dbDelta si el esquema guardado quedó atrás. */
	public function maybe_upgrade_schema(): void {
		Db\Schema::maybe_upgrade();
	}

	/** Registra en WordPress todo lo declarado. */
	public function run(): void {
		$this->loader->run();
	}
}
