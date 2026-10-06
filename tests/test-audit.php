<?php
/**
 * Tests de la fase 2 de la reconciliación.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Log\Events;
use Woonderful\StockSync\Detector\Detector;
use Woonderful\StockSync\Detector\Guard;
use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Ledger\Ledger;
use Woonderful\StockSync\Linking\Links;
use Woonderful\StockSync\Reconciliation\Audit;
use Woonderful\StockSync\Reconciliation\Schedule;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Pass;

/**
 * El stock de cada tienda contra su propio libro (ADR-010).
 */
class Test_Audit extends WP_UnitTestCase {

	private const REMOTE_STORE = 'b1b1b1b1-0000-4000-8000-000000000000';

	/**
	 * El próximo producto de la otra tienda.
	 *
	 * @var int
	 */
	private int $next_remote = 7000;

	public function set_up(): void {
		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Installer::install();
		Store::ensure_identity();
		wss_unhook_detector();

		update_option( Store::OPTION_FINGERPRINT, Store::fingerprint() );
		Schedule::unschedule();
	}

	public function tear_down(): void {
		global $wpdb;

		Schedule::unschedule();
		wss_unhook_detector();
		Detector::forget_all();
		Guard::forget_all();

		// Las filas del log, no la tabla: el DROP de las otras confirma el borrado, y una
		// tabla menos que crear y borrar en cada test hace la suite más rápida.
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
	 * Un producto vinculado, con su stock y su stock base.
	 *
	 * @param int $stock      El stock que tiene.
	 * @param int $base       Con cuánto arrancó el par.
	 * @param int $generation La generación.
	 *
	 * @return \stdClass El vínculo.
	 */
	private function linked( int $stock, int $base, int $generation = 1 ): \stdClass {
		global $wpdb;

		$product = new WC_Product_Simple();
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );
		$product_id = (int) $product->save();

		$wpdb->insert( // phpcs:ignore WordPress.DB
			Installer::table( 'links' ),
			array(
				'local_product'  => $product_id,
				'remote_store'   => self::REMOTE_STORE,
				'remote_product' => $this->next_remote++,
				'generation'     => $generation,
				'base_stock'     => $base,
				'base_set_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		return Links::find( (int) $wpdb->insert_id );
	}

	/**
	 * Un movimiento del vínculo.
	 *
	 * @param \stdClass            $link    El vínculo.
	 * @param int                  $delta   Unidades.
	 * @param array<string, mixed> $columns Columnas a pisar: por defecto, uno propio.
	 */
	private function movement( \stdClass $link, int $delta, array $columns = array() ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB
			Installer::table( 'movements' ),
			array_merge(
				array(
					'origin_store'   => Store::id(),
					'generation'     => (int) $link->generation,
					'delta'          => $delta,
					'reason'         => 'sale',
					'created_at'     => gmdate( 'Y-m-d H:i:s' ),
					'link_id'        => (int) $link->id,
					'origin_product' => (int) $link->local_product,
					'delivery'       => 'confirmed',
				),
				$columns
			)
		);

		$id = (int) $wpdb->insert_id;

		if ( ! isset( $columns['origin_seq'] ) ) {
			$wpdb->update( Installer::table( 'movements' ), array( 'origin_seq' => $id ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
		}

		return $id;
	}

	/**
	 * Uno recibido de la otra tienda.
	 *
	 * @param \stdClass $link        El vínculo.
	 * @param int       $delta       Unidades.
	 * @param string    $application Qué se hizo con él.
	 * @param int       $seq         Su secuencia allá.
	 */
	private function received( \stdClass $link, int $delta, string $application, int $seq ): void {
		$this->movement(
			$link,
			$delta,
			array(
				'origin_store' => self::REMOTE_STORE,
				'origin_seq'   => $seq,
				'delivery'     => null,
				'application'  => $application,
			)
		);
	}

	/**
	 * Cambia el stock de un producto sin pasar por el plugin: la escritura que nadie anotó.
	 *
	 * @param \stdClass $link  El vínculo.
	 * @param int       $stock El stock nuevo.
	 */
	private function unrecorded_write( \stdClass $link, int $stock ): void {
		update_post_meta( (int) $link->local_product, '_stock', $stock );
	}

	/**
	 * La segunda revisión agendada, o null.
	 *
	 * @return array<int, mixed>|null Sus argumentos.
	 */
	private function confirmation(): ?array {
		$actions = as_get_scheduled_actions(
			array(
				'hook'   => Audit::CONFIRM,
				'group'  => Pass::GROUP,
				'status' => ActionScheduler_Store::STATUS_PENDING,
			)
		);

		return array() === $actions ? null : reset( $actions )->get_args();
	}

	/**
	 * AUD-01 — **Lo esperado es el stock base, más todo lo propio, más lo recibido que se
	 * aplicó, de esa generación** (ADR-010 §1).
	 */
	public function test_the_expected_stock_follows_the_ledger(): void {
		$link  = $this->linked( 0, 50 );
		$other = $this->linked( 0, 50 );

		$this->movement( $link, -2 );
		$this->movement( $link, -1, array( 'delivery' => 'pending' ) );
		$this->movement( $link, -3, array( 'delivery' => 'abandoned' ) );
		$this->received( $link, 5, Ledger::APPLIED, 10 );
		$this->received( $link, -7, Ledger::HELD, 11 );
		$this->received( $link, -9, Ledger::DISCARDED, 12 );
		$this->movement( $link, -20, array( 'generation' => 99 ) );
		$this->movement( $other, -30 );

		// Recibidos y aplicados, pero de otra generación y de otro vínculo: tampoco cuentan.
		$this->movement( $link, -40, array( 'origin_store' => self::REMOTE_STORE, 'origin_seq' => 13, 'delivery' => null, 'application' => Ledger::APPLIED, 'generation' => 99 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->received( $other, -50, Ledger::APPLIED, 14 );

		$this->assertSame( 50 - 2 - 1 - 3 + 5, Audit::expected( $link ) );
	}

	/**
	 * AUD-02 — Si el stock coincide con el libro, no hay nada que revisar de nuevo.
	 */
	public function test_a_matching_stock_needs_nothing(): void {
		$link = $this->linked( 48, 50 );
		$this->movement( $link, -2 );

		$summary = Audit::run();

		$this->assertSame( 1, $summary['checked'] );
		$this->assertSame( 0, $summary['suspected'] );
		$this->assertNull( $this->confirmation() );
	}

	/**
	 * AUD-03 — **Lo que no cierra no se abre todavía**: se vuelve a revisar en 2 minutos, para
	 * no tomar por discrepancia una venta que se está anotando (ADR-010 §3).
	 */
	public function test_a_mismatch_is_not_opened_at_first_sight(): void {
		$link = $this->linked( 47, 50 );

		$summary = Audit::run();

		$this->assertSame( 1, $summary['suspected'] );
		$this->assertSame( array(), Audit::open() );
		$this->assertSame( array( array( array( (int) $link->id, -3 ) ) ), $this->confirmation() );
	}

	/**
	 * AUD-04 — Si a los 2 minutos sigue igual, se abre: lo esperado, lo que hay, y desde cuándo.
	 */
	public function test_a_mismatch_that_stays_is_opened(): void {
		$link = $this->linked( 47, 50 );

		Audit::confirm( array( array( (int) $link->id, -3 ) ) );

		$open = Audit::open();

		$this->assertCount( 1, $open );
		$this->assertSame( (int) $link->local_product, $open[0]['product'] );
		$this->assertSame( 50, $open[0]['expected'] );
		$this->assertSame( 47, $open[0]['found'] );
		$this->assertSame( $open[0]['since'], $open[0]['seen_at'] );
	}

	/**
	 * AUD-05 — **Si a los 2 minutos ya cerró, no se abre**: era una venta que se estaba
	 * anotando.
	 */
	public function test_a_mismatch_that_settled_is_not_opened(): void {
		$link = $this->linked( 47, 50 );
		$this->movement( $link, -3 );

		Audit::confirm( array( array( (int) $link->id, -3 ) ) );

		$this->assertSame( array(), Audit::open() );
	}

	/**
	 * AUD-06 — Si a los 2 minutos la diferencia es otra, tampoco se abre: no es lo que se vio.
	 */
	public function test_a_mismatch_that_changed_is_not_opened(): void {
		$link = $this->linked( 45, 50 );

		Audit::confirm( array( array( (int) $link->id, -3 ) ) );

		$this->assertSame( array(), Audit::open() );
	}

	/**
	 * AUD-07 — **Una abierta que sigue igual no se abre de nuevo**: se anota que se la vio, y
	 * el "desde" no cambia.
	 */
	public function test_an_open_discrepancy_is_seen_again_not_reopened(): void {
		global $wpdb;

		$link = $this->linked( 47, 50 );
		Audit::confirm( array( array( (int) $link->id, -3 ) ) );
		$wpdb->update( // phpcs:ignore WordPress.DB
			Installer::table( 'links' ),
			array(
				'discrepancy_since'   => '2026-01-01 00:00:00',
				'discrepancy_seen_at' => '2026-01-01 00:00:00',
			),
			array( 'id' => (int) $link->id )
		);

		$summary = Audit::run();

		$open = Audit::open();

		$this->assertSame( 0, $summary['suspected'] );
		$this->assertNull( $this->confirmation() );
		$this->assertSame( '2026-01-01 00:00:00', $open[0]['since'] );
		$this->assertNotSame( '2026-01-01 00:00:00', $open[0]['seen_at'] );
	}

	/**
	 * AUD-08 — Una abierta cuya diferencia cambió vuelve a pasar por la segunda revisión antes
	 * de reemplazar los números.
	 */
	public function test_an_open_discrepancy_that_changed_is_confirmed_again(): void {
		$link = $this->linked( 47, 50 );
		Audit::confirm( array( array( (int) $link->id, -3 ) ) );
		$this->unrecorded_write( $link, 45 );

		Audit::run();

		$this->assertSame( 47, Audit::open()[0]['found'], 'los números todavía no se reemplazan' );
		$this->assertSame( array( array( array( (int) $link->id, -5 ) ) ), $this->confirmation() );

		Audit::confirm( array( array( (int) $link->id, -5 ) ) );

		$this->assertSame( 45, Audit::open()[0]['found'] );
	}

	/**
	 * AUD-09 — **Si los números vuelven a coincidir, se cierra sola**.
	 */
	public function test_it_closes_by_itself_when_the_numbers_match_again(): void {
		$link = $this->linked( 47, 50 );
		Audit::confirm( array( array( (int) $link->id, -3 ) ) );
		$this->unrecorded_write( $link, 50 );

		$summary = Audit::run();

		$this->assertSame( 1, $summary['closed'] );
		$this->assertSame( array(), Audit::open() );
	}

	/**
	 * AUD-10 — No se revisa lo que no tiene por qué coincidir: retenidos, bootstrap sin
	 * resolver, o un producto que no lleva su propio stock, en la papelera o borrado
	 * (ADR-010 §2).
	 *
	 * @dataProvider excluded
	 *
	 * @param string $case El caso.
	 */
	public function test_what_need_not_match_is_not_checked( string $case ): void {
		$link = $this->linked( 47, 50, 'bootstrap' === $case ? 0 : 1 );

		switch ( $case ) {
			case 'retenido':
				$this->received( $link, -1, Ledger::HELD, 20 );
				break;
			case 'bootstrap':
				$bootstrap = $this->movement( $link, 0, array( 'reason' => Ledger::BOOTSTRAP, 'delivery' => Ledger::UNCERTAIN ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				global $wpdb;
				$wpdb->update( Installer::table( 'links' ), array( 'generation' => $bootstrap ), array( 'id' => (int) $link->id ) ); // phpcs:ignore WordPress.DB
				break;
			case 'sin stock propio':
				$product = wc_get_product( (int) $link->local_product );
				$product->set_manage_stock( false );
				$product->save();
				break;
			case 'papelera':
				wp_trash_post( (int) $link->local_product );
				break;
			case 'borrado':
				wp_delete_post( (int) $link->local_product, true );
				break;
		}

		$summary = Audit::run();

		$this->assertSame( 0, $summary['checked'] );
		$this->assertNull( $this->confirmation() );
	}

	/**
	 * Lo que no se revisa.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function excluded(): array {
		return array(
			'retenido'         => array( 'retenido' ),
			'bootstrap'        => array( 'bootstrap' ),
			'sin stock propio' => array( 'sin stock propio' ),
			'papelera'         => array( 'papelera' ),
			'borrado'          => array( 'borrado' ),
		);
	}

	/**
	 * AUD-11 — La segunda revisión no hace nada si la sincronización está suspendida.
	 */
	public function test_a_suspended_store_does_not_confirm(): void {
		$link = $this->linked( 47, 50 );
		update_option( Store::OPTION_FINGERPRINT, hash( 'sha256', 'https://otra.test' ) );

		Audit::confirm( array( array( (int) $link->id, -3 ) ) );

		$this->assertSame( array(), Audit::open() );
	}

	/**
	 * AUD-12 — El esquema tiene las cuatro columnas, y un vínculo nuevo nace sin discrepancia.
	 */
	public function test_a_new_link_has_no_discrepancy(): void {
		$link = $this->linked( 47, 50 );

		$this->assertNull( $link->expected_stock );
		$this->assertNull( $link->found_stock );
		$this->assertNull( $link->discrepancy_since );
		$this->assertNull( $link->discrepancy_seen_at );
	}

	/**
	 * AUD-13 — Dos segundas revisiones de lo mismo —una manual poco después de la de la noche—
	 * no mueven el "desde".
	 */
	public function test_a_repeated_confirmation_keeps_the_since(): void {
		global $wpdb;

		$link = $this->linked( 47, 50 );
		Audit::confirm( array( array( (int) $link->id, -3 ) ) );
		$wpdb->update( Installer::table( 'links' ), array( 'discrepancy_since' => '2026-01-01 00:00:00' ), array( 'id' => (int) $link->id ) ); // phpcs:ignore WordPress.DB

		Audit::confirm( array( array( (int) $link->id, -3 ) ) );

		$this->assertSame( '2026-01-01 00:00:00', Audit::open()[0]['since'] );
	}

	/**
	 * Una discrepancia abierta: el libro dice 50, hay 47.
	 */
	private function open_discrepancy(): \stdClass {
		$link = $this->linked( 47, 50 );
		Audit::confirm( array( array( (int) $link->id, -3 ) ) );

		return $link;
	}

	/**
	 * Los movimientos propios de un vínculo: delta y motivo.
	 *
	 * @param \stdClass $link El vínculo.
	 *
	 * @return array<int, array{0: int, 1: string}>
	 */
	private function own_movements( \stdClass $link ): array {
		global $wpdb;

		$table = Installer::table( 'movements' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT delta, reason FROM {$table} WHERE link_id = %d AND origin_store = %s ORDER BY id", (int) $link->id, Store::id() ) ); // phpcs:ignore WordPress.DB

		return array_map( static fn ( $row ) => array( (int) $row->delta, (string) $row->reason ), $rows );
	}

	/**
	 * AUD-14 — **"El stock de acá está bien" anota la diferencia como ajuste, sin tocar el
	 * stock**: queda pendiente para viajar a la otra, y la discrepancia se cierra (ADR-010 §6).
	 */
	public function test_accept_records_the_difference_without_touching_the_stock(): void {
		$link = $this->open_discrepancy();

		$this->assertTrue( Audit::accept( (int) $link->local_product ) );

		$this->assertSame( array( array( -3, 'adjustment' ) ), $this->own_movements( $link ) );
		$this->assertSame( 47, Detector::stock_in_db( (int) $link->local_product ) );
		$this->assertSame( 47, Audit::expected( $link ) );
		$this->assertSame( array(), Audit::open() );
	}

	/**
	 * AUD-15 — **"El libro está bien" vuelve el stock a lo esperado, sin anotar un movimiento**:
	 * la otra no se entera, ya tenía el número bueno.
	 */
	public function test_restore_brings_the_stock_back_without_a_movement(): void {
		$link = $this->open_discrepancy();
		Detector::register();

		$this->assertTrue( Audit::restore( (int) $link->local_product ) );

		$this->assertSame( 50, Detector::stock_in_db( (int) $link->local_product ) );
		$this->assertSame( array(), $this->own_movements( $link ) );
		$this->assertSame( array(), Audit::open() );
	}

	/**
	 * AUD-16 — **"El libro está bien" suma la diferencia; no escribe el número**: una venta que
	 * entra justo en el medio no se pierde.
	 */
	public function test_restore_does_not_overwrite_a_sale_in_the_middle(): void {
		global $wpdb;

		$link    = $this->open_discrepancy();
		$product = (int) $link->local_product;
		$sold    = false;
		$sale    = static function ( string $query ) use ( $wpdb, $product, &$sold ): string {
			if ( ! $sold && str_starts_with( $query, "UPDATE {$wpdb->postmeta} SET meta_value" ) && str_contains( $query, "post_id = {$product}" ) ) {
				$sold = true;
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = meta_value - 1 WHERE post_id = %d AND meta_key = '_stock'", $product ) ); // phpcs:ignore WordPress.DB
			}

			return $query;
		};

		add_filter( 'query', $sale );
		Audit::restore( $product );
		remove_filter( 'query', $sale );

		$this->assertTrue( $sold );
		$this->assertSame( 49, Detector::stock_in_db( $product ), '50 de la corrección, menos 1 de la venta' );
	}

	/**
	 * AUD-17 — **Si la diferencia cambió desde que el dueño la vio, se niega**, y no toca nada.
	 *
	 * @dataProvider actions
	 *
	 * @param string $action `accept` o `restore`.
	 */
	public function test_a_difference_that_changed_is_refused( string $action ): void {
		$link = $this->open_discrepancy();
		$this->unrecorded_write( $link, 45 );

		$result = Audit::$action( (int) $link->local_product );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wss_changed', $result->get_error_code() );
		$this->assertSame( 45, Detector::stock_in_db( (int) $link->local_product ) );
		$this->assertSame( array(), $this->own_movements( $link ) );
		$this->assertCount( 1, Audit::open() );
	}

	/**
	 * Las dos acciones.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function actions(): array {
		return array(
			'accept'  => array( 'accept' ),
			'restore' => array( 'restore' ),
		);
	}

	/**
	 * AUD-18 — Sin discrepancia abierta, sin vínculo, o con algo que no deja revisar, se niega.
	 */
	public function test_nothing_to_resolve_is_refused(): void {
		$clean = $this->linked( 50, 50 );

		$this->assertSame( 'wss_no_discrepancy', Audit::accept( (int) $clean->local_product )->get_error_code() );
		$this->assertSame( 'wss_no_discrepancy', Audit::restore( 999999 )->get_error_code() );

		$held = $this->open_discrepancy();
		$this->received( $held, -1, Ledger::HELD, 30 );

		$this->assertSame( 'wss_not_checkable', Audit::restore( (int) $held->local_product )->get_error_code() );
	}

	/**
	 * AUD-19 — "El libro está bien" también baja: si hay de más, vuelve a lo esperado.
	 */
	public function test_restore_also_brings_an_excess_down(): void {
		$link = $this->linked( 53, 50 );
		Audit::confirm( array( array( (int) $link->id, 3 ) ) );
		Detector::register();

		$this->assertTrue( Audit::restore( (int) $link->local_product ) );

		$this->assertSame( 50, Detector::stock_in_db( (int) $link->local_product ) );
		$this->assertSame( array(), $this->own_movements( $link ) );
	}

	/**
	 * AUD-20 — Las discrepancias dejan su rastro: se abrió con sus números, se cerró sola, se
	 * aceptó, se restauró.
	 */
	public function test_discrepancies_are_logged(): void {
		$closing = $this->open_discrepancy();
		$this->unrecorded_write( $closing, 50 );
		Audit::run();

		$accepted = $this->open_discrepancy();
		Audit::accept( (int) $accepted->local_product );

		$restored = $this->open_discrepancy();
		Audit::restore( (int) $restored->local_product );

		$events = array_reverse( Events::list( array(), 1, 100 )['items'] );
		$kinds  = array_column( $events, 'kind' );

		$this->assertSame(
			array(
				Events::DISCREPANCY_OPENED,
				Events::DISCREPANCY_CLOSED,
				Events::DISCREPANCY_OPENED,
				Events::DISCREPANCY_ACCEPTED,
				Events::DISCREPANCY_OPENED,
				Events::DISCREPANCY_RESTORED,
			),
			$kinds
		);
		$this->assertSame( 50, $events[0]['context']['expected'] );
		$this->assertSame( 47, $events[0]['context']['found'] );
		$this->assertSame( (int) $closing->local_product, $events[1]['product'] );
	}
}
