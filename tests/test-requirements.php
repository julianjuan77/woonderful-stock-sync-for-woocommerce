<?php
/**
 * Tests de los requisitos de la configuración inicial.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Admin\Requirements;
use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Pass;

/**
 * Lo que el plugin necesita para funcionar, cada uno con su resultado (ADR-013 §3).
 */
class Test_Requirements extends WP_UnitTestCase {

	/**
	 * Lo que contesta la propia API REST.
	 *
	 * @var array<string, mixed>|WP_Error
	 */
	private $rest;

	public function set_up(): void {
		global $wpdb;

		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Installer::install();
		Store::ensure_identity();
		delete_option( Store::OPTION_FINGERPRINT );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s", Pass::HOOK ) ); // phpcs:ignore WordPress.DB

		$this->rest = $this->http( 401, array( 'code' => 'wss_missing_signature' ) );
		add_filter( 'pre_http_request', array( $this, 'own_rest' ) );
	}

	public function tear_down(): void {
		global $wpdb;

		remove_filter( 'pre_http_request', array( $this, 'own_rest' ) );
		remove_all_filters( 'option_home' );

		$wpdb->query( 'DELETE FROM ' . Installer::table( 'events' ) ); // phpcs:ignore WordPress.DB

		foreach ( array( 'movements', 'links', 'nonces' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		delete_option( Store::OPTION_FINGERPRINT );
		delete_option( Installer::OPTION_VERSION );
		delete_option( Store::OPTION_ID );

		parent::tear_down();
	}

	/**
	 * Hace de la propia API REST.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function own_rest() {
		return $this->rest;
	}

	/**
	 * Una respuesta HTTP.
	 *
	 * @param int                  $status El código.
	 * @param array<string, mixed> $body   El cuerpo.
	 *
	 * @return array<string, mixed>
	 */
	private function http( int $status, array $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => (string) wp_json_encode( $body ),
		);
	}

	/**
	 * Los requisitos, por identificador.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function checks(): array {
		return array_column( Requirements::check(), null, 'id' );
	}

	/**
	 * REQ-01 — **Los ocho, con los críticos del ADR**: versiones, tablas, no ser una copia,
	 * HTTPS y la API REST. La pasada, PHP-FPM y el correo, recomendados.
	 */
	public function test_the_critical_ones_are_the_adr_ones(): void {
		$critical = array_keys( array_filter( $this->checks(), static fn ( array $check ) => $check['critical'] ) );

		$this->assertSame( array( 'versions', 'tables', 'not_a_copy', 'https', 'rest' ), $critical );
		$this->assertCount( 8, $this->checks() );
	}

	/**
	 * REQ-02 — Lo que se cumple no trae cómo arreglarlo; lo que no, sí.
	 */
	public function test_what_passes_has_no_fix(): void {
		$checks = $this->checks();

		$this->assertTrue( $checks['versions']['pass'] );
		$this->assertNull( $checks['versions']['fix'] );
		$this->assertNotNull( $checks['mail']['fix'] );
	}

	/**
	 * REQ-03 — **El correo no se puede saber sin probar**: ni sí ni no.
	 */
	public function test_mail_is_unknown_until_tried(): void {
		$this->assertNull( $this->checks()['mail']['pass'] );
	}

	/**
	 * REQ-04 — **La API REST responde si el rechazo es de este plugin**; un 403 de otro, o un
	 * error de red, es que algo la bloquea.
	 */
	public function test_the_rest_api_answers_only_if_the_plugin_does(): void {
		$this->assertTrue( $this->checks()['rest']['pass'] );

		$this->rest = $this->http( 403, array( 'code' => 'rest_forbidden' ) );
		$blocked    = $this->checks()['rest'];

		$this->assertFalse( $blocked['pass'] );
		$this->assertSame( 'HTTP 403 rest_forbidden', $blocked['value'] );

		$this->rest = new WP_Error( 'http_request_failed', 'cURL error 7' );

		$this->assertFalse( $this->checks()['rest']['pass'] );
	}

	/**
	 * REQ-05 — Una tabla que falta, y una copia del sitio, no pasan.
	 */
	public function test_a_missing_table_and_a_copy_fail(): void {
		global $wpdb;

		update_option( Store::OPTION_FINGERPRINT, hash( 'sha256', 'https://otra-tienda.test' ) );
		$wpdb->query( 'DROP TABLE ' . Installer::table( 'nonces' ) ); // phpcs:ignore WordPress.DB

		$checks = $this->checks();

		$this->assertFalse( $checks['tables']['pass'] );
		$this->assertSame( Installer::table( 'nonces' ), $checks['tables']['value'] );
		$this->assertFalse( $checks['not_a_copy']['pass'] );
	}

	/**
	 * REQ-06 — En http, fuera de un entorno local, no se puede emparejar.
	 */
	public function test_http_fails_outside_a_local_environment(): void {
		add_filter( 'option_home', static fn () => 'http://tienda.test' );

		$this->assertFalse( $this->checks()['https']['pass'] );
	}

	/**
	 * REQ-07 — **La pasada, según la última ejecución completa en Action Scheduler**: reciente,
	 * pasa; hace una hora o nunca, no.
	 */
	public function test_the_pass_must_have_run_recently(): void {
		global $wpdb;

		$this->assertFalse( $this->checks()['pass']['pass'] );

		$id = as_schedule_single_action( time(), Pass::HOOK, array(), Pass::GROUP );
		$wpdb->update( $wpdb->prefix . 'actionscheduler_actions', array( 'status' => 'complete', 'last_attempt_gmt' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'action_id' => $id ) ); // phpcs:ignore WordPress.DB, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertTrue( $this->checks()['pass']['pass'] );

		$wpdb->update( $wpdb->prefix . 'actionscheduler_actions', array( 'last_attempt_gmt' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), array( 'action_id' => $id ) ); // phpcs:ignore WordPress.DB

		$this->assertFalse( $this->checks()['pass']['pass'] );
	}
}
