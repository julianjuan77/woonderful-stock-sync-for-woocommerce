<?php
/**
 * Tests de los pares rotos.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Linking\Links;
use Woonderful\StockSync\Log\Events;
use Woonderful\StockSync\Log\Pairs;
use Woonderful\StockSync\Schema\Installer;

/**
 * Cada tienda mira sus propios productos vinculados, de noche (ADR-011 §7).
 */
class Test_Pairs extends WP_UnitTestCase {

	private const REMOTE_STORE = 'b1b1b1b1-0000-4000-8000-000000000000';

	public function set_up(): void {
		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Installer::install();
		Store::ensure_identity();
		wss_unhook_detector();
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
	 * Un producto vinculado.
	 */
	private function linked(): int {
		$product = new WC_Product_Simple();
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 10 );
		$id = (int) $product->save();

		Links::create( $id, self::REMOTE_STORE, $id + 1000, 1, 10 );

		return $id;
	}

	/**
	 * Los tipos de evento registrados, del más viejo al más nuevo.
	 *
	 * @return string[]
	 */
	private function kinds(): array {
		return array_reverse( array_column( Events::list()['items'], 'kind' ) );
	}

	/**
	 * PAI-01 — Un par sano no deja nada.
	 */
	public function test_a_healthy_pair_leaves_nothing(): void {
		$this->linked();

		$this->assertSame(
			array(
				'broken'   => 0,
				'restored' => 0,
			),
			Pairs::check()
		);
		$this->assertSame( array(), $this->kinds() );
	}

	/**
	 * PAI-02 — **Un par roto se registra una vez**: con el motivo, y la noche siguiente, si
	 * sigue igual, no se repite.
	 */
	public function test_a_broken_pair_is_recorded_once(): void {
		$product = $this->linked();
		wp_trash_post( $product );

		Pairs::check();
		Pairs::check();

		$event = Events::list()['items'][0];

		$this->assertSame( array( Events::PAIR_BROKEN ), $this->kinds() );
		$this->assertSame( $product, $event['product'] );
		$this->assertSame( 'local_trashed', $event['context']['reason'] );
		$this->assertNotNull( Links::for_product( $product )->broken_since );
	}

	/**
	 * PAI-03 — **Si vuelve, se registra; y si se rompe otra vez, se registra de nuevo.**
	 */
	public function test_a_pair_that_comes_back_and_breaks_again_is_recorded_again(): void {
		$product = $this->linked();

		wp_trash_post( $product );
		Pairs::check();
		wp_untrash_post( $product );
		Pairs::check();
		wp_trash_post( $product );
		Pairs::check();

		$this->assertSame( array( Events::PAIR_BROKEN, Events::PAIR_RESTORED, Events::PAIR_BROKEN ), $this->kinds() );
	}

	/**
	 * PAI-04 — Los tres motivos de este lado: borrado, en la papelera, sin su propio stock.
	 */
	public function test_the_three_local_reasons(): void {
		$missing = $this->linked();
		wp_delete_post( $missing, true );

		$trashed = $this->linked();
		wp_trash_post( $trashed );

		$unmanaged = $this->linked();
		$product   = wc_get_product( $unmanaged );
		$product->set_manage_stock( false );
		$product->save();

		Pairs::check();

		$reasons = array();

		foreach ( Events::list()['items'] as $event ) {
			$reasons[ $event['product'] ] = $event['context']['reason'];
		}

		$this->assertSame( 'local_missing', $reasons[ $missing ] );
		$this->assertSame( 'local_trashed', $reasons[ $trashed ] );
		$this->assertSame( 'local_not_managing_stock', $reasons[ $unmanaged ] );
	}
}
