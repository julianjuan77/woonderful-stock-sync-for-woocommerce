<?php
/**
 * Un WP-CLI falso para los tests: guarda lo que se imprime, y `error()` tira en vez de cortar
 * el proceso, que es lo que haría el de verdad.
 *
 * @package Woonderful\StockSync
 */

// phpcs:ignoreFile

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		public static array $out = array();

		public static function add_command( string $name, $callable, array $args = array() ): bool {
			return true;
		}

		public static function line( string $message = '' ): void {
			self::$out[] = $message;
		}

		public static function success( string $message ): void {
			self::$out[] = 'Success: ' . $message;
		}

		public static function error( $message, bool $exit = true ): void {
			throw new RuntimeException( (string) $message );
		}
	}
}
