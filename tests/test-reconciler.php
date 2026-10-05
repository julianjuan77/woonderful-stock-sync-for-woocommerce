<?php
/**
 * Tests de la reconciliación, del lado de quien la lanza.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Ledger\Ledger;
use Woonderful\StockSync\Reconciliation\Books;
use Woonderful\StockSync\Reconciliation\Lock;
use Woonderful\StockSync\Reconciliation\Reconciler;
use Woonderful\StockSync\Reconciliation\Result;
use Woonderful\StockSync\Rest\Reconcile;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Peers;

/**
 * La tienda que lanza la reconciliación: el candado, las preguntas y el resultado (ADR-009).
 *
 * La otra tienda es un doble: contesta desde `$this->other` lo que le preguntan.
 */
class Test_Reconciler extends WP_UnitTestCase {

	private const REMOTE_STORE = 'b1b1b1b1-0000-4000-8000-000000000000';

	private const REST_URL = 'https://otra.test/wp-json/wss/v1';

	/**
	 * La otra tienda: qué tiene de ésta, su marca de purga, su techo, cómo contesta.
	 *
	 * `pages` es una lista de respuestas a "¿qué tenés de lo mío?", una por tanda; si está, se
	 * usa en lugar de armar una sola con `has`. `fail` dice en qué paso contestar con un error.
	 *
	 * @var array<string, mixed>
	 */
	private array $other = array();

