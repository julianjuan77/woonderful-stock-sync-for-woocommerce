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
use Woonderful\StockSync\Transport\Express;

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

		// La suite recrea las tablas de WordPress en cada corrida, pero no las de
		// WooCommerce. Las líneas de pedido quedan confirmadas —por la transacción de
		// record() y por el DROP TABLE del tear_down— y la corrida siguiente, con los IDs
		// de pedido desde cero, se las hereda: un pedido de una línea descuenta ocho veces.
		// Borradas acá, el próximo commit implícito confirma el borrado.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_itemmeta" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_items" ); // phpcs:ignore WordPress.DB

		Detector::register();
	}

	public function tear_down(): void {
		global $wpdb;

		wss_unhook_detector();

		Detector::forget_all();
		Guard::forget_all();
		Express::forget_all();

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
	 * El motivo y la referencia de cada movimiento, en orden.
	 *
	 * @return array<int, array{0: string, 1: string|null}>
	 */
	private function stamps(): array {
		global $wpdb;

		$table = Installer::table( 'movements' );
		$rows  = $wpdb->get_results( "SELECT reason, reference FROM {$table} ORDER BY id" ); // phpcs:ignore WordPress.DB

		return array_map(
			static fn ( $row ) => array( (string) $row->reason, null === $row->reference ? null : (string) $row->reference ),
			$rows
		);
	}

	/**
	 * Un pedido con una línea del producto.
	 *
	 * @param int $product_id Producto.
	 * @param int $qty        Unidades.
	 */
	private function create_order( int $product_id, int $qty ): WC_Order {
		$order = wc_create_order();
		$order->add_product( wc_get_product( $product_id ), $qty );
		$order->save();

		return $order;
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

	/**
	 * DET-11 — Un cambio sin pedido detrás se queda en `adjustment`, sin referencia.
	 */
	public function test_a_change_without_an_order_stays_an_adjustment(): void {
		$product_id = $this->create_product( 10 );

		wc_update_product_stock( $product_id, 2, 'decrease' );

		$this->assertSame( array( array( 'adjustment', null ) ), $this->stamps() );
	}

	/**
	 * DET-12 — La venta de un pedido se estampa con su número.
	 */
	public function test_an_order_stamps_a_sale(): void {
		$product_id = $this->create_product( 10 );
		$order      = $this->create_order( $product_id, 2 );

		wc_reduce_stock_levels( $order->get_id() );

		$this->assertSame( array( array( $product_id, -2 ) ), $this->movements() );
		$this->assertSame( array( array( 'sale', 'pedido #' . $order->get_order_number() ) ), $this->stamps() );
	}

	/**
	 * DET-17 — Lo que estampa un pedido queda anotado para salir al final del request, y con
	 * la prioridad más baja de `shutdown` (ADR-006 §5).
	 */
	public function test_an_order_queues_its_movement_for_the_end_of_the_request(): void {
		global $wpdb;

		$product_id = $this->create_product( 10 );
		$order      = $this->create_order( $product_id, 2 );

		wc_reduce_stock_levels( $order->get_id() );

		$table = Installer::table( 'movements' );

		$this->assertSame( array( (int) $wpdb->get_var( "SELECT id FROM {$table}" ) ), Express::queued() ); // phpcs:ignore WordPress.DB
		$this->assertSame( PHP_INT_MAX, has_action( 'shutdown', array( Express::class, 'on_shutdown' ) ) );
	}

	/**
	 * DET-18 — Un cambio sin pedido no se anota: espera la pasada, para que una importación
	 * se agrupe.
	 */
	public function test_a_change_without_an_order_is_not_queued(): void {
		$product_id = $this->create_product( 10 );

		wc_update_product_stock( $product_id, 2, 'decrease' );

		$this->assertSame( array(), Express::queued() );
	}

	/**
	 * DET-13 — Un pedido cancelado devuelve el stock, y eso es un `refund`.
	 */
	public function test_a_restored_order_stamps_a_refund(): void {
		$product_id = $this->create_product( 10 );
		$order      = $this->create_order( $product_id, 2 );

		wc_reduce_stock_levels( $order->get_id() );
		wc_increase_stock_levels( $order->get_id() );

		$reference = 'pedido #' . $order->get_order_number();

		$this->assertSame( array( array( $product_id, -2 ), array( $product_id, 2 ) ), $this->movements() );
		$this->assertSame( array( array( 'sale', $reference ), array( 'refund', $reference ) ), $this->stamps() );
	}

	/**
	 * DET-14 — Un reembolso que repone una unidad es un `refund` de +1.
	 */
	public function test_a_restocked_refund_stamps_a_refund(): void {
		$product_id = $this->create_product( 10 );
		$order      = $this->create_order( $product_id, 2 );

		wc_reduce_stock_levels( $order->get_id() );

		$items = $order->get_items();
		$item  = reset( $items );

		wc_create_refund(
			array(
				'order_id'      => $order->get_id(),
				'amount'        => 0,
				'restock_items' => true,
				'line_items'    => array(
					$item->get_id() => array(
						'qty'          => 1,
						'refund_total' => 0,
					),
				),
			)
		);

		$this->assertSame( array( $product_id, 1 ), $this->movements()[1] );
		$this->assertSame( array( 'refund', 'pedido #' . $order->get_order_number() ), $this->stamps()[1] );
	}

	/**
	 * El cambio del pedido lo tapó el guard, así que no se registró. El estampado no puede
	 * caer sobre el ajuste anterior del mismo producto, que no tiene nada que ver.
	 *
	 * DET-15
	 */
	public function test_an_unrecorded_change_does_not_stamp_an_earlier_one(): void {
		$product_id = $this->create_product( 10 );
		$order      = $this->create_order( $product_id, 2 );

		wc_update_product_stock( $product_id, 1, 'decrease' );

		Guard::expect( $product_id );
		wc_reduce_stock_levels( $order->get_id() );

		$this->assertSame( array( array( 'adjustment', null ) ), $this->stamps() );
	}

	/**
	 * La línea del pedido es la variación, pero la fila es del padre: se estampa la del
	 * padre.
	 *
	 * DET-16
	 */
	public function test_an_order_of_a_variation_stamps_the_parent_row(): void {
		$parent = new WC_Product_Variable();
		$parent->set_manage_stock( true );
		$parent->set_stock_quantity( 10 );
		$parent_id = (int) $parent->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation_id = (int) $variation->save();

		$this->link( $parent_id );

		$order = $this->create_order( $variation_id, 2 );
		wc_reduce_stock_levels( $order->get_id() );

		$this->assertSame( array( array( $parent_id, -2 ) ), $this->movements() );
		$this->assertSame( array( array( 'sale', 'pedido #' . $order->get_order_number() ) ), $this->stamps() );
	}
}
