<?php
/**
 * Tests de las filas de Productos.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Catalog\Rows;
use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Linking\Links;
use Woonderful\StockSync\Linking\State;
use Woonderful\StockSync\Log\Events;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Peers;

/**
 * Una fila por vinculable, con los dos lados, su estado, su motivo y su sugerencia (ADR-014 §3,
 * §4).
 */
class Test_Rows extends WP_UnitTestCase {

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

	public function set_up(): void {
		global $wpdb;

		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Installer::install();
		Store::ensure_identity();
		wss_unhook_detector();

		$wpdb->query( 'DELETE FROM ' . Installer::table( Installer::REMOTE_PRODUCTS ) ); // phpcs:ignore WordPress.DB
		Peers::pair( self::REMOTE_STORE, 'https://otra.test/wp-json/wss/v1', 'secreto', 'Mayorista', Peers::SECONDARY );

		$this->run = 'r' . wp_generate_password( 8, false );
	}

	public function tear_down(): void {
		global $wpdb;

		wss_unhook_detector();
		$wpdb->query( 'DELETE FROM ' . Installer::table( 'events' ) ); // phpcs:ignore WordPress.DB

		foreach ( array( 'movements', 'links', 'nonces' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		delete_option( Peers::OPTION );
		delete_option( Installer::OPTION_VERSION );
		delete_option( Store::OPTION_ID );

		parent::tear_down();
	}

	/**
	 * Un simple de acá.
	 *
	 * @param string   $sku    El SKU.
	 * @param int|null $stock  Su stock, o null si no lo lleva.
	 * @param string   $status El estado.
	 */
	private function simple( string $sku, ?int $stock = 5, string $status = 'publish' ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $this->run . ' simple ' . $sku );
		$product->set_sku( '' === $sku ? '' : $sku . '-' . $this->run );
		$product->set_manage_stock( null !== $stock );
		$product->set_stock_quantity( $stock ?? 0 );
		$product->set_status( $status );

		return (int) $product->save();
	}

	/**
	 * Uno de allá, en la copia.
	 *
	 * @param int                  $id      Su ID.
	 * @param string               $sku     El SKU, sin la marca.
	 * @param array<string, mixed> $columns Lo que cambia.
	 */
	private function remote( int $id, string $sku, array $columns = array() ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB
			Installer::table( Installer::REMOTE_PRODUCTS ),
			array_merge(
				array(
					'remote_product' => $id,
					'type'           => 'simple',
					'sku'            => '' === $sku ? '' : $sku . '-' . $this->run,
					'name'           => 'Allá ' . $sku,
					'stock'          => 5,
					'status'         => 'publish',
					'linked'         => 0,
					'seen_at'        => gmdate( 'Y-m-d H:i:s' ),
				),
				$columns
			)
		);
	}

