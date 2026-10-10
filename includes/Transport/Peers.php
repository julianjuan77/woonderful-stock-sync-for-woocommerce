<?php
/**
 * Las tiendas emparejadas.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Transport;

use Woonderful\StockSync\Catalog\Copy;
use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Log\Events;
use Woonderful\StockSync\Log\Peer;

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
	 * La otra tienda emitió el código: es la maestra.
	 */
	public const MASTER = 'master';

	/**
	 * La otra tienda canjeó el código: es la secundaria.
	 */
	public const SECONDARY = 'secondary';

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
	 * @param string $role     El rol de **la otra**: `master` si ella emitió el código,
	 *                         `secondary` si lo canjeó (ADR-008 §2).
	 */
	public static function pair( string $store, string $rest_url, string $secret, string $label, string $role ): void {
		$before = self::only();

		// La copia del catálogo es de la tienda anterior (ADR-014 §1).
		if ( null !== $before && $before['store'] !== $store ) {
			Copy::clear();
		}

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
				'role'      => $role,
				'paired_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		update_option( self::OPTION, $peers, false );
	}

	/**
	 * Cómo se llama la otra tienda —el nombre que dio al emparejarse, o su identificador— y
	 * cuándo se emparejó, o null si no hay una.
	 *
	 * @return array{label: string, paired_at: string}|null
	 */
	public static function describe(): ?array {
		$peer = self::only();

		if ( null === $peer ) {
			return null;
		}

		$stored = (array) ( (array) get_option( self::OPTION, array() ) )[ $peer['store'] ];

		return array(
			'label'     => is_string( $stored['label'] ?? null ) && '' !== $stored['label'] ? $stored['label'] : $peer['store'],
			'paired_at' => is_string( $stored['paired_at'] ?? null ) ? $stored['paired_at'] : '',
		);
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
	 * @return array{store: string, rest_url: string, secret: string, role: string}|null El
	 *         `role` es el de la otra tienda, o '' si se emparejó antes de ADR-008.
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
					'role'     => is_string( $peer['role'] ?? null ) ? $peer['role'] : '',
				);
			}
		}

		return 1 === count( $paired ) ? $paired[0] : null;
	}

	/**
	 * Hasta qué secuencia de esa tienda purgó ésta: la marca de ADR-004 §4.
	 *
	 * Se lee de la base y no de la caché: la reconciliación la manda junto con una lista que
	 * acaba de leer, y la purga mueve las dos en la misma transacción (ADR-009 §2).
	 *
	 * @param string $store Identificador de la otra tienda.
	 */
	public static function purged_through( string $store ): int {
		wp_cache_delete( self::OPTION, 'options' );

		$peers = (array) get_option( self::OPTION, array() );
		$peer  = (array) ( $peers[ $store ] ?? array() );

		return (int) ( $peer['purged_through'] ?? 0 );
	}

	/**
	 * Desempareja: se lo avisa a la otra y olvida la URL y el secreto (ADR-015 §3).
	 *
	 * Si la otra no responde, se desempareja igual acá: la otra va a ver `wss_unknown_store`
	 * y mostrar que ésta rechaza sus pedidos, que es la verdad.
	 *
	 * @return bool Si la otra se enteró.
	 */
	public static function unpair(): bool {
		$peer = self::only();
		$told = null !== $peer && self::tell( $peer, '/unpair', array() );

		self::forget( Pause::HERE );

		return $told;
	}

	/**
	 * Olvida la URL y el secreto de toda entrada, y la pausa. Quedan la etiqueta,
	 * `purged_through`, los vínculos, el libro y la copia del catálogo: re-emparejar con la
	 * misma tienda deja todo como estaba (ADR-015 §3).
	 *
	 * @param string $by `here` o `peer`: quién lo pidió.
	 */
	public static function forget( string $by ): void {
		$peers = array();

		foreach ( (array) get_option( self::OPTION, array() ) as $key => $peer ) {
			$peer = (array) $peer;
			unset( $peer['rest_url'], $peer['secret'] );
			$peers[ (string) $key ] = $peer;
		}

		update_option( self::OPTION, $peers, false );
		delete_option( Pause::OPTION );
		// Sin socia no hay estado de la socia: lo de antes ya no dice nada.
		delete_option( Peer::OPTION );

		Events::record( Events::UNPAIRED, Events::INFO, null, array( 'by' => $by ) );
	}

	/**
	 * Le manda a la otra un pedido firmado (`/pause`, `/unpair`).
	 *
	 * @param array{store: string, rest_url: string, secret: string, role: string} $peer La otra tienda.
	 * @param string                                                               $path La ruta, relativa a `wss/v1`.
	 * @param array<string, mixed>                                                 $body Lo que va.
	 *
	 * @return bool Si contestó 200.
	 */
	public static function tell( array $peer, string $path, array $body ): bool {
		$json     = (string) wp_json_encode( (object) $body );
		$response = wp_remote_post(
			untrailingslashit( $peer['rest_url'] ) . $path,
			array(
				'headers'     => array_merge(
					array( 'Content-Type' => 'application/json' ),
					Signature::headers( Store::id(), $peer['secret'], 'POST', '/wss/v1' . $path, array(), $json )
				),
				'body'        => $json,
				// Lo pide la pantalla: si la otra está caída, mejor no colgarla 30 segundos.
				'timeout'     => 10,
				'redirection' => 0,
			)
		);

		Peer::observe( $response );

		return ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Si esta tienda es la maestra: la otra quedó anotada como secundaria (ADR-008 §2).
	 *
	 * Un emparejamiento anterior a ADR-008 no tiene rol, y no es maestra ninguna de las
	 * dos: no se vincula hasta re-emparejar.
	 */
	public static function this_is_master(): bool {
		$peer = self::only();

		return null !== $peer && self::SECONDARY === $peer['role'];
	}

	/**
	 * Si esa tienda es la maestra de ésta.
	 *
	 * @param string $store Identificador de la otra tienda.
	 */
	public static function is_master( string $store ): bool {
		$peer = self::only();

		return null !== $peer && $peer['store'] === $store && self::MASTER === $peer['role'];
	}
}
