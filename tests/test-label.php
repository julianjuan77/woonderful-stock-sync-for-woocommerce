<?php
/**
 * Tests del nombre de la tienda, que llega a la otra.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Admin\Api;
use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Log\Peer;
use Woonderful\StockSync\Rest\Pair;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Pass;
use Woonderful\StockSync\Transport\Peers;

/**
 * Guardar el nombre se lo avisa a la otra; si no responde, la pasada reintenta (ADR-016).
 */
class Test_Label extends WP_UnitTestCase {

	private const REMOTE_STORE = 'c2c2c2c2-0000-4000-8000-000000000000';

	/**
	 * Lo que contesta la otra tienda.
	 *
	 * @var array<string, mixed>|WP_Error
	 */
	private $reply;

	/**
	 * Los requests a la otra: URL y argumentos.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $sent = array();

	public function set_up(): void {
		global $wp_rest_server;

		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Installer::install();
		Store::ensure_identity();

		foreach ( array( Peer::OPTION, Peers::OPTION_LABEL_PENDING ) as $option ) {
			delete_option( $option );
		}

		update_option( Store::OPTION_LABEL, 'Mayoristas', false );
		update_option(
			Peers::OPTION,
			array(
				self::REMOTE_STORE => array(
					'rest_url' => 'https://otra.test/wp-json/wss/v1',
					'secret'   => 'secreto-de-prueba',
					'role'     => Peers::SECONDARY,
					'label'    => 'Minorista',
				),
			),
			false
		);

		$this->reply = $this->http( 200 );
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );

		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		add_action( 'rest_api_init', array( Api::class, 'register_routes' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		delete_option( Peers::OPTION );

		parent::tear_down();
	}

	/**
	 * Hace de la otra tienda.
	 *
	 * @param false|array|WP_Error $preempt Lo que venía.
	 * @param array<string, mixed> $args    Los argumentos.
	 * @param string               $url     La URL.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function intercept( $preempt, array $args, string $url ) {
		$this->sent[] = array( $url, $args );

		return $this->reply;
	}

	/**
	 * Una respuesta HTTP.
	 *
	 * @param int $status El código.
	 *
	 * @return array<string, mixed>
	 */
	private function http( int $status ): array {
		return array(
			'headers'  => array(),
			'body'     => '{}',
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Guarda el nombre desde la pantalla.
	 *
	 * @param string $label El nombre.
	 */
	private function save( string $label ): void {
		$request = new WP_REST_Request( 'POST', '/wss-admin/v1/store' );
		$request->set_param( 'label', $label );
		rest_get_server()->dispatch( $request );
	}

	/**
	 * LBL-01 — **Guardar un nombre nuevo se lo manda a la otra** con `POST /label` firmado.
	 * Guardar el mismo nombre no manda nada.
	 */
	public function test_a_new_name_is_sent(): void {
		$this->save( 'Mayoristas Pergamino' );

		$this->assertSame( 'https://otra.test/wp-json/wss/v1/label', $this->sent[0][0] );
		$this->assertSame( array( 'label' => 'Mayoristas Pergamino' ), json_decode( $this->sent[0][1]['body'], true ) );
		$this->assertArrayHasKey( 'X-WSS-Signature', $this->sent[0][1]['headers'] );
		$this->assertFalse( get_option( Peers::OPTION_LABEL_PENDING, false ) );

		$this->save( 'Mayoristas Pergamino' );

		$this->assertCount( 1, $this->sent, 'el mismo nombre no viaja' );
	}

	/**
	 * LBL-02 — **Con la otra caída queda pendiente**, y la pasada lo manda cuando la otra
	 * responde. Mientras está caída, la pasada no lo intenta.
	 */
	public function test_a_pending_name_is_retried_by_the_pass(): void {
		$this->reply = new WP_Error( 'http_request_failed', 'cURL error 7' );
		$this->save( 'Mayoristas Pergamino' );

		$this->assertTrue( (bool) get_option( Peers::OPTION_LABEL_PENDING ) );

		update_option( Peer::OPTION, array( 'state' => Peer::UNREACHABLE ), false );
		$this->sent = array();
		Pass::run();

		$this->assertSame( array(), array_filter( $this->sent, static fn ( array $r ) => str_ends_with( $r[0], '/label' ) ), 'caída, no se intenta' );

		update_option( Peer::OPTION, array( 'state' => Peer::OK ), false );
		$this->reply = $this->http( 200 );
		Pass::run();

		$this->assertFalse( get_option( Peers::OPTION_LABEL_PENDING, false ) );
	}

	/**
	 * LBL-03 — **El nombre que llega se guarda en la entrada de quien lo manda**, sin tocar su
	 * URL ni su secreto. Vacío o de más de 100 caracteres, un 400.
	 */
	public function test_the_received_name_is_stored(): void {
		$request = new WP_REST_Request( 'POST', '/wss/v1/label' );
		$request->set_header( 'X-WSS-Store', self::REMOTE_STORE );
		$request->set_body( '{"label":"Minorista Centro"}' );
		Pair::label( $request );

		$this->assertSame( 'Minorista Centro', Peers::describe()['label'] );
		$this->assertSame( 'secreto-de-prueba', Peers::secret( self::REMOTE_STORE ) );

		$request->set_body( '{"label":""}' );
		$this->assertSame( 400, Pair::label( $request )->get_error_data()['status'] );

		$request->set_body( (string) wp_json_encode( array( 'label' => str_repeat( 'a', 101 ) ) ) );
		$this->assertSame( 400, Pair::label( $request )->get_error_data()['status'] );
	}

	/**
	 * LBL-04 — **Desemparejar olvida el nombre pendiente**: no hay a quién mandárselo.
	 */
	public function test_unpairing_drops_the_pending_name(): void {
		update_option( Peers::OPTION_LABEL_PENDING, true, false );

		Peers::forget( 'here' );

		$this->assertFalse( get_option( Peers::OPTION_LABEL_PENDING, false ) );
	}
}
