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
 * Las tres tablas del plugin y su versión de esquema (ADR-004).
 */
final class Installer {

	public const SCHEMA_VERSION = 1;

	public const OPTION_VERSION = 'wss_schema_version';

	private const TABLES = array( 'movements', 'links', 'nonces' );

	/**
	 * Nombre completo de una de las tablas del plugin.
	 *
	 * @param string $name Nombre corto: movements, links o nonces.
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

		update_option( self::OPTION_VERSION, self::SCHEMA_VERSION, false );
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
	 * Las tablas que no son InnoDB, si hay alguna.
	 *
	 * Las crea `dbDelta` con ENGINE=InnoDB, pero no convierte una que ya exista con otro
	 * motor, y en MyISAM las transacciones se ignoran en silencio: la numeración de un
	 * movimiento y la purga que consolida el stock base dejarían los números mal.
	 *
	 * @return string[] Nombres completos de tabla.
	 */
	public static function tables_without_transactions(): array {
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

		$problems = array();

		foreach ( (array) $rows as $row ) {
			if ( 0 !== strcasecmp( (string) $row->engine, 'InnoDB' ) ) {
				$problems[] = (string) $row->name;
			}
		}

		return $problems;
	}

	/**
	 * El SQL de las tres tablas.
	 *
	 * @param string $charset_collate Charset y collation del sitio.
	 *
	 * @return string[]
	 */
	private static function definitions( string $charset_collate ): array {
		$movements = self::table( 'movements' );
		$links     = self::table( 'links' );
		$nonces    = self::table( 'nonces' );

		return array(
			// origin_seq admite NULL a propósito: la secuencia de un movimiento propio es
			// su AUTO_INCREMENT, que no existe hasta después del INSERT. En un índice
			// UNIQUE, NULL no choca con NULL; dos ceros sí (ADR-004 §1).
			"CREATE TABLE {$movements} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				origin_store char(32) NOT NULL,
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
				claimed_by char(32) DEFAULT NULL,
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
				remote_store char(32) NOT NULL,
				remote_product bigint(20) unsigned NOT NULL,
				generation int(10) unsigned NOT NULL DEFAULT 1,
				base_stock int(11) NOT NULL,
				base_set_at datetime NOT NULL,
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
		);
	}
}
