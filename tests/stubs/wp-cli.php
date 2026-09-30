<?php
/**
 * Lo mínimo de WP-CLI que usa el plugin, para que PHPStan lo conozca.
 *
 * Un stub propio y no `php-stubs/wp-cli-stubs`: son tres métodos, y una dependencia más
 * pide ADR. Si el comando crece, conviene la dependencia.
 *
 * @package Woonderful\StockSync
 */

// phpcs:ignoreFile

/**
 * WP-CLI.
 */
class WP_CLI {

	/**
	 * @param string $name     El comando.
	 * @param mixed  $callable La clase o el callable.
	 * @param array<string, mixed> $args Argumentos del registro.
	 */
	public static function add_command( string $name, $callable, array $args = array() ): bool {
		return true;
	}

	/**
	 * @param string $message Lo que se imprime.
	 */
	public static function line( string $message = '' ): void {}

	/**
	 * @param string $message Lo que se imprime.
	 */
	public static function success( string $message ): void {}

	/**
	 * @param string|\WP_Error $message Lo que se imprime.
	 * @param bool             $exit    Si termina.
	 *
	 * @return never
	 */
	public static function error( $message, bool $exit = true ): void {
		exit( 1 );
	}
}
