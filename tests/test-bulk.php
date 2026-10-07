<?php
/**
 * Tests de la vinculación masiva.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Catalog\Bulk;
use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Linking\Links;
use Woonderful\StockSync\Log\Events;
use Woonderful\StockSync\Log\Peer;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Peers;

/**
 * Los pares sin ambigüedad, en tandas de 20, revalidados en el momento (ADR-014 §6).
 */
class Test_Bulk extends WP_UnitTestCase {

	/**
	 * La otra tienda.
	 */
	private const REMOTE_STORE = 'b1b1b1b1-0000-4000-8000-000000000000';

	/**
	 * Una marca de esta corrida: los productos de otros tests sobreviven al rollback.
	 *
	 * @var string
	 */
	private string $run;

	/**
	 * Cómo contesta la otra cada producto, por ID: lo que cambia respecto de la copia.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private array $fresh = array();

	public function set_up(): void {
		global $wpdb;

		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Installer::install();
		Store::ensure_identity();
		wss_unhook_detector();

		$wpdb->query( 'DELETE FROM ' . Installer::table( Installer::REMOTE_PRODUCTS ) ); // phpcs:ignore WordPress.DB
		delete_option( Peer::OPTION );
		Peers::pair( self::REMOTE_STORE, 'https://otra.test/wp-json/wss/v1', 'secreto', 'Mayorista', Peers::SECONDARY );
		update_option( Store::OPTION_FINGERPRINT, Store::fingerprint() );

		$this->run = 'b' . wp_generate_password( 8, false );

		add_filter( 'pre_http_request', array( $this, 'other_store' ), 10, 3 );
	}

	public function tear_down(): void {
		global $wpdb;

		remove_filter( 'pre_http_request', array( $this, 'other_store' ), 10 );
		wss_unhook_detector();

		$wpdb->query( 'DELETE FROM ' . Installer::table( 'events' ) ); // phpcs:ignore WordPress.DB

		foreach ( array( 'movements', 'links', 'nonces' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		delete_option( Peers::OPTION );
		delete_option( Store::OPTION_FINGERPRINT );
		delete_option( Installer::OPTION_VERSION );
		delete_option( Store::OPTION_ID );

		parent::tear_down();
	}

	/**
	 * Hace de la otra tienda: el catálogo con `include`, como dice `$fresh`, y el bootstrap
	 * como recibido.
	 *
	 * @param mixed  $preempt Lo que traía el filtro.
	 * @param mixed  $args    Los argumentos.
	 * @param string $url     A dónde iba.
	 *
	 * @return array<string, mixed>
	 */
	public function other_store( $preempt, $args, string $url ): array {
		if ( str_contains( $url, '/products' ) ) {
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
			$products = array();

			foreach ( array_map( 'intval', explode( ',', (string) ( $query['include'] ?? '' ) ) ) as $id ) {
				if ( array_key_exists( $id, $this->fresh ) && null === $this->fresh[ $id ] ) {
					continue;
				}

				$products[] = array_merge(
					array(
						'id'     => $id,
						'sku'    => 'SKU' . $id . '-' . $this->run,
						'name'   => 'Allá ' . $id,
						'stock'  => 4,
						'status' => 'publish',
						'linked' => false,
					),
					$this->fresh[ $id ] ?? array()
				);
			}

			$body = array(
				'products' => $products,
				'total'    => count( $products ),
			);
		} else {
			$movement = json_decode( (string) $args['body'], true )['movements'][0];
			$body     = array(
				'results' => array(
					array(
						'origin' => $movement['origin'],
						'seq'    => $movement['seq'],
						'status' => 'received',
					),
				),
			);
		}

		return array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( $body ),
		);
	}

	/**
	 * Un par con sugerencia: el de acá y el de allá, en la copia, con el mismo SKU.
	 *
	 * @param int $remote El de allá.
	 *
	 * @return int El de acá.
	 */
	private function suggested( int $remote ): int {
		global $wpdb;

		$product = new WC_Product_Simple();
		$product->set_name( $this->run . ' ' . $remote );
		$product->set_sku( 'SKU' . $remote . '-' . $this->run );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 9 );
		$local = (int) $product->save();

		$wpdb->insert( // phpcs:ignore WordPress.DB
			Installer::table( Installer::REMOTE_PRODUCTS ),
			array(
				'remote_product' => $remote,
				'type'           => 'simple',
				'sku'            => 'SKU' . $remote . '-' . $this->run,
				'name'           => 'Allá ' . $remote,
				'stock'          => 4,
				'status'         => 'publish',
				'seen_at'        => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		return $local;
	}

	/**
	 * BLK-01 — **La masiva propone los pares con sugerencia**; los ambiguos quedan aparte,
	 * contados.
	 */
	public function test_the_preview_proposes_the_suggestions(): void {
		$local = $this->suggested( 700 );

		$preview = Bulk::preview();

		$this->assertContains(
			array(
				'local'  => $local,
				'remote' => 700,
			),
			$preview['pairs']
		);
		$this->assertArrayHasKey( 'ambiguous', $preview );
	}

	/**
	 * BLK-02 — **Cada par se vincula con los stocks recién leídos**: el de acá de la base, el de
	 * allá pedido en el momento. Arranca con el de la maestra.
	 */
	public function test_each_pair_is_linked_with_fresh_stocks(): void {
		$first  = $this->suggested( 701 );
		$second = $this->suggested( 702 );

		$result = Bulk::link(
			array(
				array(
					'local'  => $first,
					'remote' => 701,
				),
				array(
					'local'  => $second,
					'remote' => 702,
				),
			)
		);

		$this->assertSame( array( $first, $second ), array_column( $result['linked'], 'local' ) );
		$this->assertSame( array(), $result['omitted'] );
		$this->assertSame( 9, (int) Links::for_product( $first )->base_stock );
	}

	/**
	 * BLK-03 — **Revalida en el momento**: lo que cambió allá desde la copia —borrado, en la
	 * papelera, vinculado, otro SKU— y lo descartado acá se omiten con su motivo, y no cortan
	 * la tanda.
	 */
	public function test_each_pair_is_revalidated(): void {
		$gone      = $this->suggested( 711 );
		$trashed   = $this->suggested( 712 );
		$taken     = $this->suggested( 713 );
		$renamed   = $this->suggested( 714 );
		$discarded = $this->suggested( 715 );
		$fine      = $this->suggested( 716 );

		$this->fresh = array(
			711 => null,
			712 => array( 'status' => 'trash' ),
			713 => array( 'linked' => true ),
			714 => array( 'sku' => 'OTRO' ),
		);
		Events::record( Events::DISCARDED, Events::INFO, $discarded );

		$result = Bulk::link(
			array_map(
				static fn ( int $local, int $remote ) => array(
					'local'  => $local,
					'remote' => $remote,
				),
				array( $gone, $trashed, $taken, $renamed, $discarded, $fine ),
				range( 711, 716 )
			)
		);

		$this->assertSame( array( $fine ), array_column( $result['linked'], 'local' ) );
		$this->assertSame(
			array( 'target_missing', 'not_published', 'already_linked', 'sku_changed', 'discarded' ),
			array_column( $result['omitted'], 'reason' )
		);
		$this->assertSame( Events::reason_text( 'sku_changed' ), $result['omitted'][3]['text'] );
	}

	/**
	 * BLK-04 — **Reintentar no duplica**: un par ya vinculado vuelve omitido, `already_linked`.
	 */
	public function test_retrying_does_not_duplicate(): void {
		$local = $this->suggested( 720 );
		$pair  = array(
			array(
				'local'  => $local,
				'remote' => 720,
			),
		);

		Bulk::link( $pair );
		$again = Bulk::link( $pair );

		$this->assertSame( array(), $again['linked'] );
		$this->assertSame( 'already_linked', $again['omitted'][0]['reason'] );
	}

	/**
	 * BLK-05 — Más de 20 pares en una tanda se rechazan sin vincular ninguno.
	 */
	public function test_a_batch_has_at_most_twenty(): void {
		$pairs = array_fill(
			0,
			21,
			array(
				'local'  => 1,
				'remote' => 1,
			)
		);

		$this->assertSame( 'wss_bad_request', Bulk::link( $pairs )->get_error_code() );
	}
}
