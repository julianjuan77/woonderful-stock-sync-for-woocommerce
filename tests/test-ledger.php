<?php
/**
 * Tests del libro de movimientos.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Detector\Detector;
use Woonderful\StockSync\Detector\Guard;
use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Ledger\Ledger;
use Woonderful\StockSync\Schema\Installer;

/**
 * Escribir un movimiento propio (ADR-004 §1, ADR-006 §4).
 */
class Test_Ledger extends WP_UnitTestCase {

	/**
	 * El vínculo que arma set_up() para el producto `PRODUCT`.
	 *
	 * @var int
	 */
	private int $link_id = 0;

	/**
	 * La otra tienda de la malla.
	 */
	private const REMOTE_STORE = 'b1b1b1b1-0000-4000-8000-000000000000';

	/**
	 * El producto que vincula set_up(). Alto a propósito: los productos de los tests sobreviven
	 * al rollback, y uno creado con el mismo ID chocaría contra este vínculo.
	 */
	private const PRODUCT = 900123;

	/**
	 * Secuencias para los movimientos de prueba.
	 *
	 * Un contador y no `wp_rand()`: dos filas que sacaran el mismo número chocarían
	 * contra el `UNIQUE` y el test fallaría de vez en cuando.
	 *
	 * @var int
	 */
	private int $next_seq = 1000;

	public function set_up(): void {
		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Installer::install();
		Store::ensure_identity();

		wss_unhook_detector();

		$this->link_id = $this->link_product( self::PRODUCT, 456, 77 );
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
		delete_option( Store::OPTION_ID );

		parent::tear_down();
	}

	/**
	 * Crea un producto de WooCommerce que gestiona stock.
	 *
	 * @param int $stock Con cuántas unidades arranca.
	 */
	private function create_product( int $stock ): int {
		$product = new WC_Product_Simple();
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );

