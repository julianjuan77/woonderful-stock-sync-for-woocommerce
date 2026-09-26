<?php
/**
 * Identidad de esta tienda.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Identity;

defined( 'ABSPATH' ) || exit;

/**
 * El identificador de la tienda y su huella de sitio (ADR-002, ADR-001 §1).
 */
final class Store {


	public const OPTION_ID          = 'wss_store_id';
	public const OPTION_LABEL       = 'wss_store_label';
	public const OPTION_FINGERPRINT = 'wss_site_fingerprint';

	/**
	 * Genera el identificador si todavía no existe. Es lo primero que el plugin escribe.
	 */
	public static function ensure_identity(): void {
		if ( '' !== self::id() ) {
			return;
		}

		add_option( self::OPTION_ID, wp_generate_uuid4(), '', false );
		add_option( self::OPTION_LABEL, get_bloginfo( 'name' ), '', false );
	}

	/**
	 * El identificador opaco de esta tienda. Cadena vacía si todavía no se generó.
	 */
	public static function id(): string {
		return (string) get_option( self::OPTION_ID, '' );
	}

	/**
	 * La etiqueta legible, para el log y las pantallas.
	 */
	public static function label(): string {
		$label = (string) get_option( self::OPTION_LABEL, '' );

		return '' !== $label ? $label : self::id();
	}

	/**
	 * La huella del sitio. Se guarda hasheada porque un search-replace de restore
	 * reescribiría la URL en claro y el clon se daría por sano (ADR-001 §1).
	 *
	 * La opción y no home_url(): esa cambia con is_ssl() y con quien la filtre, y la
	 * tienda viva se tomaría por clon (ADR-007).
	 */
	public static function fingerprint(): string {
		return hash( 'sha256', (string) get_option( 'home' ) );
	}

	/**
	 * Si el sitio no es aquel donde se emparejó, la sincronización queda suspendida.
	 */
	public static function is_cloned_site(): bool {
		$stored = (string) get_option( self::OPTION_FINGERPRINT, '' );

		return '' !== $stored && ! hash_equals( $stored, self::fingerprint() );
	}
}
