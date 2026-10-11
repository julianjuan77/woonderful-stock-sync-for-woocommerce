<?php
/**
 * Tests de la comparación entre las dos tiendas.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Ledger\Ledger;
use Woonderful\StockSync\Linking\Links;
use Woonderful\StockSync\Log\Events;
use Woonderful\StockSync\Reconciliation\Crosscheck;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Pause;
use Woonderful\StockSync\Transport\Peers;

/**
 * Cada noche la maestra compara su stock con el de la otra, y avisa lo que no coincide
 * (ADR-021).
 */
class Test_Crosscheck extends WP_UnitTestCase {

	private const OTHER = 'b1b1b1b1-0000-4000-8000-000000000000';

	/**
	 * El stock de cada producto de la otra: un número, 'trash', o null si no lleva stock. Null
	 * entero si no responde.
	 *
	 * @var array<int, int|string|null>|null
	 */
	private ?array $remote = array();

	/**
	 * Los emails que salieron: asuntos y cuerpos.
	 *
	 * @var array<int, array{subject: string, body: string}>
	 */
	private array $mails = array();

	/**
	 * El próximo producto de la otra.
	 *
	 * @var int
	 */
	private int $next_remote = 900;

	public function set_up(): void {
		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		delete_option( \Woonderful\StockSync\Log\Peer::OPTION );
		delete_option( Peers::OPTION );
		delete_option( Crosscheck::OPTION );
		delete_option( 'wss_notify_state' );

		Installer::install();
		Store::ensure_identity();
		wss_unhook_detector();

		// La otra es la secundaria: ésta, la maestra.
		Peers::pair( self::OTHER, 'https://otra.test/wp-json/wss/v1', 'secreto', 'Minorista', Peers::SECONDARY );

		add_filter( 'pre_http_request', array( $this, 'catalog' ), 10, 3 );
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
	}

	public function tear_down(): void {
		global $wpdb;

		remove_filter( 'pre_http_request', array( $this, 'catalog' ), 10 );
		remove_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10 );

		$wpdb->query( 'DELETE FROM ' . Installer::table( 'events' ) ); // phpcs:ignore WordPress.DB

