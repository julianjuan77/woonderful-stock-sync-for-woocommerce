<?php
/**
 * Tests de la copia del catálogo de la otra tienda.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Catalog\Copy;
use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Log\Peer;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Peers;

/**
 * `wss_remote_products` y su refresco (ADR-014 §1).
 */
class Test_Copy extends WP_UnitTestCase {

	/**
	 * La otra tienda.
	 */
	private const REMOTE_STORE = 'b1b1b1b1-0000-4000-8000-000000000000';

	public function set_up(): void {
		global $wpdb;

		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Installer::install();
		Store::ensure_identity();

		// En el set_up: el DROP del tear_down confirma lo que escribió el test anterior.
		$wpdb->query( 'DELETE FROM ' . Installer::table( Installer::REMOTE_PRODUCTS ) ); // phpcs:ignore WordPress.DB
		delete_option( Copy::OPTION_RUN );
		delete_option( Copy::OPTION_REFRESHED );
		delete_option( Peer::OPTION );

		Peers::pair( self::REMOTE_STORE, 'https://otra.test/wp-json/wss/v1', 'secreto', 'Mayorista', Peers::SECONDARY );
	}

	public function tear_down(): void {
		global $wpdb;

		remove_all_filters( 'pre_http_request' );

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
	 * La otra tienda contesta `GET /products` con este catálogo, de a 100.
	 *
	 * @param array<int, array<string, mixed>> $catalog Los productos.
	 * @param int|null                         $fails   Una página que contesta 500.
	 */
	private function remote_catalog( array $catalog, ?int $fails = null ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $catalog, $fails ) {
				parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
				$page = (int) ( $query['page'] ?? 1 );

				if ( $page === $fails ) {
					return array(
						'response' => array( 'code' => 500 ),
						'body'     => '',
					);
				}

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => (string) wp_json_encode(
						array(
							'products' => array_slice( $catalog, ( $page - 1 ) * 100, 100 ),
							'total'    => count( $catalog ),
						)
					),
				);
			},
			10,
			3
		);
	}

	/**
	 * Un producto de la otra, como viaja.
	 *
	 * @param int                  $id      Su ID.
	 * @param array<string, mixed> $changes Lo que cambia.
	 *
	 * @return array<string, mixed>
	 */
	private function remote( int $id, array $changes = array() ): array {
		return array_merge(
			array(
				'id'     => $id,
				'sku'    => 'SKU-' . $id,
				'name'   => 'Producto ' . $id,
				'stock'  => 5,
				'status' => 'publish',
				'linked' => false,
				'type'   => 'simple',
				'parent' => 0,
			),
			$changes
		);
	}

	/**
	 * Las filas de la copia, por producto.
	 *
	 * @return array<int, \stdClass>
	 */
	private function rows(): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . Installer::table( Installer::REMOTE_PRODUCTS ) . ' ORDER BY remote_product' ); // phpcs:ignore WordPress.DB

		return array_combine( array_map( static fn ( $row ) => (int) $row->remote_product, $rows ), $rows );
	}

	/**
	 * Una fila que vio un refresco anterior.
	 *
	 * @param int $id El producto.
	 */
	private function seen_before( int $id ): void {
		global $wpdb;

		$wpdb->insert( Installer::table( Installer::REMOTE_PRODUCTS ), array( 'remote_product' => $id, 'name' => 'Viejo', 'seen_at' => '2026-01-01 00:00:00' ) ); // phpcs:ignore WordPress.DB, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * CPY-01 — **El refresco va de a una página por llamada, con su avance**: cada producto
	 * con su padre, su tipo y su stock —`NULL` si no lleva el suyo—; al terminar, anota cuándo.
	 */
	public function test_the_refresh_goes_page_by_page(): void {
		$catalog = array_map( fn ( $id ) => $this->remote( $id ), range( 1, 150 ) );
		$catalog[149] = $this->remote( 150, array( 'type' => 'variation', 'parent' => 7, 'stock' => null, 'linked' => true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->remote_catalog( $catalog );

		$first = Copy::refresh_page( 1 );

		$this->assertSame( array( 'page' => 1, 'pages' => 2, 'total' => 150, 'done' => false ), $first ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertCount( 100, $this->rows() );
		$this->assertNull( Copy::age() );

		$this->assertTrue( Copy::refresh_page( 2 )['done'] );

		$rows = $this->rows();

		$this->assertCount( 150, $rows );
		$this->assertSame( '7', $rows[150]->parent );
		$this->assertSame( 'variation', $rows[150]->type );
		$this->assertNull( $rows[150]->stock );
		$this->assertSame( '1', $rows[150]->linked );
		$this->assertSame( '5', $rows[1]->stock );
		$this->assertSame( 0, Copy::age() );
		$this->assertFalse( Copy::is_stale() );
	}

	/**
	 * CPY-02 — **Un refresco completo borra lo que ya no está allá**, y pisa lo que cambió.
	 */
	public function test_a_complete_refresh_forgets_what_is_gone(): void {
		$this->seen_before( 1 );
		$this->seen_before( 99 );
		$this->remote_catalog( array( $this->remote( 1, array( 'stock' => 8 ) ) ) );

		$this->assertTrue( Copy::refresh() );

		$rows = $this->rows();

		$this->assertSame( array( 1 ), array_keys( $rows ) );
		$this->assertSame( 'Producto 1', $rows[1]->name );
		$this->assertSame( '8', $rows[1]->stock );
	}

	/**
	 * CPY-03 — **Un refresco cortado a mitad no borra nada**, ni se da por hecho.
	 */
	public function test_a_cut_refresh_forgets_nothing(): void {
		$this->seen_before( 999 );
		$this->remote_catalog( array_map( fn ( $id ) => $this->remote( $id ), range( 1, 150 ) ), 2 );

		$this->assertWPError( Copy::refresh() );

		$this->assertArrayHasKey( 999, $this->rows() );
		$this->assertNull( Copy::age() );
		$this->assertTrue( Copy::is_stale() );
	}

	/**
	 * CPY-04 — Seguir un refresco que no empezó no hace nada: la página 1 lo empieza.
	 */
	public function test_a_refresh_starts_at_page_one(): void {
		$this->remote_catalog( array( $this->remote( 1 ) ) );

		$this->assertSame( 'wss_refresh_not_started', Copy::refresh_page( 2 )->get_error_code() );
		$this->assertSame( array(), $this->rows() );
	}

	/**
	 * CPY-05 — Un catálogo vacío allá deja la copia vacía.
	 */
	public function test_an_empty_catalog_empties_the_copy(): void {
		$this->seen_before( 5 );
		$this->remote_catalog( array() );

		$this->assertTrue( Copy::refresh_page( 1 )['done'] );
		$this->assertSame( array(), $this->rows() );
	}

	/**
	 * CPY-06 — Pasados 10 minutos, la pantalla la refresca al abrirse.
	 */
	public function test_it_is_stale_after_ten_minutes(): void {
		update_option( Copy::OPTION_REFRESHED, time() - Copy::MAX_AGE - 1 );

		$this->assertTrue( Copy::is_stale() );

		update_option( Copy::OPTION_REFRESHED, time() - Copy::MAX_AGE + 5 );

		$this->assertFalse( Copy::is_stale() );
	}

	/**
	 * CPY-07 — **Emparejarse con otra tienda vacía la copia**: era de la anterior. Volver a
	 * emparejarse con la misma, no.
	 */
	public function test_pairing_with_another_store_clears_it(): void {
		$this->seen_before( 1 );
		update_option( Copy::OPTION_REFRESHED, time() );

		Peers::pair( self::REMOTE_STORE, 'https://otra.test/wp-json/wss/v1', 'otro-secreto', 'Mayorista', Peers::SECONDARY );

		$this->assertCount( 1, $this->rows() );

		Peers::pair( 'c2c2c2c2-0000-4000-8000-000000000000', 'https://nueva.test/wp-json/wss/v1', 'secreto', 'Nueva', Peers::SECONDARY );

		$this->assertSame( array(), $this->rows() );
		$this->assertNull( Copy::age() );
	}
}
