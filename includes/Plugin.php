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

/**
 * Arma los componentes del plugin y declara sus hooks.
 *
 * El constructor no registra nada en WordPress: los hooks quedan encolados en el Loader y
 * se aplican recién al llamar run().
 */
class Plugin {

	private Loader $loader;

	public function __construct() {
		$this->loader = new Loader();

		$this->define_i18n_hooks();
		$this->define_admin_hooks();
	}

	private function define_i18n_hooks(): void {
		$this->loader->add_action( 'init', $this, 'load_textdomain' );
	}

	private function define_admin_hooks(): void {
		$admin = new Admin();

		$this->loader->add_action( 'admin_menu', $admin, 'register_menu' );

		// En admin_init y no en un hook del frontend: la comprobación consulta una opción
		// en cada request y solo hace falta cuando alguien entra al admin.
		$this->loader->add_action( 'admin_init', $this, 'maybe_upgrade_schema' );
	}

	/**
	 * Carga el archivo de traducciones del plugin.
	 *
	 * Colgado de init y no antes: desde WordPress 6.7, llamar a load_plugin_textdomain()
	 * más temprano dispara un aviso de _doing_it_wrong().
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'woonderful-stock-sync-for-woocommerce',
			false,
			dirname( plugin_basename( WSS_FILE ) ) . '/languages'
		);
	}

	/** Actualiza las tablas si la versión del esquema guardada quedó atrás. */
	public function maybe_upgrade_schema(): void {
		Db\Schema::maybe_upgrade();
	}

	/** Registra en WordPress los hooks de todos los componentes. */
	public function run(): void {
		$this->loader->run();
	}
}
