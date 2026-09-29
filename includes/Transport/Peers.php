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
 * Lectura de `wss_peers`: por tienda remota, su URL, su secreto y hasta dónde se purgó.
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
}
