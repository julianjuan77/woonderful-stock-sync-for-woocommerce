<?php
/**
 * Tests del endpoint de la reconciliación.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Log\Events;
use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Ledger\Ledger;
use Woonderful\StockSync\Reconciliation\Audit;
use Woonderful\StockSync\Reconciliation\Lock;
use Woonderful\StockSync\Reconciliation\Schedule;
use Woonderful\StockSync\Reconciliation\Result;
use Woonderful\StockSync\Rest\Reconcile;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Peers;
use Woonderful\StockSync\Transport\Signature;

/**
 * `POST /reconcile/digest`: esta tienda contesta lo que le pregunta la otra (ADR-009 §1).
 */
class Test_Rest_Reconcile extends WP_UnitTestCase {

	private const REMOTE_STORE = 'b1b1b1b1-0000-4000-8000-000000000000';

	private const SECRET = 'secreto-de-prueba';

	private const ROUTE = '/wss/v1/reconcile/digest';

	/**
	 * El servidor REST.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	public function set_up(): void {
		global $wpdb, $wp_rest_server;

		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		delete_option( \Woonderful\StockSync\Log\Peer::OPTION );

		Installer::install();
		Store::ensure_identity();

		update_option( Peers::OPTION, array( self::REMOTE_STORE => array( 'secret' => self::SECRET ) ), false );

		$wpdb->delete( $wpdb->options, array( 'option_name' => Lock::OPTION ) ); // phpcs:ignore WordPress.DB
		wp_cache_delete( Lock::OPTION, 'options' );
		delete_option( Result::OPTION );

		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		add_action( 'rest_api_init', array( Reconcile::class, 'register_routes' ) );
		$this->server = rest_get_server();
	}

	public function tear_down(): void {
		global $wpdb, $wp_rest_server;

		remove_action( 'rest_api_init', array( Reconcile::class, 'register_routes' ) );
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride

		Schedule::unschedule();

		foreach ( array( 'movements', 'links', 'nonces', 'events' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		$wpdb->delete( $wpdb->options, array( 'option_name' => Lock::OPTION ) ); // phpcs:ignore WordPress.DB
		delete_option( Result::OPTION );
		delete_option( Peers::OPTION );
		delete_option( Installer::OPTION_VERSION );
		delete_option( Store::OPTION_ID );

		parent::tear_down();
	}

	/**
	 * Hace una pregunta firmada, como la otra tienda.
	 *
	 * @param array<string, mixed> $body La pregunta.
	 */
	private function ask( array $body ): WP_REST_Response {
		$json    = (string) wp_json_encode( $body );
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_body( $json );

		foreach ( Signature::headers( self::REMOTE_STORE, self::SECRET, 'POST', self::ROUTE, array(), $json ) as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return $this->server->dispatch( $request );
	}

	/**
	 * Un movimiento recibido de la otra tienda.
	 *
	 * @param int $seq Su secuencia allá.
	 */
	private function received( int $seq ): void {
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
				'application'    => Ledger::APPLIED,
			)
		);
	}

	/**
	 * Un movimiento propio abandonado.
	 */
	private function abandoned(): int {
		global $wpdb;

		$table = Installer::table( 'movements' );

		$wpdb->insert( // phpcs:ignore WordPress.DB
			$table,
			array(
				'origin_store'   => Store::id(),
				'generation'     => 1,
				'delta'          => -1,
				'reason'         => 'sale',
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
				'origin_product' => 10,
				'delivery'       => 'abandoned',
			)
		);

		$id = (int) $wpdb->insert_id;
		$wpdb->update( $table, array( 'origin_seq' => $id ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB

		return $id;
	}

	/**
	 * La marca de purga de lo de la otra tienda.
	 *
	 * @param int $seq Hasta dónde.
	 */
	private function purged_through( int $seq ): void {
		update_option(
			Peers::OPTION,
			array(
				self::REMOTE_STORE => array(
					'secret'         => self::SECRET,
					'purged_through' => $seq,
				),
			),
			false
		);
	}

	/**
	 * RCN-01 — "¿Qué tenés de lo mío?": la lista, hasta dónde cubre, la marca de purga y el
	 * techo propio.
	 */
	public function test_theirs_answers_the_list_the_mark_and_the_ceiling(): void {
		$this->received( 3 );
		$this->received( 7 );
		$own = $this->abandoned();
		$this->purged_through( 2 );

		$response = $this->ask(
			array(
				'step'  => Reconcile::THEIRS,
				'after' => 0,
				'until' => 10,
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'seqs'           => array( 3, 7 ),
				'covered_to'     => 10,
				'more'           => false,
				'purged_through' => 2,
				'ceiling'        => $own,
			),
			$response->get_data()
		);
	}

	/**
	 * RCN-02 — **La marca se lee después de la lista**: si la purga borra en el medio, la marca
	 * que vuelve ya la incluye, y la otra no reenvía lo que esta tienda tuvo y borró.
	 */
	public function test_the_mark_is_read_after_the_list(): void {
		global $wpdb;

		$this->received( 3 );

		$table  = Installer::table( 'movements' );
		$purged = false;
		$purge  = function ( string $query ) use ( $wpdb, $table, &$purged ): string {
			// La purga confirma justo antes de que se lea la lista.
			if ( ! $purged && str_contains( $query, "SELECT origin_seq FROM {$table}" ) ) {
				$purged = true;
				$wpdb->delete( $table, array( 'origin_store' => self::REMOTE_STORE ) ); // phpcs:ignore WordPress.DB
				$this->purged_through( 3 );
			}

			return $query;
		};

		add_filter( 'query', $purge );
		$data = $this->ask(
			array(
				'step'  => Reconcile::THEIRS,
				'after' => 0,
				'until' => 10,
			)
		)->get_data();
		remove_filter( 'query', $purge );

		$this->assertTrue( $purged );
		$this->assertSame( array(), $data['seqs'] );
		$this->assertSame( 3, $data['purged_through'], 'el 3 no está, pero la marca lo cubre' );
	}

	/**
	 * RCN-03 — "Esto tengo de lo tuyo": esta tienda reencola lo propio que la otra no tiene, y
	 * lo anota en su resultado.
	 */
	public function test_mine_compares_the_own_movements(): void {
		$missing = $this->abandoned();
		$arrived = $this->abandoned();

		$response = $this->ask(
			array(
				'step'           => Reconcile::MINE,
				'after'          => 0,
				'until'          => $arrived,
				'seqs'           => array( $arrived ),
				'purged_through' => 0,
				'session'        => 'pasada-1',
			)
		);

		$this->assertSame(
			array(
				'reopened'  => 1,
				'confirmed' => 1,
			),
			$response->get_data()
		);
		$this->assertSame( $missing > 0, true );

		$last = Result::last();

		$this->assertSame( Result::PEER, $last['trigger'] ?? null );
		$this->assertSame( 1, $last['details']['reopened'] ?? null );
		$this->assertSame( self::REMOTE_STORE, $last['details']['by'] ?? null );
	}

	/**
	 * RCN-04 — Las tandas de la misma reconciliación se suman en un resultado; una
	 * reconciliación nueva empieza de cero.
	 */
	public function test_the_pages_of_one_reconciliation_add_up(): void {
		$first  = $this->abandoned();
		$second = $this->abandoned();
		$third  = $this->abandoned();

		$page = static fn ( int $after, int $until, string $session ) => array(
			'step'           => Reconcile::MINE,
			'after'          => $after,
			'until'          => $until,
			'seqs'           => array(),
			'purged_through' => 0,
			'session'        => $session,
		);

		$this->ask( $page( 0, $first, 'pasada-1' ) );
		$this->ask( $page( $first, $second, 'pasada-1' ) );

		$this->assertSame( 2, Result::last()['details']['reopened'] ?? null );

		$this->ask( $page( $second, $third, 'pasada-2' ) );

		$this->assertSame( 1, Result::last()['details']['reopened'] ?? null );
	}

	/**
	 * RCN-05 — **Si esta tienda está reconciliando, contesta que está ocupada**, y no toca nada:
	 * una reconciliación por vez para el par (ADR-009 §4).
	 */
	public function test_a_store_that_is_reconciling_answers_busy(): void {
		$id = $this->abandoned();
		Lock::acquire();

		$response = $this->ask(
			array(
				'step'           => Reconcile::MINE,
				'after'          => 0,
				'until'          => $id,
				'seqs'           => array(),
				'purged_through' => 0,
				'session'        => 'pasada-1',
			)
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wss_reconciling', $response->get_data()['code'] ?? null );
		$this->assertNull( Result::last() );
	}

	/**
	 * RCN-06 — Una pregunta mal armada se rechaza entera, sin tocar nada.
	 *
	 * @dataProvider malformed
	 *
	 * @param array<string, mixed> $body La pregunta.
	 */
	public function test_a_malformed_question_is_rejected( array $body ): void {
		$id = $this->abandoned();

		$response = $this->ask( $body );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wss_bad_request', $response->get_data()['code'] ?? null );

		global $wpdb;
		$table = Installer::table( 'movements' );
		$this->assertSame( 'abandoned', $wpdb->get_var( $wpdb->prepare( "SELECT delivery FROM {$table} WHERE id = %d", $id ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Preguntas mal armadas.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function malformed(): array {
		$mine = array(
			'step'           => Reconcile::MINE,
			'after'          => 0,
			'until'          => 1000,
			'seqs'           => array(),
			'purged_through' => 0,
			'session'        => 'pasada-1',
		);

		$theirs = array(
			'step'  => Reconcile::THEIRS,
			'after' => 0,
		);

		return array(
			'sin paso'              => array( array( 'after' => 0 ) ),
			'paso desconocido'      => array( array( 'step' => 'otro' ) ),
			'tramo al revés'        => array( array_merge( $mine, array( 'after' => 10 ), array( 'until' => 5 ) ) ),
			'tramo en texto'        => array( array_merge( $mine, array( 'until' => '1000' ) ) ),
			'secuencia en texto'    => array( array_merge( $mine, array( 'seqs' => array( '1' ) ) ) ),
			'secuencia cero'        => array( array_merge( $mine, array( 'seqs' => array( 0 ) ) ) ),
			'lista que no es lista' => array( array_merge( $mine, array( 'seqs' => array( 'a' => 1 ) ) ) ),
			'tanda de más'          => array( array_merge( $mine, array( 'seqs' => range( 1, 5001 ) ) ) ),
			'marca negativa'        => array( array_merge( $mine, array( 'purged_through' => -1 ) ) ),
			'sin sesión'            => array( array_merge( $mine, array( 'session' => '' ) ) ),
			'theirs sin techo'      => array( $theirs ),
		);
	}

	/**
	 * RCN-07 — Sin firma no se contesta: la lista dice qué ventas tuvo la tienda.
	 */
	public function test_an_unsigned_question_is_rejected(): void {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'step'  => Reconcile::THEIRS,
					'after' => 0,
					'until' => 10,
				)
			)
		);

		$this->assertSame( 401, $this->server->dispatch( $request )->get_status() );
	}

	/**
	 * Empareja con la otra tienda como maestra.
	 */
	private function master_peer(): void {
		update_option(
			Peers::OPTION,
			array(
				self::REMOTE_STORE => array(
					'rest_url' => 'https://otra.test/wp-json/wss/v1',
					'secret'   => self::SECRET,
					'role'     => Peers::MASTER,
				),
			),
			false
		);
	}

	/**
	 * RCN-08 — "Estos son mis vínculos", de la maestra: esta tienda borra el suyo sin respaldo
	 * e informa lo que falta.
	 */
	public function test_links_from_the_master_are_compared(): void {
		global $wpdb;

		$this->master_peer();
		$wpdb->insert( // phpcs:ignore WordPress.DB
			Installer::table( 'links' ),
			array(
				'local_product'  => 10,
				'remote_store'   => self::REMOTE_STORE,
				'remote_product' => 110,
				'generation'     => 4,
				'base_stock'     => 10,
				'base_set_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$response = $this->ask(
			array(
				'step'    => Reconcile::LINKS,
				'ceiling' => 9,
				'links'   => array(
					array(
						'local'      => 120,
						'remote'     => 20,
						'generation' => 6,
					),
				),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'deleted'    => array( 10 ),
				'missing'    => array( 6 ),
				'mismatched' => array(),
			),
			$response->get_data()
		);
	}

	/**
	 * RCN-09 — **Si los manda la secundaria, no se compara nada**: borrar vínculos porque lo
	 * pide ella dejaría a cualquiera desarmar a la otra.
	 */
	public function test_links_from_the_secondary_are_refused(): void {
		$response = $this->ask(
			array(
				'step'    => Reconcile::LINKS,
				'ceiling' => 9,
				'links'   => array(),
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wss_not_master', $response->get_data()['code'] ?? null );
	}

	/**
	 * RCN-10 — Una lista de vínculos mal armada se rechaza, sin tocar nada.
	 *
	 * @dataProvider malformed_links
	 *
	 * @param mixed $links Lo que viene en `links`.
	 */
	public function test_malformed_links_are_rejected( $links ): void {
		$this->master_peer();

		$response = $this->ask(
			array(
				'step'    => Reconcile::LINKS,
				'ceiling' => 9,
				'links'   => $links,
			)
		);

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Listas mal armadas.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function malformed_links(): array {
		$good = array(
			'local'      => 1,
			'remote'     => 2,
			'generation' => 3,
		);

		return array(
			'no es lista'          => array( 'x' ),
			'vínculo que no es'    => array( array( 'x' ) ),
			'producto en texto'    => array( array( array_merge( $good, array( 'local' => '1' ) ) ) ),
			'producto cero'        => array( array( array_merge( $good, array( 'remote' => 0 ) ) ) ),
			'pasado del techo'     => array( array( array_merge( $good, array( 'generation' => 10 ) ) ) ),
		);
	}

	/**
	 * RCN-11 — "Hacé tu fase 2": esta tienda la hace, contesta sus discrepancias abiertas y lo
	 * anota en su resultado.
	 */
	public function test_audit_runs_the_own_phase_two(): void {
		// Uno que no cierra —el libro dice 50, hay 47— y uno ya abierto que sigue igual.
		$suspect = $this->linked_product( 47, 50 );
		$open    = $this->linked_product( 8, 10 );
		Audit::confirm( array( array( $open, -2 ) ) );

		$response = $this->ask(
			array(
				'step'    => Reconcile::AUDIT,
				'session' => 'pasada-1',
			)
		);

		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'checked'   => 2,
				'closed'    => 0,
				'suspected' => 1,
			),
			$data['audit']
		);
		$this->assertCount( 1, $data['open'] );
		$this->assertSame( array( 10, 8 ), array( $data['open'][0]['expected'], $data['open'][0]['found'] ) );
		$this->assertSame( 2, Result::last()['details']['audit']['checked'] ?? null );
		$this->assertGreaterThan( 0, $suspect );
	}

	/**
	 * Un producto vinculado con su stock y su stock base.
	 *
	 * @param int $stock El stock que tiene.
	 * @param int $base  Con cuánto arrancó.
	 *
	 * @return int El ID del vínculo.
	 */
	private function linked_product( int $stock, int $base ): int {
		global $wpdb;

		$product = new WC_Product_Simple();
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );

		$wpdb->insert( // phpcs:ignore WordPress.DB
			Installer::table( 'links' ),
			array(
				'local_product'  => (int) $product->save(),
				'remote_store'   => self::REMOTE_STORE,
				'remote_product' => 900 + $stock,
				'generation'     => 1,
				'base_stock'     => $base,
				'base_set_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * RCN-12 — Sin sesión, no.
	 */
	public function test_audit_without_a_session_is_rejected(): void {
		$this->assertSame( 400, $this->ask( array( 'step' => Reconcile::AUDIT ) )->get_status() );
	}

	/**
	 * RCN-13 — La tienda que contesta también deja su reconciliación en el log, al contestar la
	 * última pregunta.
	 */
	public function test_the_answering_store_logs_its_reconciliation(): void {
		$this->ask(
			array(
				'step'    => Reconcile::AUDIT,
				'session' => 'pasada-1',
			)
		);

		$event = Events::list( array( 'kind' => Events::RECONCILED ) )['items'][0];

		$this->assertSame( Result::PEER, $event['context']['trigger'] );
		$this->assertSame( self::REMOTE_STORE, $event['context']['by'] );
	}
}
