<?php
/**
 * Tests del libro de movimientos.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Ledger\Ledger;
use Woonderful\StockSync\Schema\Installer;

/**
 * Escribir un movimiento propio (ADR-004 §1, ADR-006 §4).
 */
class Test_Ledger extends WP_UnitTestCase {

	/**
	 * El vínculo que arma set_up() para el producto 123.
	 *
	 * @var int
	 */
	private int $link_id = 0;

	public function set_up(): void {
		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Installer::install();
		Store::ensure_identity();

		$this->link_id = $this->link_product( 123, 456, 77 );
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
	 * Vincula un producto local con uno remoto.
	 *
	 * @param int $local      Producto de esta tienda.
	 * @param int $remote     Producto de la otra.
	 * @param int $generation Generación del par.
	 */
	private function link_product( int $local, int $remote, int $generation ): int {
		global $wpdb;

		$wpdb->insert(
			Installer::table( 'links' ),
			array(
				'local_product'  => $local,
				'remote_store'   => 'b1b1b1b1-0000-4000-8000-000000000000',
				'remote_product' => $remote,
				'generation'     => $generation,
				'base_stock'     => 10,
				'base_set_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * La fila del movimiento recién escrito.
	 *
	 * @param int $movement_id ID del movimiento.
	 */
	private function movement( int $movement_id ): ?object {
		global $wpdb;

		$table = Installer::table( 'movements' );

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $movement_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Cuántos movimientos hay en el libro.
	 */
	private function count_movements(): int {
		global $wpdb;

		$table = Installer::table( 'movements' );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * LED-01 — Un cambio de un producto vinculado escribe una fila con sus datos.
	 */
	public function test_it_writes_a_row_for_a_linked_product(): void {
		$movement_id = Ledger::record( 123, -2, 'sale', 'pedido #4512' );

		$this->assertIsInt( $movement_id );

		$movement = $this->movement( $movement_id );

		$this->assertSame( Store::id(), $movement->origin_store );
		$this->assertSame( -2, (int) $movement->delta );
		$this->assertSame( 123, (int) $movement->origin_product );
		$this->assertSame( $this->link_id, (int) $movement->link_id );
		$this->assertSame( 'sale', $movement->reason );
		$this->assertSame( 'pedido #4512', $movement->reference );
	}

	/**
	 * La secuencia de un movimiento propio ES su AUTO_INCREMENT, y el INSERT no puede
	 * saberlo: lo completa el UPDATE de la misma transacción (ADR-004 §1).
	 *
	 * LED-02
	 */
	public function test_the_sequence_ends_up_equal_to_the_row_id(): void {
		$movement_id = Ledger::record( 123, -1 );

		$this->assertSame( $movement_id, (int) $this->movement( $movement_id )->origin_seq );
	}

	/**
	 * Las dos escrituras van dentro de una transacción (ADR-004 §1).
	 *
	 * No se puede verificar por el efecto: la suite de WordPress ya envuelve cada test en
	 * su propia transacción con `autocommit = 0`, así que una conexión aparte tampoco
	 * vería la fila a medio escribir, haya o no transacción nuestra. Lo que sí se puede
	 * observar es el orden de las consultas.
	 *
	 * LED-10
	 */
	public function test_both_writes_happen_inside_a_transaction(): void {
		$queries = array();

		$watch = static function ( string $query ) use ( &$queries ): string {
			$queries[] = $query;

			return $query;
		};

		add_filter( 'query', $watch );
		Ledger::record( 123, -1 );
		remove_filter( 'query', $watch );

		$position = static function ( callable $matches ) use ( $queries ): int {
			foreach ( $queries as $index => $query ) {
				if ( $matches( $query ) ) {
					return $index;
				}
			}

			return -1;
		};

		$table = Installer::table( 'movements' );

		$start  = $position( static fn( string $q ): bool => str_contains( $q, 'START TRANSACTION' ) );
		$insert = $position( static fn( string $q ): bool => str_contains( $q, 'INSERT' ) && str_contains( $q, $table ) );
		$update = $position( static fn( string $q ): bool => str_contains( $q, 'origin_seq' ) && str_contains( $q, 'UPDATE' ) );
		$commit = $position( static fn( string $q ): bool => str_contains( $q, 'COMMIT' ) );

		$this->assertGreaterThan( -1, $start, 'falta el START TRANSACTION' );
		$this->assertGreaterThan( -1, $commit, 'falta el COMMIT' );
		$this->assertLessThan( $insert, $start, 'el INSERT queda fuera de la transacción' );
		$this->assertGreaterThan( $update, $commit, 'el UPDATE queda fuera de la transacción' );
	}

	/**
	 * LED-03 — El movimiento toma la generación vigente del vínculo.
	 */
	public function test_it_takes_the_generation_from_the_link(): void {
		$movement_id = Ledger::record( 123, -1 );

		$this->assertSame( 77, (int) $this->movement( $movement_id )->generation );
	}

	/**
	 * LED-04 — Nace pendiente de entrega y sin intentos.
	 */
	public function test_it_is_born_pending_with_no_attempts(): void {
		$movement = $this->movement( (int) Ledger::record( 123, -1 ) );

		$this->assertSame( 'pending', $movement->delivery );
		$this->assertSame( 0, (int) $movement->attempts );
		$this->assertNull( $movement->confirmed_at );
	}

	/**
	 * La cola no puede llevárselo enseguida: el hook del pedido todavía no estampó el
	 * motivo, y una ráfaga no tendría tiempo de agruparse (ADR-006 §4).
	 *
	 * LED-05
	 */
	public function test_it_is_not_deliverable_right_away(): void {
		$movement = $this->movement( (int) Ledger::record( 123, -1 ) );

		// Dos aserciones y no una: la primera dice que el código usa la constante, la
		// segunda que la constante es la que decidimos. Comparar la fila contra la
		// constante sola no detecta que alguien la baje a 1 segundo, porque cambian los
		// dos lados de la comparación (ADR-006 §4).
		$this->assertSame(
			Ledger::DELIVERY_DELAY,
			strtotime( $movement->next_attempt_at ) - strtotime( $movement->created_at )
		);
		$this->assertSame( 10, Ledger::DELIVERY_DELAY );
	}

	/**
	 * LED-06 — El motivo por defecto es `adjustment`: cambió el stock y no hay documento.
	 */
	public function test_the_default_reason_is_adjustment(): void {
		$movement = $this->movement( (int) Ledger::record( 123, 5 ) );

		$this->assertSame( 'adjustment', $movement->reason );
		$this->assertNull( $movement->reference );
	}

	/**
	 * La transacción existe para que las dos escrituras vayan juntas o no vaya ninguna.
	 * Si el UPDATE de la secuencia falla y se confirma igual, queda en el libro una fila
	 * pendiente de entregar con el identificador en NULL: el receptor no puede
	 * deduplicarla (ADR-004 §1).
	 *
	 * LED-09
	 */
	public function test_a_failed_sequence_update_leaves_nothing(): void {
		global $wpdb;

		$break_the_update = static function ( string $query ): string {
			if ( 0 === stripos( ltrim( $query ), 'UPDATE' ) && str_contains( $query, 'origin_seq' ) ) {
				return 'UPDATE wss_no_existe SET origin_seq = 1';
			}

			return $query;
		};

		add_filter( 'query', $break_the_update );
		$wpdb->suppress_errors( true );

		$result = Ledger::record( 123, -1 );

		$wpdb->suppress_errors( false );
		remove_filter( 'query', $break_the_update );

		$this->assertNull( $result );
		$this->assertSame( 0, $this->count_movements() );
	}

	/**
	 * LED-07 — Un producto sin vincular no genera movimiento.
	 */
	public function test_an_unlinked_product_writes_nothing(): void {
		$this->assertNull( Ledger::record( 999, -3 ) );
		$this->assertSame( 0, $this->count_movements() );
	}

	/**
	 * LED-08 — Un delta de cero no es un cambio.
	 */
	public function test_a_zero_delta_writes_nothing(): void {
		$this->assertNull( Ledger::record( 123, 0 ) );
		$this->assertSame( 0, $this->count_movements() );
	}
}
