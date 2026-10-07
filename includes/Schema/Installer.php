<?php
/**
 * Creación y migración de las tablas.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Las cuatro tablas del plugin y su versión de esquema (ADR-004, ADR-011).
 */
final class Installer {

	/**
	 * La versión del esquema. La 2 suma las cuatro columnas de la discrepancia a `wss_links`
	 * (ADR-010 §4); la 3, la tabla `wss_events` y `broken_since` (ADR-011); la 4, la copia del
	 * catálogo de la otra, `wss_remote_products` (ADR-014). `dbDelta` agrega sin tocar las
	 * filas.
	 */
	public const SCHEMA_VERSION = 4;

	public const OPTION_VERSION = 'wss_schema_version';

	/**
	 * Si las cuatro tablas están sanas. Se autocarga: la guarda de arranque la lee en cada
	 * request.
	 */
	public const OPTION_SCHEMA_HEALTHY = 'wss_schema_healthy';

	private const TABLES = array( 'movements', 'links', 'nonces', 'events' );

	/**
	 * La copia del catálogo de la otra tienda (ADR-014 §1). Se crea con las demás, pero no
	 * entra en la salud del esquema: es una caché de la pantalla, y sin ella el stock se
	 * sincroniza igual.
	 */
	public const REMOTE_PRODUCTS = 'remote_products';

	/**
	 * Nombre completo de una de las tablas del plugin.
	 *
	 * @param string $name Nombre corto: movements, links, nonces, events o remote_products.
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'wss_' . $name;
	}

	/**
	 * Crea o actualiza las tablas y deja registrada la versión.
	 */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		foreach ( self::definitions( $charset_collate ) as $sql ) {
			dbDelta( $sql );
		}

		// Autocargada: maybe_upgrade() la lee en cada request, así que sin autoload sería
		// una consulta por página para un número que cambia cada varios meses.
		update_option( self::OPTION_VERSION, self::SCHEMA_VERSION, true );

