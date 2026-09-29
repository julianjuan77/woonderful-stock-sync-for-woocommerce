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
	 * La tienda a la que se le mandan los movimientos, o null si no hay una sola lista.
	 *
	 * El SPEC fija exactamente dos tiendas (§5), así que hay una sola a quien mandarle. Con
	 * ninguna, o con más de una, el emisor no adivina: no manda.
	 *
	 * @return array{store: string, rest_url: string, secret: string}|null
	 */
	public static function only(): ?array {
		$peers = (array) get_option( self::OPTION, array() );

		if ( 1 !== count( $peers ) ) {
			return null;
		}

		$store = (string) array_key_first( $peers );
		$peer  = (array) $peers[ $store ];

		$rest_url = is_string( $peer['rest_url'] ?? null ) ? $peer['rest_url'] : '';
		$secret   = self::secret( $store );

		if ( '' === $rest_url || '' === $secret ) {
			return null;
		}

		return array(
			'store'    => $store,
			'rest_url' => $rest_url,
			'secret'   => $secret,
		);
	}
}
