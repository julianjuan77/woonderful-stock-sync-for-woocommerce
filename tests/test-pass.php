<?php
/**
 * Tests de la pasada recurrente.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Ledger\Ledger;
use Woonderful\StockSync\Plugin;
use Woonderful\StockSync\Reconciliation\Schedule;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Nonces;
use Woonderful\StockSync\Transport\Pass;
use Woonderful\StockSync\Transport\Sender;
use Woonderful\StockSync\Transport\Peers;

/**
 * Una acción recurrente que vacía pendientes y purga nonces (ADR-001 §2).
 */
class Test_Pass extends WP_UnitTestCase {

	private const REMOTE_STORE = 'b1b1b1b1-0000-4000-8000-000000000000';

	/**
	 * Cuántos requests salieron.
	 *
	 * @var int
	 */
	private int $sent = 0;

	public function set_up(): void {
		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		// El DROP TABLE del tear_down confirma lo escrito: un fallo de otro test lo dejaría.
		delete_transient( Sender::FAILED );

		Installer::install();
		Store::ensure_identity();
		wss_unhook_detector();

		update_option( Store::OPTION_FINGERPRINT, Store::fingerprint() );
		update_option(
			Peers::OPTION,
			array(
				self::REMOTE_STORE => array(
					'rest_url' => 'https://otra.test/wp-json/wss/v1',
					'secret'   => 'secreto-de-prueba',
				),
			),
			false
		);

		add_filter( 'pre_http_request', array( $this, 'answer' ), 10, 3 );

		Pass::unschedule();
	}

