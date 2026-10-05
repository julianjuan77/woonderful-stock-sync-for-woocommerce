<?php
/**
 * Tests de la comparación de libros.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Ledger\Ledger;
use Woonderful\StockSync\Reconciliation\Books;
use Woonderful\StockSync\Schema\Installer;

/**
 * La fase 1 de cada lado: qué tiene esta tienda de la otra, y qué se hace con lo propio
 * (ADR-009 §1–§3).
 */
class Test_Books extends WP_UnitTestCase {

	private const REMOTE_STORE = 'b1b1b1b1-0000-4000-8000-000000000000';

	public function set_up(): void {
		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Installer::install();
		Store::ensure_identity();
	}

	public function tear_down(): void {
		global $wpdb;

		foreach ( array( 'movements', 'links', 'nonces' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		delete_option( Installer::OPTION_VERSION );
		delete_option( Store::OPTION_ID );

		parent::tear_down();
	}

	/**
	 * Un movimiento propio en ese estado de entrega. Su secuencia es su ID.
	 *
	 * @param string               $delivery El estado.
	 * @param array<string, mixed> $columns  Columnas a pisar.
	 */
	private function own( string $delivery, array $columns = array() ): int {
		global $wpdb;

		$table = Installer::table( 'movements' );

		$wpdb->insert( // phpcs:ignore WordPress.DB
			$table,
			array_merge(
				array(
					'origin_store'     => Store::id(),
					'generation'       => 1,
					'delta'            => -1,
					'reason'           => 'sale',
					'created_at'       => gmdate( 'Y-m-d H:i:s' ),
					'origin_product'   => 10,
					'delivery'         => $delivery,
					'attempts'         => 4,
					'first_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 30000 ),
					'next_attempt_at'  => gmdate( 'Y-m-d H:i:s', time() + 600 ),
					'last_error'       => 'HTTP 503',
				),
				$columns
			)
		);

		$id = (int) $wpdb->insert_id;
		$wpdb->update( $table, array( 'origin_seq' => $id ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB

		return $id;
	}

	/**
	 * Un movimiento recibido de la otra tienda.
	 *
	 * @param int    $seq         Su secuencia allá.
	 * @param string $application Qué se hizo con él.
	 */
	private function received( int $seq, string $application = Ledger::APPLIED ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB
			Installer::table( 'movements' ),
			array(
				'origin_store'   => self::REMOTE_STORE,
				'origin_seq'     => $seq,
				'generation'     => 1,
				'delta'          => -1,
				'reason'         => 'sale',
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
				'origin_product' => 20,
				'application'    => $application,
			)
		);
	}

	/**
	 * Una columna de un movimiento.
	 *
	 * @param int    $id     ID.
	 * @param string $column Columna.
	 */
	private function column_of( int $id, string $column ): ?string {
		global $wpdb;

		$table = Installer::table( 'movements' );

		return $wpdb->get_var( $wpdb->prepare( "SELECT {$column} FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * BOK-01 — Lo que esta tienda tiene de la otra, en cualquier estado, en orden y sólo del
	 * tramo. Lo propio no cuenta.
	 */
	public function test_it_lists_what_it_holds_from_the_other_store(): void {
		$this->received( 30, Ledger::HELD );
		$this->received( 10 );
		$this->received( 20, Ledger::DISCARDED );
		$this->received( 40 );
		$this->own( 'confirmed' );

		$page = Books::held_from( self::REMOTE_STORE, 5, 30 );

		$this->assertSame( array( 10, 20, 30 ), $page['seqs'] );
		$this->assertSame( 30, $page['covered_to'] );
		$this->assertFalse( $page['more'] );
	}

	/**
	 * BOK-02 — Una tanda llena cubre hasta su última secuencia, y avisa que hay más.
	 */
	public function test_a_full_page_covers_up_to_its_last_sequence(): void {
		global $wpdb;

		$table  = Installer::table( 'movements' );
		$values = array();

		for ( $seq = 1; $seq <= Books::PAGE + 1; $seq++ ) {
			$values[] = $wpdb->prepare( '( %s, %d, 1, -1, %s, %s, 20, %s )', self::REMOTE_STORE, $seq * 2, 'sale', gmdate( 'Y-m-d H:i:s' ), Ledger::APPLIED );
		}

		$wpdb->query( "INSERT INTO {$table} ( origin_store, origin_seq, generation, delta, reason, created_at, origin_product, application ) VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB

		$first = Books::held_from( self::REMOTE_STORE, 0, 100000 );

		$this->assertCount( Books::PAGE, $first['seqs'] );
		$this->assertSame( Books::PAGE * 2, $first['covered_to'] );
		$this->assertTrue( $first['more'] );

		$second = Books::held_from( self::REMOTE_STORE, $first['covered_to'], 100000 );

		$this->assertSame( array( ( Books::PAGE + 1 ) * 2 ), $second['seqs'] );
		$this->assertSame( 100000, $second['covered_to'] );
		$this->assertFalse( $second['more'] );
	}

	/**
	 * BOK-03 — **Lo abandonado que la otra no tiene vuelve a la cola, con el reloj en cero**:
	 * sin intentos, sin primer intento y sin error (ADR-001 §4).
	 */
	public function test_an_abandoned_movement_the_other_lacks_is_reopened_with_a_fresh_clock(): void {
		$id = $this->own( 'abandoned' );

		$done = Books::compare_own( 0, $id, array(), 0 );

		$this->assertSame( 1, $done['reopened'] );
		$this->assertSame( 'pending', $this->column_of( $id, 'delivery' ) );
		$this->assertSame( '0', $this->column_of( $id, 'attempts' ) );
		$this->assertNull( $this->column_of( $id, 'first_attempt_at' ) );
		$this->assertNull( $this->column_of( $id, 'last_error' ) );
		$this->assertLessThanOrEqual( gmdate( 'Y-m-d H:i:s' ), $this->column_of( $id, 'next_attempt_at' ) );
	}

	/**
	 * BOK-04 — **La marca de purga deja afuera sólo lo confirmado** (ADR-009 §2): lo confirmado
	 * que falta se reabre por encima de la marca y no por debajo; lo abandonado, siempre.
	 */
	public function test_the_purge_mark_only_shields_what_was_confirmed(): void {
		$below_confirmed = $this->own( 'confirmed' );
		$below_abandoned = $this->own( 'abandoned' );
		$above_confirmed = $this->own( 'confirmed' );

		$done = Books::compare_own( 0, $above_confirmed, array(), $below_abandoned );

		$this->assertSame( 2, $done['reopened'] );
		$this->assertSame( 'confirmed', $this->column_of( $below_confirmed, 'delivery' ), 'la otra lo tuvo y lo borró' );
		$this->assertSame( 'pending', $this->column_of( $below_abandoned, 'delivery' ), 'nunca llegó' );
		$this->assertSame( 'pending', $this->column_of( $above_confirmed, 'delivery' ), 'la otra lo perdió' );
	}

	/**
	 * BOK-05 — Lo que está en camino no se toca, lo tenga la otra o no.
	 */
	public function test_what_is_on_its_way_is_left_alone(): void {
		$pending   = $this->own( 'pending' );
		$in_flight = $this->own( 'in_flight' );
		$arrived   = $this->own( 'in_flight' );

		$done = Books::compare_own( 0, $arrived, array( $arrived ), 0 );

		$this->assertSame( 0, $done['reopened'] + $done['confirmed'] );
		$this->assertSame( '4', $this->column_of( $pending, 'attempts' ), 'no se le reseteó el reloj' );
		$this->assertSame( 'in_flight', $this->column_of( $in_flight, 'delivery' ) );
		$this->assertSame( 'in_flight', $this->column_of( $arrived, 'delivery' ), 'su ACK está por llegar' );
	}

	/**
	 * BOK-06 — Lo que la otra tiene y acá estaba pendiente o abandonado se confirma: se perdió
	 * el ACK, pero llegó.
	 */
	public function test_what_the_other_has_is_confirmed(): void {
		$pending   = $this->own( 'pending' );
		$abandoned = $this->own( 'abandoned' );

		$done = Books::compare_own( 0, $abandoned, array( $pending, $abandoned ), 0 );

		$this->assertSame( 2, $done['confirmed'] );
		$this->assertSame( 'confirmed', $this->column_of( $pending, 'delivery' ) );
		$this->assertSame( 'confirmed', $this->column_of( $abandoned, 'delivery' ) );
		$this->assertNotNull( $this->column_of( $abandoned, 'confirmed_at' ) );
	}

	/**
	 * BOK-07 — El bootstrap no se toca: no va por la cola (ADR-008).
	 */
	public function test_a_bootstrap_is_never_reopened(): void {
		$id = $this->own( 'confirmed', array( 'reason' => Ledger::BOOTSTRAP ) );

		Books::compare_own( 0, $id, array(), 0 );

		$this->assertSame( 'confirmed', $this->column_of( $id, 'delivery' ) );
	}

	/**
	 * BOK-08 — Fuera del tramo no se toca nada: cada tanda compara sólo el suyo.
	 */
	public function test_only_the_range_is_compared(): void {
		$before = $this->own( 'abandoned' );
		$inside = $this->own( 'abandoned' );
		$after  = $this->own( 'abandoned' );

		Books::compare_own( $before, $inside, array(), 0 );

		$this->assertSame( 'abandoned', $this->column_of( $before, 'delivery' ) );
		$this->assertSame( 'pending', $this->column_of( $inside, 'delivery' ) );
		$this->assertSame( 'abandoned', $this->column_of( $after, 'delivery' ) );
	}

	/**
	 * BOK-09 — **Si la pasada se lleva la fila en el medio, no se la pisa**: el `UPDATE` mira
	 * el estado esperado, no el que se leyó.
	 */
	public function test_a_row_claimed_in_the_middle_is_not_overwritten(): void {
		global $wpdb;

		$id      = $this->own( 'pending' );
		$table   = Installer::table( 'movements' );
		$stepped = false;
		$race    = static function ( string $query ) use ( $wpdb, $table, $id, &$stepped ): string {
			if ( ! $stepped && str_starts_with( ltrim( $query ), "UPDATE {$table}" ) && str_contains( $query, "delivery = 'confirmed'" ) ) {
				$stepped = true;
				$wpdb->update( $table, array( 'delivery' => 'in_flight' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
			}

			return $query;
		};

		add_filter( 'query', $race );
		$done = Books::compare_own( 0, $id, array( $id ), 0 );
		remove_filter( 'query', $race );

		$this->assertTrue( $stepped );
		$this->assertSame( 0, $done['confirmed'] );
		$this->assertSame( 'in_flight', $this->column_of( $id, 'delivery' ) );
	}

	/**
	 * BOK-10 — El techo es la última secuencia propia; lo recibido no cuenta.
	 */
	public function test_the_ceiling_is_the_last_own_sequence(): void {
		$this->assertSame( 0, Books::ceiling() );

		$last = $this->own( 'confirmed' );
		$this->received( 999999 );

		$this->assertSame( $last, Books::ceiling() );
	}
}
