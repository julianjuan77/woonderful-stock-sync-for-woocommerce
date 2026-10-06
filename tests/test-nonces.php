<?php
/**
 * Tests del almacén de nonces.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Nonces;
use Woonderful\StockSync\Transport\Signature;

/**
 * Cada nonce se acepta una vez, y se guarda mientras un reenvío pueda pasar la ventana
 * (ADR-001 §1).
 */
class Test_Nonces extends WP_UnitTestCase {

	private const NOW = 1758300000;

	public function set_up(): void {
		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Installer::install();
	}

	public function tear_down(): void {
		global $wpdb;

		// Las filas del log, no la tabla: el DROP de las otras confirma el borrado, y una
		// tabla menos que crear y borrar en cada test hace la suite más rápida.
		$wpdb->query( 'DELETE FROM ' . Installer::table( 'events' ) ); // phpcs:ignore WordPress.DB

		foreach ( array( 'movements', 'links', 'nonces' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		delete_option( Installer::OPTION_VERSION );

		parent::tear_down();
	}

	/**
	 * NON-01 — Un nonce que nunca se vio es nuevo.
	 */
	public function test_a_new_nonce_is_claimed(): void {
		$this->assertTrue( Nonces::claim( '7f3c1b9e4a2d', self::NOW ) );
	}

	/**
	 * NON-02 — El mismo nonce, otra vez, es un reenvío.
	 */
	public function test_a_repeated_nonce_is_rejected(): void {
		Nonces::claim( '7f3c1b9e4a2d', self::NOW );

		$this->assertFalse( Nonces::claim( '7f3c1b9e4a2d', self::NOW + 30 ) );
	}

	/**
	 * Un nonce vacío no identifica nada; uno más largo que la columna se recortaría y
	 * chocaría con otro que empiece igual.
	 *
	 * NON-03
	 */
	public function test_an_empty_or_too_long_nonce_is_rejected(): void {
		$this->assertFalse( Nonces::claim( '', self::NOW ) );
		$this->assertFalse( Nonces::claim( str_repeat( 'a', 65 ), self::NOW ) );
		$this->assertTrue( Nonces::claim( str_repeat( 'a', 64 ), self::NOW ) );
	}

	/**
	 * El caso que obligó a guardarlos dos ventanas, en su peor momento: el reloj del emisor
	 * adelanta una ventana entera y el reenvío llega a las dos ventanas justas, el último
	 * segundo en que la firma todavía verifica. El nonce tiene que seguir ahí: cualquier
	 * retención menor, aunque sea por un segundo, lo deja pasar.
	 *
	 * NON-04
	 */
	public function test_a_replay_from_a_clock_ahead_is_still_caught(): void {
		$timestamp = self::NOW + Signature::WINDOW;

		Nonces::claim( '7f3c1b9e4a2d', self::NOW );

		$replay_at = self::NOW + 2 * Signature::WINDOW;
		Nonces::purge( $replay_at );

		$signature = Signature::sign( 'secreto', 'POST', '/wss/v1/movements', array(), $timestamp, '7f3c1b9e4a2d', '' );

		$this->assertTrue(
			Signature::verify( 'secreto', 'POST', '/wss/v1/movements', array(), $timestamp, '7f3c1b9e4a2d', '', $signature, $replay_at ),
			'la firma del reenvío todavía es válida'
		);
		$this->assertFalse( Nonces::claim( '7f3c1b9e4a2d', $replay_at ), 'y el nonce tiene que seguir anotado' );
	}

	/**
	 * Justo en el borde de las dos ventanas se queda; un segundo después, se va.
	 *
	 * NON-05
	 */
	public function test_purge_keeps_two_windows(): void {
		Nonces::claim( 'viejo', self::NOW - Nonces::RETENTION - 1 );
		Nonces::claim( 'borde', self::NOW - Nonces::RETENTION );
		Nonces::claim( 'nuevo', self::NOW );

		$this->assertSame( 1, Nonces::purge( self::NOW ) );

		$this->assertTrue( Nonces::claim( 'viejo', self::NOW ), 'el viejo ya no está' );
		$this->assertFalse( Nonces::claim( 'borde', self::NOW ), 'el del borde se queda' );
		$this->assertFalse( Nonces::claim( 'nuevo', self::NOW ), 'el nuevo se queda' );
	}

	/**
	 * Sin la tabla no se puede saber si el nonce es nuevo, y ante la duda el request se
	 * rechaza.
	 *
	 * NON-07
	 */
	public function test_without_the_table_nothing_is_new(): void {
		global $wpdb;

		$table = Installer::table( 'nonces' );
		$wpdb->query( "DROP TABLE {$table}" ); // phpcs:ignore WordPress.DB

		$suppress = $wpdb->suppress_errors( true );
		$claimed  = Nonces::claim( '7f3c1b9e4a2d', self::NOW );
		$wpdb->suppress_errors( $suppress );

		$this->assertFalse( $claimed );
	}
}