		self::refresh_schema_status();
	}

	/**
	 * Vuelve a revisar las cuatro tablas y guarda el resultado.
	 *
	 * La consulta a `information_schema` es cara y esto no cambia solo, así que se hace
	 * al instalar y al migrar, no en cada carga de página (ADR-004 §5).
	 */
	public static function refresh_schema_status(): bool {
		$healthy = array() === self::unhealthy_tables();

		// 'yes'/'no' y no un booleano: `update_option()` compara el valor nuevo contra lo
		// que devuelve `get_option()` de una opción que no existe, que es `false`. Con
		// `false` como valor concluye que no hay nada que cambiar y **no escribe**, así
		// que el resultado malo no se guardaba nunca y la consulta cara se repetía en
		// cada request.
		update_option( self::OPTION_SCHEMA_HEALTHY, $healthy ? 'yes' : 'no', true );

		return $healthy;
	}

	/**
	 * Si las tablas están sanas, según el último control.
	 */
	public static function schema_is_healthy(): bool {
		$stored = (string) get_option( self::OPTION_SCHEMA_HEALTHY, '' );

		// Sin dato guardado —una instalación anterior a este control— se mira una vez.
		if ( '' === $stored ) {
			return self::refresh_schema_status();
		}

		return 'yes' === $stored;
	}

	/**
	 * Aplica el esquema si el guardado quedó atrás. Corre en cada request y no hace
	 * nada en el caso normal.
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::OPTION_VERSION, 0 ) >= self::SCHEMA_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Las tablas con las que no se puede trabajar, si hay alguna.
	 *
	 * Son dos problemas distintos con la misma consecuencia —el plugin no puede
	 * sincronizar— y por eso se responden juntos:
	 *
	 * - **Falta la tabla.** `dbDelta` puede no haberla creado: sin permiso `CREATE`, por
	 *   ejemplo. Una tabla que no existe no aparece en `information_schema`, así que hay
	 *   que buscarla por ausencia y no entre las filas que la consulta devuelve.
	 * - **No es InnoDB.** `dbDelta` las crea con `ENGINE=InnoDB` pero no convierte una que
	 *   ya exista con otro motor, y en MyISAM las transacciones se ignoran en silencio: la
	 *   numeración de un movimiento y la purga que consolida el stock base dejarían los
	 *   números mal.
	 *
	 * @return string[] Nombres completos de tabla.
	 */
	public static function unhealthy_tables(): array {
		global $wpdb;

		$names = array_map( array( self::class, 'table' ), self::TABLES );

		$placeholders = implode( ', ', array_fill( 0, count( $names ), '%s' ) );

		// information_schema no tiene API de WordPress, y lo único interpolado en la
		// consulta son los propios %s, que salen de contar las tablas: no hay dato de
		// entrada en juego.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS name, ENGINE AS engine FROM information_schema.TABLES
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ( ' . $placeholders . ' )',
				$names
			)
		);
		// phpcs:enable

		$engines = array();

		foreach ( (array) $rows as $row ) {
			$engines[ (string) $row->name ] = (string) $row->engine;
		}

		$problems = array();

		foreach ( $names as $name ) {
			// Sin fila en information_schema, la tabla no existe.
			if ( ! isset( $engines[ $name ] ) || 0 !== strcasecmp( $engines[ $name ], 'InnoDB' ) ) {
				$problems[] = $name;
			}
		}

		return $problems;
	}

	/**
	 * El SQL de las cuatro tablas.
	 *
	 * @param string $charset_collate Charset y collation del sitio.
	 *
	 * @return string[]
	 */
	private static function definitions( string $charset_collate ): array {
		$movements = self::table( 'movements' );
		$links     = self::table( 'links' );
		$nonces    = self::table( 'nonces' );
		$events    = self::table( 'events' );
		$remote    = self::table( self::REMOTE_PRODUCTS );

		return array(
			// origin_seq admite NULL a propósito: la secuencia de un movimiento propio es
			// su AUTO_INCREMENT, que no existe hasta después del INSERT. En un índice
			// UNIQUE, NULL no choca con NULL; dos ceros sí (ADR-004 §1).
			"CREATE TABLE {$movements} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				origin_store char(36) NOT NULL,
				origin_seq bigint(20) unsigned DEFAULT NULL,
				generation int(10) unsigned NOT NULL DEFAULT 0,
				delta int(11) NOT NULL,
				reason varchar(20) NOT NULL,
				reference varchar(100) DEFAULT NULL,
				created_at datetime NOT NULL,
				link_id bigint(20) unsigned DEFAULT NULL,
				origin_product bigint(20) unsigned NOT NULL,
				delivery varchar(12) DEFAULT NULL,
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				first_attempt_at datetime DEFAULT NULL,
				next_attempt_at datetime DEFAULT NULL,
				last_error varchar(255) DEFAULT NULL,
				claimed_by char(36) DEFAULT NULL,
				claimed_at datetime DEFAULT NULL,
				confirmed_at datetime DEFAULT NULL,
				application varchar(12) DEFAULT NULL,
				applied_at datetime DEFAULT NULL,
				details text DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY dedup (origin_store,origin_seq),
				KEY delivery_queue (delivery,next_attempt_at),
				KEY by_link (link_id,generation)
			) ENGINE=InnoDB {$charset_collate};",

			"CREATE TABLE {$links} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				local_product bigint(20) unsigned NOT NULL,
				remote_store char(36) NOT NULL,
				remote_product bigint(20) unsigned NOT NULL,
				generation int(10) unsigned NOT NULL DEFAULT 1,
				base_stock int(11) NOT NULL,
				base_set_at datetime NOT NULL,
				expected_stock int(11) DEFAULT NULL,
				found_stock int(11) DEFAULT NULL,
				discrepancy_since datetime DEFAULT NULL,
				discrepancy_seen_at datetime DEFAULT NULL,
				broken_since datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY local (local_product,remote_store),
				KEY remote (remote_store,remote_product)
			) ENGINE=InnoDB {$charset_collate};",

			// Acá el índice por fecha sí se justifica: esta purga corre en cada pasada,
			// no una vez por noche como la del libro (ADR-004 §3).
			"CREATE TABLE {$nonces} (
				nonce char(64) NOT NULL,
				seen_at datetime NOT NULL,
				PRIMARY KEY  (nonce),
				KEY seen_at (seen_at)
			) ENGINE=InnoDB {$charset_collate};",

			// Lo que pasa y no es un movimiento: los movimientos ya están en el libro (ADR-011).
			"CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				level varchar(10) NOT NULL,
				kind varchar(40) NOT NULL,
				product bigint(20) unsigned DEFAULT NULL,
				user_id bigint(20) unsigned DEFAULT NULL,
				cause varchar(40) DEFAULT NULL,
				context text DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY by_product (product,created_at),
				KEY by_date (created_at)
			) ENGINE=InnoDB {$charset_collate};",

			// Sin la tienda de origen: es la de la otra única, y re-emparejar la vacía.
			"CREATE TABLE {$remote} (
				remote_product bigint(20) unsigned NOT NULL,
				parent bigint(20) unsigned NOT NULL DEFAULT 0,
				type varchar(32) NOT NULL DEFAULT '',
				sku varchar(100) NOT NULL DEFAULT '',
				name varchar(255) NOT NULL DEFAULT '',
				stock int(11) DEFAULT NULL,
				status varchar(20) NOT NULL DEFAULT '',
				linked tinyint(1) NOT NULL DEFAULT 0,
				seen_at datetime NOT NULL,
				PRIMARY KEY  (remote_product),
				KEY parent (parent),
				KEY sku (sku)
			) ENGINE=InnoDB {$charset_collate};",
		);
	}
}
