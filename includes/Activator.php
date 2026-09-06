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

/** Lo que corre una sola vez, al activar el plugin. */
class Activator {

	/** Crea las tablas. Reactivar no pierde datos: dbDelta es idempotente. */
	public static function activate(): void {
		Schema::create_tables();
	}
}
