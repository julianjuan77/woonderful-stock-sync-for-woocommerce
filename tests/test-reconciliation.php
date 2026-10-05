<?php
/**
 * Tests de la reconciliación.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Reconciliation\Lock;
use Woonderful\StockSync\Reconciliation\Result;

/**
 * El candado y el resultado (ADR-009 §4, §7).
 */
class Test_Reconciliation extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		// Otros tests confirman lo escrito con un DROP TABLE: un candado de ellos llegaría acá.
		$this->forget_lock();
		delete_option( Result::OPTION );
	}

	public function tear_down(): void {
		$this->forget_lock();
		delete_option( Result::OPTION );

		parent::tear_down();
	}

	/**
	 * Borra el candado de la base y de la caché.
	 */
	private function forget_lock(): void {
		global $wpdb;

		$wpdb->delete( $wpdb->options, array( 'option_name' => Lock::OPTION ) ); // phpcs:ignore WordPress.DB
		wp_cache_delete( Lock::OPTION, 'options' );
	}

	/**
	 * Lo guardado en la base, sin pasar por la caché.
	 */
	private function stored(): ?string {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Lock::OPTION ) ); // phpcs:ignore WordPress.DB

		return null === $value ? null : (string) $value;
	}

	/**
	 * REC-01 — Libre, se toma: queda guardado con su vencimiento a 15 minutos, sin autocargar.
	 */
	public function test_a_free_lock_is_taken(): void {
		global $wpdb;

		$mine = Lock::acquire( 1000 );

		$this->assertIsString( $mine );
		$this->assertSame( $mine, $this->stored() );
		$this->assertStringEndsWith( '|' . ( 1000 + Lock::TTL ), $mine );
		$this->assertSame( 'no', $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Lock::OPTION ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * REC-02 — Tomado y sin vencer, no se toma de nuevo, y no se pisa.
	 */
	public function test_a_taken_lock_is_not_taken_again(): void {
		$first = Lock::acquire( 1000 );

		$this->assertNull( Lock::acquire( 1000 + Lock::TTL - 1 ) );
		$this->assertSame( $first, $this->stored() );
	}

	/**
	 * REC-03 — Vencido, se toma: una reconciliación que murió a mitad no la apaga para siempre.
	 */
	public function test_an_expired_lock_is_taken_over(): void {
		$first  = Lock::acquire( 1000 );
		$second = Lock::acquire( 1000 + Lock::TTL );

		$this->assertIsString( $second );
		$this->assertNotSame( $first, $second );
		$this->assertSame( $second, $this->stored() );
	}

	/**
	 * REC-04 — Quien lo tomó lo suelta, y queda libre.
	 */
	public function test_the_owner_releases_it(): void {
		$mine = (string) Lock::acquire( 1000 );

		$this->assertTrue( Lock::release( $mine ) );
		$this->assertNull( $this->stored() );
		$this->assertIsString( Lock::acquire( 1000 ) );
	}

	/**
	 * REC-05 — **Uno que venció no suelta el de la que vino después**: la reconciliación lenta
	 * ya no lo tiene.
	 */
	public function test_a_late_owner_does_not_release_the_next_one(): void {
		$slow = (string) Lock::acquire( 1000 );
		$next = Lock::acquire( 1000 + Lock::TTL );

		$this->assertFalse( Lock::release( $slow ) );
		$this->assertSame( $next, $this->stored() );
	}

	/**
	 * REC-06 — Si está tomado lo dice sólo mientras no venció. Es lo que contesta esta tienda
	 * cuando la otra le pregunta.
	 */
	public function test_is_taken_only_until_it_expires(): void {
		$this->assertFalse( Lock::is_taken( 1000 ) );

		Lock::acquire( 1000 );

		$this->assertTrue( Lock::is_taken( 1000 + Lock::TTL - 1 ) );
		$this->assertFalse( Lock::is_taken( 1000 + Lock::TTL ) );
	}

	/**
	 * REC-07 — **No le cree a la caché**: una copia vieja que dice "vencido" no deja tomar uno
	 * que en la base está vigente.
	 */
	public function test_a_stale_cache_does_not_fool_it(): void {
		$live = Lock::acquire( 1000 );
		wp_cache_set( Lock::OPTION, 'viejo|1', 'options' );

		$this->assertNull( Lock::acquire( 1001 ) );
		$this->assertTrue( Lock::is_taken( 1001 ) );
		$this->assertSame( $live, $this->stored() );
	}

	/**
	 * REC-08 — Un valor ilegible se toma como vencido: si no, nadie podría volver a tomarlo.
	 */
	public function test_an_unreadable_lock_is_taken_over(): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB
			$wpdb->options,
			array(
				'option_name'  => Lock::OPTION,
				'option_value' => 'basura',
				'autoload'     => 'no',
			)
		);

		$this->assertIsString( Lock::acquire( 1000 ) );
	}

	/**
	 * REC-10 — **Dos que toman el mismo vencido a la vez: gana uno.** Otro proceso lo toma
	 * justo entre que éste lo lee vencido y lo pisa; el `UPDATE` sólo pisa el valor vencido, así
	 * que no le saca el candado al otro.
	 */
	public function test_two_taking_over_the_same_expired_lock_do_not_both_win(): void {
		global $wpdb;

		Lock::acquire( 1000 );

		$other   = 'otro-proceso|' . ( 2000 + Lock::TTL );
		$stepped = false;
		$race    = static function ( string $query ) use ( $wpdb, $other, &$stepped ): string {
			if ( ! $stepped && str_starts_with( $query, "UPDATE {$wpdb->options}" ) && str_contains( $query, Lock::OPTION ) ) {
				$stepped = true;
				$wpdb->update( $wpdb->options, array( 'option_value' => $other ), array( 'option_name' => Lock::OPTION ) ); // phpcs:ignore WordPress.DB
			}

			return $query;
		};

		add_filter( 'query', $race );
		$mine = Lock::acquire( 2000 );
		remove_filter( 'query', $race );

		$this->assertTrue( $stepped, 'el otro proceso se metió en el medio' );
		$this->assertNull( $mine );
		$this->assertSame( $other, $this->stored() );
	}

	/**
	 * REC-09 — El resultado se guarda sin autocargar, y se lee como se guardó.
	 */
	public function test_the_result_is_kept(): void {
		global $wpdb;

		$this->assertNull( Result::last() );

		Result::save( Result::NIGHTLY, Result::DONE, array( 'reopened' => 3 ) );

		$last = Result::last();

		$this->assertSame( Result::NIGHTLY, $last['trigger'] ?? null );
		$this->assertSame( Result::DONE, $last['outcome'] ?? null );
		$this->assertSame( array( 'reopened' => 3 ), $last['details'] ?? null );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $last['at'] ?? '' );
		// `off` desde WordPress 6.6; `no` antes, y el plugin pide 6.5.
		$this->assertContains( $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Result::OPTION ) ), array( 'no', 'off' ) ); // phpcs:ignore WordPress.DB
	}
}
