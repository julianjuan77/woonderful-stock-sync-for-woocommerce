<?php
/**
 * Tests de cuándo se lanza sola la reconciliación.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Linking\Links;
use Woonderful\StockSync\Log\Events;
use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Ledger\Ledger;
use Woonderful\StockSync\Reconciliation\Audit;
use Woonderful\StockSync\Reconciliation\Lock;
use Woonderful\StockSync\Reconciliation\Result;
use Woonderful\StockSync\Reconciliation\Schedule;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Pass;
use Woonderful\StockSync\Transport\Peers;

/**
 * La noche y el cierre de una importación (ADR-009 §5).
 */
class Test_Schedule extends WP_UnitTestCase {

	private const REMOTE_STORE = 'b1b1b1b1-0000-4000-8000-000000000000';

	/**
	 * Los requests que salieron.
	 *
	 * @var int
	 */
	private int $asked = 0;

	public function set_up(): void {
		global $wpdb;

		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		delete_option( \Woonderful\StockSync\Log\Peer::OPTION );
		delete_option( \Woonderful\StockSync\Log\Notifier::OPTION_STATE );

		Installer::install();
		Store::ensure_identity();
		wss_unhook_detector();

		update_option( Store::OPTION_FINGERPRINT, Store::fingerprint() );
		$this->pair_as( Peers::SECONDARY );

		$wpdb->delete( $wpdb->options, array( 'option_name' => Lock::OPTION ) ); // phpcs:ignore WordPress.DB
		wp_cache_delete( Lock::OPTION, 'options' );
		delete_option( Result::OPTION );

		Schedule::unschedule();

		// La otra tienda no contesta: alcanza para ver que se lanzó una reconciliación.
		add_filter( 'pre_http_request', array( $this, 'unreachable' ) );
	}