		return (int) $product->save();
	}

	/**
	 * El stock que tiene un producto, leído de la base.
	 *
	 * @param int $product_id Producto.
	 */
	private function stock_of( int $product_id ): int {
		return (int) get_post_meta( $product_id, '_stock', true );
	}

	/**
	 * Un movimiento tal como llega de la otra tienda.
	 *
	 * @param int   $product   Producto en la tienda de origen.
	 * @param int   $delta     Unidades con signo.
	 * @param array $overrides Campos a pisar.
	 *
	 * @return array<string, mixed>
	 */
	private function incoming( int $product, int $delta, array $overrides = array() ): array {
		return array_merge(
			array(
				'origin'     => self::REMOTE_STORE,
				'seq'        => 77,
				'generation' => 77,
				'delta'      => $delta,
				'product'    => $product,
				'reason'     => 'sale',
				'reference'  => 'pedido #4512',
			),
			$overrides
		);
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
				'remote_store'   => self::REMOTE_STORE,
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
	 * LED-11 — Un movimiento aplicable descuenta el stock y queda como `applied`.
	 */
	public function test_an_applicable_movement_is_applied(): void {
		$product_id = $this->create_product( 10 );
		$this->link_product( $product_id, 789, 77 );

		$state = Ledger::apply( $this->incoming( 789, -2 ) );

		$this->assertSame( 'applied', $state );
		$this->assertSame( 8, $this->stock_of( $product_id ) );
	}

	/**
	 * LED-12 — La fila del receptor guarda el movimiento con su origen y su secuencia.
	 */
	public function test_the_received_row_keeps_its_origin(): void {
		$product_id = $this->create_product( 10 );
		$this->link_product( $product_id, 789, 77 );

		Ledger::apply( $this->incoming( 789, -2 ) );

		global $wpdb;
		$table    = Installer::table( 'movements' );
		$movement = $wpdb->get_row( "SELECT * FROM {$table} LIMIT 1" ); // phpcs:ignore WordPress.DB

		$this->assertSame( self::REMOTE_STORE, $movement->origin_store );
		$this->assertSame( 77, (int) $movement->origin_seq );
		$this->assertSame( 789, (int) $movement->origin_product );
		$this->assertSame( 'applied', $movement->application );
		$this->assertNull( $movement->delivery );
	}

	/**
	 * Un reenvío no puede descontar dos veces: el ACK confirma que el movimiento está
	 * guardado, así que el emisor puede reintentarlo sin saber que ya llegó (ADR-001 §3).
	 *
	 * LED-13
	 */
	public function test_a_resent_movement_is_not_applied_twice(): void {
		$product_id = $this->create_product( 10 );
		$this->link_product( $product_id, 789, 77 );

		$this->assertSame( 'applied', Ledger::apply( $this->incoming( 789, -2 ) ) );
		$this->assertSame( 'applied', Ledger::apply( $this->incoming( 789, -2 ) ) );

		$this->assertSame( 8, $this->stock_of( $product_id ) );
		$this->assertSame( 1, $this->count_movements() );
	}

	/**
	 * LED-14 — Sin vínculo, el movimiento se descarta y no toca ningún stock.
	 */
	public function test_a_movement_without_a_link_is_discarded(): void {
		$state = Ledger::apply( $this->incoming( 999, -2 ) );

		$this->assertSame( 'discarded', $state );
		$this->assertSame( 1, $this->count_movements() );
	}

	/**
	 * Un delta que quedó en cola del vínculo anterior llega después de un bootstrap
	 * nuevo: no es un duplicado y la deduplicación no lo detiene, pero pertenece a una
	 * época que ya no corre (ADR-005 §4).
	 *
	 * LED-15
	 */
	public function test_a_movement_from_an_old_generation_is_discarded(): void {
		$product_id = $this->create_product( 10 );
		$this->link_product( $product_id, 789, 412 );

		$state = Ledger::apply( $this->incoming( 789, -2, array( 'generation' => 77 ) ) );

		$this->assertSame( 'discarded', $state );
		$this->assertSame( 10, $this->stock_of( $product_id ) );
	}

	/**
	 * El producto local desapareció: no se puede aplicar, pero tampoco se descarta —
	 * descartarlo pierde stock en silencio (§3 del SPEC, UC-03).
	 *
	 * LED-16
	 */
	public function test_a_movement_for_a_missing_product_is_held(): void {
		$product_id = $this->create_product( 10 );
		$this->link_product( $product_id, 789, 77 );

		wp_delete_post( $product_id, true );

		$state = Ledger::apply( $this->incoming( 789, -2 ) );

		$this->assertSame( 'held', $state );
	}

	/**
	 * Aplicar cambia el stock local, y el detector lo vería como una venta: el guard
	 * tiene que quedar anotado antes (ADR-006 §3).
	 *
	 * LED-17
	 */
	public function test_applying_arms_the_guard(): void {
		$product_id = $this->create_product( 10 );
		$this->link_product( $product_id, 789, 77 );

		Guard::forget_all();

		// Mirar el guard después de `apply()` no dice nada del orden: quedaría igual si se
		// anotara al final. El espía hace lo que va a hacer el detector —consumir— en el
		// momento exacto en que WooCommerce avisa el cambio.
		$armed = null;

		$spy = static function ( $product ) use ( &$armed ): void {
			$armed = Guard::consume( $product->get_id() );
		};

		add_action( 'woocommerce_product_set_stock', $spy );
		Ledger::apply( $this->incoming( 789, -2 ) );
		remove_action( 'woocommerce_product_set_stock', $spy );

		$this->assertTrue( $armed, 'el guard tiene que estar puesto cuando el stock cambia' );
	}

	/**
	 * Una devolución suma. Con un solo test de −2, usar siempre 'decrease' pasaría igual.
	 *
	 * LED-18
	 */
	public function test_a_positive_delta_adds_stock(): void {
		$product_id = $this->create_product( 10 );
		$this->link_product( $product_id, 789, 77 );

		$this->assertSame( 'applied', Ledger::apply( $this->incoming( 789, 2 ) ) );
		$this->assertSame( 12, $this->stock_of( $product_id ) );
	}

	/**
	 * Si el INSERT falla de verdad —un error de la base, o un campo más largo que su
	 * columna— no se puede confirmar: con un ACK el emisor deja de reintentar y el
	 * movimiento se pierde en las dos tiendas (ADR-001 §3).
	 *
	 * LED-19
	 */
	public function test_a_failed_insert_is_not_confirmable(): void {
		global $wpdb;

		$product_id = $this->create_product( 10 );
		$this->link_product( $product_id, 789, 77 );

		$table = Installer::table( 'movements' );

		$break_the_insert = static function ( string $query ) use ( $table ): string {
			if ( 0 === stripos( ltrim( $query ), 'INSERT' ) && str_contains( $query, $table ) ) {
				return 'INSERT INTO wss_no_existe (id) VALUES (1)';
			}

			return $query;
		};

		add_filter( 'query', $break_the_insert );
		$wpdb->suppress_errors( true );

		$state = Ledger::apply( $this->incoming( 789, -2 ) );

		$wpdb->suppress_errors( false );
		remove_filter( 'query', $break_the_insert );

		$this->assertNull( $state, 'sin fila no hay nada que confirmar' );
		$this->assertSame( 10, $this->stock_of( $product_id ) );
		$this->assertSame( 0, $this->count_movements() );
	}

	/**
	 * La papelera deja el par suspendido (UC-03), y `wc_get_product()` devuelve el
	 * producto igual: hay que mirar el estado.
	 *
	 * LED-20
	 */
	public function test_a_movement_for_a_trashed_product_is_held(): void {
		$product_id = $this->create_product( 10 );
		$this->link_product( $product_id, 789, 77 );

		wp_trash_post( $product_id );

		$this->assertSame( 'held', Ledger::apply( $this->incoming( 789, -2 ) ) );
		$this->assertSame( 10, $this->stock_of( $product_id ) );
	}

	/**
	 * Escribe un movimiento propio ya entregable, saltándose la espera de `record()`.
	 *
	 * @param array<string, mixed> $overrides Columnas a pisar.
	 */
	private function pending_movement( array $overrides = array() ): int {
		global $wpdb;

		$wpdb->insert(
			Installer::table( 'movements' ),
			array_merge(
				array(
					'origin_store'    => Store::id(),
					'origin_seq'      => ++$this->next_seq,
					'generation'      => 1,
					'delta'           => -1,
					'reason'          => 'sale',
					'created_at'      => gmdate( 'Y-m-d H:i:s', time() - 60 ),
					'origin_product'  => self::PRODUCT,
					'delivery'        => 'pending',
					'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 30 ),
				),
				$overrides
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Una columna de un movimiento.
	 *
	 * @param int    $movement_id ID del movimiento.
	 * @param string $column      Columna.
	 */
	private function column_of( int $movement_id, string $column ): ?string {
		$movement = $this->movement( $movement_id );

		return null === $movement ? null : ( null === $movement->$column ? null : (string) $movement->$column );
	}

	/**
	 * LED-22 — Reclamar devuelve las filas pendientes y las marca en vuelo.
	 */
	public function test_claiming_takes_the_pending_rows(): void {
		$first  = $this->pending_movement();
		$second = $this->pending_movement();

		$claimed = Ledger::claim();

		$this->assertCount( 2, $claimed );
		$this->assertSame( 'in_flight', $this->column_of( $first, 'delivery' ) );
		$this->assertNotNull( $this->column_of( $second, 'claimed_by' ) );
	}

	/**
	 * LED-23 — Lo que todavía no le toca no se reclama.
	 */
	public function test_it_does_not_claim_what_is_not_due_yet(): void {
		$this->pending_movement( array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ) );

		$this->assertCount( 0, Ledger::claim() );
	}

	/**
	 * Dos pasadas no pueden llevarse las mismas filas. El token lo genera `claim()`, así
	 * que no depende de que quien llama elija bien: con un identificador fijo por sitio,
	 * la segunda pasada se llevaría también lo que la primera está enviando.
	 *
	 * LED-24
	 */
	public function test_two_passes_never_take_the_same_rows(): void {
		$this->pending_movement();
		$this->pending_movement();
		$this->pending_movement();

		$first  = Ledger::claim( 2 );
		$second = Ledger::claim( 2 );

		$this->assertCount( 2, $first );
		$this->assertCount( 1, $second );

		$ids = static fn( array $rows ): array => array_map( static fn( $row ): int => (int) $row->id, $rows );

		$this->assertSame( array(), array_intersect( $ids( $first ), $ids( $second ) ) );
	}

	/**
	 * Un worker que murió a mitad deja filas en vuelo para siempre: pasado el plazo
	 * vuelven a estar disponibles (ADR-001 §2).
	 *
	 * LED-25
	 */
	public function test_it_releases_a_stale_claim(): void {
		$movement = $this->pending_movement(
			array(
				'delivery'   => 'in_flight',
				'claimed_by' => 'worker-muerto',
				'claimed_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ),
			)
		);

		global $wpdb;

		// Con la zona corrida: si el paso 1 comparara contra NOW(), este claim muerto
		// tardaría tres horas de más en liberarse.
		$wpdb->query( "SET time_zone = '-03:00'" ); // phpcs:ignore WordPress.DB

		$claimed = Ledger::claim();

		$wpdb->query( "SET time_zone = '+00:00'" ); // phpcs:ignore WordPress.DB

		$this->assertCount( 1, $claimed );
		$this->assertNotSame( 'worker-muerto', $this->column_of( $movement, 'claimed_by' ) );
	}

	/**
	 * LED-26 — Un claim reciente de otro worker no se toca.
	 */
	public function test_it_leaves_a_fresh_claim_alone(): void {
		$this->pending_movement(
			array(
				'delivery'   => 'in_flight',
				'claimed_by' => 'worker-vivo',
				'claimed_at' => gmdate( 'Y-m-d H:i:s', time() - 30 ),
			)
		);

		$this->assertCount( 0, Ledger::claim() );
	}

	/**
	 * LED-27 — El lote respeta el tope que le pasan.
	 */
	public function test_it_respects_the_batch_size(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->pending_movement();
		}

		$this->assertCount( 3, Ledger::claim( 3 ) );
	}

	/**
	 * Las fechas se guardan en UTC y `NOW()` devuelve la hora del servidor MySQL en su
	 * zona: con el servidor en otra zona, comparar contra `NOW()` entrega tarde o
	 * temprano. El SQL tiene que usar `UTC_TIMESTAMP()`.
	 *
	 * LED-28
	 */
	public function test_it_does_not_depend_on_the_server_timezone(): void {
		global $wpdb;

		$this->pending_movement();

		$wpdb->query( "SET time_zone = '-03:00'" ); // phpcs:ignore WordPress.DB

		$claimed = Ledger::claim();

		$wpdb->query( "SET time_zone = '+00:00'" ); // phpcs:ignore WordPress.DB

		$this->assertCount( 1, $claimed, 'la zona del servidor no puede cambiar qué se entrega' );
	}

	/**
	 * El intento es el claim, no el ACK: si el worker muere, `ack()` no corre nunca y el
	 * techo de 6 horas no avanzaría jamás (ADR-001 §2).
	 *
	 * LED-29
	 */
	public function test_claiming_counts_as_an_attempt(): void {
		$movement = $this->pending_movement();

		Ledger::claim();

		$this->assertSame( '1', $this->column_of( $movement, 'attempts' ) );
		$this->assertNotNull( $this->column_of( $movement, 'first_attempt_at' ) );
	}

	/**
	 * LED-30 — La hora del primer intento no se pisa en los reintentos siguientes.
	 */
	public function test_the_first_attempt_is_not_overwritten(): void {
		$movement = $this->pending_movement(
			array(
				'attempts'         => 3,
				'first_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
			)
		);

		$first_attempt = $this->column_of( $movement, 'first_attempt_at' );

		Ledger::claim();

		$this->assertSame( $first_attempt, $this->column_of( $movement, 'first_attempt_at' ) );
		$this->assertSame( '4', $this->column_of( $movement, 'attempts' ) );
	}

	/**
	 * LED-31 — Una fila confirmada queda confirmada, con su hora y sin claim.
	 */
	public function test_a_confirmed_row_is_closed(): void {
		$movement = $this->pending_movement();
		$claimed  = Ledger::claim();

		Ledger::ack( (string) $claimed[0]->claimed_by, array( $movement => true ) );

		$this->assertSame( 'confirmed', $this->column_of( $movement, 'delivery' ) );
		$this->assertNotNull( $this->column_of( $movement, 'confirmed_at' ) );
		$this->assertNull( $this->column_of( $movement, 'claimed_by' ) );
	}

	/**
	 * LED-32 — Una fila que falló vuelve a la cola, con el próximo intento más adelante.
	 */
	public function test_a_failed_row_goes_back_to_the_queue(): void {
		$movement = $this->pending_movement();
		$claimed  = Ledger::claim();

		Ledger::ack( (string) $claimed[0]->claimed_by, array( $movement => 'cURL 28: timeout' ) );

		$this->assertSame( 'pending', $this->column_of( $movement, 'delivery' ) );
		$this->assertSame( 'cURL 28: timeout', $this->column_of( $movement, 'last_error' ) );
		$this->assertNull( $this->column_of( $movement, 'claimed_by' ) );
		$this->assertGreaterThan(
			gmdate( 'Y-m-d H:i:s' ),
			(string) $this->column_of( $movement, 'next_attempt_at' )
		);
	}

	/**
	 * La espera crece con los intentos. Mirar sólo que sea "más adelante" pasaría igual
	 * con un backoff fijo, que es lo que el ADR no quiere: sin curva, una tienda caída
	 * recibiría un intento por minuto durante seis horas.
	 *
	 * LED-43
	 */
	public function test_the_wait_grows_between_failures(): void {
		global $wpdb;

		$movement = $this->pending_movement();

		$waits = array();

		for ( $failure = 1; $failure <= 2; $failure++ ) {
			$claimed = Ledger::claim();

			$before = time();
			Ledger::ack( (string) $claimed[0]->claimed_by, array( $movement => 'la tienda no responde' ) );

			$waits[] = strtotime( (string) $this->column_of( $movement, 'next_attempt_at' ) ) - $before;

			// Adelantar el reloj de la fila para poder reclamarla otra vez.
			$wpdb->update( // phpcs:ignore WordPress.DB
				Installer::table( 'movements' ),
				array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 10 ) ),
				array( 'id' => $movement )
			);
		}

		$this->assertEqualsWithDelta( Ledger::backoff_for( 1 ), $waits[0], 2 );
		$this->assertEqualsWithDelta( Ledger::backoff_for( 2 ), $waits[1], 2 );
		$this->assertGreaterThan( $waits[0], $waits[1] );
	}

	/**
	 * La columna son 255 caracteres: un motivo más largo haría fallar el UPDATE entero y
	 * la fila quedaría en vuelo, sin nadie que la libere hasta que venza el claim.
	 *
	 * LED-41
	 */
	public function test_a_long_error_is_truncated(): void {
		$movement = $this->pending_movement();
		$claimed  = Ledger::claim();

		Ledger::ack( (string) $claimed[0]->claimed_by, array( $movement => str_repeat( 'x', 400 ) ) );

		$this->assertSame( 255, strlen( (string) $this->column_of( $movement, 'last_error' ) ) );
		$this->assertSame( 'pending', $this->column_of( $movement, 'delivery' ) );
	}

	/**
	 * LED-42 — Al confirmarse, el último error queda como historia.
	 */
	public function test_the_last_error_survives_a_later_confirmation(): void {
		$movement = $this->pending_movement();

		$claimed = Ledger::claim();
		Ledger::ack( (string) $claimed[0]->claimed_by, array( $movement => 'la tienda no responde' ) );

		$this->pending_movement( array( 'id' => null ) );
		global $wpdb;
		$wpdb->update( Installer::table( 'movements' ), array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 10 ) ), array( 'id' => $movement ) );

		$claimed = Ledger::claim();
		Ledger::ack( (string) $claimed[0]->claimed_by, array( $movement => true ) );

		$this->assertSame( 'confirmed', $this->column_of( $movement, 'delivery' ) );
		$this->assertSame( 'la tienda no responde', $this->column_of( $movement, 'last_error' ) );
	}

	/**
	 * LED-33 — El backoff crece con los intentos y no pasa del tope.
	 */
	public function test_the_backoff_grows_and_is_capped(): void {
		$this->assertSame( 60, Ledger::backoff_for( 1 ) );
		$this->assertSame( 120, Ledger::backoff_for( 2 ) );
		$this->assertSame( 960, Ledger::backoff_for( 5 ) );
		$this->assertSame( Ledger::MAX_BACKOFF, Ledger::backoff_for( 20 ) );
	}

	/**
	 * Toda fila vuelve a pasar por `claim()`, muera quien muera, así que el techo se
	 * aplica ahí: en `ack()` no alcanzaría porque un worker muerto nunca lo llama
	 * (ADR-001 §2).
	 *
	 * LED-34
	 */
	public function test_claiming_abandons_what_passed_the_ceiling(): void {
		$movement = $this->pending_movement(
			array(
				'attempts'         => 12,
				'first_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 7 * HOUR_IN_SECONDS ),
			)
		);

		$claimed = Ledger::claim();

		$this->assertCount( 0, $claimed, 'no se entrega lo que ya pasó el techo' );
		$this->assertSame( 'abandoned', $this->column_of( $movement, 'delivery' ) );
	}

	/**
	 * Se abandona lo que está esperando, nunca lo que está en camino: una fila al borde
	 * del techo que otra pasada está enviando no se puede cancelar, o su ACK llega a una
	 * fila abandonada y la reconciliación la reenvía sin necesidad.
	 *
	 * LED-39
	 */
	public function test_it_does_not_abandon_what_is_being_delivered(): void {
		$movement = $this->pending_movement(
			array(
				'delivery'         => 'in_flight',
				'claimed_by'       => 'worker-vivo',
				'claimed_at'       => gmdate( 'Y-m-d H:i:s', time() - 30 ),
				'attempts'         => 12,
				'first_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 7 * HOUR_IN_SECONDS ),
			)
		);

		Ledger::claim();

		$this->assertSame( 'in_flight', $this->column_of( $movement, 'delivery' ) );
	}

	/**
	 * Y el worker muerto sí llega a abandonarse, en la misma pasada: el paso que libera
	 * corre antes que el que abandona.
	 *
	 * LED-40
	 */
	public function test_a_dead_workers_row_is_abandoned_in_the_same_pass(): void {
		$movement = $this->pending_movement(
			array(
				'delivery'         => 'in_flight',
				'claimed_by'       => 'worker-muerto',
				'claimed_at'       => gmdate( 'Y-m-d H:i:s', time() - 600 ),
				'attempts'         => 12,
				'first_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 7 * HOUR_IN_SECONDS ),
			)
		);

		Ledger::claim();

		$this->assertSame( 'abandoned', $this->column_of( $movement, 'delivery' ) );
	}

	/**
	 * LED-35 — Lo que todavía está dentro del techo se sigue reclamando.
	 */
	public function test_it_still_claims_what_is_inside_the_ceiling(): void {
		$this->pending_movement(
			array(
				'attempts'         => 12,
				'first_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 5 * HOUR_IN_SECONDS ),
			)
		);

		$this->assertCount( 1, Ledger::claim() );
	}

	/**
	 * LED-36 — Un id que no está en el libro no rompe nada.
	 */
	public function test_acking_an_unknown_id_does_nothing(): void {
		Ledger::ack( 'un-token', array( 999999 => true ) );

		$this->assertSame( 0, $this->count_movements() );
	}

	/**
	 * Un worker lento puede anotar su resultado después de que su claim venció y otra
	 * pasada se llevó la fila: la escritura tiene que no encontrarla.
	 *
	 * LED-37
	 */
	public function test_an_ack_from_another_worker_changes_nothing(): void {
		$movement = $this->pending_movement();
		$claimed  = Ledger::claim();

		Ledger::ack( 'token-de-otra-pasada', array( $movement => 'falló' ) );

		$this->assertSame( 'in_flight', $this->column_of( $movement, 'delivery' ) );
		$this->assertSame( (string) $claimed[0]->claimed_by, $this->column_of( $movement, 'claimed_by' ) );
	}

	/**
	 * Una fila recibida no tiene entrega que anotar: los dos ejes existen justamente para
	 * eso, y un ack con el id equivocado la metería en la cola de salida (ADR-001 §3).
	 *
	 * LED-38
	 */
	public function test_an_ack_never_touches_a_received_movement(): void {
		$product_id = $this->create_product( 10 );
		$this->link_product( $product_id, 789, 77 );
		Ledger::apply( $this->incoming( 789, -2 ) );

		global $wpdb;
		$table    = Installer::table( 'movements' );
		$received = (int) $wpdb->get_var( "SELECT id FROM {$table} LIMIT 1" ); // phpcs:ignore WordPress.DB

		Ledger::ack( 'un-token', array( $received => 'falló' ) );

		$this->assertNull( $this->column_of( $received, 'delivery' ) );
	}

	/**
	 * Escribe un movimiento viejo y terminado, listo para purgar.
	 *
	 * @param array<string, mixed> $overrides Columnas a pisar.
	 */
	private function old_movement( array $overrides = array() ): int {
		return $this->pending_movement(
			array_merge(
				array(
					'created_at'      => gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS ),
					'delivery'        => 'confirmed',
					'confirmed_at'    => gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS ),
					'next_attempt_at' => null,
					'link_id'         => $this->link_id,
					// La del vínculo que arma set_up: sólo se consolida la generación
					// vigente, así que un movimiento de otra no cuenta.
					'generation'      => 77,
				),
				$overrides
			)
		);
	}

	/**
	 * El stock base de un vínculo.
	 *
	 * @param int $link_id Vínculo.
	 */
	private function base_stock_of( int $link_id ): int {
		global $wpdb;

		$table = Installer::table( 'links' );

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT base_stock FROM {$table} WHERE id = %d", $link_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * LED-44 — Lo terminado y viejo se purga.
	 */
	public function test_it_purges_what_is_finished_and_old(): void {
		$this->old_movement();
		$this->old_movement();

		$this->assertSame( 2, Ledger::purge() );
		$this->assertSame( 0, $this->count_movements() );
	}

	/**
	 * LED-45 — Lo reciente no se toca, por más terminado que esté.
	 */
	public function test_it_leaves_recent_movements_alone(): void {
		$this->old_movement( array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ) );

		$this->assertSame( 0, Ledger::purge() );
	}

	/**
	 * Lo que no llegó no se purga: borrarlo le quitaría a la reconciliación la única
	 * forma de repararlo, y el desvío quedaría permanente y silencioso (§3 del SPEC).
	 *
	 * LED-46
	 */
	public function test_it_never_purges_what_is_not_finished(): void {
		$this->old_movement( array( 'delivery' => 'abandoned' ) );
		$this->old_movement( array( 'delivery' => 'pending' ) );
		$this->old_movement(
			array(
				'delivery'     => null,
				'confirmed_at' => null,
				'application'  => 'held',
			)
		);

		$this->assertSame( 0, Ledger::purge() );
		$this->assertSame( 3, $this->count_movements() );
	}

	/**
	 * Al purgar, el stock base absorbe la suma de lo purgado: si no, la fórmula
	 * `base + movimientos` deja de dar el mismo número y la fase 2 reportaría
	 * discrepancias falsas en todos los productos con historia (ADR-004 §4).
	 *
	 * LED-47
	 */
	public function test_the_base_stock_absorbs_what_was_purged(): void {
		// Deltas que NO suman cero: con −2, −1 y +3 el test pasaba igual sin consolidar
		// nada, porque el resultado esperado era el mismo número de antes.
		$this->old_movement( array( 'delta' => -2 ) );
		$this->old_movement( array( 'delta' => -1 ) );

		$base_before = $this->base_stock_of( $this->link_id );

		Ledger::purge();

		$this->assertSame( $base_before - 3, $this->base_stock_of( $this->link_id ) );
	}

	/**
	 * Si un re-bootstrap reutiliza la fila del vínculo en vez de borrarla, el stock base
	 * nuevo ya absorbió lo de antes: sumarle los movimientos viejos lo correría dos veces
	 * y la fase 2 vería un desvío falso.
	 *
	 * LED-50
	 */
	public function test_it_only_consolidates_the_current_generation(): void {
		$this->old_movement(
			array(
				'delta'      => -5,
				'generation' => 1,
			)
		);

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB
			Installer::table( 'links' ),
			array( 'generation' => 2 ),
			array( 'id' => $this->link_id )
		);

		$base_before = $this->base_stock_of( $this->link_id );

		$this->assertSame( 1, Ledger::purge() );
		$this->assertSame( $base_before, $this->base_stock_of( $this->link_id ) );
	}

	/**
	 * La marca se escribe adentro de la transacción: si el proceso muriera entre el
	 * COMMIT y la marca, las filas ya no estarían y la fase 1 las volvería a ofrecer.
	 *
	 * LED-51
	 */
	public function test_a_failed_purge_leaves_everything_as_it_was(): void {
		global $wpdb;

		$this->old_movement(
			array(
				'delta'        => -5,
				'origin_store' => self::REMOTE_STORE,
				'origin_seq'   => 4120,
				'delivery'     => null,
				'confirmed_at' => null,
				'application'  => 'applied',
			)
		);

		$base_before = $this->base_stock_of( $this->link_id );

		$break_the_delete = static function ( string $query ): string {
			return 0 === stripos( ltrim( $query ), 'DELETE' ) ? 'DELETE FROM wss_no_existe' : $query;
		};

		add_filter( 'query', $break_the_delete );
		$wpdb->suppress_errors( true );

		$purged = Ledger::purge();

		$wpdb->suppress_errors( false );
		remove_filter( 'query', $break_the_delete );

		$this->assertSame( 0, $purged );
		$this->assertSame( 1, $this->count_movements(), 'la fila sigue en el libro' );
		$this->assertSame( $base_before, $this->base_stock_of( $this->link_id ), 'el stock base no se movió' );

		$peers = (array) get_option( 'wss_peers', array() );
		$this->assertSame( 0, (int) ( $peers[ self::REMOTE_STORE ]['purged_through'] ?? 0 ) );
	}

	/**
	 * Una recibida descartada nunca tocó el stock: consolidarla correría el stock base
	 * justo por los movimientos que decidimos no aplicar.
	 *
	 * LED-48
	 */
	public function test_a_discarded_movement_is_not_consolidated(): void {
		$this->old_movement(
			array(
				'delta'        => -5,
				'delivery'     => null,
				'confirmed_at' => null,
				'application'  => 'discarded',
			)
		);

		$base_before = $this->base_stock_of( $this->link_id );

		$this->assertSame( 1, Ledger::purge() );
		$this->assertSame( $base_before, $this->base_stock_of( $this->link_id ) );
	}

	/**
	 * Purgar una fila recibida borra el registro que la deduplica: sin la marca, la
	 * fase 1 se la vuelve a ofrecer y se aplicaría dos veces (ADR-004 §4).
	 *
	 * LED-49
	 */
	public function test_it_records_how_far_it_purged_for_each_store(): void {
		$this->old_movement(
			array(
				'origin_store' => self::REMOTE_STORE,
				'origin_seq'   => 4120,
				'delivery'     => null,
				'confirmed_at' => null,
				'application'  => 'applied',
			)
		);

		Ledger::purge();

		$peers = (array) get_option( 'wss_peers', array() );

		$this->assertSame( 4120, (int) ( $peers[ self::REMOTE_STORE ]['purged_through'] ?? 0 ) );
	}

	/**
	 * LED-01 — Un cambio de un producto vinculado escribe una fila con sus datos.
	 */
	public function test_it_writes_a_row_for_a_linked_product(): void {
		$movement_id = Ledger::record( self::PRODUCT, -2, 'sale', 'pedido #4512' );

		$this->assertIsInt( $movement_id );

		$movement = $this->movement( $movement_id );

		$this->assertSame( Store::id(), $movement->origin_store );
		$this->assertSame( -2, (int) $movement->delta );
		$this->assertSame( self::PRODUCT, (int) $movement->origin_product );
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
		$movement_id = Ledger::record( self::PRODUCT, -1 );

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
		Ledger::record( self::PRODUCT, -1 );
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
		$movement_id = Ledger::record( self::PRODUCT, -1 );

		$this->assertSame( 77, (int) $this->movement( $movement_id )->generation );
	}

	/**
	 * LED-04 — Nace pendiente de entrega y sin intentos.
	 */
	public function test_it_is_born_pending_with_no_attempts(): void {
		$movement = $this->movement( (int) Ledger::record( self::PRODUCT, -1 ) );

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
		$movement = $this->movement( (int) Ledger::record( self::PRODUCT, -1 ) );

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
		$movement = $this->movement( (int) Ledger::record( self::PRODUCT, 5 ) );

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

		$result = Ledger::record( self::PRODUCT, -1 );

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
		$this->assertNull( Ledger::record( self::PRODUCT, 0 ) );
		$this->assertSame( 0, $this->count_movements() );
	}

	/**
	 * LED-52 — Estampar completa el motivo y la referencia de un movimiento pendiente.
	 */
	public function test_stamping_completes_a_pending_movement(): void {
		$movement_id = (int) Ledger::record( self::PRODUCT, -2 );

		Ledger::stamp( $movement_id, 'sale', 'pedido #4512' );

		$this->assertSame( 'sale', $this->movement( $movement_id )->reason );
		$this->assertSame( 'pedido #4512', $this->movement( $movement_id )->reference );
	}

	/**
	 * Un movimiento que la cola ya se llevó viaja con el motivo que tenía: cambiarlo acá
	 * dejaría las dos tiendas con historiales distintos.
	 *
	 * LED-53
	 */
	public function test_stamping_leaves_a_movement_in_flight_alone(): void {
		global $wpdb;

		$movement_id = (int) Ledger::record( self::PRODUCT, -2 );
		$wpdb->update( Installer::table( 'movements' ), array( 'delivery' => 'in_flight' ), array( 'id' => $movement_id ) ); // phpcs:ignore WordPress.DB

		Ledger::stamp( $movement_id, 'sale', 'pedido #4512' );

		$this->assertSame( 'adjustment', $this->movement( $movement_id )->reason );
		$this->assertNull( $this->movement( $movement_id )->reference );
	}

	/**
	 * LED-54 — Un movimiento recibido no es nuestro: no se estampa.
	 */
	public function test_stamping_leaves_a_received_movement_alone(): void {
		$product_id = $this->create_product( 10 );
		$this->link_product( $product_id, 789, 77 );

		global $wpdb;

		Ledger::apply(
			$this->incoming(
				789,
				-2,
				array(
					'reason'    => 'adjustment',
					'reference' => null,
				)
			)
		);

		$table       = Installer::table( 'movements' );
		$movement_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE origin_store = %s", self::REMOTE_STORE ) ); // phpcs:ignore WordPress.DB

		Ledger::stamp( $movement_id, 'sale', 'pedido #4512' );

		$this->assertSame( 'adjustment', $this->movement( $movement_id )->reason );
	}

	/**
	 * Un número de pedido filtrado a algo más largo que la columna: sin recortarlo, el
	 * UPDATE falla entero y la fila se queda sin el motivo.
	 *
	 * LED-55
	 */
	public function test_a_long_reference_is_truncated(): void {
		$movement_id = (int) Ledger::record( self::PRODUCT, -2 );

		Ledger::stamp( $movement_id, 'sale', str_repeat( 'x', 150 ) );

		$this->assertSame( 'sale', $this->movement( $movement_id )->reason );
		$this->assertSame( str_repeat( 'x', 100 ), $this->movement( $movement_id )->reference );
	}

	/**
	 * Un hook de terceros que falla adentro de `apply()` —un `TypeError`, que no es una
	 * `Exception`— no deja nada a medias: ni la fila, ni el stock, ni la caché con el stock
	 * deshecho, ni la anotación del guard.
	 *
	 * LED-56
	 */
	public function test_a_failing_hook_leaves_nothing_behind(): void {
		$product_id = $this->create_product( 10 );
		$this->link_product( $product_id, 789, 77 );

		$break = static function (): void {
			throw new TypeError( 'un plugin roto' );
		};

		add_action( 'woocommerce_product_set_stock', $break );
		$state = Ledger::apply( $this->incoming( 789, -2 ) );
		remove_action( 'woocommerce_product_set_stock', $break );

		$this->assertNull( $state, 'sin fila no hay nada que confirmar' );
		$this->assertSame( 10, $this->stock_of( $product_id ), 'el stock de la base' );
		$this->assertSame( 10, wc_get_product( $product_id )->get_stock_quantity(), 'el stock que ve WooCommerce, de la caché' );
		$this->assertFalse( Guard::consume( $product_id ), 'la anotación del guard' );

		// Y la transacción se cerró: el próximo movimiento se aplica y queda.
		$this->assertSame( 'applied', Ledger::apply( $this->incoming( 789, -1, array( 'seq' => 78 ) ) ) );
		$this->assertSame( 9, $this->stock_of( $product_id ) );
	}

	/**
	 * Mientras `apply()` cambia el stock de un producto, un hook de terceros cambia el de
	 * otro vinculado. El detector registra ese cambio adentro de la transacción de
	 * `apply()`, y si algo falla después, se deshace todo junto: el movimiento, el stock
	 * del tercero y la fila recibida. Con un `START TRANSACTION` adentro, la fila recibida y
	 * el movimiento del tercero habrían quedado confirmados.
	 *
	 * LED-57
	 */
	public function test_a_nested_movement_rolls_back_with_apply(): void {
		$applied = $this->create_product( 10 );
		$other   = $this->create_product( 20 );
		$this->link_product( $applied, 789, 77 );
		$this->link_product( $other, 790, 77 );

		Detector::register();

		$nested = static function ( $product ) use ( $applied, $other ): void {
			if ( $product->get_id() === $applied ) {
				wc_update_product_stock( $other, 1, 'decrease' );

				throw new RuntimeException( 'falla después del cambio anidado' );
			}
		};

		add_action( 'woocommerce_product_set_stock', $nested, 20 );
		$state = Ledger::apply( $this->incoming( 789, -2 ) );
		remove_action( 'woocommerce_product_set_stock', $nested, 20 );

		wss_unhook_detector();

		$this->assertNull( $state );
		$this->assertSame( 0, $this->count_movements(), 'ni la fila recibida ni el movimiento del tercero' );
		$this->assertSame( 20, $this->stock_of( $other ) );
		$this->assertSame( 10, $this->stock_of( $applied ) );
	}

	/**
	 * El mismo cambio anidado, sin falla: el movimiento del tercero se confirma con
	 * `apply()`.
	 *
	 * LED-58
	 */
	public function test_a_nested_movement_commits_with_apply(): void {
		$applied = $this->create_product( 10 );
		$other   = $this->create_product( 20 );
		$this->link_product( $applied, 789, 77 );
		$this->link_product( $other, 790, 77 );

		Detector::register();

		$nested = static function ( $product ) use ( $applied, $other ): void {
			if ( $product->get_id() === $applied ) {
				wc_update_product_stock( $other, 1, 'decrease' );
			}
		};

		add_action( 'woocommerce_product_set_stock', $nested, 20 );
		$state = Ledger::apply( $this->incoming( 789, -2 ) );
		remove_action( 'woocommerce_product_set_stock', $nested, 20 );

		wss_unhook_detector();

		global $wpdb;
		$table = Installer::table( 'movements' );
		$own   = $wpdb->get_results( $wpdb->prepare( "SELECT origin_product, delta FROM {$table} WHERE origin_store = %s", Store::id() ) ); // phpcs:ignore WordPress.DB

		$this->assertSame( 'applied', $state );
		$this->assertCount( 1, $own );
		$this->assertSame( $other, (int) $own[0]->origin_product );
		$this->assertSame( -1, (int) $own[0]->delta );
		$this->assertSame( 8, $this->stock_of( $applied ) );
	}

	/**
	 * Si el `record()` anidado falla, deshace sólo lo suyo. Con un `ROLLBACK` pelado
	 * cerraría la transacción de `apply()` y la fila recibida se perdería.
	 *
	 * LED-59
	 */
	public function test_a_failing_nested_record_does_not_close_apply(): void {
		$applied = $this->create_product( 10 );
		$other   = $this->create_product( 20 );
		$this->link_product( $applied, 789, 77 );
		$this->link_product( $other, 790, 77 );

		Detector::register();

		$table = Installer::table( 'movements' );

		// Rompe el UPDATE que completa la secuencia del movimiento propio.
		$break_the_update = static function ( string $query ) use ( $table ): string {
			if ( str_starts_with( $query, "UPDATE `{$table}` SET `origin_seq`" ) ) {
				return "UPDATE `{$table}` SET `no_such_column` = 1";
			}

			return $query;
		};

		$nested = static function ( $product ) use ( $applied, $other ): void {
			if ( $product->get_id() === $applied ) {
				wc_update_product_stock( $other, 1, 'decrease' );
			}
		};

		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );

		add_filter( 'query', $break_the_update );
		add_action( 'woocommerce_product_set_stock', $nested, 20 );
		$state = Ledger::apply( $this->incoming( 789, -2 ) );
		remove_action( 'woocommerce_product_set_stock', $nested, 20 );
		remove_filter( 'query', $break_the_update );

		$wpdb->suppress_errors( $suppress );
		wss_unhook_detector();

		$this->assertSame( 'applied', $state );
		$this->assertSame( 1, $this->count_movements(), 'queda la fila recibida, sin el movimiento del tercero' );
		$this->assertSame( 8, $this->stock_of( $applied ) );
	}

	/**
	 * Una variación vinculada que dejó de gestionar su propio stock, mientras el padre sí lo
	 * gestiona: `managing_stock()` dice que sí —devuelve 'parent'—, pero el stock que
	 * cambiaría es el del padre, que es otro producto. El movimiento se retiene, y el padre
	 * no se toca.
	 *
	 * LED-60
	 */
	public function test_a_variation_that_takes_the_parent_stock_is_held(): void {
		$parent = new WC_Product_Variable();
		$parent->set_manage_stock( true );
		$parent->set_stock_quantity( 10 );
		$parent_id = (int) $parent->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation_id = (int) $variation->save();

		$this->link_product( $variation_id, 789, 77 );

		$this->assertSame( Ledger::HELD, Ledger::apply( $this->incoming( 789, -2 ) ) );
		$this->assertSame( 10, $this->stock_of( $parent_id ), 'el padre no se toca' );
	}

	/**
	 * LED-61 — **Uno recibido que se aplica guarda el stock de antes y el de después**; uno
	 * retenido, no: no cambió nada acá.
	 */
	public function test_an_applied_movement_keeps_the_stock_before_and_after(): void {
		global $wpdb;

		$product_id = $this->create_product( 10 );
		$this->link_product( $product_id, 789, 77 );

		Ledger::apply( $this->incoming( 789, -2 ) );

		$table = Installer::table( 'movements' );

		$this->assertSame(
			array(
				'stock_before' => 10,
				'stock_after'  => 8,
			),
			json_decode( (string) $wpdb->get_var( "SELECT details FROM {$table} WHERE origin_seq = 77" ), true ) // phpcs:ignore WordPress.DB
		);

		Ledger::apply( $this->incoming( 999, -1, array( 'seq' => 78 ) ) );

		$this->assertNull( $wpdb->get_var( "SELECT details FROM {$table} WHERE origin_seq = 78" ) ); // phpcs:ignore WordPress.DB
	}
}
