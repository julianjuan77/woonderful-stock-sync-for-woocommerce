<?php
/**
 * Los comandos de WP-CLI.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

namespace Woonderful\StockSync\Cli;

use Woonderful\StockSync\Linking\Linker;
use Woonderful\StockSync\Linking\State;
use Woonderful\StockSync\Ledger\Ledger;
use Woonderful\StockSync\Transport\Pairing;

defined( 'ABSPATH' ) || exit;

/**
 * Empareja las dos tiendas y vincula productos mientras no hay pantalla (ADR-001 §1,
 * ADR-008 §7).
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
	 * <code>
	 * : El que emitió la otra tienda con `wp wss pairing-code`.
	 *
	 * El nombre va sin tildes: WP-CLI arma los argumentos a partir de este docblock, y con
	 * `<código>` marca el argumento como inválido y rechaza el código.
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

	/**
	 * Vincula un producto de esta tienda con uno de la otra. En la maestra.
	 *
	 * Los dos stocks son los que viste: si alguno cambió, no se vincula y hay que volver a
	 * mirarlos. El stock de arranque es el de esta tienda.
	 *
	 * ## OPTIONS
	 *
	 * <local>
	 * : El ID del producto en esta tienda.
	 *
	 * <remote>
	 * : El ID del producto en la otra tienda.
	 *
	 * --stock=<stock>
	 * : El stock que viste acá.
	 *
	 * --remote-stock=<stock>
	 * : El stock que viste en la otra tienda.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wss link 123 456 --stock=10 --remote-stock=7
	 *
	 * @param array<int, string>    $args       Los dos productos.
	 * @param array<string, string> $assoc_args Los dos stocks.
	 */
	public function link( array $args, array $assoc_args ): void {
		$result = Linker::link(
			(int) ( $args[0] ?? 0 ),
			(int) ( $args[1] ?? 0 ),
			(int) ( $assoc_args['stock'] ?? -1 ),
			(int) ( $assoc_args['remote-stock'] ?? -1 ),
			__( 'vinculado por WP-CLI', 'woonderful-stock-sync-for-woocommerce' )
		);

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_code() . ': ' . $result->get_error_message() );
		}

		if ( Ledger::UNCERTAIN === $result ) {
			\WP_CLI::line( __( 'La otra tienda no respondió: no se sabe si lo vinculó. El plugin le va a preguntar solo dentro de 10 minutos.', 'woonderful-stock-sync-for-woocommerce' ) );

			return;
		}

		\WP_CLI::success( __( 'Vinculados.', 'woonderful-stock-sync-for-woocommerce' ) );
	}

	/**
	 * Desvincula un producto de esta tienda. En la maestra.
	 *
	 * ## OPTIONS
	 *
	 * <local>
	 * : El ID del producto en esta tienda.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wss unlink 123
	 *
	 * @param array<int, string>    $args       El producto.
	 * @param array<string, string> $assoc_args No lleva.
	 */
	public function unlink( array $args, array $assoc_args ): void {
		$result = Linker::unlink( (int) ( $args[0] ?? 0 ) );

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_code() . ': ' . $result->get_error_message() );
		}

		\WP_CLI::success( __( 'Desvinculado. La otra tienda se entera en la próxima pasada.', 'woonderful-stock-sync-for-woocommerce' ) );
	}

	/**
	 * Muestra el estado de sincronización de productos de esta tienda.
	 *
	 * ## OPTIONS
	 *
	 * <local>...
	 * : Los IDs de los productos de esta tienda.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wss status 123 124
	 *
	 * @param array<int, string>    $args       Los productos.
	 * @param array<string, string> $assoc_args No lleva.
	 */
	public function status( array $args, array $assoc_args ): void {
		foreach ( $args as $arg ) {
			if ( ! ctype_digit( $arg ) || '0' === $arg ) {
				/* translators: %s: lo que se pasó en lugar de un ID. */
				\WP_CLI::error( sprintf( __( '"%s" no es el ID de un producto.', 'woonderful-stock-sync-for-woocommerce' ), $arg ) );
			}
		}

		$result = State::of( array_map( 'intval', $args ) );

		foreach ( $result['states'] as $product => $state ) {
			\WP_CLI::line( $product . ': ' . $state['state'] . ( null === $state['reason'] ? '' : ' (' . $state['reason'] . ')' ) );
		}

		if ( 'wss_not_paired' === $result['remote_error'] ) {
			\WP_CLI::line( __( 'Esta tienda no está emparejada: no se puede ver el producto de la otra.', 'woonderful-stock-sync-for-woocommerce' ) );
		} elseif ( null !== $result['remote_error'] ) {
			\WP_CLI::line( __( 'La otra tienda no respondió: los vinculados figuran como vinculados, pero no se pudo ver si su producto sigue existiendo.', 'woonderful-stock-sync-for-woocommerce' ) );
		}
	}
}
