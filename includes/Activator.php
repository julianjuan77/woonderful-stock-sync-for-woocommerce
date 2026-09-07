<?php
/**
 * Activación del plugin.
 *
 * @package Woonderful\StockSync
 */

namespace Woonderful\StockSync;

use Woonderful\StockSync\Db\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Callback de register_activation_hook: corre al activar el plugin. */
class Activator {

	/** Crea las tablas del plugin. Reactivar no borra las filas existentes. */
	public static function activate(): void {
		Schema::create_tables();
	}
}
