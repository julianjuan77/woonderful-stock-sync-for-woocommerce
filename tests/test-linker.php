<?php
/**
 * Tests de vincular y desvincular, del lado de la maestra.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Log\Peer;
use Woonderful\StockSync\Log\Events;
use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Ledger\Ledger;
use Woonderful\StockSync\Linking\Linker;
use Woonderful\StockSync\Linking\Links;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Express;
use Woonderful\StockSync\Transport\Peers;
use Woonderful\StockSync\Transport\Signature;

/**
 * El bootstrap se manda en el momento, y lo que no tuvo respuesta se pregunta (ADR-008).
 */
class Test_Linker extends WP_UnitTestCase {

	private const OTHER = 'b1b1b1b1-0000-4000-8000-000000000000';

	private const SECRET = 'secreto-de-prueba';

	private const REST_URL = 'https://otra.test/wp-json/wss/v1';

	/**
	 * Los requests que salieron.
	 *
	 * @var array<int, array{url: string, args: array<string, mixed>}>
	 */
	private array $sent = array();

	/**
	 * Lo que contesta la otra tienda: recibe la URL y los argumentos.
	 *
	 * @var callable|null
	 */
	private $other = null;

	public function set_up(): void {
		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		delete_option( \Woonderful\StockSync\Log\Peer::OPTION );

		// En el set_up y no en el tear_down: ver tests/TEST-CASES.md.
		delete_option( Peers::OPTION );

		Installer::install();
		Store::ensure_identity();
		wss_unhook_detector();

		// La otra canjeó el código: esta tienda es la maestra.
		Peers::pair( self::OTHER, self::REST_URL, self::SECRET, 'Mayorista', Peers::SECONDARY );
		update_option( Store::OPTION_FINGERPRINT, Store::fingerprint() );

		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	public function tear_down(): void {
		global $wpdb;

		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );

		// Las filas del log, no la tabla: el DROP de las otras confirma el borrado, y una
		// tabla menos que crear y borrar en cada test hace la suite más rápida.
		$wpdb->query( 'DELETE FROM ' . Installer::table( 'events' ) ); // phpcs:ignore WordPress.DB

		foreach ( array( 'movements', 'links', 'nonces' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

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
		$this->sent[] = array(
			'url'  => $url,
			'args' => $args,
		);

		if ( null === $this->other ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		}

		return ( $this->other )( $url, $args );
	}

	/**
	 * Una respuesta HTTP.
	 *
	 * @param int                  $status El código.
	 * @param array<string, mixed> $body   El cuerpo.
	 *
	 * @return array<string, mixed>
	 */
	private static function reply( int $status, array $body ): array {
		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * La otra tienda contesta el bootstrap con ese resultado.
	 *
	 * @param string      $status `received` o `error`.
	 * @param string|null $reason El motivo, si es un error.
	 */
	private function other_answers( string $status, ?string $reason = null ): void {
		$this->other = static function ( string $url, array $args ) use ( $status, $reason ) {
			$movement = json_decode( (string) $args['body'], true )['movements'][0];

			return self::reply(
				200,
				array(
					'results' => array(
						array_filter(
							array(
								'origin' => $movement['origin'],
								'seq'    => $movement['seq'],
								'status' => $status,
								'reason' => $reason,
							)
						),
					),
				)
			);
		};
	}

	/**
	 * Un producto que gestiona stock.
	 *
	 * @param int $stock Con cuánto arranca.
	 */
	private function product( int $stock ): int {
		$product = new WC_Product_Simple();
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );

		return (int) $product->save();
	}

	/**
	 * La única fila del libro.
	 */
	private function row(): ?\stdClass {
		global $wpdb;

		$table = Installer::table( 'movements' );

		return $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * La maestra manda el bootstrap firmado, en el momento, con sus campos; la otra dice que
	 * sí, y queda vinculado.
	 *
	 * LNK-01
	 */
	public function test_a_confirmed_bootstrap_links_the_pair(): void {
		$local = $this->product( 10 );
		$this->other_answers( 'received' );

		$result = Linker::link( $local, 900, 10, 7, 'vinculado por julian' );

		$movement = json_decode( (string) $this->sent[0]['args']['body'], true )['movements'][0];
		$headers  = $this->sent[0]['args']['headers'];

		$this->assertSame( 'confirmed', $result );
		$this->assertSame( self::REST_URL . '/movements', $this->sent[0]['url'] );
		$this->assertTrue( Signature::verify( self::SECRET, 'POST', '/wss/v1/movements', array(), (int) $headers['X-WSS-Timestamp'], $headers['X-WSS-Nonce'], (string) $this->sent[0]['args']['body'], $headers['X-WSS-Signature'], time() ) );
		$this->assertSame( 'bootstrap', $movement['reason'] );
		$this->assertSame( 900, $movement['target_product'] );
		$this->assertSame( 10, $movement['base_stock'] );
		$this->assertSame( 7, $movement['target_previous_stock'] );
		$this->assertSame( $movement['seq'], $movement['generation'] );
		$this->assertSame( 'confirmed', $this->row()->delivery );
		$this->assertSame( $movement['seq'], (int) Links::for_product( $local )->generation );
	}

	/**
	 * Lo que revisa la maestra, antes de escribir nada y sin salir a la red: que sea la
	 * maestra, que su stock sea el que vio el dueño, que gestione stock y que el par esté libre.
	 *
	 * LNK-02
	 */
	public function test_the_master_checks_before_writing(): void {
		$local   = $this->product( 10 );
		$linked  = $this->product( 10 );
		$unmanag = $this->product( 10 );
		$product = wc_get_product( $unmanag );
		$product->set_manage_stock( false );
		$product->save();
		Links::create( $linked, self::OTHER, 901, 1, 10 );

		$this->assertSame( 'stock_changed', Linker::link( $local, 900, 9, 7 )->get_error_code() );
		$this->assertSame( 'already_linked', Linker::link( $linked, 900, 10, 7 )->get_error_code() );
		$this->assertSame( 'already_linked', Linker::link( $local, 901, 10, 7 )->get_error_code(), 'el de allá ya está vinculado' );
		$this->assertSame( 'product_not_managing_stock', Linker::link( $unmanag, 900, 10, 7 )->get_error_code() );
		$this->assertSame( 'product_missing', Linker::link( 99999, 900, 10, 7 )->get_error_code() );

		$trashed = $this->product( 10 );
		wp_trash_post( $trashed );
		$refused = Linker::link( $trashed, 900, 10, 7 );
		$this->assertSame( 'product_missing', $refused->get_error_code() );
		$this->assertStringContainsString( 'papelera', $refused->get_error_message(), 'no dice que no existe: está en la papelera' );

		Peers::pair( self::OTHER, self::REST_URL, self::SECRET, 'Mayorista', Peers::MASTER );
		$this->assertSame( 'wss_not_master', Linker::link( $local, 900, 10, 7 )->get_error_code() );

		$this->assertSame( array(), $this->sent );
		$this->assertNull( $this->row() );
	}

	/**
	 * La otra tienda lo rechaza con un motivo: no se vincula, el vínculo se borra, y el motivo
	 * queda anotado.
	 *
	 * LNK-03
	 */
	public function test_a_rejected_bootstrap_unlinks(): void {
		$local = $this->product( 10 );
		$this->other_answers( 'error', 'stock_changed' );

		$result = Linker::link( $local, 900, 10, 7 );

		$this->assertSame( 'stock_changed', $result->get_error_code() );
		$this->assertNull( Links::for_product( $local ) );
		$this->assertSame( 'failed', $this->row()->delivery );
		$this->assertSame( 'stock_changed', $this->row()->last_error );
	}

	/**
	 * Sin respuesta, no se sabe: queda incierto y el vínculo se mantiene.
	 *
	 * LNK-04
	 */
	public function test_no_answer_is_uncertain(): void {
		$local = $this->product( 10 );

		$this->assertSame( Ledger::UNCERTAIN, Linker::link( $local, 900, 10, 7 ) );
		$this->assertSame( Ledger::UNCERTAIN, $this->row()->delivery );
		$this->assertNotNull( Links::for_product( $local ) );
	}

	/**
	 * Un 5xx tampoco dice si se aplicó; un 4xx del lote entero —la firma, el reloj— dice que
	 * no.
	 *
	 * LNK-05
	 */
	public function test_server_errors_are_uncertain_and_client_errors_fail(): void {
		$this->other = static fn () => self::reply( 500, array() );
		$this->assertSame( Ledger::UNCERTAIN, Linker::link( $this->product( 10 ), 900, 10, 7 ) );

		$this->other = static fn () => self::reply( 401, array( 'code' => 'wss_stale_timestamp' ) );
		$local       = $this->product( 10 );
		$result      = Linker::link( $local, 901, 10, 7 );

		$this->assertSame( 'bootstrap_failed', $result->get_error_code() );
		$this->assertSame( 'HTTP 401 wss_stale_timestamp', $result->get_error_message() );
		$this->assertNull( Links::for_product( $local ) );
	}

	/**
	 * Un incierto viejo se pregunta: `applied` lo confirma, `unknown` lo da por fallido y borra
	 * el vínculo, y sin respuesta sigue incierto. La pregunta va firmada y no manda nada.
	 *
	 * LNK-06
	 */
	public function test_uncertain_bootstraps_are_resolved_by_asking(): void {
		global $wpdb;

		$applied = $this->product( 10 );
		$unknown = $this->product( 10 );
		$silent  = $this->product( 10 );

		$pairs = array(
			$applied => 900,
			$unknown => 901,
			$silent  => 902,
		);

		foreach ( $pairs as $local => $remote ) {
			Linker::link( $local, $remote, 10, 7 );
		}

		$table = Installer::table( 'movements' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET created_at = %s", gmdate( 'Y-m-d H:i:s', time() - Linker::QUERY_AFTER - 60 ) ) ); // phpcs:ignore WordPress.DB

		$seq_of = static fn ( int $local ) => (int) Links::for_product( $local )->generation;
		$states = array(
			$seq_of( $applied ) => 'applied',
			$seq_of( $unknown ) => 'unknown',
		);

		$this->sent  = array();
		$this->other = static function ( string $url, array $args ) use ( $states ) {
			$seq = (int) basename( wp_parse_url( $url, PHP_URL_PATH ) );

			if ( ! isset( $states[ $seq ] ) ) {
				return new WP_Error( 'http_request_failed', 'nadie contesta' );
			}

			return self::reply( 200, array( 'state' => $states[ $seq ] ) );
		};

		$silent_seq = $seq_of( $silent );

		Linker::resolve_uncertain();

		$this->assertNotNull( Links::for_product( $applied ) );
		$this->assertNull( Links::for_product( $unknown ) );
		$this->assertNotNull( Links::for_product( $silent ), 'sin respuesta, sigue' );

		$delivery = static fn ( int $seq ) => (string) $wpdb->get_var( $wpdb->prepare( "SELECT delivery FROM {$table} WHERE origin_seq = %d", $seq ) ); // phpcs:ignore WordPress.DB

		$this->assertSame( Ledger::UNCERTAIN, $delivery( $silent_seq ) );

		$first   = $this->sent[0];
		$route   = wp_parse_url( $first['url'], PHP_URL_PATH );
		$headers = $first['args']['headers'];

		$this->assertSame( 'GET', $first['args']['method'] );
		$this->assertTrue( Signature::verify( self::SECRET, 'GET', substr( $route, strlen( '/wp-json' ) ), array(), (int) $headers['X-WSS-Timestamp'], $headers['X-WSS-Nonce'], '', $headers['X-WSS-Signature'], time() ) );
	}

	/**
	 * Un incierto nuevo no se pregunta todavía: la otra tienda puede estar procesándolo.
	 *
	 * LNK-07
	 */
	public function test_a_young_uncertain_bootstrap_is_not_asked(): void {
		Linker::link( $this->product( 10 ), 900, 10, 7 );
		$this->sent = array();

		Linker::resolve_uncertain();

		$this->assertSame( array(), $this->sent );
	}

	/**
	 * Desvincular escribe el `unlink`, que sale por la cola y queda anotado para salir al
	 * terminar el request, como una venta. Sin vínculo, o desde la secundaria, no.
	 *
	 * LNK-08
	 */
	public function test_unlink(): void {
		$local = $this->product( 10 );
		Links::create( $local, self::OTHER, 900, 77, 10 );
		Express::forget_all();

		$this->assertTrue( Linker::unlink( $local ) );
		$this->assertSame( 'unlink', $this->row()->reason );
		$this->assertSame( 'pending', $this->row()->delivery );
		$this->assertSame( array( (int) $this->row()->id ), Express::queued(), 'sale al terminar el request' );
		Express::forget_all();
		$this->assertSame( 'not_linked', Linker::unlink( $local )->get_error_code() );

		Links::create( $local, self::OTHER, 900, 78, 10 );
		Peers::pair( self::OTHER, self::REST_URL, self::SECRET, 'Mayorista', Peers::MASTER );

		$this->assertSame( 'wss_not_master', Linker::unlink( $local )->get_error_code() );
	}

	/**
	 * Una copia de la tienda —la base restaurada en otro sitio— trae el identificador y el
	 * secreto de la real. No vincula ni desvincula, y no sale nada a la red.
	 *
	 * LNK-09
	 */
	public function test_a_suspended_copy_neither_links_nor_unlinks(): void {
		$local  = $this->product( 10 );
		$linked = $this->product( 10 );
		Links::create( $linked, self::OTHER, 901, 77, 10 );

		update_option( Store::OPTION_FINGERPRINT, hash( 'sha256', 'https://la-tienda-real.test' ) );

		$this->assertSame( 'wss_suspended', Linker::link( $local, 900, 10, 7 )->get_error_code() );
		$this->assertSame( 'wss_suspended', Linker::unlink( $linked )->get_error_code() );
		$this->assertSame( array(), $this->sent );
		$this->assertNull( $this->row() );
		$this->assertNotNull( Links::for_product( $linked ) );
	}

	/**
	 * LNK-10 — Sin emparejar, el motivo es ése, no "no es la maestra".
	 */
	public function test_without_a_peer_the_reason_is_not_paired(): void {
		delete_option( Peers::OPTION );

		$this->assertSame( 'wss_not_paired', Linker::link( $this->product( 10 ), 900, 10, 7 )->get_error_code() );
	}

	/**
	 * Un bootstrap que llegue tarde no puede aplicarse después de la pregunta: para cuando se
	 * pregunta, ya pasó la ventana de la firma y el receptor lo rechazaría por viejo. Si alguien
	 * acortara la espera por debajo de la ventana, la pregunta podría decir "nunca llegó" y el
	 * bootstrap aplicarse igual después, dejando el par de un solo lado.
	 *
	 * LNK-11
	 */
	public function test_the_query_waits_longer_than_the_signature_window(): void {
		$this->assertGreaterThan( Signature::WINDOW, Linker::QUERY_AFTER );
	}

	/**
	 * Los eventos del log, del más viejo al más nuevo.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function events(): array {
		return array_reverse( Events::list( array(), 1, 100 )['items'] );
	}

	/**
	 * LNK-12 — **Cada intento de vincular queda en el log**: el que sale bien como `linked`, el
	 * que falla como `link_failed` con su motivo —también los rechazos antes de mandar nada—,
	 * marcado como lo que el dueño estaba haciendo. El incierto todavía no es nada.
	 */
	public function test_every_link_attempt_is_logged(): void {
		$local = $this->product( 10 );
		$this->other_answers( 'received' );
		Linker::link( $local, 900, 10, 7 );

		Linker::link( $local, 901, 10, 7 );

		$this->other = null;
		Linker::link( $this->product( 10 ), 902, 10, 7 );

		// El incierto deja anotada la caída de la otra, pero ningún evento de vinculación.
		$events = array_values( array_filter( $this->events(), static fn ( $event ) => Events::PEER_UNREACHABLE !== $event['kind'] ) );

		$this->assertSame( array( Events::LINKED, Events::LINK_FAILED ), array_column( $events, 'kind' ) );
		$this->assertSame( $local, $events[0]['product'] );
		$this->assertSame( 900, $events[0]['context']['remote'] );
		$this->assertSame( 'already_linked', $events[1]['context']['reason'] );
		$this->assertFalse( $events[1]['context']['background'] );
	}

	/**
	 * LNK-13 — **Con la otra caída, el intento fallido se atribuye a la caída**, no al producto
	 * (ADR-011 §5).
	 */
	public function test_a_failed_attempt_during_an_outage_is_attributed_to_it(): void {
		Peer::observe( new WP_Error( 'http_request_failed', 'x' ) );

		$this->other = static fn () => self::reply( 500, array() );
		Linker::link( $this->product( 10 ), 900, 10, 7 );
		Linker::link( 99999, 901, 10, 7 );

		$failed = Events::list( array( 'kind' => Events::LINK_FAILED ) )['items'];

		$this->assertCount( 1, $failed );
		$this->assertSame( Events::PEER_UNREACHABLE, $failed[0]['cause'] );
	}

	/**
	 * LNK-14 — La pregunta de los 10 minutos deja su resultado en el log como algo que corrió
	 * solo; y **un bootstrap que no se puede resolver se registra una vez**, no en cada pasada.
	 */
	public function test_resolving_bootstraps_is_logged(): void {
		global $wpdb;

		$applied = $this->product( 10 );
		$unknown = $this->product( 10 );
		$silent  = $this->product( 10 );

		foreach ( array( $applied => 900, $unknown => 901, $silent => 902 ) as $local => $remote ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			Linker::link( $local, $remote, 10, 7 );
		}

		$table = Installer::table( 'movements' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET created_at = %s", gmdate( 'Y-m-d H:i:s', time() - Linker::QUERY_AFTER - 60 ) ) ); // phpcs:ignore WordPress.DB

		$seq_of = static fn ( int $local ) => (int) Links::for_product( $local )->generation;
		$states = array(
			$seq_of( $applied ) => 'applied',
			$seq_of( $unknown ) => 'unknown',
		);

		$this->other = static function ( string $url ) use ( $states ) {
			$seq = (int) basename( wp_parse_url( $url, PHP_URL_PATH ) );

			return isset( $states[ $seq ] ) ? self::reply( 200, array( 'state' => $states[ $seq ] ) ) : self::reply( 200, array() );
		};

		Linker::resolve_uncertain();
		Linker::resolve_uncertain();

		$by_kind = array();

		foreach ( $this->events() as $event ) {
			$by_kind[ $event['kind'] ][] = $event;
		}

		$this->assertSame( $applied, $by_kind[ Events::LINKED ][0]['product'] );
		$this->assertTrue( $by_kind[ Events::LINKED ][0]['context']['background'] );
		$this->assertSame( $unknown, $by_kind[ Events::LINK_FAILED ][0]['product'] );
		$this->assertTrue( $by_kind[ Events::LINK_FAILED ][0]['context']['background'] );
		$this->assertCount( 1, $by_kind[ Events::LINK_UNRESOLVED ], 'una vez, aunque la pasada pregunte de nuevo' );
		$this->assertSame( $silent, $by_kind[ Events::LINK_UNRESOLVED ][0]['product'] );
	}

	/**
	 * LNK-15 — Desvincular queda en el log.
	 */
	public function test_unlinking_is_logged(): void {
		$local = $this->product( 10 );
		Links::create( $local, self::OTHER, 900, 77, 10 );

		Linker::unlink( $local );

		$this->assertSame( Events::UNLINKED, $this->events()[0]['kind'] );
		$this->assertSame( $local, $this->events()[0]['product'] );
	}

	/**
	 * LNK-16 — **Reasignar desvincula y vincula, en ese orden**: queda con el par nuevo, y el
	 * `unlink` del viejo sale por la cola.
	 */
	public function test_reassign_unlinks_then_links(): void {
		$local = $this->product( 10 );
		Links::create( $local, self::OTHER, 900, 77, 10 );
		$this->other_answers( 'received' );

		$this->assertSame( 'confirmed', Linker::reassign( $local, 901, 10, 4 ) );

		$this->assertSame( 901, (int) Links::for_product( $local )->remote_product );
		$this->assertSame( array( Events::UNLINKED, Events::LINKED ), array_column( $this->events(), 'kind' ) );
	}

	/**
	 * LNK-17 — **Si vincular falla, queda sin vincular**: un estado válido, que se reintenta. Sin
	 * vínculo que desvincular, no hace nada.
	 */
	public function test_a_failed_reassign_leaves_it_unlinked(): void {
		$local = $this->product( 10 );
		Links::create( $local, self::OTHER, 900, 77, 10 );
		$this->other_answers( 'error', 'target_missing' );

		$this->assertSame( 'target_missing', Linker::reassign( $local, 901, 10, 4 )->get_error_code() );
		$this->assertNull( Links::for_product( $local ) );
		$this->assertSame( 'not_linked', Linker::reassign( $local, 901, 10, 4 )->get_error_code() );
	}

	/**
	 * LNK-18 — **Descartar y deshacer quedan en el log**, una vez cada uno: descartar lo
	 * descartado, o deshacer lo que no lo está, se rechaza.
	 */
	public function test_discard_and_undiscard(): void {
		$local = $this->product( 10 );

		$this->assertTrue( Linker::discard( $local ) );
		$this->assertSame( 'already_discarded', Linker::discard( $local )->get_error_code() );
		$this->assertArrayHasKey( $local, Events::discarded_among( array( $local ) ) );

		$this->assertTrue( Linker::undiscard( $local ) );
		$this->assertSame( 'not_discarded', Linker::undiscard( $local )->get_error_code() );
		$this->assertSame( array(), Events::discarded_among( array( $local ) ) );
		$this->assertSame( array( Events::DISCARDED, Events::UNDISCARDED ), array_column( $this->events(), 'kind' ) );
	}

	/**
	 * LNK-19 — Descartar es de la maestra, como vincular.
	 */
	public function test_only_the_master_discards(): void {
		Peers::pair( self::OTHER, self::REST_URL, self::SECRET, 'Mayorista', Peers::MASTER );

		$this->assertSame( 'wss_not_master', Linker::discard( $this->product( 10 ) )->get_error_code() );
	}

	/**
	 * LNK-20 — **Vincular anota en la copia que el de allá tiene par y quedó con el stock de la
	 * maestra**; desvincular, que ya no tiene par. Sin esperar al refresco: si no, Productos
	 * mostraba el stock de antes de vincular.
	 */
	public function test_linking_updates_the_copy(): void {
		global $wpdb;

		$table = Installer::table( Installer::REMOTE_PRODUCTS );
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		$wpdb->insert( $table, array( 'remote_product' => 900, 'stock' => 4, 'linked' => 0, 'seen_at' => gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$local = $this->product( 6 );
		$this->other_answers( 'received' );

		Linker::link( $local, 900, 6, 4 );

		$row = $wpdb->get_row( "SELECT stock, linked FROM {$table} WHERE remote_product = 900" ); // phpcs:ignore WordPress.DB

		$this->assertSame( '6', $row->stock );
		$this->assertSame( '1', $row->linked );

		Linker::unlink( $local );

		$this->assertSame( '0', $wpdb->get_var( "SELECT linked FROM {$table} WHERE remote_product = 900" ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * LNK-21 — **Volver al mismo par antes de que la otra reciba el desvincular**: allá sigue
	 * vinculado, y se dice eso. Con otro par —reasignar, LNK-16— no choca.
	 */
	public function test_relinking_the_same_pair_waits_for_the_unlink(): void {
		$local = $this->product( 10 );
		Links::create( $local, self::OTHER, 900, 77, 10 );
		Linker::unlink( $local );

		$this->assertSame( 'peer_unlinking', Linker::link( $local, 900, 10, 10 )->get_error_code() );
		$this->assertSame( array(), $this->sent, 'ni se intentó' );
	}

	/**
	 * Un par ya vinculado, con el stock de acá y una generación vieja.
	 *
	 * @param int $stock El de acá.
	 *
	 * @return array{0: int, 1: \stdClass} El producto y su vínculo.
	 */
	private function linked_pair( int $stock ): array {
		$local = $this->product( $stock );
		Links::create( $local, self::OTHER, 900, 5, $stock );

		return array( $local, Links::for_product( $local ) );
	}

	/**
	 * LNK-22 — **"Vale el de acá" arranca el par de nuevo sin desvincularlo** (ADR-022): un
	 * bootstrap con los dos stocks vistos, el mismo vínculo con otra generación y la base
	 * nueva, y queda en Registros.
	 */
	public function test_equalizing_to_here_restarts_the_same_link(): void {
		list( $local, $before ) = $this->linked_pair( 10 );
		$this->other_answers( 'received' );

		$this->assertSame( 'confirmed', Linker::equalize( $local, 'here', 10, 7 ) );

		$movement = json_decode( (string) $this->sent[0]['args']['body'], true )['movements'][0];
		$after    = Links::for_product( $local );

		$this->assertSame( 'bootstrap', $movement['reason'] );
		$this->assertSame( 10, $movement['base_stock'] );
		$this->assertSame( 7, $movement['target_previous_stock'] );
		$this->assertArrayNotHasKey( 'replaces', $movement, 'lo de antes no viaja' );
		$this->assertSame( $before->id, $after->id, 'el mismo vínculo' );
		$this->assertSame( (string) $movement['seq'], $after->generation );
		$this->assertSame( '10', $after->base_stock );
		$this->assertContains( Events::EQUALIZED, array_column( $this->events(), 'kind' ) );
	}

	/**
	 * LNK-23 — **Si la otra lo rechaza, el par vuelve a como estaba**: no se borra, como al
	 * vincular, porque ya sincronizaba.
	 */
	public function test_a_rejected_equalize_restores_the_link(): void {
		list( $local, $before ) = $this->linked_pair( 10 );
		$this->other_answers( 'error', 'stock_changed' );

		$result = Linker::equalize( $local, 'here', 10, 7 );
		$after  = Links::for_product( $local );

		$this->assertSame( 'stock_changed', $result->get_error_code() );
		$this->assertNotNull( $after );
		$this->assertSame( $before->id, $after->id );
		$this->assertSame( $before->generation, $after->generation );
		$this->assertSame( $before->base_stock, $after->base_stock );
		$this->assertNotContains( Events::EQUALIZED, array_column( $this->events(), 'kind' ) );
	}

	/**
	 * LNK-24 — **"Vale el de la otra" corrige acá sin anotar movimiento**: nada sale, y el libro
	 * da el número nuevo, o la auditoría lo vería como discrepancia.
	 */
	public function test_equalizing_to_there_adopts_without_a_movement(): void {
		global $wpdb;

		list( $local, $link ) = $this->linked_pair( 10 );

		$this->assertSame( 'adopted', Linker::equalize( $local, 'there', 10, 7 ) );
		$this->assertSame( array(), $this->sent );
		$this->assertSame( 7, (int) get_post_meta( $local, '_stock', true ) );
		$this->assertSame( '0', (string) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::table( 'movements' ) ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 7, \Woonderful\StockSync\Reconciliation\Audit::expected( Links::find( (int) $link->id ) ) );
	}

	/**
	 * LNK-25 — **Lo que se niega antes de escribir**: ya coinciden, no está vinculado, y el de
	 * acá cambió desde que se miró.
	 */
	public function test_equalize_refusals(): void {
		list( $local ) = $this->linked_pair( 10 );

		$this->assertSame( 'already_equal', Linker::equalize( $local, 'here', 8, 8 )->get_error_code() );
		$this->assertSame( 'not_linked', Linker::equalize( $this->product( 5 ), 'here', 5, 4 )->get_error_code() );
		$this->assertSame( 'stock_changed', Linker::equalize( $local, 'there', 9, 7 )->get_error_code() );
		$this->assertSame( array(), $this->sent );
		$this->assertSame( 10, (int) get_post_meta( $local, '_stock', true ) );
	}
}