	/**
	 * Las filas de esta corrida, por producto.
	 *
	 * @param array<string, mixed> $filters Los filtros.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows( array $filters = array() ): array {
		$rows = Rows::page( array( 'search' => $this->run ) + $filters )['rows'];

		return array_column( $rows, null, 'id' );
	}

	/**
	 * ROW-01 — **Un vinculado muestra los dos lados**: el de allá sale de la copia.
	 */
	public function test_a_linked_row_has_both_sides(): void {
		$product = $this->simple( 'ALP' );
		$this->remote( 900, 'ALP', array( 'stock' => 6, 'linked' => 1 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		Links::create( $product, self::REMOTE_STORE, 900, 1, 5 );

		$row = $this->rows()[ $product ];

		$this->assertSame( State::LINKED, $row['state'] );
		$this->assertNull( $row['motive'] );
		$this->assertSame( 900, $row['remote']['id'] );
		$this->assertSame( 6, $row['remote']['stock'] );
		$this->assertSame( 5, $row['stock'] );
	}

	/**
	 * ROW-02 — **El estado sale de la copia**: el de allá en la papelera suspende el par, con
	 * el motivo en castellano.
	 */
	public function test_the_state_comes_from_the_copy(): void {
		$product = $this->simple( 'ALP' );
		$this->remote( 900, 'ALP', array( 'status' => 'trash', 'linked' => 1 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		Links::create( $product, self::REMOTE_STORE, 900, 1, 5 );

		$row = $this->rows()[ $product ];

		$this->assertSame( State::SUSPENDED, $row['state'] );
		$this->assertSame( Events::reason_text( 'remote_trashed' ), $row['reason'] );
	}

	/**
	 * ROW-03 — **Uno solo de allá con el mismo SKU, sin vincular, es la sugerencia**; el SKU
	 * se compara sin espacios y sin mayúsculas.
	 */
	public function test_a_single_match_is_suggested(): void {
		$product = $this->simple( 'alp-v39' );
		$this->remote( 901, 'ALP-V39' );

		global $wpdb;
		$wpdb->update( Installer::table( Installer::REMOTE_PRODUCTS ), array( 'sku' => ' ALP-V39-' . strtoupper( $this->run ) . ' ' ), array( 'remote_product' => 901 ) ); // phpcs:ignore WordPress.DB

		$row = $this->rows()[ $product ];

		$this->assertSame( State::UNLINKED, $row['state'] );
		$this->assertSame( Rows::SUGGESTION, $row['motive'] );
		$this->assertSame( 901, $row['suggestion']['id'] );
	}

	/**
	 * ROW-04 — **Los motivos de un sin vincular**: sin SKU, SKU duplicado de este lado o del
	 * otro, sin coincidencia, en la papelera; y uno de allá que ya tiene par no se sugiere.
	 */
	public function test_the_motives_of_an_unlinked_row(): void {
		$no_sku       = $this->simple( '' );
		$twin_a       = $this->simple( 'TWIN' );
		$twin_b       = $this->simple( 'GEMELO' );
		$there_twice  = $this->simple( 'DOS' );
		$alone        = $this->simple( 'SOLO' );
		$taken        = $this->simple( 'TOMADO' );
		$trashed      = $this->simple( 'PAPELERA', 5, 'trash' );
		$not_managing = $this->simple( 'SINSTOCK', null );

		// WooCommerce no deja repetir un SKU: llega así por una importación o por SQL.
		update_post_meta( $twin_b, '_sku', ' twin-' . $this->run );
		$this->remote( 910, 'TWIN' );
		$this->remote( 911, 'DOS' );
		$this->remote( 912, 'DOS' );
		$this->remote( 913, 'TOMADO', array( 'linked' => 1 ) );
		$this->remote( 914, 'PAPELERA' );

		$rows = $this->rows();

		$this->assertSame( Rows::NO_SKU, $rows[ $no_sku ]['motive'] );
		$this->assertSame( Rows::DUPLICATE_SKU, $rows[ $twin_a ]['motive'] );
		$this->assertSame( Rows::DUPLICATE_SKU, $rows[ $twin_b ]['motive'] );
		$this->assertSame( Rows::DUPLICATE_SKU, $rows[ $there_twice ]['motive'] );
		$this->assertSame( Rows::NO_MATCH, $rows[ $alone ]['motive'] );
		$this->assertSame( Rows::NO_MATCH, $rows[ $taken ]['motive'] );
		$this->assertSame( Rows::TRASHED, $rows[ $trashed ]['motive'] );
		$this->assertArrayNotHasKey( $not_managing, $rows, 'sin su propio stock no se puede vincular' );
	}

	/**
	 * ROW-05 — **Lo descartado no se ve, salvo pedido o buscado**: la búsqueda lo encuentra
	 * (ADR-014 §4). Deshacer el descarte lo devuelve.
	 *
	 * Sin búsqueda se comparan los totales: el log se vacía en cada test, así que el único
	 * descartado es éste.
	 */
	public function test_discarded_rows_are_hidden_until_asked(): void {
		$product = $this->simple( 'ALP' );
		$this->remote( 901, 'ALP' );
		Events::record( Events::DISCARDED, Events::INFO, $product );

		$hidden = Rows::page( array() )['total'];
		$shown  = Rows::page( array( 'show_discarded' => true ) )['total'];

		$this->assertSame( $hidden + 1, $shown );
		$this->assertSame( Rows::DISCARDED, $this->rows()[ $product ]['motive'], 'la búsqueda lo encuentra' );

		Events::record( Events::UNDISCARDED, Events::INFO, $product );

		$this->assertSame( Rows::SUGGESTION, $this->rows()[ $product ]['motive'] );
	}

	/**
	 * ROW-06 — **Las variaciones van juntas y debajo de su padre**, con su nombre; un variable
	 * que lleva el stock en el padre lo dice en su propia fila.
	 */
	public function test_variations_are_grouped_under_their_parent(): void {
		$parent = new WC_Product_Variable();
		$parent->set_name( $this->run . ' alpargata' );
		$parent_id = (int) $parent->save();

		$ids = array();

		foreach ( array( 39, 40 ) as $size ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( $size );
			$ids[] = (int) $variation->save();
		}

		$shared = new WC_Product_Variable();
		$shared->set_name( $this->run . ' ojota' );
		$shared->set_manage_stock( true );
		$shared->set_stock_quantity( 30 );
		$shared_id = (int) $shared->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $shared_id );
		$variation->save();

		$rows = Rows::page( array( 'search' => $this->run ) )['rows'];

		$this->assertSame( array( $ids[0], $ids[1], $shared_id ), array_column( $rows, 'id' ) );
		$this->assertSame( $this->run . ' alpargata', $rows[0]['parent_name'] );
		$this->assertSame( Rows::STOCK_IN_PARENT, $rows[2]['motive'] );
	}

	/**
	 * ROW-07 — **Filtros, conteos y búsqueda sobre todo el catálogo**: por estado —"con
	 * sugerencia" incluido—, por motivo, y buscando también el nombre del otro lado.
	 */
	public function test_filters_counts_and_search(): void {
		$linked     = $this->simple( 'VINC' );
		$suggestion = $this->simple( 'SUG' );
		$alone      = $this->simple( 'SOLO' );
		$this->remote( 920, 'VINC', array( 'name' => 'Nombre de allá ' . $this->run ) );
		$this->remote( 921, 'SUG' );
		Links::create( $linked, self::REMOTE_STORE, 920, 1, 5 );

		$page = Rows::page( array( 'search' => $this->run ) );

		$this->assertSame( 3, $page['counts']['all'] );
		$this->assertSame( 2, $page['counts'][ State::UNLINKED ] );
		$this->assertSame( 1, $page['counts'][ Rows::SUGGESTION ] );
		$this->assertSame( 1, $page['counts'][ State::LINKED ] );
		$this->assertSame( array( $suggestion ), array_keys( $this->rows( array( 'state' => Rows::SUGGESTION ) ) ) );
		$this->assertEqualsCanonicalizing( array( $suggestion, $alone ), array_keys( $this->rows( array( 'state' => State::UNLINKED ) ) ) );
		$this->assertSame( array( $alone ), array_keys( $this->rows( array( 'motive' => Rows::NO_MATCH ) ) ) );
		$this->assertSame( array( $linked ), array_column( Rows::page( array( 'search' => 'nombre de allá ' . $this->run ) )['rows'], 'id' ) );
	}

	/**
	 * ROW-08 — De a 50, con el total de lo filtrado.
	 */
	public function test_rows_come_in_pages_of_fifty(): void {
		for ( $i = 0; $i < 52; $i++ ) {
			$this->simple( 'P' . $i );
		}

		$second = Rows::page( array( 'search' => $this->run ), 2 );

		$this->assertSame( 52, $second['total'] );
		$this->assertCount( 2, $second['rows'] );
	}
}
