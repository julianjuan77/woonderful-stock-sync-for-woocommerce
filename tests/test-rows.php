<?php
/**
 * Tests de las filas de Productos.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Catalog\Rows;
use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Ledger\Ledger;
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
		// El texto, no `reason_text()`: comparar contra la misma función daba verde con el código crudo.
		$this->assertSame( 'el de la otra tienda está en la papelera', $row['reason'] );
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

	/**
	 * ROW-09 — **Un padre variable de allá no se sugiere**, aunque tenga el mismo SKU y lleve
	 * el stock de sus variaciones: lo que se vincula es cada variación.
	 */
	public function test_a_variable_parent_is_not_suggested(): void {
		$product = $this->simple( 'PADRE' );
		$this->remote( 930, 'PADRE', array( 'type' => 'variable' ) );

		$this->assertSame( Rows::NO_MATCH, $this->rows()[ $product ]['motive'] );
	}

	/**
	 * ROW-10 — **La cuenta de Resumen es la del filtro "Sin vincular"**, con un descartado, uno
	 * descartado en la papelera, uno vinculado y un padre que lleva el stock: un solo número. Y
	 * los vinculados, los de los filtros "Vinculado" y "Suspendido" juntos.
	 */
	public function test_the_unlinked_count_matches_the_filter(): void {
		$loose     = $this->simple( 'SUELTO' );
		$discarded = $this->simple( 'DESC' );
		$trashed   = $this->simple( 'PAPEL', 5, 'trash' );
		$linked    = $this->simple( 'VINC' );
		Links::create( $linked, self::REMOTE_STORE, 940, 1, 5 );
		Events::record( Events::DISCARDED, Events::INFO, $discarded );
		Events::record( Events::DISCARDED, Events::INFO, $trashed );

		$parent = new WC_Product_Variable();
		$parent->set_name( $this->run . ' padre' );
		$parent->set_manage_stock( true );
		$parent->set_stock_quantity( 9 );
		$parent_id = (int) $parent->save();
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->save();

		$counts = Rows::page( array() )['counts'];

		$this->assertSame( $counts[ State::UNLINKED ], Rows::linking()['unlinked'] );
		$this->assertSame( $counts[ State::LINKED ] + $counts[ State::SUSPENDED ], Rows::linking()['linked'] );
		$this->assertArrayHasKey( $loose, $this->rows( array( 'state' => State::UNLINKED ) ) );
	}

	/**
	 * ROW-11 — **Se pagina de a 50 productos, no de a 50 filas**: un padre va entero, con todas
	 * sus variaciones, en la misma página. Y trae su resumen: cuántas y en qué estado.
	 */
	public function test_a_parent_is_never_split_across_pages(): void {
		$parent = new WC_Product_Variable();
		$parent->set_name( $this->run . ' alpargata' );
		$parent_id = (int) $parent->save();

		$variations = array();

		foreach ( array( 39, 40, 41 ) as $size ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( $size );
			$variations[] = (int) $variation->save();
		}

		Links::create( $variations[0], self::REMOTE_STORE, 950, 1, 39 );
		$this->remote( 950, 'V39', array( 'stock' => 39, 'linked' => 1 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		for ( $i = 0; $i < 50; $i++ ) {
			$this->simple( 'P' . $i );
		}

		$first  = Rows::page( array( 'search' => $this->run ) );
		$second = Rows::page( array( 'search' => $this->run ), 2 );

		$this->assertSame( 51, $first['total'], 'productos, no filas' );
		$this->assertCount( 52, $first['rows'], 'las 3 variaciones y 49 simples' );
		$this->assertSame( $variations, array_slice( array_column( $first['rows'], 'id' ), 0, 3 ) );
		$this->assertCount( 1, $second['rows'] );
		$this->assertSame(
			array(
				'total'    => 3,
				'linked'   => 1,
				'unlinked' => 2,
			),
			$first['summary'][ $parent_id ]
		);
	}

	/**
	 * ROW-12 — **Cuántos sin vincular hay por motivo**, para las pestañas: los descartados
	 * también, aunque no se listen.
	 */
	public function test_unlinked_rows_are_counted_by_motive(): void {
		$this->simple( '' );
		$this->simple( 'SOLO' );
		$discarded = $this->simple( 'DESC' );
		Events::record( Events::DISCARDED, Events::INFO, $discarded );

		$motives = Rows::page( array( 'search' => $this->run ) )['motives'];

		$this->assertSame( 1, $motives[ Rows::NO_SKU ] );
		$this->assertSame( 1, $motives[ Rows::NO_MATCH ] );
		$this->assertSame( 1, $motives[ Rows::DISCARDED ] );
	}

	/**
	 * ROW-13 — **Primero lo vinculado**: vinculado, con sugerencia, sin vincular y descartado,
	 * aunque se hayan creado al revés.
	 */
	public function test_linked_rows_come_first(): void {
		$discarded = $this->simple( 'DESC' );
		Events::record( Events::DISCARDED, Events::INFO, $discarded );
		$loose      = $this->simple( 'SOLO' );
		$suggestion = $this->simple( 'SUG' );
		$this->remote( 950, 'SUG' );
		$linked = $this->simple( 'VINC' );
		$this->remote( 951, 'VINC', array( 'linked' => 1 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		Links::create( $linked, self::REMOTE_STORE, 951, 1, 5 );

		$this->assertSame( array( $linked, $suggestion, $loose, $discarded ), array_keys( $this->rows() ) );
	}

	/**
	 * ROW-14 — **Desvinculado acá, sin confirmar allá**: la fila dice que se está
	 * desvinculando hasta que la otra lo confirma.
	 */
	public function test_an_unconfirmed_unlink_is_shown(): void {
		global $wpdb;

		$product = $this->simple( 'DESV' );
		Links::create( $product, self::REMOTE_STORE, 960, 1, 5 );
		$movement = Ledger::record_unlink( $product );

		$this->assertTrue( $this->rows()[ $product ]['unlinking'] );

		$wpdb->update( Installer::table( 'movements' ), array( 'delivery' => 'confirmed' ), array( 'id' => $movement ) ); // phpcs:ignore WordPress.DB

		$this->assertFalse( $this->rows()[ $product ]['unlinking'] );
	}

	/**
	 * Una fila de `Rows::all()`, por ID: un borrado no tiene la marca de la corrida.
	 *
	 * @param int $id El producto.
	 *
	 * @return array<string, mixed>|null
	 */
	private function any_row( int $id ): ?array {
		return array_column( Rows::all(), null, 'id' )[ $id ] ?? null;
	}

	/**
	 * ROW-15 — **Un vinculado que se borró se sigue viendo**, suspendido, con el de allá: si
	 * no, el par desaparece y no hay de dónde desvincularlo (ADR-018 §6). Y cuenta como
	 * vinculado.
	 */
	public function test_a_deleted_linked_product_is_still_listed(): void {
		$product = $this->simple( 'BORR' );
		$this->remote( 970, 'BORR', array( 'linked' => 1 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		Links::create( $product, self::REMOTE_STORE, 970, 1, 5 );
		wp_delete_post( $product, true );

		$row = $this->any_row( $product );

		$this->assertNotNull( $row );
		$this->assertSame( State::SUSPENDED, $row['state'] );
		$this->assertSame( 'se borró', $row['reason'] );
		$this->assertStringContainsString( (string) $product, $row['name'] );
		$this->assertNull( $row['stock'] );
		$this->assertSame( 970, $row['remote']['id'] );
		$this->assertSame( 1, Rows::linking()['linked'] );
	}

	/**
	 * ROW-16 — **Un vinculado que dejó de llevar su stock también se ve**, suspendido y sin
	 * número.
	 */
	public function test_a_linked_product_without_own_stock_is_still_listed(): void {
		$product = $this->simple( 'SINS' );
		Links::create( $product, self::REMOTE_STORE, 971, 1, 5 );

		$item = wc_get_product( $product );
		$item->set_manage_stock( false );
		$item->save();

		$row = $this->rows()[ $product ];

		$this->assertSame( State::SUSPENDED, $row['state'] );
		$this->assertSame( 'dejó de llevar su propio stock', $row['reason'] );
		$this->assertNull( $row['stock'] );
	}

	/**
	 * ROW-17 — **Stock distinto**: un vinculado con otro número allá se marca, se cuenta, se
	 * filtra, y su padre dice cuántas variaciones difieren. Uno igual, o suspendido, no.
	 */
	public function test_linked_rows_with_a_different_stock_are_flagged(): void {
		$parent = new WC_Product_Variable();
		$parent->set_name( $this->run . ' corralera' );
		$parent_id = (int) $parent->save();

		$ids = array();

		foreach ( array( 34, 35 ) as $size ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( 10 );
			$ids[] = (int) $variation->save();
		}

		$this->remote( 980, 'C34', array( 'stock' => 12, 'linked' => 1 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->remote( 981, 'C35', array( 'stock' => 10, 'linked' => 1 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		Links::create( $ids[0], self::REMOTE_STORE, 980, 1, 10 );
		Links::create( $ids[1], self::REMOTE_STORE, 981, 1, 10 );

		$suspended = $this->simple( 'SUSP', 4 );
		$this->remote( 982, 'SUSP', array( 'stock' => 9, 'status' => 'trash', 'linked' => 1 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		Links::create( $suspended, self::REMOTE_STORE, 982, 1, 4 );

		$all = Rows::page( array( 'search' => $this->run ) );

		$this->assertTrue( array_column( $all['rows'], 'differs', 'id' )[ $ids[0] ] );
		$this->assertFalse( array_column( $all['rows'], 'differs', 'id' )[ $ids[1] ] );
		$this->assertFalse( array_column( $all['rows'], 'differs', 'id' )[ $suspended ], 'suspendido es otra cosa' );
		$this->assertSame( 1, $all['counts'][ Rows::DIFFERS ] );
		$this->assertSame( 1, $all['summary'][ $parent_id ][ Rows::DIFFERS ] );

		$filtered = Rows::page(
			array(
				'search' => $this->run,
				'state'  => Rows::DIFFERS,
			)
		);

		$this->assertSame( array( $ids[0] ), array_column( $filtered['rows'], 'id' ) );
	}
}
