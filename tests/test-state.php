<?php
/**
 * Tests del estado de sincronización.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Ledger\Ledger;
use Woonderful\StockSync\Linking\Links;
use Woonderful\StockSync\Linking\State;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Peers;

/**
 * El estado es una vista: se calcula al mostrarlo (ADR-005 §1, UC-03).
 */
class Test_State extends WP_UnitTestCase {

	private const OTHER = 'b1b1b1b1-0000-4000-8000-000000000000';

	/**
	 * El catálogo de la otra tienda: ID => estado. Null si no responde.
	 *
	 * @var array<int, string>|null
	 */
	private ?array $remote = array();

	/**
	 * Cuántos requests salieron.
	 *
	 * @var int
	 */
	private int $requests = 0;

	public function set_up(): void {
		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		// En el set_up y no en el tear_down: ver tests/TEST-CASES.md.
		delete_option( Peers::OPTION );

		Installer::install();
		Store::ensure_identity();
		wss_unhook_detector();

		Peers::pair( self::OTHER, 'https://otra.test/wp-json/wss/v1', 'secreto', 'Mayorista', Peers::SECONDARY );

		add_filter( 'pre_http_request', array( $this, 'catalog' ), 10, 3 );
	}

	public function tear_down(): void {
		global $wpdb;

		remove_filter( 'pre_http_request', array( $this, 'catalog' ), 10 );

		foreach ( array( 'movements', 'links', 'nonces' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		parent::tear_down();
	}

	/**
	 * Hace de `GET /products` de la otra tienda: devuelve los pedidos que estén en
	 * `$this->remote`, con su estado.
	 *
	 * @param false|array|WP_Error $preempt Lo que venía.
	 * @param array<string, mixed> $args    Los argumentos.
	 * @param string               $url     La URL.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function catalog( $preempt, array $args, string $url ) {
		++$this->requests;

		if ( null === $this->remote ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		}

		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$products = array();

		foreach ( array_map( 'intval', explode( ',', (string) ( $query['include'] ?? '' ) ) ) as $id ) {
			if ( isset( $this->remote[ $id ] ) ) {
				$products[] = array(
					'id'     => $id,
					'sku'    => '',
					'name'   => 'Remoto',
					'stock'  => 5,
					'status' => $this->remote[ $id ],
					'linked' => true,
				);
			}
		}

		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode(
				array(
					'products' => $products,
					'total'    => count( $products ),
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Un producto de acá, vinculado con ese de la otra tienda.
	 *
	 * @param int $remote El producto de la otra.
	 */
	private function linked( int $remote ): int {
		$product = new WC_Product_Simple();
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 5 );
		$id = (int) $product->save();

		Links::create( $id, self::OTHER, $remote, 77, 5 );

		return $id;
	}

	/**
	 * El estado y el motivo de un producto.
	 *
	 * @param array<string, mixed> $result Lo que devolvió `State::of()`.
	 * @param int                  $id     El producto.
	 *
	 * @return array{0: string, 1: string|null}
	 */
	private static function state( array $result, int $id ): array {
		return array( $result['states'][ $id ]['state'], $result['states'][ $id ]['reason'] );
	}

	/**
	 * Cada caso con su estado y su motivo, en una sola pregunta a la otra tienda.
	 *
	 * STA-01
	 */
	public function test_each_case_gets_its_state_in_one_question(): void {
		$product = new WC_Product_Simple();
		$unlinked = (int) $product->save();

		$linked   = $this->linked( 900 );
		$trashed  = $this->linked( 901 );
		$missing  = $this->linked( 902 );
		$local_tr = $this->linked( 903 );
		wp_trash_post( $local_tr );

		$this->remote = array(
			900 => 'publish',
			901 => 'trash',
			903 => 'publish',
		);

		$result = State::of( array( $unlinked, $linked, $trashed, $missing, $local_tr ) );

		$this->assertSame( array( State::UNLINKED, null ), self::state( $result, $unlinked ) );
		$this->assertSame( array( State::LINKED, null ), self::state( $result, $linked ) );
		$this->assertSame( array( State::SUSPENDED, 'remote_trashed' ), self::state( $result, $trashed ) );
		$this->assertSame( array( State::SUSPENDED, 'remote_missing' ), self::state( $result, $missing ) );
		$this->assertSame( array( State::SUSPENDED, 'local_trashed' ), self::state( $result, $local_tr ) );
		$this->assertTrue( $result['remote_reachable'] );
		$this->assertSame( 1, $this->requests, 'una sola pregunta por todos' );
	}

	/**
	 * Si la otra tienda no responde, los vinculados siguen vinculados: la caída no es
	 * suspensión. Se avisa una vez, en el resultado, y no producto por producto.
	 *
	 * STA-02
	 */
	public function test_an_unreachable_store_does_not_suspend(): void {
		$linked       = $this->linked( 900 );
		$this->remote = null;

		$result = State::of( array( $linked ) );

		$this->assertSame( array( State::LINKED, null ), self::state( $result, $linked ) );
		$this->assertFalse( $result['remote_reachable'] );
	}

	/**
	 * Un vínculo con el bootstrap sin resolver es incierto, y no se le pregunta a la otra
	 * tienda por él.
	 *
	 * STA-03
	 */
	public function test_an_unresolved_bootstrap_is_uncertain(): void {
		$product = new WC_Product_Simple();
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 5 );
		$id = (int) $product->save();

		$bootstrap = (int) Ledger::record_bootstrap(
			$id,
			self::OTHER,
			array(
				'target_product'        => 900,
				'base_stock'            => 5,
				'origin_previous_stock' => 5,
				'target_previous_stock' => 5,
			),
			null
		);
		Ledger::resolve_bootstrap( $bootstrap, Ledger::UNCERTAIN );

		$result = State::of( array( $id ) );

		$this->assertSame( array( State::UNCERTAIN, null ), self::state( $result, $id ) );
		$this->assertSame( 0, $this->requests );
	}

	/**
	 * STA-04 — Sin ningún vínculo no se le pregunta nada a la otra tienda.
	 */
	public function test_without_links_nothing_is_asked(): void {
		$product = new WC_Product_Simple();

		State::of( array( (int) $product->save() ) );

		$this->assertSame( 0, $this->requests );
	}

	/**
	 * Restaurado de la papelera, vuelve a vinculado solo: no hay nada que reanudar, porque el
	 * estado no se guarda (UC-03).
	 *
	 * STA-05
	 */
	public function test_a_restored_product_is_linked_again(): void {
		$linked       = $this->linked( 900 );
		$this->remote = array( 900 => 'trash' );

		$this->assertSame( State::SUSPENDED, State::of( array( $linked ) )['states'][ $linked ]['state'] );

		$this->remote = array( 900 => 'publish' );

		$this->assertSame( State::LINKED, State::of( array( $linked ) )['states'][ $linked ]['state'] );
	}
}
