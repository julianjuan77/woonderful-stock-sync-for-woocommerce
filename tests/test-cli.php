<?php
/**
 * Tests de los comandos de WP-CLI.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Log\Notifier;
use Woonderful\StockSync\Log\Events;
use Woonderful\StockSync\Cli\Command;
use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Linking\Links;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Pairing;
use Woonderful\StockSync\Transport\Peers;
use Woonderful\StockSync\Reconciliation\Lock;
use Woonderful\StockSync\Reconciliation\Result;
use Woonderful\StockSync\Rest\Reconcile;

require_once __DIR__ . '/fakes/class-wp-cli.php';

/**
 * `wp wss pairing-code` y `wp wss pair`, mientras no hay pantalla (ADR-001 §1).
 */
class Test_Cli extends WP_UnitTestCase {

	/**
	 * La opción `home` de antes del test.
	 *
	 * @var string
	 */
	private string $original_home = '';

	public function set_up(): void {
		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		delete_option( \Woonderful\StockSync\Log\Peer::OPTION );

		// Acá y no en el tear_down, por lo mismo que en test-pairing.php.
		foreach ( array( Peers::OPTION, Pairing::OPTION_PAIRING, Pairing::OPTION_REDEEMING, Store::OPTION_FINGERPRINT ) as $option ) {
			delete_option( $option );
		}

		Installer::install();
		Store::ensure_identity();

		$this->original_home = (string) get_option( 'home' );
		update_option( 'home', 'https://example.org' );

		WP_CLI::$out = array();

		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => Lock::OPTION ) ); // phpcs:ignore WordPress.DB
		wp_cache_delete( Lock::OPTION, 'options' );
		delete_option( Result::OPTION );
	}

	public function tear_down(): void {
		global $wpdb;

		remove_all_filters( 'pre_http_request' );
		update_option( 'home', $this->original_home );

		// CLI-05 vincula y desvincula de verdad: sin esto, sus filas pasaban a la corrida
		// siguiente, y la primera clase de la suite encontraba movimientos ajenos.
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
	 * CLI-01 — `pairing-code` imprime un código que se puede canjear.
	 */
	public function test_pairing_code_prints_a_code(): void {
		( new Command() )->pairing_code( array(), array() );

		$decoded = Pairing::decode( WP_CLI::$out[0] );

		$this->assertSame( Store::id(), $decoded['id'] );
		$this->assertSame( $decoded['c'], Pairing::pending_code() );
	}

	/**
	 * CLI-02 — Sin https, `pairing-code` termina con error y dice por qué.
	 */
	public function test_pairing_code_fails_without_https(): void {
		update_option( 'home', 'http://example.org' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'https' );

		( new Command() )->pairing_code( array(), array() );
	}

	/**
	 * CLI-03 — `pair` canjea el código y avisa que quedaron emparejadas.
	 */
	public function test_pair_redeems_the_code(): void {
		$master = 'b1b1b1b1-0000-4000-8000-000000000000';

		add_filter(
			'pre_http_request',
			static fn () => array(
				'headers'  => array(),
				'body'     => (string) wp_json_encode(
					array(
						'secret' => str_repeat( 'd', 64 ),
						'id'     => $master,
						'label'  => 'Minorista',
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => '',
				),
				'cookies'  => array(),
			)
		);

		$payload = (string) wp_json_encode(
			array(
				'url' => 'https://otra.test/wp-json/wss/v1',
				'id'  => $master,
				'c'   => str_repeat( 'c', 64 ),
			)
		);

		( new Command() )->pair( array( Pairing::PREFIX . rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' ) ), array() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$this->assertStringStartsWith( 'Success:', WP_CLI::$out[0] );
		$this->assertSame( str_repeat( 'd', 64 ), Peers::secret( $master ) );
	}

	/**
	 * CLI-04 — Con algo que no es un código, `pair` termina con error y el código del error.
	 */
	public function test_pair_fails_with_a_bad_code(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'wss_pairing_invalid' );

		( new Command() )->pair( array( 'no es un código' ), array() );
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
	 * CLI-05 — `link` vincula y avisa; `unlink` desvincula.
	 */
	public function test_link_and_unlink(): void {
		Peers::pair( 'b1b1b1b1-0000-4000-8000-000000000000', 'https://otra.test/wp-json/wss/v1', 'secreto', 'Mayorista', Peers::SECONDARY );
		$local = $this->product( 10 );

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) {
				$movement = json_decode( (string) $args['body'], true )['movements'][0];

				return array(
					'headers'  => array(),
					'body'     => (string) wp_json_encode(
						array(
							'results' => array(
								array(
									'origin' => $movement['origin'],
									'seq'    => $movement['seq'],
									'status' => 'received',
								),
							),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => '',
					),
					'cookies'  => array(),
				);
			},
			10,
			2
		);

		( new Command() )->link(
			array( (string) $local, '900' ),
			array(
				'stock'        => '10',
				'remote-stock' => '7',
			)
		);

		$this->assertStringStartsWith( 'Success:', WP_CLI::$out[0] );
		$this->assertNotNull( Links::for_product( $local ) );

		( new Command() )->unlink( array( (string) $local ), array() );

		$this->assertStringStartsWith( 'Success:', WP_CLI::$out[1] );
		$this->assertNull( Links::for_product( $local ) );
	}

	/**
	 * CLI-06 — En la secundaria, `link` termina con error y dice que se hace desde la maestra.
	 */
	public function test_link_from_the_secondary_fails(): void {
		Peers::pair( 'b1b1b1b1-0000-4000-8000-000000000000', 'https://otra.test/wp-json/wss/v1', 'secreto', 'Minorista', Peers::MASTER );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'wss_not_master' );

		( new Command() )->link(
			array( (string) $this->product( 10 ), '900' ),
			array(
				'stock'        => '10',
				'remote-stock' => '7',
			)
		);
	}

	/**
	 * CLI-07 — `wp wss status` con algo que no es un ID termina con error, y no muestra un
	 * "0: unlinked".
	 */
	public function test_status_rejects_what_is_not_an_id(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'abc' );

		( new Command() )->status( array( '12', 'abc' ), array() );
	}

	/**
	 * Responde por la otra tienda las preguntas de la reconciliación: no tiene nada.
	 *
	 * @param false|array|WP_Error $preempt Lo que venía.
	 * @param array<string, mixed> $args    Los argumentos del request.
	 *
	 * @return array<string, mixed>
	 */
	public function empty_store( $preempt, array $args ): array {
		$body = json_decode( (string) $args['body'], true );
		$data = Reconcile::THEIRS === $body['step']
			? array(
				'seqs'           => array(),
				'covered_to'     => $body['until'],
				'more'           => false,
				'purged_through' => 0,
				'ceiling'        => 0,
			)
			: array(
				'reopened'  => 0,
				'confirmed' => 0,
			);

		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( $data ),
			'response' => array(
				'code'    => 200,
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * CLI-08 — En la secundaria, `reconcile` termina con error: la lanza la maestra.
	 */
	public function test_reconcile_from_the_secondary_fails(): void {
		Peers::pair( 'b1b1b1b1-0000-4000-8000-000000000000', 'https://otra.test/wp-json/wss/v1', 'secreto', 'Minorista', Peers::MASTER );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'wss_not_master' );

		( new Command() )->reconcile( array(), array() );
	}

	/**
	 * CLI-09 — En la maestra, reconcilia y dice qué hizo de cada lado.
	 */
	public function test_reconcile_from_the_master_prints_what_it_did(): void {
		Peers::pair( 'b1b1b1b1-0000-4000-8000-000000000000', 'https://otra.test/wp-json/wss/v1', 'secreto', 'Mayorista', Peers::SECONDARY );
		add_filter( 'pre_http_request', array( $this, 'empty_store' ), 10, 2 );

		( new Command() )->reconcile( array(), array() );

		$this->assertSame( 'Success: Reconciliadas.', end( WP_CLI::$out ) );
		$this->assertStringStartsWith( 'Acá: 0', WP_CLI::$out[0] );
		$this->assertSame( Result::MANUAL, Result::last()['trigger'] ?? null );
	}

	/**
	 * CLI-10 — **Si la otra no responde, lo dice como error**: no se pudo mirar, que no es lo
	 * mismo que no haber nada que arreglar (UC-06).
	 */
	public function test_reconcile_says_when_it_could_not_look(): void {
		Peers::pair( 'b1b1b1b1-0000-4000-8000-000000000000', 'https://otra.test/wp-json/wss/v1', 'secreto', 'Mayorista', Peers::SECONDARY );
		add_filter( 'pre_http_request', static fn () => new WP_Error( 'http_request_failed', 'no responde' ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'no se pudo mirar' );

		( new Command() )->reconcile( array(), array() );
	}

	/**
	 * CLI-11 — Con una reconciliación en curso, termina con error y no lanza otra.
	 */
	public function test_reconcile_while_another_runs_fails(): void {
		Peers::pair( 'b1b1b1b1-0000-4000-8000-000000000000', 'https://otra.test/wp-json/wss/v1', 'secreto', 'Mayorista', Peers::SECONDARY );
		Lock::acquire();

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'en curso' );

		( new Command() )->reconcile( array(), array() );
	}

	/**
	 * CLI-12 — Si la otra borró vínculos sin respaldo, o falta alguno, lo dice.
	 */
	public function test_reconcile_reports_one_sided_links(): void {
		Peers::pair( 'b1b1b1b1-0000-4000-8000-000000000000', 'https://otra.test/wp-json/wss/v1', 'secreto', 'Mayorista', Peers::SECONDARY );

		$answer = function ( $preempt, array $args ): array {
			$body = json_decode( (string) $args['body'], true );

			if ( Reconcile::LINKS !== $body['step'] ) {
				return $this->empty_store( $preempt, $args );
			}

			return array(
				'headers'  => array(),
				'body'     => (string) wp_json_encode(
					array(
						'deleted'    => array( 130 ),
						'missing'    => array(),
						'mismatched' => array(),
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => '',
				),
				'cookies'  => array(),
			);
		};

		add_filter( 'pre_http_request', $answer, 10, 2 );

		( new Command() )->reconcile( array(), array() );

		$this->assertContains( 'La otra tienda tenía vínculos que acá no existen, y los borró: sus productos 130.', WP_CLI::$out );
	}

	/**
	 * CLI-13 — `discrepancies` sin nada abierto lo dice.
	 */
	public function test_discrepancies_says_when_there_are_none(): void {
		( new Command() )->discrepancies( array(), array() );

		$this->assertSame( array( 'Success: No hay discrepancias abiertas.' ), WP_CLI::$out );
	}

	/**
	 * CLI-14 — `discrepancies` lista cada una: lo que dice el libro, lo que hay y desde cuándo;
	 * y `discrepancy accept` la resuelve.
	 */
	public function test_discrepancies_lists_and_accept_resolves(): void {
		global $wpdb;

		$product = $this->product( 47 );
		$wpdb->insert( // phpcs:ignore WordPress.DB
			Installer::table( 'links' ),
			array(
				'local_product'       => $product,
				'remote_store'        => 'b1b1b1b1-0000-4000-8000-000000000000',
				'remote_product'      => 900,
				'generation'          => 1,
				'base_stock'          => 50,
				'base_set_at'         => gmdate( 'Y-m-d H:i:s' ),
				'expected_stock'      => 50,
				'found_stock'         => 47,
				'discrepancy_since'   => '2026-10-01 03:00:00',
				'discrepancy_seen_at' => '2026-10-05 03:00:00',
			)
		);

		( new Command() )->discrepancies( array(), array() );

		$this->assertSame( array( $product . ': el libro dice 50, hay 47 (desde 2026-10-01 03:00:00)' ), WP_CLI::$out );

		( new Command() )->discrepancy( array( 'accept', (string) $product ), array() );

		$this->assertStringStartsWith( 'Success: Anotado como ajuste', end( WP_CLI::$out ) );
	}

	/**
	 * CLI-15 — Una acción que no es `accept` ni `restore` termina con error.
	 */
	public function test_discrepancy_rejects_an_unknown_action(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'accept o restore' );

		( new Command() )->discrepancy( array( 'borrar', '12' ), array() );
	}

	/**
	 * CLI-16 — `wp wss log` muestra cada evento con su fecha, su nivel y su frase.
	 */
	public function test_log_shows_the_events(): void {
		Events::record( Events::PEER_BACK, Events::INFO );

		( new Command() )->log( array(), array() );

		$this->assertCount( 1, WP_CLI::$out );
		$this->assertStringContainsString( 'INFO  La otra tienda volvió a responder.', WP_CLI::$out[0] );
	}

	/**
	 * CLI-17 — `wp wss log --product` muestra el historial: movimientos y eventos juntos.
	 */
	public function test_log_shows_a_product_history(): void {
		global $wpdb;

		$wpdb->insert( Installer::table( 'movements' ), array( 'origin_store' => Store::id(), 'origin_seq' => 1, 'generation' => 1, 'delta' => -2, 'reason' => 'sale', 'reference' => 'pedido #9', 'created_at' => '2026-10-01 10:00:00', 'origin_product' => 12, 'delivery' => 'confirmed' ) ); // phpcs:ignore WordPress.DB, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		Events::record( Events::UNLINKED, Events::INFO, 12 );

		( new Command() )->log( array(), array( 'product' => '12' ) );

		$this->assertCount( 2, WP_CLI::$out );
		$this->assertStringContainsString( 'Se desvinculó el producto 12.', WP_CLI::$out[0] );
		$this->assertStringContainsString( 'Acá: venta, -2 (pedido #9)', WP_CLI::$out[1] );
	}

	/**
	 * CLI-18 — `wp wss notify` cambia los avisos y muestra cómo quedaron.
	 */
	public function test_notify_changes_the_settings(): void {
		( new Command() )->notify(
			array(),
			array(
				'enabled' => 'no',
				'email'   => 'deposito@example.org',
				'after'   => '48',
			)
		);

		$this->assertFalse( Notifier::enabled() );
		$this->assertSame( 'deposito@example.org', Notifier::recipient() );
		$this->assertSame( 48, Notifier::after_hours() );
		$this->assertSame( 'Avisos: no. A: deposito@example.org. Plazo de lo que no sincroniza: 48 horas.', WP_CLI::$out[0] );

		foreach ( array( Notifier::OPTION_ENABLED, Notifier::OPTION_EMAIL, Notifier::OPTION_AFTER ) as $option ) {
			delete_option( $option );
		}
	}

	/**
	 * CLI-19 — `wp wss notify` con un email que no es, termina con error y no lo guarda.
	 */
	public function test_notify_rejects_a_bad_email(): void {
		try {
			( new Command() )->notify( array(), array( 'email' => 'no-es-un-email' ) );
			$this->fail( 'tenía que terminar con error' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'no es un email', $error->getMessage() );
		}

		$this->assertFalse( get_option( Notifier::OPTION_EMAIL, false ) );
	}
}
