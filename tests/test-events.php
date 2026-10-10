<?php
/**
 * Tests del log.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Log\Events;
use Woonderful\StockSync\Schema\Installer;

/**
 * `wss_events`: lo que pasa y no es un movimiento de stock (ADR-011).
 */
class Test_Events extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Installer::install();
		Store::ensure_identity();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		global $wpdb;

		// La tabla del log también: EVT-03 la borra.
		foreach ( array( 'movements', 'links', 'nonces', 'events' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		delete_option( Installer::OPTION_VERSION );
		delete_option( Store::OPTION_ID );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Un evento con la fecha que se le diga.
	 *
	 * @param string $kind El tipo.
	 * @param int    $days Hace cuántos días.
	 */
	private function old_event( string $kind, int $days ): void {
		global $wpdb;

		Events::record( $kind, Events::INFO, 7 );
		$wpdb->update( Installer::table( 'events' ), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ), array( 'id' => (int) $wpdb->insert_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * EVT-01 — Un evento queda con su tipo, nivel, producto, causa y datos; sin persona, el
	 * usuario va en `NULL` —WP-CLI sin `--user` da 0—.
	 */
	public function test_an_event_is_recorded(): void {
		Events::record( Events::LINK_FAILED, Events::WARNING, 12, array( 'reason' => 'timeout' ), 'peer_unreachable' );

		$event = Events::list()['items'][0];

		$this->assertSame( Events::LINK_FAILED, $event['kind'] );
		$this->assertSame( Events::WARNING, $event['level'] );
		$this->assertSame( 12, $event['product'] );
		$this->assertSame( 'peer_unreachable', $event['cause'] );
		$this->assertSame( array( 'reason' => 'timeout' ), $event['context'] );
		$this->assertNull( $event['user_id'] );
	}

	/**
	 * EVT-02 — Con una persona, queda quién fue.
	 */
	public function test_the_user_is_recorded(): void {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );

		Events::record( Events::UNLINKED, Events::INFO, 12 );

		$this->assertSame( $user, Events::list()['items'][0]['user_id'] );
	}

	/**
	 * EVT-03 — **Si escribir falla, no corta nada ni imprime nada**: el log no puede impedir
	 * que pase lo que cuenta.
	 */
	public function test_a_failed_write_does_not_interrupt(): void {
		global $wpdb;

		$table = Installer::table( 'events' );
		$wpdb->query( "DROP TABLE {$table}" ); // phpcs:ignore WordPress.DB

		ob_start();
		Events::record( Events::LINKED, Events::INFO, 12 );
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
		$this->assertFalse( $wpdb->suppress_errors( false ), 'deja los errores como estaban' );
	}

	/**
	 * EVT-04 — La lista va de lo más nuevo a lo más viejo, filtra por producto —uno o una lista—,
	 * tipo, nivel y causa, y pagina. Cuenta cuántos hay de cada nivel sin el filtro de nivel.
	 */
	public function test_the_list_filters_and_pages(): void {
		Events::record( Events::LINKED, Events::INFO, 1 );
		Events::record( Events::LINK_FAILED, Events::WARNING, 2, array(), 'peer_unreachable' );
		Events::record( Events::LINK_FAILED, Events::WARNING, 3 );

		$this->assertSame( array( 3, 2, 1 ), array_column( Events::list()['items'], 'product' ) );
		$this->assertSame( array( 2 ), array_column( Events::list( array( 'product' => 2 ) )['items'], 'product' ) );
		$this->assertSame( 2, Events::list( array( 'kind' => Events::LINK_FAILED ) )['total'] );
		$this->assertSame( 1, Events::list( array( 'cause' => 'peer_unreachable' ) )['total'] );
		$this->assertSame( 1, Events::list( array( 'level' => Events::INFO ) )['total'] );
		$this->assertSame(
			array(
				Events::INFO    => 1,
				Events::WARNING => 2,
				Events::ERROR   => 0,
			),
			Events::list( array( 'level' => Events::INFO ) )['levels'],
			'las cuentas, sin el filtro de nivel'
		);
		$this->assertSame( array( 3, 1 ), array_column( Events::list( array( 'products' => array( 1, 3 ) ) )['items'], 'product' ) );
		$this->assertSame( 0, Events::list( array( 'products' => array() ) )['total'], 'una búsqueda sin productos no trae nada' );

		$second = Events::list( array(), 2, 2 );

		$this->assertSame( 3, $second['total'] );
		$this->assertSame( array( 1 ), array_column( $second['items'], 'product' ) );
	}

	/**
	 * EVT-05 — **El historial de un producto junta sus movimientos del libro y sus eventos**,
	 * en una sola línea de tiempo (UC-03).
	 */
	public function test_the_history_merges_movements_and_events(): void {
		global $wpdb;

		$movements = Installer::table( 'movements' );

		$wpdb->insert( $movements, array( 'origin_store' => Store::id(), 'origin_seq' => 1, 'generation' => 1, 'delta' => -2, 'reason' => 'sale', 'created_at' => '2026-10-01 10:00:00', 'origin_product' => 12, 'delivery' => 'confirmed' ) ); // phpcs:ignore WordPress.DB, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$wpdb->insert( $movements, array( 'origin_store' => Store::id(), 'origin_seq' => 2, 'generation' => 1, 'delta' => -9, 'reason' => 'sale', 'created_at' => '2026-10-01 11:00:00', 'origin_product' => 99, 'delivery' => 'confirmed' ) ); // phpcs:ignore WordPress.DB, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		Events::record( Events::LINKED, Events::INFO, 12 );
		$wpdb->update( Installer::table( 'events' ), array( 'created_at' => '2026-10-01 09:00:00' ), array( 'id' => (int) $wpdb->insert_id ) ); // phpcs:ignore WordPress.DB
		Events::record( Events::UNLINKED, Events::INFO, 99 );

		$history = Events::history( 12 );

		$this->assertSame( array( 'movement', 'event' ), array_column( $history, 'type' ) );
		$this->assertSame( -2, $history[0]['delta'] );
		$this->assertSame( Events::LINKED, $history[1]['kind'] );
	}

	/**
	 * EVT-06 — **La purga borra lo de más de 90 días, salvo el descarte y lo avisado**: son
	 * decisiones y memorias, no eventos (ADR-011 §6).
	 */
	public function test_the_purge_keeps_decisions_and_memories(): void {
		$this->old_event( Events::LINKED, 91 );
		$this->old_event( Events::DISCARDED, 91 );
		$this->old_event( Events::UNDISCARDED, 91 );
		$this->old_event( Events::NOTIFIED, 91 );
		$this->old_event( Events::UNLINKED, 89 );

		$this->assertSame( 1, Events::purge() );
		$this->assertEqualsCanonicalizing(
			array( Events::DISCARDED, Events::UNDISCARDED, Events::NOTIFIED, Events::UNLINKED ),
			array_column( Events::list( array( 'product' => 7 ) )['items'], 'kind' )
		);
	}

	/**
	 * EVT-07 — Cada tipo tiene su frase: ninguno se muestra con el nombre interno.
	 */
	public function test_every_kind_has_a_sentence(): void {
		$kinds = array_filter(
			( new ReflectionClass( Events::class ) )->getConstants(),
			static fn ( $value, $name ) => is_string( $value ) && ! in_array( $name, array( 'INFO', 'WARNING', 'ERROR' ), true ),
			ARRAY_FILTER_USE_BOTH
		);

		foreach ( $kinds as $kind ) {
			$sentence = Events::describe(
				array(
					'kind'    => $kind,
					'product' => 12,
					'context' => array(),
				)
			);

			$this->assertNotSame( $kind, $sentence, $kind );
		}
	}

	/**
	 * EVT-08 — El historial incluye lo recibido de la otra tienda para ese producto, por su
	 * vínculo.
	 */
	public function test_the_history_includes_received_movements(): void {
		global $wpdb;

		$link = (int) \Woonderful\StockSync\Linking\Links::create( 12, 'b1b1b1b1-0000-4000-8000-000000000000', 900, 1, 10 );
		$wpdb->insert( Installer::table( 'movements' ), array( 'origin_store' => 'b1b1b1b1-0000-4000-8000-000000000000', 'origin_seq' => 5, 'generation' => 1, 'delta' => -3, 'reason' => 'sale', 'created_at' => '2026-10-01 10:00:00', 'link_id' => $link, 'origin_product' => 900, 'application' => 'applied' ) ); // phpcs:ignore WordPress.DB, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$history = Events::history( 12 );

		$this->assertCount( 1, $history );
		$this->assertFalse( $history[0]['own'] );
		$this->assertSame( -3, $history[0]['delta'] );
	}

	/**
	 * EVT-09 — **Los motivos, quién lanzó y cómo terminó, en castellano**: no el código interno.
	 * Un motivo que no se conoce va como vino.
	 */
	public function test_reasons_and_outcomes_are_readable(): void {
		$broken     = Events::describe(
			array(
				'kind'    => Events::PAIR_BROKEN,
				'product' => 13,
				'context' => array( 'reason' => 'local_trashed' ),
			)
		);
		$reconciled = Events::describe(
			array(
				'kind'    => Events::RECONCILED,
				'product' => null,
				'context' => array(
					'trigger' => 'nightly',
					'outcome' => 'unreachable',
				),
			)
		);

		$this->assertSame( 'Se rompió el par del producto 13: está en la papelera.', $broken );
		$this->assertSame( 'Reconciliación de la noche: la otra tienda no respondió, no se pudo mirar.', $reconciled );
		$this->assertSame( 'HTTP 418', Events::reason_text( 'HTTP 418' ) );
	}

	/**
	 * EVT-10 — Las fechas del log, guardadas en UTC, se muestran en la hora del sitio.
	 */
	public function test_times_are_shown_in_the_site_time(): void {
		update_option( 'timezone_string', 'America/Argentina/Buenos_Aires' );

		$this->assertSame( '2026-10-06 21:09:06', Events::local_time( '2026-10-07 00:09:06' ) );

		delete_option( 'timezone_string' );
	}

	/**
	 * EVT-11 — **El historial muestra el stock de antes y el de después** cuando se anotó: de
	 * un movimiento, o de un bootstrap —el que tenía cada lado y el de arranque—. Sin anotar,
	 * sólo la diferencia.
	 */
	public function test_the_history_shows_stock_before_and_after(): void {
		global $wpdb;

		$movements = Installer::table( 'movements' );
		$base      = array(
			'origin_store'   => Store::id(),
			'generation'     => 1,
			'origin_product' => 12,
			'delivery'       => 'confirmed',
		);

		$wpdb->insert( $movements, $base + array( 'origin_seq' => 1, 'delta' => 0, 'reason' => 'bootstrap', 'created_at' => '2026-10-01 09:00:00', 'details' => '{"target_product":900,"base_stock":6,"origin_previous_stock":6,"target_previous_stock":4}' ) ); // phpcs:ignore WordPress.DB, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$wpdb->insert( $movements, $base + array( 'origin_seq' => 2, 'delta' => -2, 'reason' => 'sale', 'created_at' => '2026-10-01 10:00:00', 'details' => '{"stock_before":6,"stock_after":4}' ) ); // phpcs:ignore WordPress.DB, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$wpdb->insert( $movements, $base + array( 'origin_seq' => 3, 'delta' => -1, 'reason' => 'sale', 'created_at' => '2026-10-01 11:00:00' ) ); // phpcs:ignore WordPress.DB, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$history = Events::history( 12 );

		$this->assertNull( $history[0]['stocks'] );
		$this->assertSame( array( 6, 4 ), $history[1]['stocks'] );
		$this->assertSame( array( 6, 6 ), $history[2]['stocks'], 'el bootstrap propio: el de acá, y el de arranque' );
		$this->assertSame( 'Acá: venta, -2 · stock 6 → 4', Events::movement_text( $history[1] ) );
		$this->assertSame( 'Acá: venta', Events::movement_text( $history[1], false ), 'la pantalla muestra la diferencia aparte' );
		$this->assertSame( 'Acá: venta, -1', Events::movement_text( $history[0] ) );
		$this->assertSame(
			'Acá: desvinculación',
			Events::movement_text(
				array(
					'own'    => true,
					'reason' => 'unlink',
					'delta'  => 0,
				)
			),
			'sin "+0": desvincular no mueve stock'
		);
	}

	/**
	 * EVT-12 — **La lista general no trae las marcas "ya avisé"**: son una por producto avisado,
	 * y parecían un email cada una. Se ven al pedirlas por tipo, o en la lista de un producto.
	 */
	public function test_the_list_hides_the_notified_marks(): void {
		Events::record( Events::NOTIFIED, Events::INFO, 12 );
		Events::record( Events::NOTIFIED, Events::INFO, 13 );
		Events::record( Events::DIGEST_SENT, Events::INFO, null, array( 'count' => 2, 'total' => 2 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertSame( array( Events::DIGEST_SENT ), array_column( Events::list()['items'], 'kind' ) );
		$this->assertSame( 2, Events::list( array( 'kind' => Events::NOTIFIED ) )['total'] );
		$this->assertSame( array( Events::NOTIFIED ), array_column( Events::list( array( 'product' => 12 ) )['items'], 'kind' ) );
	}
}