		foreach ( array( 'movements', 'links', 'nonces' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		delete_option( Crosscheck::OPTION );
		delete_option( Pause::OPTION );
		delete_option( 'wss_notify_state' );

		parent::tear_down();
	}

	/**
	 * Hace de `GET /products` de la otra con `include`.
	 *
	 * @param false|array|WP_Error $preempt Lo que venía.
	 * @param array<string, mixed> $args    Los argumentos.
	 * @param string               $url     La URL.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function catalog( $preempt, array $args, string $url ) {
		if ( null === $this->remote ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		}

		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$products = array();

		foreach ( array_map( 'intval', explode( ',', (string) ( $query['include'] ?? '' ) ) ) as $id ) {
			if ( array_key_exists( $id, $this->remote ) ) {
				$value      = $this->remote[ $id ];
				$products[] = array(
					'id'     => $id,
					'sku'    => '',
					'name'   => 'Remoto',
					'stock'  => is_int( $value ) ? $value : ( 'trash' === $value ? 5 : null ),
					'status' => 'trash' === $value ? 'trash' : 'publish',
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
	 * Junta los emails en vez de mandarlos.
	 *
	 * @param null|bool            $short Lo que venía.
	 * @param array<string, mixed> $atts  El email.
	 */
	public function catch_mail( $short, array $atts ): bool {
		$this->mails[] = array(
			'subject' => (string) $atts['subject'],
			'body'    => (string) $atts['message'],
		);

		return true;
	}

	/**
	 * Un producto de acá con ese stock, vinculado con uno de la otra que tiene otro.
	 *
	 * @param int             $here  El stock de acá.
	 * @param int|string|null $there El de allá.
	 *
	 * @return array{0: int, 1: int, 2: int} El producto, el de allá y el vínculo.
	 */
	private function pair( int $here, $there ): array {
		$product = new WC_Product_Simple();
		$product->set_name( 'Bolso' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $here );
		$product_id = (int) $product->save();

		$remote                  = $this->next_remote++;
		$this->remote[ $remote ] = $there;
		$link_id                 = (int) Links::create( $product_id, self::OTHER, $remote, 1, $here );

		return array( $product_id, $remote, $link_id );
	}

	/**
	 * Cuántos eventos de ese tipo hay.
	 *
	 * @param string $kind El tipo.
	 */
	private function events( string $kind ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Installer::table( 'events' ) . ' WHERE kind = %s', $kind ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * CRS-01 — **Una diferencia se confirma a los 2 minutos y se avisa**: lo que coincide no
	 * entra; la que sigue igual va al email con los dos números y queda en Registros.
	 */
	public function test_a_confirmed_difference_is_reported(): void {
		$this->pair( 10, 10 );
		list( $product, , $link ) = $this->pair( 7, 6 );

		$this->assertSame(
			array(
				'compared'  => 2,
				'suspected' => 1,
				'closed'    => 0,
			),
			Crosscheck::run()
		);
		$this->assertSame( array(), $this->mails, 'la primera medida no avisa' );

		Crosscheck::confirm( array( array( $link, 7, 6 ) ) );

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( '1 producto con stock distinto entre las tiendas', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'Bolso (#' . $product . ')', $this->mails[0]['body'] );
		$this->assertStringContainsString( 'tiene 7, Minorista tiene 6', $this->mails[0]['body'] );
		$this->assertSame( 1, $this->events( Events::CROSS_DIFF ) );
		$this->assertSame( array( 7, 6 ), array( Crosscheck::known()[ $link ]['here'], Crosscheck::known()[ $link ]['there'] ) );
	}

	/**
	 * CRS-02 — **Una venta que se estaba anotando no llega al email**: en la segunda medida
	 * los números cambiaron.
	 */
	public function test_a_difference_that_moves_is_not_reported(): void {
		list( , $remote, $link ) = $this->pair( 7, 8 );

		Crosscheck::run();
		$this->remote[ $remote ] = 7;
		Crosscheck::confirm( array( array( $link, 7, 8 ) ) );

		$this->assertSame( array(), $this->mails );
		$this->assertSame( array(), Crosscheck::known() );
	}

	/**
	 * CRS-03 — **Una vez por diferencia**: la misma, la noche siguiente, no se repite; una
	 * distinta, sí. Cuando vuelven a coincidir, se cierra y se olvida.
	 */
	public function test_each_difference_is_reported_once_and_closed(): void {
		list( , $remote, $link ) = $this->pair( 7, 6 );

		Crosscheck::confirm( array( array( $link, 7, 6 ) ) );
		Crosscheck::confirm( array( array( $link, 7, 6 ) ) );

		$this->assertCount( 1, $this->mails, 'la misma diferencia, una vez' );

		$this->remote[ $remote ] = 5;
		Crosscheck::confirm( array( array( $link, 7, 5 ) ) );

		$this->assertCount( 2, $this->mails, 'otra diferencia, de nuevo' );

		$this->remote[ $remote ] = 7;

		$this->assertSame( 1, Crosscheck::run()['closed'] );
		$this->assertSame( array(), Crosscheck::known() );
		$this->assertSame( 1, $this->events( Events::CROSS_DIFF_CLOSED ) );
	}

	/**
	 * CRS-04 — **No se compara lo que no tiene por qué coincidir**: un movimiento propio sin
	 * entregar, uno recibido retenido, y el de allá en la papelera o sin su stock.
	 */
	public function test_pairs_that_cannot_match_are_skipped(): void {
		global $wpdb;

		list( $pending ) = $this->pair( 7, 6 );
		Ledger::record( $pending, -1 );

		list( , $held_remote, $held_link ) = $this->pair( 7, 6 );
		$wpdb->insert( // phpcs:ignore WordPress.DB
			Installer::table( 'movements' ),
			array(
				'origin_store'   => self::OTHER,
				'origin_seq'     => 5,
				'generation'     => 1,
				'delta'          => -1,
				'reason'         => 'sale',
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
				'link_id'        => $held_link,
				'origin_product' => $held_remote,
				'application'    => Ledger::HELD,
			)
		);

		$this->pair( 7, 'trash' );
		$this->pair( 7, null );

		$this->assertSame( 0, Crosscheck::run()['compared'] ?? 0 );
	}

	/**
	 * CRS-05 — **Sólo la maestra, fuera de pausa y con la otra respondiendo**: si no, esa
	 * noche no se compara.
	 */
	public function test_it_only_runs_on_a_reachable_unpaused_master(): void {
		$this->pair( 7, 6 );

		$this->remote = null;
		$this->assertNull( Crosscheck::run(), 'la otra no responde' );

		$this->remote = array( 900 => 6 );
		Pause::set( true, Pause::HERE );
		$this->assertNull( Crosscheck::run(), 'en pausa' );
		Pause::set( false, Pause::HERE );

		Peers::pair( self::OTHER, 'https://otra.test/wp-json/wss/v1', 'secreto', 'Mayorista', Peers::MASTER );
		$this->assertNull( Crosscheck::run(), 'en la secundaria' );
	}

	/**
	 * CRS-06 — **Con los avisos apagados no sale email**, pero queda en Registros.
	 */
	public function test_no_email_when_notifications_are_off(): void {
		list( , , $link ) = $this->pair( 7, 6 );
		update_option( 'wss_notify_enabled', 'no' );

		Crosscheck::confirm( array( array( $link, 7, 6 ) ) );

		delete_option( 'wss_notify_enabled' );

		$this->assertSame( array(), $this->mails );
		$this->assertSame( 1, $this->events( Events::CROSS_DIFF ) );
	}
}
