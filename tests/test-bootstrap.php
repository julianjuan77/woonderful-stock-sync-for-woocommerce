<?php
/**
 * Tests del bootstrap y el unlink en el libro y en el receptor.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Detector\Detector;
use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Ledger\Ledger;
use Woonderful\StockSync\Linking\Links;
use Woonderful\StockSync\Rest\Movements;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Peers;
use Woonderful\StockSync\Transport\Signature;

/**
 * Cómo se fija un par y cómo se deshace (ADR-005, ADR-008).
 */
class Test_Bootstrap extends WP_UnitTestCase {

	private const OTHER = 'b1b1b1b1-0000-4000-8000-000000000000';

	private const SECRET = 'secreto-de-prueba';

	/**
	 * Secuencias de la otra tienda.
	 *
	 * @var int
	 */
	private int $next_seq = 500;

	public function set_up(): void {
		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		// En el set_up y no en el tear_down: ver tests/TEST-CASES.md.
		delete_option( Peers::OPTION );

		Installer::install();
		Store::ensure_identity();
		wss_unhook_detector();

		$this->other_is( Peers::MASTER );
	}

	public function tear_down(): void {
		global $wpdb, $wp_rest_server;

		wss_unhook_detector();
		remove_action( 'rest_api_init', array( Movements::class, 'register_routes' ) );
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride

		foreach ( array( 'movements', 'links', 'nonces', 'events' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		parent::tear_down();
	}

	/**
	 * Empareja con la otra tienda, con el rol que se pida para ella.
	 *
	 * @param string $role El de la otra.
	 */
	private function other_is( string $role ): void {
		Peers::pair( self::OTHER, 'https://otra.test/wp-json/wss/v1', self::SECRET, 'Otra', $role );
	}

	/**
	 * Un producto que gestiona stock.
	 *
	 * @param int $stock Con cuánto arranca.
	 */
	private function product( int $stock ): int {
		$product = new WC_Product_Simple();
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );

		return (int) $product->save();
	}

	/**
	 * Un bootstrap como lo manda la maestra.
	 *
	 * @param int                  $target    El producto de esta tienda.
	 * @param int                  $base      El stock que fija.
	 * @param int                  $seen      El stock de esta tienda que vio el dueño.
	 * @param array<string, mixed> $overrides Campos a pisar.
	 *
	 * @return array<string, mixed>
	 */
	private function bootstrap( int $target, int $base, int $seen, array $overrides = array() ): array {
		$seq = $this->next_seq++;

		return array_merge(
			array(
				'origin'     => self::OTHER,
				'seq'        => $seq,
				'generation' => $seq,
				'delta'      => 0,
				'product'    => 900,
				'reason'     => 'bootstrap',
				'reference'  => 'vinculado por julian',
				'details'    => array(
					'target_product'        => $target,
					'base_stock'            => $base,
					'origin_previous_stock' => $base,
					'target_previous_stock' => $seen,
				),
			),
			$overrides
		);
	}

	/**
	 * Los campos de un bootstrap propio, hacia ese producto de la otra tienda.
	 *
	 * @param int $target El producto de la otra tienda.
	 *
	 * @return array<string, int>
	 */
	private function details( int $target ): array {
		return array(
			'target_product'        => $target,
			'base_stock'            => 10,
			'origin_previous_stock' => 10,
			'target_previous_stock' => 7,
		);
	}

	/**
	 * Las filas del libro.
	 *
	 * @return \stdClass[]
	 */
	private function rows(): array {
		global $wpdb;

		$table = Installer::table( 'movements' );

		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Un movimiento propio vencido su retardo, como lo dejaría el detector.
	 *
	 * @param int $movement_id ID.
	 */
	private function make_due( int $movement_id ): void {
		global $wpdb;

		$wpdb->update( Installer::table( 'movements' ), array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $movement_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Si `claim()` se lleva ese movimiento. Lo devuelve a la cola después de mirar.
	 *
	 * @param int $movement_id ID.
	 */
	private function claimable( int $movement_id ): bool {
		global $wpdb;

		$ids = array_map( static fn ( $row ) => (int) $row->id, Ledger::claim() );

		$wpdb->query( 'UPDATE ' . Installer::table( 'movements' ) . " SET delivery = 'pending', claimed_by = NULL WHERE delivery = 'in_flight'" ); // phpcs:ignore WordPress.DB

		return in_array( $movement_id, $ids, true );
	}

	/**
	 * La secundaria aplica el bootstrap: fija el stock de la maestra, arma el par al revés
	 * con la secuencia como generación, guarda los campos en `details`, y no emite eco.
	 *
	 * BOO-01
	 */
	public function test_the_secondary_applies_a_bootstrap(): void {
		$target    = $this->product( 7 );
		$bootstrap = $this->bootstrap( $target, 10, 7 );

		Detector::register();
		$state = Ledger::apply( $bootstrap );
		wss_unhook_detector();

		$link = Links::for_product( $target );
		$rows = $this->rows();

		$this->assertSame( Ledger::APPLIED, $state );
		$this->assertSame( 10, Detector::stock_in_db( $target ) );
		$this->assertSame( self::OTHER, $link->remote_store );
		$this->assertSame( 900, (int) $link->remote_product );
		$this->assertSame( $bootstrap['seq'], (int) $link->generation );
		$this->assertSame( 10, (int) $link->base_stock );
		$this->assertCount( 1, $rows, 'sin eco: ningún movimiento propio' );
		$this->assertSame( $bootstrap['details'], json_decode( $rows[0]->details, true ) );
	}

	/**
	 * Cada revisión de ADR-008 §3 rechaza sin escribir nada: ni la fila, ni el vínculo, ni el
	 * stock.
	 *
	 * BOO-02
	 */
	public function test_each_check_rejects_without_writing(): void {
		$free     = $this->product( 7 );
		$trashed  = $this->product( 7 );
		$unmanage = $this->product( 7 );

		wp_trash_post( $trashed );
		$product = wc_get_product( $unmanage );
		$product->set_manage_stock( false );
		$product->save();

		$cases = array(
			'stock_changed'             => $this->bootstrap( $free, 10, 6 ),
			'target_missing'            => $this->bootstrap( $trashed, 10, 7 ),
			'target_not_managing_stock' => $this->bootstrap( $unmanage, 10, 7 ),
		);

		foreach ( $cases as $reason => $bootstrap ) {
			$this->assertSame( $reason, Ledger::apply( $bootstrap ), $reason );
		}

		$this->other_is( Peers::SECONDARY );
		$this->assertSame( 'not_master', Ledger::apply( $this->bootstrap( $free, 10, 7 ) ) );

		$this->assertSame( array(), $this->rows() );
		$this->assertSame( 7, Detector::stock_in_db( $free ) );
		$this->assertNull( Links::for_product( $free ) );
	}

	/**
	 * El mismo bootstrap reenviado no vuelve a fijar el stock: si después se vendió, la venta
	 * queda.
	 *
	 * BOO-03
	 */
	public function test_a_resent_bootstrap_does_not_set_the_stock_again(): void {
		$target    = $this->product( 7 );
		$bootstrap = $this->bootstrap( $target, 10, 7 );

		Ledger::apply( $bootstrap );
		wc_update_product_stock( $target, 1, 'decrease' );

		$this->assertSame( Ledger::APPLIED, Ledger::apply( $bootstrap ) );
		$this->assertSame( 9, Detector::stock_in_db( $target ) );
	}

	/**
	 * Un `unlink` de la maestra, de la generación vigente, borra el par.
	 *
	 * BOO-04
	 */
	public function test_an_unlink_from_the_master_deletes_the_pair(): void {
		$target = $this->product( 7 );
		Links::create( $target, self::OTHER, 900, 77, 7 );

		$state = Ledger::apply(
			array(
				'origin'     => self::OTHER,
				'seq'        => 600,
				'generation' => 77,
				'delta'      => 0,
				'product'    => 900,
				'reason'     => 'unlink',
			)
		);

		$this->assertSame( Ledger::APPLIED, $state );
		$this->assertNull( Links::for_product( $target ) );
	}

	/**
	 * Un `unlink` de una generación vieja, o que no viene de la maestra, se descarta y se
	 * confirma: va por la cola, y un error se reintentaría 6 horas para nada.
	 *
	 * BOO-05
	 */
	public function test_an_old_or_foreign_unlink_is_discarded(): void {
		$target = $this->product( 7 );
		Links::create( $target, self::OTHER, 900, 77, 7 );

		$unlink = array(
			'origin'     => self::OTHER,
			'seq'        => 601,
			'generation' => 76,
			'delta'      => 0,
			'product'    => 900,
			'reason'     => 'unlink',
		);

		$this->assertSame( Ledger::DISCARDED, Ledger::apply( $unlink ) );

		$this->other_is( Peers::SECONDARY );
		$unlink['seq']        = 602;
		$unlink['generation'] = 77;

		$this->assertSame( Ledger::DISCARDED, Ledger::apply( $unlink ) );
		$this->assertNotNull( Links::for_product( $target ), 'el par sigue' );
	}

	/**
	 * Por el endpoint: un bootstrap sin sus campos es `invalid_bootstrap`; uno rechazado vuelve
	 * con su motivo; uno bueno, `received`. Y la consulta responde qué hay de cada uno.
	 *
	 * BOO-06
	 */
	public function test_the_endpoint_answers_bootstraps_and_queries(): void {
		global $wp_rest_server;

		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		add_action( 'rest_api_init', array( Movements::class, 'register_routes' ) );
		$server = rest_get_server();

		$target  = $this->product( 7 );
		$good    = $this->bootstrap( $target, 10, 7 );
		$changed = $this->bootstrap( $this->product( 7 ), 10, 3 );
		$broken  = $this->bootstrap( $this->product( 7 ), 10, 7 );

		$flatten = static fn ( array $m ) => array_merge( array_diff_key( $m, array( 'details' => 1 ) ), $m['details'] );
		$body    = (string) wp_json_encode(
			array(
				'movements' => array(
					$flatten( $good ),
					$flatten( $changed ),
					array_diff_key( $flatten( $broken ), array( 'base_stock' => 1 ) ),
				),
			)
		);

		$post = new WP_REST_Request( 'POST', '/wss/v1/movements' );
		$post->set_body( $body );
		foreach ( Signature::headers( self::OTHER, self::SECRET, 'POST', '/wss/v1/movements', array(), $body ) as $name => $value ) {
			$post->set_header( $name, $value );
		}

		$results = $server->dispatch( $post )->get_data()['results'];

		$this->assertSame( 'received', $results[0]['status'] );
		$this->assertSame( 'stock_changed', $results[1]['reason'] );
		$this->assertSame( 'invalid_bootstrap', $results[2]['reason'] );

		$expected_states = array(
			$good['seq']    => 'applied',
			$changed['seq'] => 'unknown',
		);

		foreach ( $expected_states as $seq => $expected ) {
			$route = '/wss/v1/movements/' . self::OTHER . '/' . $seq;
			$get   = new WP_REST_Request( 'GET', $route );
			foreach ( Signature::headers( self::OTHER, self::SECRET, 'GET', $route, array(), '' ) as $name => $value ) {
				$get->set_header( $name, $value );
			}

			$this->assertSame( $expected, $server->dispatch( $get )->get_data()['state'], "seq {$seq}" );
		}
	}

	/**
	 * Del lado de la maestra: el bootstrap y su vínculo se escriben juntos, con la secuencia
	 * como generación, y la fila nace `syncing`, que la cola no se lleva.
	 *
	 * BOO-07
	 */
	public function test_the_master_records_the_bootstrap_with_its_link(): void {
		$product = $this->product( 10 );

		$id = Ledger::record_bootstrap(
			$product,
			self::OTHER,
			array(
				'target_product'        => 900,
				'base_stock'            => 10,
				'origin_previous_stock' => 10,
				'target_previous_stock' => 7,
			),
			'vinculado por julian'
		);

		$link = Links::for_product( $product );
		$row  = $this->rows()[0];

		$this->assertSame( $id, (int) $link->generation );
		$this->assertSame( $id, (int) $row->origin_seq );
		$this->assertSame( Ledger::SYNCING, $row->delivery );
		$this->assertFalse( $this->claimable( $id ), 'el bootstrap no va por la cola' );
	}

	/**
	 * Una venta de esos segundos sale con la generación nueva, y espera mientras el bootstrap
	 * está en camino o incierto. Con `confirmed`, sale.
	 *
	 * BOO-08
	 */
	public function test_a_sale_waits_for_its_bootstrap(): void {
		$product = $this->product( 10 );
		$id      = Ledger::record_bootstrap( $product, self::OTHER, $this->details( 900 ), null );

		$sale = (int) Ledger::record( $product, -1, 'sale' );
		$this->make_due( $sale );

		$this->assertFalse( $this->claimable( $sale ), 'en camino' );

		Ledger::resolve_bootstrap( $id, Ledger::UNCERTAIN );
		$this->assertFalse( $this->claimable( $sale ), 'incierto' );

		Ledger::resolve_bootstrap( $id, 'confirmed' );
		$this->assertTrue( $this->claimable( $sale ), 'confirmado' );
	}

	/**
	 * Si el bootstrap falla, el vínculo se borra y la venta que esperaba sale: la otra tienda
	 * la descarta sin daño, porque no tiene par.
	 *
	 * BOO-09
	 */
	public function test_a_failed_bootstrap_deletes_the_link_and_frees_the_sale(): void {
		$product = $this->product( 10 );
		$id      = Ledger::record_bootstrap( $product, self::OTHER, $this->details( 900 ), null );
		$sale    = (int) Ledger::record( $product, -1, 'sale' );
		$this->make_due( $sale );

		Ledger::resolve_bootstrap( $id, 'failed', 'stock_changed' );

		$this->assertNull( Links::for_product( $product ) );
		$this->assertTrue( $this->claimable( $sale ) );
		$this->assertSame( 'stock_changed', $this->rows()[0]->last_error );
	}

	/**
	 * Se averiguan los `uncertain` y los `syncing` de un proceso que murió, pero recién pasado
	 * el tiempo de espera.
	 *
	 * BOO-10
	 */
	public function test_unresolved_bootstraps_wait_their_age(): void {
		global $wpdb;

		$old   = Ledger::record_bootstrap( $this->product( 10 ), self::OTHER, $this->details( 900 ), null );
		$young = Ledger::record_bootstrap( $this->product( 10 ), self::OTHER, $this->details( 901 ), null );
		$done  = Ledger::record_bootstrap( $this->product( 10 ), self::OTHER, $this->details( 902 ), null );

		$table = Installer::table( 'movements' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET created_at = %s WHERE id IN ( %d, %d )", gmdate( 'Y-m-d H:i:s', time() - 700 ), $old, $done ) ); // phpcs:ignore WordPress.DB
		Ledger::resolve_bootstrap( $done, 'confirmed' );

		$ids = array_map( static fn ( $row ) => (int) $row->id, Ledger::unresolved_bootstraps( 600 ) );

		$this->assertSame( array( $old ), $ids );
		$this->assertNotContains( $young, $ids );
	}

	/**
	 * El `unlink` se escribe con la generación del vínculo, va a la cola, y el vínculo se
	 * borra.
	 *
	 * BOO-11
	 */
	public function test_the_master_records_an_unlink(): void {
		$product = $this->product( 10 );
		Links::create( $product, self::OTHER, 900, 77, 10 );

		$id  = (int) Ledger::record_unlink( $product );
		$row = $this->rows()[0];

		$this->assertSame( 'unlink', $row->reason );
		$this->assertSame( 77, (int) $row->generation );
		$this->assertSame( 'pending', $row->delivery );
		$this->assertSame( $id, (int) $row->origin_seq );
		$this->assertNull( Links::for_product( $product ) );
	}

	/**
	 * Un vínculo que esta tienda recibió cuando era secundaria —su generación es la secuencia
	 * de la otra—, con el mismo número que un bootstrap propio. Escribe uno y su "gemelo".
	 *
	 * El gemelo se crea **antes**, así tiene el ID más bajo: buscar por generación lo
	 * encontraría primero.
	 *
	 * @return array{0: int, 1: int, 2: int} El bootstrap, el producto propio y el del gemelo.
	 */
	private function bootstrap_with_a_twin(): array {
		global $wpdb;

		$twin    = $this->product( 10 );
		$twin_id = (int) Links::create( $twin, self::OTHER, 950, 0, 10 );
		$product = $this->product( 10 );
		$id      = (int) Ledger::record_bootstrap( $product, self::OTHER, $this->details( 900 ), null );

		$wpdb->update( Installer::table( 'links' ), array( 'generation' => $id ), array( 'id' => $twin_id ) ); // phpcs:ignore WordPress.DB

		return array( $id, $product, $twin );
	}

	/**
	 * Si el bootstrap falla, se borra **su** vínculo y no el gemelo con la misma generación.
	 *
	 * BOO-12
	 */
	public function test_a_failed_bootstrap_deletes_only_its_own_link(): void {
		list( $id, $product, $twin ) = $this->bootstrap_with_a_twin();

		Ledger::resolve_bootstrap( $id, 'failed', 'stock_changed' );

		$this->assertNull( Links::for_product( $product ) );
		$this->assertNotNull( Links::for_product( $twin ), 'el vínculo ajeno sigue' );
	}

	/**
	 * Lo que retiene `claim()` es lo que cuelga de **ese** vínculo: una venta del gemelo, con
	 * la misma generación, sale.
	 *
	 * BOO-13
	 */
	public function test_only_sales_of_that_link_wait(): void {
		list( , $product, $twin ) = $this->bootstrap_with_a_twin();

		$own  = (int) Ledger::record( $product, -1, 'sale' );
		$alien = (int) Ledger::record( $twin, -1, 'sale' );
		$this->make_due( $own );
		$this->make_due( $alien );

		$this->assertFalse( $this->claimable( $own ), 'la del bootstrap espera' );
		$this->assertTrue( $this->claimable( $alien ), 'la del gemelo sale' );
	}

	/**
	 * Un bootstrap ya resuelto no se vuelve a resolver: dos pasadas a la vez no pisan un
	 * `confirmed` con un `uncertain`, ni uno `failed` le borra el vínculo a uno confirmado.
	 *
	 * BOO-14
	 */
	public function test_a_resolved_bootstrap_stays_resolved(): void {
		$product = $this->product( 10 );
		$id      = (int) Ledger::record_bootstrap( $product, self::OTHER, $this->details( 900 ), null );

		Ledger::resolve_bootstrap( $id, 'confirmed' );
		Ledger::resolve_bootstrap( $id, Ledger::UNCERTAIN );
		Ledger::resolve_bootstrap( $id, 'failed', 'tarde' );

		$this->assertSame( 'confirmed', $this->rows()[0]->delivery );
		$this->assertNotNull( Links::for_product( $product ) );
	}

	/**
	 * Las consultas que corren mientras se ejecuta algo, en orden.
	 *
	 * @param callable $run Lo que se observa.
	 *
	 * @return string[]
	 */
	private static function queries_during( callable $run ): array {
		$queries = array();
		$spy     = static function ( string $query ) use ( &$queries ): string {
			$queries[] = $query;

			return $query;
		};

		add_filter( 'query', $spy );
		$run();
		remove_filter( 'query', $spy );

		return $queries;
	}

	/**
	 * La posición de la primera consulta que contiene ese texto, o -1.
	 *
	 * @param string[] $queries Las consultas.
	 * @param string   $needle  El texto.
	 */
	private static function position_of( array $queries, string $needle ): int {
		foreach ( $queries as $position => $query ) {
			if ( false !== stripos( $query, $needle ) ) {
				return $position;
			}
		}

		return -1;
	}

	/**
	 * Revisar el stock y escribir van en el mismo paso: el `FOR UPDATE` sale adentro de la
	 * transacción y antes de escribir. Una venta concurrente no se puede reproducir en un test
	 * de un solo proceso; se mira el mecanismo, como en `LED-10`.
	 *
	 * BOO-15
	 */
	public function test_the_secondary_locks_the_stock_inside_the_transaction(): void {
		$target  = $this->product( 7 );
		$queries = self::queries_during( fn () => Ledger::apply( $this->bootstrap( $target, 10, 7 ) ) );

		$begin  = self::position_of( $queries, 'START TRANSACTION' );
		$lock   = self::position_of( $queries, 'FOR UPDATE' );
		$insert = self::position_of( $queries, 'INSERT INTO `' . Installer::table( 'movements' ) );

		$this->assertGreaterThan( -1, $lock, 'el stock se lee con FOR UPDATE' );
		$this->assertGreaterThan( $begin, $lock, 'adentro de la transacción' );
		$this->assertLessThan( $insert, $lock, 'antes de escribir' );
	}

	/**
	 * BOO-16 — Lo mismo del lado de la maestra.
	 */
	public function test_the_master_locks_the_stock_inside_the_transaction(): void {
		$product = $this->product( 10 );
		$queries = self::queries_during( fn () => Ledger::record_bootstrap( $product, self::OTHER, $this->details( 900 ), null ) );

		$begin  = self::position_of( $queries, 'START TRANSACTION' );
		$lock   = self::position_of( $queries, 'FOR UPDATE' );
		$insert = self::position_of( $queries, 'INSERT INTO `' . Installer::table( 'movements' ) );

		$this->assertGreaterThan( -1, $lock );
		$this->assertGreaterThan( $begin, $lock );
		$this->assertLessThan( $insert, $lock );
	}

	/**
	 * Un vínculo viejo del producto de acá —un desvincular que no llegó— se reemplaza: el
	 * bootstrap entra, y queda un solo vínculo, el nuevo.
	 *
	 * BOO-17
	 */
	public function test_a_stale_link_of_the_target_is_replaced(): void {
		$target = $this->product( 7 );
		Links::create( $target, self::OTHER, 800, 3, 7 );

		$bootstrap = $this->bootstrap( $target, 10, 7 );

		$this->assertSame( Ledger::APPLIED, Ledger::apply( $bootstrap ) );
		$this->assertSame( 900, (int) Links::for_product( $target )->remote_product );
		$this->assertSame( $bootstrap['seq'], (int) Links::for_product( $target )->generation );
		$this->assertSame( 10, Detector::stock_in_db( $target ) );
	}

	/**
	 * Lo mismo con un vínculo viejo que apunta al producto de la maestra desde otro producto
	 * de acá: también se reemplaza, y no quedan dos vínculos con el mismo remoto.
	 *
	 * BOO-18
	 */
	public function test_a_stale_link_to_the_masters_product_is_replaced(): void {
		global $wpdb;

		$old    = $this->product( 7 );
		$target = $this->product( 7 );
		Links::create( $old, self::OTHER, 900, 3, 7 );

		$this->assertSame( Ledger::APPLIED, Ledger::apply( $this->bootstrap( $target, 10, 7 ) ) );

		$table = Installer::table( 'links' );

		$this->assertNull( Links::for_product( $old ) );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE remote_store = %s AND remote_product = %d", self::OTHER, 900 ) ) ); // phpcs:ignore WordPress.DB
	}
}
