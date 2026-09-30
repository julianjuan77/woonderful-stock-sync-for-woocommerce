<?php
/**
 * Las tiendas emparejadas.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Transport;

defined( 'ABSPATH' ) || exit;

/**
 * Lectura de `wss_peers`: por tienda remota, la base de su API (`rest_url`, como
 * `https://otra.com/wp-json/wss/v1`), su secreto y hasta dónde se purgó.
 *
 * Lo escribe el emparejamiento (tarea 5.7), con el UUID de la otra tienda como clave.
 */
final class Peers {

	/**
	 * La opción, con una entrada por tienda remota.
	 */
	public const OPTION = 'wss_peers';

	/**
	 * El secreto compartido con una tienda, o '' si no está emparejada.
	 *
	 * @param string $store Identificador de la otra tienda.
	 */
	public static function secret( string $store ): string {
		$peers = (array) get_option( self::OPTION, array() );
		$peer  = (array) ( $peers[ $store ] ?? array() );

		return is_string( $peer['secret'] ?? null ) ? $peer['secret'] : '';
	}

	/**
	 * Guarda la tienda con la que se acaba de emparejar.
	 *
	 * **Borra la `rest_url` y el secreto de cualquier otra entrada**: en v1 hay una sola
	 * socia (ADR-001 §1). El `purged_through` de cada una se conserva: lo usa la purga.
	 *
	 * @param string $store    Identificador de la otra tienda.
	 * @param string $rest_url La base de su API.
	 * @param string $secret   El secreto compartido.
	 * @param string $label    Su etiqueta.
	 */
	public static function pair( string $store, string $rest_url, string $secret, string $label ): void {
		$peers = array();

		foreach ( (array) get_option( self::OPTION, array() ) as $key => $peer ) {
			$peer = (array) $peer;
			unset( $peer['rest_url'], $peer['secret'] );
			$peers[ (string) $key ] = $peer;
		}

		$peers[ $store ] = array_merge(
			$peers[ $store ] ?? array(),
			array(
				'rest_url'  => $rest_url,
				'secret'    => $secret,
				'label'     => $label,
				'paired_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		update_option( self::OPTION, $peers, false );
	}

	/**
	 * La tienda a la que se le mandan los movimientos, o null si no hay una sola lista.
	 *
	 * El SPEC fija exactamente dos tiendas (§5), así que hay una sola a quien mandarle. Con
	 * ninguna, o con más de una, el emisor no adivina: no manda.
	 *
	 * Cuentan sólo las entradas con `rest_url` y secreto. La purga anota hasta dónde purgó
	 * de cada tienda de origen, y una tienda que se volvió a emparejar con otro UUID deja
	 * una entrada vieja sin URL: contada, cortaría la entrega para siempre.
	 *
	 * @return array{store: string, rest_url: string, secret: string}|null
	 */
	public static function only(): ?array {
		$paired = array();

		foreach ( (array) get_option( self::OPTION, array() ) as $store => $peer ) {
			$peer     = (array) $peer;
			$rest_url = is_string( $peer['rest_url'] ?? null ) ? $peer['rest_url'] : '';
			$secret   = is_string( $peer['secret'] ?? null ) ? $peer['secret'] : '';

			if ( '' !== $rest_url && '' !== $secret ) {
				$paired[] = array(
					'store'    => (string) $store,
					'rest_url' => $rest_url,
					'secret'   => $secret,
				);
			}
		}

		return 1 === count( $paired ) ? $paired[0] : null;
	}
}
