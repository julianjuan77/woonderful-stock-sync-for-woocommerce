<?php
/**
 * Los comandos de WP-CLI.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Cli;

use Woonderful\StockSync\Transport\Pairing;

defined( 'ABSPATH' ) || exit;

/**
 * Empareja las dos tiendas mientras no hay pantalla de ajustes (ADR-001 §1).
 */
final class Command {

	/**
	 * Emite un código de emparejamiento. En la maestra.
	 *
	 * Vale 15 minutos y para un solo canje. Se pega en la otra tienda con `wp wss pair`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wss pairing-code
	 *
	 * @subcommand pairing-code
	 *
	 * @param array<int, string>    $args       No lleva.
	 * @param array<string, string> $assoc_args No lleva.
	 */
	public function pairing_code( array $args, array $assoc_args ): void {
		$code = Pairing::create_code();

		if ( is_wp_error( $code ) ) {
			\WP_CLI::error( $code->get_error_message() );
		}

		\WP_CLI::line( $code );
	}

	/**
	 * Canjea un código de emparejamiento. En la secundaria.
	 *
	 * ## OPTIONS
	 *
	 * <código>
	 * : El que emitió la otra tienda con `wp wss pairing-code`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wss pair wss_eyJ1cmwiOi...
	 *
	 * @param array<int, string>    $args       El código.
	 * @param array<string, string> $assoc_args No lleva.
	 */
	public function pair( array $args, array $assoc_args ): void {
		$result = Pairing::redeem( (string) ( $args[0] ?? '' ) );

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_code() . ': ' . $result->get_error_message() );
		}

		\WP_CLI::success( __( 'Las dos tiendas quedaron emparejadas.', 'woonderful-stock-sync-for-woocommerce' ) );
	}
}
