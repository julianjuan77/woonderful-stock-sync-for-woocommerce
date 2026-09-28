<?php
/**
 * Tests del detector de cambios de stock.
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
 * Cada cambio de stock de un producto vinculado es un movimiento, con el delta leído de la
 * base (ADR-006 §1).
 */
class Test_Detector extends WP_UnitTestCase {

	private const REMOTE_STORE = 'b1b1b1b1-0000-4000-8000-000000000000';

	/**
	 * Productos remotos para los vínculos: el índice `remote` no admite dos iguales.
	 *
	 * @var int
	 */
	private int $next_remote = 5000;

	public function set_up(): void {
		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Installer::install();
		Store::ensure_identity();

		Detector::register();
	}

	public function tear_down(): void {
		global $wpdb;

		foreach ( array( 'product', 'variation' ) as $type ) {
			remove_action( "woocommerce_{$type}_before_set_stock", array( Detector::class, 'before_change' ) );
			remove_action( "woocommerce_{$type}_set_stock", array( Detector::class, 'after_change' ) );
		}

		Detector::forget_all();
		Guard::forget_all();

		foreach ( array( 'movements', 'links', 'nonces' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		delete_option( Installer::OPTION_VERSION );
		delete_option( Store::OPTION_ID );

		parent::tear_down();
	}

	/**
	 * Crea un producto simple que gestiona stock y lo vincula.
	 *
	 * Se vincula después de crearlo: crear el producto también cambia su stock, y eso no
	 * tiene que dejar movimientos en el libro de cada test.
	 *
	 * @param int  $stock  Con cuántas unidades arranca.
	 * @param bool $linked Si se vincula.
	 */
	private function create_product( int $stock, bool $linked = true ): int {
		$product = new WC_Product_Simple();
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );
		$product_id = (int) $product->save();

		if ( $linked ) {
			$this->link( $product_id );
		}

		return $product_id;
	}

	/**
	 * Vincula un producto local con uno remoto cualquiera.
	 *
	 * @param int $product_id Producto de esta tienda.
	 */
	private function link( int $product_id ): void {
		global $wpdb;

		$wpdb->insert(
			Installer::table( 'links' ),
			array(
				'local_product'  => $product_id,
				'remote_store'   => self::REMOTE_STORE,
				'remote_product' => $this->next_remote++,
				'generation'     => 1,
				'base_stock'     => 10,
				'base_set_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Los movimientos del libro, en orden, como producto => delta.
	 *
	 * @return array<int, array{0: int, 1: int}>
	 */
	private function movements(): array {
		global $wpdb;

		$table = Installer::table( 'movements' );
		$rows  = $wpdb->get_results( "SELECT origin_product, delta FROM {$table} ORDER BY id" ); // phpcs:ignore WordPress.DB

		return array_map(
			static fn ( $row ) => array( (int) $row->origin_product, (int) $row->delta ),
			$rows
		);
	}

	/**
	 * DET-01 — Una venta es un movimiento con su delta.
	 */
	public function test_a_sale_is_recorded(): void {
		$product_id = $this->create_product( 10 );

		wc_update_product_stock( $product_id, 2, 'decrease' );

		$this->assertSame( array( array( $product_id, -2 ) ), $this->movements() );
	}

	/**
	 * Guardar el producto es la otra ruta, y la que el delta del objeto no ve: ahí el
	 * `before` ya trae el valor nuevo y el delta daría 0.
	 *
	 * DET-02
	 */
	public function test_saving_the_product_is_recorded(): void {
		$product_id = $this->create_product( 10 );

		$product = wc_get_product( $product_id );
		$product->set_stock_quantity( 15 );
		$product->save();

		$this->assertSame( array( array( $product_id, 5 ) ), $this->movements() );
	}

	/**
	 * El objeto se cargó con 10, y antes de que se use para vender otro request dejó la
	 * base en 9. Con el delta del objeto saldría −3; fueron −2.
	 *
	 * DET-03
	 */
	public function test_a_stale_object_does_not_change_the_delta(): void {
		$product_id = $this->create_product( 10 );
		$stale      = wc_get_product( $product_id );

		wc_update_product_stock( $product_id, 1, 'decrease' );
		wc_update_product_stock( $stale, 2, 'decrease' );

		$this->assertSame(
			array( array( $product_id, -1 ), array( $product_id, -2 ) ),
			$this->movements()
		);
	}

	/**
	 * DET-04 — Un producto sin vincular no deja movimientos.
	 */
	public function test_an_unlinked_product_is_ignored(): void {
		$product_id = $this->create_product( 10, false );

		wc_update_product_stock( $product_id, 2, 'decrease' );

		$this->assertSame( array(), $this->movements() );
	}

	/**
	 * `wc_update_product_stock()` ya lo descarta por su cuenta, así que el caso se prueba
	 * por la otra ruta: guardar un producto que tiene número pero no lo gestiona.
	 *
	 * DET-05
	 */
	public function test_a_product_without_managed_stock_is_ignored(): void {
		$product_id = $this->create_product( 10 );

		$product = wc_get_product( $product_id );
		$product->set_manage_stock( false );
		$product->set_stock_quantity( 15 );
		$product->save();

		$this->assertSame( array(), $this->movements() );
	}

	/**
	 * El cambio que anotó el guard no se emite, y el siguiente del mismo producto sí: la
	 * anotación se consumió.
	 *
	 * DET-06
	 */
	public function test_the_guarded_change_is_not_recorded(): void {
		$product_id = $this->create_product( 10 );

		Guard::expect( $product_id );
		wc_update_product_stock( $product_id, 2, 'decrease' );
		wc_update_product_stock( $product_id, 1, 'decrease' );

		$this->assertSame( array( array( $product_id, -1 ) ), $this->movements() );
	}

	/**
	 * Una variación que toma el stock del padre: quien cambia es el padre, y el
	 * movimiento es suyo.
	 *
	 * DET-07
	 */
	public function test_a_variation_that_uses_the_parent_stock_records_the_parent(): void {
		$parent = new WC_Product_Variable();
		$parent->set_manage_stock( true );
		$parent->set_stock_quantity( 10 );
		$parent_id = (int) $parent->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation_id = (int) $variation->save();

		$this->link( $parent_id );

		wc_update_product_stock( $variation_id, 2, 'decrease' );

		$this->assertSame( array( array( $parent_id, -2 ) ), $this->movements() );
	}

	/**
	 * Una variación con stock propio pasa por los hooks de variación, no por los de
	 * producto.
	 *
	 * DET-08
	 */
	public function test_a_variation_with_its_own_stock_is_recorded(): void {
		$parent_id = (int) ( new WC_Product_Variable() )->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( 10 );
		$variation_id = (int) $variation->save();

		$this->link( $variation_id );

		wc_update_product_stock( $variation_id, 3, 'decrease' );

		$this->assertSame( array( array( $variation_id, -3 ) ), $this->movements() );
	}

	/**
	 * Un `before` que se quedó sin su `set` —un guardado cuyo meta no cambió— no puede
	 * servir de punto de partida para el cambio siguiente: cada `before` pisa al anterior.
	 *
	 * DET-09
	 */
	public function test_a_before_without_its_set_is_overwritten(): void {
		$product_id = $this->create_product( 10 );

		do_action( 'woocommerce_product_before_set_stock', wc_get_product( $product_id ) );
		update_post_meta( $product_id, '_stock', 20 );

		wc_update_product_stock( $product_id, 2, 'decrease' );

		$this->assertSame( array( array( $product_id, -2 ) ), $this->movements() );
	}

	/**
	 * El eco de punta a punta: aplicar un movimiento de la otra tienda cambia el stock, y
	 * el detector no tiene que emitirlo de vuelta. DET-06 prueba el guard puesto a mano;
	 * éste, el que deja `apply()`.
	 *
	 * DET-10
	 */
	public function test_applying_a_remote_movement_does_not_echo(): void {
		global $wpdb;

		$product_id = $this->create_product( 10 );
		$remote     = $this->next_remote - 1;

		$state = Ledger::apply(
			array(
				'origin'     => self::REMOTE_STORE,
				'seq'        => 1,
				'generation' => 1,
				'delta'      => -2,
				'product'    => $remote,
				'reason'     => 'sale',
				'reference'  => 'pedido #4512',
			)
		);

		$table = Installer::table( 'movements' );
		$own   = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE origin_store = %s", Store::id() ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$this->assertSame( 'applied', $state );
		$this->assertSame( 8, (int) get_post_meta( $product_id, '_stock', true ) );
		$this->assertSame( 0, $own );
	}
}
