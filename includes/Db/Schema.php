<?php
/**
 * Definición y ciclo de vida de las tablas propias.
 *
 * @package Woonderful\StockSync
 */

namespace Woonderful\StockSync\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Definición y ciclo de vida de wss_movements y wss_product_state.
 *
 * Qué guarda cada columna y por qué: docs/ARCHITECTURE.md §1.
 */
class Schema {

	public const VERSION = '1';

	public const VERSION_OPTION = 'wss_schema_version';

	/** Nombre completo de la tabla del libro, con el prefijo del sitio. */
	public static function movements_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wss_movements';
	}

	/** Nombre completo de la tabla del snapshot, con el prefijo del sitio. */
	public static function product_state_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wss_product_state';
	}

	/**
	 * Crea las tablas si no existen, o las alinea con la definición de acá si cambiaron.
	 *
	 * Usa dbDelta, que compara contra la tabla real y emite solo los ALTER que falten, así
	 * que se puede llamar cuantas veces haga falta sin perder datos.
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$movements       = self::movements_table();
		$product_state   = self::product_state_table();

		/*
		 * El formato de este SQL no es libre: dbDelta lo parsea con expresiones regulares.
		 * Dos espacios después de PRIMARY KEY, tipos e índices en minúscula, una definición
		 * por línea, y sin backticks. Cualquier desvío hace que dbDelta no reconozca la
		 * columna, crea que la tabla difiere y emita el mismo ALTER en cada carga.
		 *
		 * Por eso `sequence` va sin escapar acá, aunque sea palabra reservada en MariaDB.
		 * En las consultas de MovementRepository sí lleva backticks.
		 */
		$sql_movements = "CREATE TABLE $movements (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	origin_store varchar(32) NOT NULL,
	sequence bigint(20) unsigned NOT NULL,
	sku varchar(100) NOT NULL,
	delta int(11) NOT NULL,
	reason varchar(20) NOT NULL,
	reference varchar(64) DEFAULT NULL,
	created_at datetime NOT NULL,
	received_at datetime NOT NULL,
	status varchar(12) NOT NULL DEFAULT 'pending',
	applied_at datetime DEFAULT NULL,
	claimed_at datetime DEFAULT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY origin_sequence (origin_store,sequence),
	KEY sku_id (sku,id),
	KEY status_claimed (status,claimed_at)
) $charset_collate;";

		$sql_product_state = "CREATE TABLE $product_state (
	sku varchar(100) NOT NULL,
	base_qty int(11) NOT NULL DEFAULT 0,
	base_up_to bigint(20) unsigned NOT NULL DEFAULT 0,
	bootstrapped_at datetime NOT NULL,
	PRIMARY KEY  (sku)
) $charset_collate;";

		dbDelta( $sql_movements );
		dbDelta( $sql_product_state );

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Reaplica el esquema si la versión guardada no es la del código.
	 *
	 * Hace falta porque actualizar un plugin no vuelve a disparar el hook de activación.
	 */
	public static function maybe_upgrade(): void {
		if ( self::installed_version() === self::VERSION ) {
			return;
		}

		self::create_tables();
	}

	/** Versión del esquema aplicada en esta instalación, o '0' si nunca se creó. */
	public static function installed_version(): string {
		return (string) get_option( self::VERSION_OPTION, '0' );
	}

	/** Borra las dos tablas y la opción de versión. La llama uninstall.php, no la desactivación. */
	public static function drop_tables(): void {
		global $wpdb;

		$movements     = self::movements_table();
		$product_state = self::product_state_table();

		$wpdb->query( "DROP TABLE IF EXISTS $movements" );
		$wpdb->query( "DROP TABLE IF EXISTS $product_state" );

		delete_option( self::VERSION_OPTION );
	}
}