	public function tear_down(): void {
		global $wpdb;

		remove_filter( 'pre_http_request', array( $this, 'answer' ), 10 );
		Pass::unschedule();

		foreach ( array( 'movements', 'links', 'nonces', 'events' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		delete_option( Peers::OPTION );
		delete_option( Pass::OPTION_CHECKED );
		delete_option( Store::OPTION_FINGERPRINT );
		delete_option( Installer::OPTION_VERSION );
		delete_option( Store::OPTION_ID );

		parent::tear_down();
	}

	/**
	 * Hace de la otra tienda: confirma todo.
	 *
	 * @param false|array|WP_Error $preempt Lo que venía.
	 * @param array<string, mixed> $args    Los argumentos del request.
	 * @param string               $url     La URL.
	 *
	 * @return array<string, mixed>
	 */
	public function answer( $preempt, array $args, string $url ): array {
		++$this->sent;

		$movements = json_decode( (string) $args['body'], true )['movements'];

		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode(
				array(
					'results' => array_map(
						static fn ( $movement ) => array(
							'origin' => $movement['origin'],
							'seq'    => $movement['seq'],
							'status' => 'received',
						),
						$movements
					),
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
	 * Un movimiento propio pendiente, ya vencido su retardo.
	 */
	private function pending_movement(): int {
		global $wpdb;

		$wpdb->insert(
			Installer::table( 'links' ),
			array(
				'local_product'  => 3000,
				'remote_store'   => self::REMOTE_STORE,
				'remote_product' => 4000,
				'generation'     => 1,
				'base_stock'     => 10,
				'base_set_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$movement_id = (int) Ledger::record( 3000, -2 );

		$wpdb->update( Installer::table( 'movements' ), array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $movement_id ) ); // phpcs:ignore WordPress.DB

		return $movement_id;
	}

	/**
	 * La entrega de un movimiento.
	 *
	 * @param int $movement_id ID.
	 */
	private function delivery_of( int $movement_id ): string {
		global $wpdb;

		$table = Installer::table( 'movements' );

		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT delivery FROM {$table} WHERE id = %d", $movement_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * PAS-01 — La pasada vacía lo pendiente y purga los nonces viejos.
	 */
	public function test_a_pass_delivers_and_purges(): void {
		$movement_id = $this->pending_movement();
		Nonces::claim( 'viejo', time() - Nonces::RETENTION - 60 );

		Pass::run();

		$this->assertSame( 1, $this->sent );
		$this->assertSame( 'confirmed', $this->delivery_of( $movement_id ) );
		$this->assertTrue( Nonces::claim( 'viejo', time() ), 'el nonce viejo se purgó' );
	}

	/**
	 * Un clon trae la acción agendada desde la tienda real. La pasada corre, pero no hace
	 * nada: ni manda, ni purga.
	 *
	 * PAS-02
	 */
	public function test_a_suspended_site_does_nothing(): void {
		$movement_id = $this->pending_movement();
		Nonces::claim( 'viejo', time() - Nonces::RETENTION - 60 );

		update_option( Store::OPTION_FINGERPRINT, hash( 'sha256', 'https://otra.test' ) );

		Pass::run();

		$this->assertSame( 0, $this->sent );
		$this->assertSame( 'pending', $this->delivery_of( $movement_id ) );
		$this->assertFalse( Nonces::claim( 'viejo', time() ), 'el nonce viejo sigue' );
	}

	/**
	 * Una sola acción recurrente, cada 60 segundos, aunque se pida agendar dos veces.
	 *
	 * PAS-03
	 */
	public function test_it_schedules_one_recurring_action(): void {
		Pass::ensure_scheduled();
		Pass::ensure_scheduled();

		$actions = as_get_scheduled_actions(
			array(
				'hook'   => Pass::HOOK,
				'group'  => Pass::GROUP,
				'status' => ActionScheduler_Store::STATUS_PENDING,
			)
		);

		$this->assertCount( 1, $actions );

		$schedule = reset( $actions )->get_schedule();

		$this->assertTrue( $schedule->is_recurring() );
		$this->assertSame( Pass::INTERVAL, (int) $schedule->get_recurrence() );
	}

	/**
	 * PAS-04 — Al desactivar el plugin la acción se desagenda.
	 */
	public function test_deactivation_unschedules(): void {
		Pass::ensure_scheduled();

		Plugin::deactivate();

		$this->assertFalse( as_has_scheduled_action( Pass::HOOK, null, Pass::GROUP ) );
	}

	/**
	 * Si hay una acción agendada.
	 */
	private function scheduled(): bool {
		return as_has_scheduled_action( Pass::HOOK, null, Pass::GROUP );
	}

	/**
	 * El primer control agenda, y deja la hora en una opción **autocargada**: se lee en cada
	 * request, y sin autoload sería una consulta por página.
	 *
	 * PAS-05
	 */
	public function test_the_first_check_schedules_and_is_autoloaded(): void {
		global $wpdb;

		Pass::maybe_ensure_scheduled();

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Pass::OPTION_CHECKED ) ); // phpcs:ignore WordPress.DB

		$this->assertTrue( $this->scheduled() );
		$this->assertContains( $autoload, array( 'yes', 'on', 'auto-on' ) );
	}

	/**
	 * Dentro de la hora no se vuelve a mirar: ni siquiera si la acción se perdió.
	 *
	 * PAS-06
	 */
	public function test_within_the_hour_it_does_not_check(): void {
		update_option( Pass::OPTION_CHECKED, time() - Pass::CHECK_EVERY + 60, true );

		Pass::maybe_ensure_scheduled();

		$this->assertFalse( $this->scheduled() );
	}

	/**
	 * La pasada se perdió —un fatal, o dejó de reagendarse tras fallar seguido—, y nadie
	 * entra al admin. Pasada la hora, cualquier request la vuelve a agendar.
	 *
	 * PAS-07
	 */
	public function test_a_lost_pass_comes_back_after_the_hour(): void {
		Pass::ensure_scheduled();
		update_option( Pass::OPTION_CHECKED, time() - Pass::CHECK_EVERY, true );
		as_unschedule_all_actions( Pass::HOOK, array(), Pass::GROUP );

		Pass::maybe_ensure_scheduled();

		$this->assertTrue( $this->scheduled() );
	}

	/**
	 * PAS-08 — **Una pasada que manda un lote lleno agenda el cierre de la importación**
	 * (ADR-009 §5).
	 */
	public function test_a_pass_with_a_full_batch_schedules_the_import_close(): void {
		global $wpdb;

		$wpdb->insert(
			Installer::table( 'links' ),
			array(
				'local_product'  => 3000,
				'remote_store'   => self::REMOTE_STORE,
				'remote_product' => 4000,
				'generation'     => 1,
				'base_stock'     => 10,
				'base_set_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		for ( $i = 0; $i < Ledger::MAX_BATCH; $i++ ) {
			Ledger::record( 3000, -1 );
		}

		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Installer::table( 'movements' ) . ' SET next_attempt_at = %s', gmdate( 'Y-m-d H:i:s', time() - 60 ) ) ); // phpcs:ignore WordPress.DB

		Pass::run();

		$this->assertTrue( as_has_scheduled_action( Schedule::IMPORT, null, Pass::GROUP ) );

		Schedule::unschedule();
	}
}