	/**
	 * Lo que se le preguntó, en orden.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $asked = array();

	public function set_up(): void {
		global $wpdb;

		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Installer::install();
		Store::ensure_identity();
		wss_unhook_detector();

		update_option(
			Peers::OPTION,
			array(
				self::REMOTE_STORE => array(
					'rest_url' => self::REST_URL,
					'secret'   => 'secreto-de-prueba',
					'role'     => Peers::SECONDARY,
				),
			),
			false
		);

		$wpdb->delete( $wpdb->options, array( 'option_name' => Lock::OPTION ) ); // phpcs:ignore WordPress.DB
		wp_cache_delete( Lock::OPTION, 'options' );
		delete_option( Result::OPTION );

		$this->other = array(
			'has'            => array(),
			'purged_through' => 0,
			'ceiling'        => 0,
			'answer_mine'    => array(
				'reopened'  => 0,
				'confirmed' => 0,
			),
		);

		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	public function tear_down(): void {
		global $wpdb;

		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );

		foreach ( array( 'movements', 'links', 'nonces' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		$wpdb->delete( $wpdb->options, array( 'option_name' => Lock::OPTION ) ); // phpcs:ignore WordPress.DB
		delete_option( Result::OPTION );
		delete_option( Peers::OPTION );
		delete_option( Installer::OPTION_VERSION );
		delete_option( Store::OPTION_ID );

		parent::tear_down();
	}

	/**
	 * Hace de la otra tienda.
	 *
	 * @param false|array|WP_Error $preempt Lo que venía.
	 * @param array<string, mixed> $args    Los argumentos del request.
	 * @param string               $url     La URL.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function intercept( $preempt, array $args, string $url ) {
		$body          = json_decode( (string) $args['body'], true );
		$this->asked[] = array( 'url' => $url ) + $body;
		$step          = $body['step'];

		if ( ( $this->other['fail'] ?? null ) === $step ) {
			return $this->other['error'] ?? new WP_Error( 'http_request_failed', 'cURL error 7: no responde' );
		}

		if ( Reconcile::THEIRS === $step ) {
			if ( isset( $this->other['pages'] ) ) {
				return $this->reply( 200, array_shift( $this->other['pages'] ) );
			}

			return $this->reply(
				200,
				array(
					'seqs'           => array_values( array_filter( $this->other['has'], static fn ( $seq ) => $seq > $body['after'] && $seq <= $body['until'] ) ),
					'covered_to'     => $body['until'],
					'more'           => false,
					'purged_through' => $this->other['purged_through'],
					'ceiling'        => $this->other['ceiling'],
				)
			);
		}

		if ( Reconcile::LINKS === $step ) {
			return $this->reply(
				200,
				$this->other['answer_links'] ?? array(
					'deleted'    => array(),
					'missing'    => array(),
					'mismatched' => array(),
				)
			);
		}

		return $this->reply( 200, $this->other['answer_mine'] );
	}

	/**
	 * Una respuesta HTTP.
	 *
	 * @param int                  $status El código.
	 * @param array<string, mixed> $data   El cuerpo.
	 *
	 * @return array<string, mixed>
	 */
	private function reply( int $status, array $data ): array {
		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( $data ),
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Un movimiento propio en ese estado de entrega. Su secuencia es su ID.
	 *
	 * @param string $delivery El estado.
	 */
	private function own( string $delivery ): int {
		global $wpdb;

		$table = Installer::table( 'movements' );

		$wpdb->insert( // phpcs:ignore WordPress.DB
			$table,
			array(
				'origin_store'   => Store::id(),
				'generation'     => 1,
				'delta'          => -1,
				'reason'         => 'sale',
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
				'origin_product' => 10,
				'delivery'       => $delivery,
			)
		);

		$id = (int) $wpdb->insert_id;
		$wpdb->update( $table, array( 'origin_seq' => $id ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB

		return $id;
	}

	/**
	 * Movimientos recibidos de la otra tienda.
	 *
	 * @param int[] $seqs Sus secuencias allá.
	 */
	private function received( array $seqs ): void {
		global $wpdb;

		$table  = Installer::table( 'movements' );
		$values = array();

		foreach ( $seqs as $seq ) {
			$values[] = $wpdb->prepare( '( %s, %d, 1, -1, %s, %s, 20, %s )', self::REMOTE_STORE, $seq, 'sale', gmdate( 'Y-m-d H:i:s' ), Ledger::APPLIED );
		}

		$wpdb->query( "INSERT INTO {$table} ( origin_store, origin_seq, generation, delta, reason, created_at, origin_product, application ) VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * El estado de entrega de un movimiento.
	 *
	 * @param int $id ID.
	 */
	private function delivery_of( int $id ): ?string {
		global $wpdb;

		$table = Installer::table( 'movements' );

		return $wpdb->get_var( $wpdb->prepare( "SELECT delivery FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Las preguntas de un paso.
	 *
	 * @param string $step El paso.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function asked( string $step ): array {
		return array_values( array_filter( $this->asked, static fn ( $question ) => $step === $question['step'] ) );
	}

	/**
	 * RCL-01 — Una reconciliación entera: lo propio que falta se reencola, lo que llegó se
	 * confirma, la otra recibe lo que esta tiene de ella, y queda el resultado. El candado se
	 * suelta.
	 */
	public function test_a_whole_reconciliation(): void {
		$missing = $this->own( 'abandoned' );
		$arrived = $this->own( 'abandoned' );
		$this->received( array( 5, 9 ) );

		$this->other['has']         = array( $arrived );
		$this->other['ceiling']     = 12;
		$this->other['answer_mine'] = array(
			'reopened'  => 2,
			'confirmed' => 1,
		);

		$result = Reconciler::run( Result::MANUAL );

		$this->assertSame( Result::DONE, $result['outcome'] );
		$this->assertSame( 'pending', $this->delivery_of( $missing ) );
		$this->assertSame( 'confirmed', $this->delivery_of( $arrived ) );

		$theirs = $this->asked( Reconcile::THEIRS );
		$this->assertSame( self::REST_URL . '/reconcile/digest', $theirs[0]['url'] );
		$this->assertSame( $arrived, $theirs[0]['until'], 'el techo propio' );

		$mine = $this->asked( Reconcile::MINE );
		$this->assertCount( 1, $mine );
		$this->assertSame( array( 5, 9 ), $mine[0]['seqs'] );
		$this->assertSame( 12, $mine[0]['until'], 'el techo de la otra' );
		$this->assertSame( $result['details']['session'], $mine[0]['session'] );

		$last = Result::last();
		$this->assertSame( Result::MANUAL, $last['trigger'] ?? null );
		$this->assertSame( Result::DONE, $last['outcome'] ?? null );
		$this->assertSame( 1, $last['details']['reopened'] ?? null );
		$this->assertSame( 1, $last['details']['confirmed'] ?? null );
		$this->assertSame( 2, $last['details']['peer_reopened'] ?? null );
		$this->assertFalse( Lock::is_taken() );
	}

	/**
	 * RCL-02 — **Cada tanda se compara con la marca de purga que vino con ella**: si la otra
	 * purgó en el medio de la pasada, lo confirmado que borró no se le reenvía.
	 */
	public function test_each_page_uses_its_own_purge_mark(): void {
		$first  = $this->own( 'confirmed' );
		$second = $this->own( 'confirmed' );

		$this->other['pages'] = array(
			array(
				'seqs'           => array( $first ),
				'covered_to'     => $first,
				'more'           => true,
				'purged_through' => 0,
				'ceiling'        => 0,
			),
			// Entre la primera tanda y ésta, la otra purgó el `$second` y movió la marca.
			array(
				'seqs'           => array(),
				'covered_to'     => $second,
				'more'           => false,
				'purged_through' => $second,
				'ceiling'        => 0,
			),
		);

		Reconciler::run( Result::NIGHTLY );

		$this->assertCount( 2, $this->asked( Reconcile::THEIRS ) );
		$this->assertSame( $first, $this->asked( Reconcile::THEIRS )[1]['after'] );
		$this->assertSame( 'confirmed', $this->delivery_of( $second ), 'la otra lo tuvo y lo borró' );
	}

	/**
	 * RCL-03 — **Si la otra no responde, no se toca nada**, y el resultado dice que no se pudo
	 * mirar (ADR-009 §6). El candado se suelta.
	 */
	public function test_an_unreachable_store_changes_nothing(): void {
		$missing             = $this->own( 'abandoned' );
		$this->other['fail'] = Reconcile::THEIRS;

		$result = Reconciler::run( Result::NIGHTLY );

		$this->assertSame( Result::UNREACHABLE, $result['outcome'] );
		$this->assertSame( 'abandoned', $this->delivery_of( $missing ) );
		$this->assertSame( Result::UNREACHABLE, Result::last()['outcome'] ?? null );
		$this->assertSame( 'theirs', Result::last()['details']['stage'] ?? null );
		$this->assertSame( array(), $this->asked( Reconcile::MINE ) );
		$this->assertFalse( Lock::is_taken() );
	}

	/**
	 * RCL-04 — Con el candado propio tomado, no pregunta nada ni guarda nada.
	 */
	public function test_a_taken_lock_skips_without_a_trace(): void {
		$taken = Lock::acquire();

		$result = Reconciler::run( Result::NIGHTLY );

		$this->assertSame( Result::BUSY, $result['outcome'] );
		$this->assertSame( array(), $this->asked );
		$this->assertNull( Result::last() );
		$this->assertTrue( Lock::is_taken(), 'el candado sigue siendo del otro' );

		Lock::release( (string) $taken );
	}

	/**
	 * RCL-05 — Si la otra está reconciliando, es una pasada salteada: no se guarda nada, y el
	 * candado propio se suelta.
	 */
	public function test_a_busy_peer_skips_without_a_trace(): void {
		$this->other['fail']  = Reconcile::THEIRS;
		$this->other['error'] = $this->reply( 409, array( 'code' => 'wss_reconciling' ) );

		$result = Reconciler::run( Result::NIGHTLY );

		$this->assertSame( Result::BUSY, $result['outcome'] );
		$this->assertNull( Result::last() );
		$this->assertFalse( Lock::is_taken() );
	}

	/**
	 * RCL-06 — **Si se corta en la segunda pregunta, lo de la primera queda**: cada tanda es
	 * independiente.
	 */
	public function test_what_was_compared_before_a_failure_stays(): void {
		$missing = $this->own( 'abandoned' );
		$this->received( array( 5 ) );
		$this->other['ceiling'] = 5;
		$this->other['fail']    = Reconcile::MINE;

		$result = Reconciler::run( Result::NIGHTLY );

		$this->assertSame( Result::UNREACHABLE, $result['outcome'] );
		$this->assertSame( 'mine', $result['details']['stage'] );
		$this->assertSame( 'pending', $this->delivery_of( $missing ) );
	}

	/**
	 * RCL-07 — Una respuesta que dice "hay más" sin avanzar corta la pasada: si no, daría
	 * vueltas para siempre.
	 */
	public function test_an_answer_that_does_not_advance_stops_it(): void {
		$id = $this->own( 'abandoned' );

		$this->other['pages'] = array_fill(
			0,
			3,
			array(
				'seqs'           => array(),
				'covered_to'     => 0,
				'more'           => true,
				'purged_through' => 0,
				'ceiling'        => 0,
			)
		);

		$result = Reconciler::run( Result::NIGHTLY );

		$this->assertSame( Result::UNREACHABLE, $result['outcome'] );
		$this->assertSame( 'wss_bad_answer', $result['details']['error'] );
		$this->assertCount( 1, $this->asked );
		$this->assertSame( 'abandoned', $this->delivery_of( $id ) );
	}

	/**
	 * RCL-08 — Sin socia no hay con quién comparar.
	 */
	public function test_without_a_peer_there_is_nothing_to_compare(): void {
		delete_option( Peers::OPTION );

		$result = Reconciler::run( Result::MANUAL );

		$this->assertSame( Result::UNREACHABLE, $result['outcome'] );
		$this->assertSame( 'wss_not_paired', $result['details']['error'] );
		$this->assertSame( array(), $this->asked );
	}

	/**
	 * RCL-09 — Lo que esta tiene de la otra va de a 5.000, y cada tanda dice qué tramo cubre.
	 */
	public function test_the_second_question_goes_in_pages(): void {
		$this->received( range( 1, Books::PAGE + 1 ) );
		$this->other['ceiling'] = Books::PAGE + 10;

		Reconciler::run( Result::NIGHTLY );

		$mine = $this->asked( Reconcile::MINE );

		$this->assertCount( 2, $mine );
		$this->assertCount( Books::PAGE, $mine[0]['seqs'] );
		$this->assertSame( array( 0, Books::PAGE ), array( $mine[0]['after'], $mine[0]['until'] ) );
		$this->assertSame( array( Books::PAGE, Books::PAGE + 10 ), array( $mine[1]['after'], $mine[1]['until'] ) );
		$this->assertSame( array( Books::PAGE + 1 ), $mine[1]['seqs'] );
	}

	/**
	 * RCL-10 — La segunda pregunta lleva la marca de purga propia de lo de la otra.
	 */
	public function test_the_second_question_carries_the_own_purge_mark(): void {
		update_option(
			Peers::OPTION,
			array(
				self::REMOTE_STORE => array(
					'rest_url'       => self::REST_URL,
					'secret'         => 'secreto-de-prueba',
					'role'           => Peers::SECONDARY,
					'purged_through' => 77,
				),
			),
			false
		);

		Reconciler::run( Result::NIGHTLY );

		$this->assertSame( 77, $this->asked( Reconcile::MINE )[0]['purged_through'] );
	}

	/**
	 * Un vínculo propio.
	 *
	 * @param int $local      Producto de acá.
	 * @param int $remote     Producto de allá.
	 * @param int $generation Generación.
	 */
	private function link( int $local, int $remote, int $generation ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB
			Installer::table( 'links' ),
			array(
				'local_product'  => $local,
				'remote_store'   => self::REMOTE_STORE,
				'remote_product' => $remote,
				'generation'     => $generation,
				'base_stock'     => 10,
				'base_set_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * RCL-11 — **La maestra manda sus vínculos hasta su techo**: uno armado después de arrancar
	 * queda para la próxima.
	 */
	public function test_the_master_sends_its_links_up_to_its_ceiling(): void {
		$ceiling = $this->own( 'confirmed' );
		$this->link( 10, 110, $ceiling );
		$this->link( 11, 111, $ceiling + 5 );

		Reconciler::run( Result::NIGHTLY );

		$links = $this->asked( Reconcile::LINKS );

		$this->assertCount( 1, $links );
		$this->assertSame( $ceiling, $links[0]['ceiling'] );
		$this->assertSame(
			array(
				array(
					'local'      => 10,
					'remote'     => 110,
					'generation' => $ceiling,
				),
			),
			$links[0]['links']
		);
	}

	/**
	 * RCL-12 — La secundaria no manda vínculos: cuando lanza ella, los cubre la noche.
	 */
	public function test_the_secondary_does_not_send_links(): void {
		update_option(
			Peers::OPTION,
			array(
				self::REMOTE_STORE => array(
					'rest_url' => self::REST_URL,
					'secret'   => 'secreto-de-prueba',
					'role'     => Peers::MASTER,
				),
			),
			false
		);

		$result = Reconciler::run( Result::IMPORT );

		$this->assertSame( Result::DONE, $result['outcome'] );
		$this->assertSame( array(), $this->asked( Reconcile::LINKS ) );
	}

	/**
	 * RCL-13 — Lo que la otra informa queda en el resultado, con los productos de acá; lo que
	 * falta por un bootstrap sin resolver no se informa: todavía no se sabe.
	 */
	public function test_what_the_other_reports_about_links_is_kept(): void {
		global $wpdb;

		// Que el techo pase de las generaciones de abajo.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->own( 'confirmed' );
		}

		$this->link( 10, 110, 3 );
		$this->link( 11, 111, 4 );

		$bootstrap = $this->own( 'uncertain' );
		$wpdb->update( Installer::table( 'movements' ), array( 'reason' => Ledger::BOOTSTRAP ), array( 'id' => $bootstrap ) ); // phpcs:ignore WordPress.DB
		$this->link( 12, 112, $bootstrap );

		$this->other['answer_links'] = array(
			'deleted'    => array( 130 ),
			'missing'    => array( 3, $bootstrap ),
			'mismatched' => array( 111 ),
		);

		$result = Reconciler::run( Result::NIGHTLY );

		$this->assertSame( array( 130 ), $result['details']['peer_unlinked'] );
		$this->assertSame( array( 10 ), $result['details']['peer_missing'] );
		$this->assertSame( array( 111 ), $result['details']['peer_mismatched'] );
	}

	/**
	 * RCL-14 — Si falla la comparación de vínculos, la pasada termina sin respuesta, y lo de
	 * los movimientos queda.
	 */
	public function test_a_failure_comparing_links_keeps_the_rest(): void {
		$missing             = $this->own( 'abandoned' );
		$this->other['fail'] = Reconcile::LINKS;

		$result = Reconciler::run( Result::NIGHTLY );

		$this->assertSame( Result::UNREACHABLE, $result['outcome'] );
		$this->assertSame( 'links', $result['details']['stage'] );
		$this->assertSame( 'pending', $this->delivery_of( $missing ) );
	}
}