	public function tear_down(): void {
		global $wpdb;

		remove_filter( 'pre_http_request', array( $this, 'unreachable' ) );
		Schedule::unschedule();

		// Las filas del log, no la tabla: el DROP de las otras confirma el borrado, y una
		// tabla menos que crear y borrar en cada test hace la suite más rápida.
		$wpdb->query( 'DELETE FROM ' . Installer::table( 'events' ) ); // phpcs:ignore WordPress.DB

		foreach ( array( 'movements', 'links', 'nonces' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		$wpdb->delete( $wpdb->options, array( 'option_name' => Lock::OPTION ) ); // phpcs:ignore WordPress.DB
		delete_option( Result::OPTION );
		delete_option( Peers::OPTION );
		delete_option( Store::OPTION_FINGERPRINT );
		delete_option( Installer::OPTION_VERSION );
		delete_option( Store::OPTION_ID );
		delete_option( 'timezone_string' );

		parent::tear_down();
	}

	/**
	 * Empareja con la otra tienda.
	 *
	 * @param string $role El rol de **la otra**: `secondary` hace maestra a ésta.
	 */
	private function pair_as( string $role ): void {
		update_option(
			Peers::OPTION,
			array(
				self::REMOTE_STORE => array(
					'rest_url' => 'https://otra.test/wp-json/wss/v1',
					'secret'   => 'secreto-de-prueba',
					'role'     => $role,
				),
			),
			false
		);
	}

	/**
	 * Hace de una tienda que no contesta.
	 */
	public function unreachable(): WP_Error {
		++$this->asked;

		return new WP_Error( 'http_request_failed', 'cURL error 7: no responde' );
	}

	/**
	 * Un movimiento propio.
	 *
	 * @param string $delivery Su estado de entrega.
	 * @param int    $attempts Cuántas veces se intentó.
	 */
	private function own( string $delivery, int $attempts = 0 ): int {
		global $wpdb;

		$table = Installer::table( 'movements' );

		$wpdb->insert( // phpcs:ignore WordPress.DB
			$table,
			array(
				'origin_store'   => Store::id(),
				'generation'     => 1,
				'delta'          => -1,
				'reason'         => 'adjustment',
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
				'origin_product' => 10,
				'delivery'       => $delivery,
				'attempts'       => $attempts,
			)
		);

		$id = (int) $wpdb->insert_id;
		$wpdb->update( $table, array( 'origin_seq' => $id ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB

		return $id;
	}

	/**
	 * Las acciones pendientes de un hook.
	 *
	 * @param string $hook El hook.
	 *
	 * @return ActionScheduler_Action[]
	 */
	private function pending( string $hook ): array {
		return as_get_scheduled_actions(
			array(
				'hook'   => $hook,
				'group'  => Pass::GROUP,
				'status' => ActionScheduler_Store::STATUS_PENDING,
			)
		);
	}

	/**
	 * Cuándo está agendada la única acción pendiente de un hook.
	 *
	 * @param string $hook El hook.
	 */
	private function scheduled_at( string $hook ): int {
		$actions = $this->pending( $hook );

		$this->assertCount( 1, $actions );

		return (int) reset( $actions )->get_schedule()->get_date()->getTimestamp();
	}

	/**
	 * SCD-01 — La noche es a las 3 de la hora del sitio: hoy si todavía no pasó, mañana si ya.
	 */
	public function test_the_night_is_three_in_the_site_time(): void {
		update_option( 'timezone_string', 'America/Argentina/Buenos_Aires' );

		// 2026-10-05 02:00 en Buenos Aires (UTC−3) son las 05:00 UTC.
		$before = strtotime( '2026-10-05 05:00:00 UTC' );
		$after  = strtotime( '2026-10-05 07:00:00 UTC' );

		$this->assertSame( strtotime( '2026-10-05 06:00:00 UTC' ), Schedule::next_night( $before, 3 ) );
		$this->assertSame( strtotime( '2026-10-06 06:00:00 UTC' ), Schedule::next_night( $after, 3 ) );
	}

	/**
	 * SCD-02 — La noche se agenda una sola vez, para la próxima.
	 */
	public function test_the_night_is_scheduled_once(): void {
		Schedule::ensure_nightly();
		Schedule::ensure_nightly();

		$this->assertSame( Schedule::next_night( time(), Schedule::NIGHT_HOUR ), $this->scheduled_at( Schedule::NIGHTLY ) );
	}

	/**
	 * SCD-03 — **De noche, la maestra reconcilia y después purga**, suelta el candado y deja
	 * agendada la noche siguiente, a las 3.
	 */
	public function test_the_master_reconciles_at_night(): void {
		$old = $this->old_confirmed();

		Schedule::nightly();

		$this->assertSame( Result::NIGHTLY, Result::last()['trigger'] ?? null );
		$this->assertGreaterThan( 0, $this->asked );
		$this->assertFalse( $this->exists( $old ), 'la maestra también purga' );
		$this->assertFalse( Lock::is_taken() );
		$this->assertSame( Schedule::next_night( time(), Schedule::NIGHT_HOUR ), $this->scheduled_at( Schedule::NIGHTLY ) );
	}

	/**
	 * SCD-17 — **La secundaria agenda su noche a las 4**: purgar toma el candado, y a las 3 le
	 * contestaría "ocupada" a la maestra que reconcilia con ella.
	 */
	public function test_the_secondary_night_is_an_hour_later(): void {
		$this->pair_as( Peers::MASTER );

		Schedule::ensure_nightly();

		$this->assertSame( Schedule::next_night( time(), Schedule::SECONDARY_NIGHT_HOUR ), $this->scheduled_at( Schedule::NIGHTLY ) );

		Schedule::unschedule();
		Schedule::nightly();

		$this->assertSame( Schedule::next_night( time(), Schedule::SECONDARY_NIGHT_HOUR ), $this->scheduled_at( Schedule::NIGHTLY ) );
	}

	/**
	 * SCD-04 — La secundaria no reconcilia de noche: la lanza la maestra (UC-06).
	 */
	public function test_the_secondary_does_not_reconcile_at_night(): void {
		$this->pair_as( Peers::MASTER );

		Schedule::nightly();

		$this->assertSame( 0, $this->asked );
		$this->assertNull( Result::last() );
		$this->assertCount( 1, $this->pending( Schedule::NIGHTLY ), 'pero agenda la siguiente: purga' );
	}

	/**
	 * SCD-05 — Suspendida, no reconcilia, pero la noche siguiente queda agendada.
	 */
	public function test_a_suspended_store_does_not_reconcile_at_night(): void {
		update_option( Store::OPTION_FINGERPRINT, hash( 'sha256', 'https://otra.test' ) );

		Schedule::nightly();

		$this->assertSame( 0, $this->asked );
		$this->assertCount( 1, $this->pending( Schedule::NIGHTLY ) );
	}

	/**
	 * SCD-06 — **Una pasada con un lote lleno agenda el cierre** para dentro de 5 minutos, con
	 * el techo de ese momento; una que no lo llena, no.
	 */
	public function test_a_full_batch_schedules_the_import_close(): void {
		$last = $this->own( 'confirmed' );

		Schedule::after_pass( array( 'sent' => Ledger::MAX_BATCH - 1, 'confirmed' => 0, 'failed' => 0, 'error' => null ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertSame( array(), $this->pending( Schedule::IMPORT ) );

		Schedule::after_pass( array( 'sent' => Ledger::MAX_BATCH, 'confirmed' => 0, 'failed' => 0, 'error' => null ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$actions = $this->pending( Schedule::IMPORT );

		$this->assertCount( 1, $actions );
		$this->assertSame( array( $last ), reset( $actions )->get_args() );
		$this->assertEqualsWithDelta( time() + Schedule::IMPORT_WAIT, $this->scheduled_at( Schedule::IMPORT ), 5 );
	}

	/**
	 * SCD-07 — Si ya hay un cierre agendado, no se agenda otro, aunque cambie el techo.
	 */
	public function test_only_one_import_close_at_a_time(): void {
		$full = array( 'sent' => Ledger::MAX_BATCH, 'confirmed' => 0, 'failed' => 0, 'error' => null ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		Schedule::after_pass( $full );
		$this->own( 'pending' );
		Schedule::after_pass( $full );

		$this->assertCount( 1, $this->pending( Schedule::IMPORT ) );
	}

	/**
	 * SCD-08 — **El cierre espera lo que no se intentó mandar o está en camino**, hasta su
	 * techo: vuelve a mirar en 5 minutos y no reconcilia.
	 *
	 * @dataProvider unsent
	 *
	 * @param string $delivery El estado.
	 * @param int    $attempts Los intentos.
	 */
	public function test_the_import_close_waits_for_what_has_not_left( string $delivery, int $attempts ): void {
		$ceiling = $this->own( $delivery, $attempts );

		Schedule::import_closed( $ceiling );

		$actions = $this->pending( Schedule::IMPORT );

		$this->assertSame( 0, $this->asked );
		$this->assertCount( 1, $actions );
		$this->assertSame( array( $ceiling ), reset( $actions )->get_args() );
	}

	/**
	 * Lo que todavía no salió.
	 *
	 * @return array<string, array{0: string, 1: int}>
	 */
	public function unsent(): array {
		return array(
			'nunca intentado' => array( 'pending', 0 ),
			'en camino'       => array( 'in_flight', 1 ),
		);
	}

	/**
	 * SCD-09 — **No espera lo que falló y espera su reintento**, ni lo que está por encima del
	 * techo: reconcilia.
	 */
	public function test_the_import_close_does_not_wait_for_retries_or_newer_movements(): void {
		$ceiling = $this->own( 'pending', 3 );
		$this->own( 'pending' );

		Schedule::import_closed( $ceiling );

		$this->assertSame( Result::IMPORT, Result::last()['trigger'] ?? null );
		$this->assertSame( array(), $this->pending( Schedule::IMPORT ) );
	}

	/**
	 * SCD-10 — El cierre corre también en la secundaria: lo lanza la tienda que importó.
	 */
	public function test_the_import_close_runs_in_the_secondary_too(): void {
		$this->pair_as( Peers::MASTER );

		Schedule::import_closed( 0 );

		$this->assertSame( Result::IMPORT, Result::last()['trigger'] ?? null );
	}

	/**
	 * SCD-11 — Suspendida, el cierre no hace nada.
	 */
	public function test_a_suspended_store_does_not_close_an_import(): void {
		update_option( Store::OPTION_FINGERPRINT, hash( 'sha256', 'https://otra.test' ) );

		Schedule::import_closed( 0 );

		$this->assertSame( 0, $this->asked );
		$this->assertNull( Result::last() );
	}

	/**
	 * SCD-12 — Desactivar el plugin desagenda la noche y el cierre, con la pasada.
	 */
	public function test_deactivating_unschedules_both(): void {
		Schedule::ensure_nightly();
		Schedule::after_pass( array( 'sent' => Ledger::MAX_BATCH, 'confirmed' => 0, 'failed' => 0, 'error' => null ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		as_schedule_single_action( time() + 120, Audit::CONFIRM, array( array( array( 1, -3 ) ) ), Pass::GROUP );

		Pass::unschedule();

		$this->assertSame( array(), $this->pending( Schedule::NIGHTLY ) );
		$this->assertSame( array(), $this->pending( Schedule::IMPORT ) );
		$this->assertSame( array(), $this->pending( Audit::CONFIRM ) );
	}

	/**
	 * SCD-13 — El control de la pasada agenda también la noche.
	 */
	public function test_the_pass_check_schedules_the_night(): void {
		Pass::ensure_scheduled();

		$this->assertCount( 1, $this->pending( Schedule::NIGHTLY ) );

		Pass::unschedule();
	}

	/**
	 * Un movimiento propio confirmado hace más de 90 días: lo que la purga borra.
	 */
	private function old_confirmed(): int {
		global $wpdb;

		$id = $this->own( 'confirmed' );
		$wpdb->update( Installer::table( 'movements' ), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB

		return $id;
	}

	/**
	 * Si un movimiento sigue en el libro.
	 *
	 * @param int $id ID.
	 */
	private function exists( int $id ): bool {
		global $wpdb;

		$table = Installer::table( 'movements' );

		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * SCD-14 — **La noche purga el libro, también en la secundaria** (ADR-004 §4), y suelta el
	 * candado.
	 */
	public function test_the_night_purges_in_both_stores(): void {
		$this->pair_as( Peers::MASTER );
		$old = $this->old_confirmed();

		Schedule::nightly();

		$this->assertFalse( $this->exists( $old ) );
		$this->assertFalse( Lock::is_taken() );
	}

	/**
	 * SCD-15 — Con el candado tomado, no purga: la marca no se mueve en medio de una
	 * reconciliación.
	 */
	public function test_the_night_does_not_purge_while_reconciling(): void {
		$this->pair_as( Peers::MASTER );
		$old   = $this->old_confirmed();
		$taken = Lock::acquire();

		Schedule::nightly();

		$this->assertTrue( $this->exists( $old ) );

		Lock::release( (string) $taken );
	}

	/**
	 * SCD-16 — Suspendida, no purga.
	 */
	public function test_a_suspended_store_does_not_purge(): void {
		$old = $this->old_confirmed();
		update_option( Store::OPTION_FINGERPRINT, hash( 'sha256', 'https://otra.test' ) );

		Schedule::nightly();

		$this->assertTrue( $this->exists( $old ) );
	}

	/**
	 * SCD-18 — **La noche, en las dos tiendas, revisa los pares rotos, purga el log y manda el
	 * resumen de lo que no sincroniza** (ADR-011 §6, §7; ADR-012 §3).
	 */
	public function test_the_night_checks_pairs_purges_the_log_and_sends_the_digest(): void {
		global $wpdb;

		$this->pair_as( Peers::MASTER );

		$product = new WC_Product_Simple();
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 5 );
		$broken = (int) $product->save();
		Links::create( $broken, self::REMOTE_STORE, 900, 1, 5 );
		wp_trash_post( $broken );

		Events::record( Events::LINKED, Events::INFO, 1 );
		$wpdb->update( Installer::table( 'events' ), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS ) ), array( 'kind' => Events::LINKED ) ); // phpcs:ignore WordPress.DB

		$mails = array();
		$catch = static function ( $short, array $atts ) use ( &$mails ): bool {
			$mails[] = $atts;

			return true;
		};

		$unsynced = new WC_Product_Simple();
		$unsynced->set_manage_stock( true );
		$unsynced->set_stock_quantity( 5 );
		$id = (int) $unsynced->save();
		$wpdb->update( $wpdb->posts, array( 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB

		add_filter( 'pre_wp_mail', $catch, 10, 2 );
		Schedule::nightly();
		remove_filter( 'pre_wp_mail', $catch, 10 );

		$this->assertCount( 1, Events::list( array( 'kind' => Events::PAIR_BROKEN ) )['items'] );
		$this->assertSame( 0, Events::list( array( 'kind' => Events::LINKED ) )['total'] );
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( "(#{$id})", $mails[0]['message'] );
	}
}
