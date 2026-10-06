<?php
/**
 * Tests del emparejamiento.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Identity\Store;
use Woonderful\StockSync\Rest\Health;
use Woonderful\StockSync\Rest\Pair;
use Woonderful\StockSync\Schema\Installer;
use Woonderful\StockSync\Transport\Pairing;
use Woonderful\StockSync\Transport\Peers;
use Woonderful\StockSync\Transport\Signature;

/**
 * El canje de un código de un solo uso por un secreto compartido (ADR-001 §1).
 *
 * Una sola instalación hace de las dos tiendas por turno: la otra la hace
 * `pre_http_request`.
 */
class Test_Pairing extends WP_UnitTestCase {

	private const OTHER = 'b1b1b1b1-0000-4000-8000-000000000000';

	private const OTHER_URL = 'https://otra.test/wp-json/wss/v1';

	/**
	 * El servidor REST, con las rutas registradas.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	/**
	 * Lo que contesta la otra tienda: recibe la URL y los argumentos del request.
	 *
	 * @var callable|null
	 */
	private $other = null;

	/**
	 * Los requests que salieron.
	 *
	 * @var array<int, array{url: string, args: array<string, mixed>}>
	 */
	private array $sent = array();

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

		// Acá y no en el tear_down: ahí van después del DROP TABLE, en una transacción que el
		// ROLLBACK de la suite deshace, y `wss_peers` llegaba con entradas de otros tests. El
		// CREATE TABLE de install() confirma el borrado (ver tests/TEST-CASES.md).
		foreach ( array( Peers::OPTION, Pairing::OPTION_PAIRING, Pairing::OPTION_REDEEMING, Store::OPTION_FINGERPRINT ) as $option ) {
			delete_option( $option );
		}

		Installer::install();
		Store::ensure_identity();

		// El entorno de tests es `production` y su `home` es http: sin esto, el control de
		// https lo rechazaría todo.
		$this->original_home = (string) get_option( 'home' );
		update_option( 'home', 'https://example.org' );

		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride

		add_action( 'rest_api_init', array( Pair::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( Health::class, 'register_routes' ) );
		$this->server = rest_get_server();

		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	public function tear_down(): void {
		global $wpdb, $wp_rest_server;

		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		remove_action( 'rest_api_init', array( Pair::class, 'register_routes' ) );
		remove_action( 'rest_api_init', array( Health::class, 'register_routes' ) );
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride

		update_option( 'home', $this->original_home );

		// Las filas del log, no la tabla: el DROP de las otras confirma el borrado, y una
		// tabla menos que crear y borrar en cada test hace la suite más rápida.
		$wpdb->query( 'DELETE FROM ' . Installer::table( 'events' ) ); // phpcs:ignore WordPress.DB

		foreach ( array( 'movements', 'links', 'nonces' ) as $name ) {
			$table = Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		foreach ( array( Peers::OPTION, Pairing::OPTION_PAIRING, Pairing::OPTION_REDEEMING, Store::OPTION_FINGERPRINT, Installer::OPTION_VERSION, Store::OPTION_ID ) as $option ) {
			delete_option( $option );
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
			return new WP_Error( 'http_request_failed', 'nadie contesta' );
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
	 * Si un request saliente va firmado con esa clave.
	 *
	 * @param array<string, mixed> $args  Los argumentos del request.
	 * @param string               $key   La clave.
	 * @param string               $route La ruta REST firmada.
	 */
	private static function signed_with( array $args, string $key, string $route ): bool {
		$headers = $args['headers'];

		return Signature::verify( $key, (string) $args['method'], $route, array(), (int) $headers['X-WSS-Timestamp'], $headers['X-WSS-Nonce'], (string) ( $args['body'] ?? '' ), $headers['X-WSS-Signature'], time() );
	}

	/**
	 * Un `POST /pair` como lo mandaría la secundaria.
	 *
	 * @param string               $key  Con qué firma.
	 * @param array<string, mixed> $body El cuerpo.
	 */
	private function pair_request( string $key, array $body = array() ): WP_REST_Request {
		$json = (string) wp_json_encode(
			array_merge(
				array(
					'url'   => self::OTHER_URL,
					'id'    => self::OTHER,
					'label' => 'Mayorista',
				),
				$body
			)
		);

		$request = new WP_REST_Request( 'POST', Pairing::ROUTE );
		$request->set_body( $json );

		foreach ( Signature::headers( self::OTHER, $key, 'POST', Pairing::ROUTE, array(), $json ) as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return $request;
	}

	/**
	 * La secundaria contesta el callback: `health` con el identificador que se le pase.
	 *
	 * @param string $store El `store` que declara.
	 */
	private function other_answers_health_as( string $store ): void {
		$this->other = static fn () => self::reply( 200, array( 'store' => $store ) );
	}

	/**
	 * Un código emitido por otra tienda, para canjear acá.
	 *
	 * @param string $code El código propiamente dicho.
	 */
	private static function code_from_other( string $code ): string {
		$payload = (string) wp_json_encode(
			array(
				'url' => self::OTHER_URL,
				'id'  => self::OTHER,
				'c'   => $code,
			)
		);

		return Pairing::PREFIX . rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Un `GET /health` firmado como lo firmaría la maestra en el callback.
	 *
	 * @param string $key Con qué firma.
	 */
	private function callback_request( string $key ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/wss/v1/health' );

		foreach ( Signature::headers( self::OTHER, $key, 'GET', '/wss/v1/health', array(), '' ) as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return $request;
	}

	/**
	 * El código lleva la URL de la API de esta tienda, su identificador y 256 bits, y
	 * queda guardado 15 minutos.
	 *
	 * PAR-01
	 */
	public function test_a_code_carries_the_url_the_id_and_256_bits(): void {
		$decoded = Pairing::decode( (string) Pairing::create_code() );

		$this->assertSame( untrailingslashit( rest_url( 'wss/v1' ) ), $decoded['url'] );
		$this->assertSame( Store::id(), $decoded['id'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $decoded['c'] );
		$this->assertSame( $decoded['c'], Pairing::pending_code() );

		$expires = get_option( Pairing::OPTION_PAIRING )['expires'];
		$this->assertEqualsWithDelta( time() + Pairing::CODE_TTL, $expires, 2 );
	}

	/**
	 * El canje completo, del lado de la maestra: el callback va firmado con el código, y al
	 * terminar las dos quedan emparejadas y el código ya no vale.
	 *
	 * PAR-02
	 */
	public function test_the_master_accepts_a_redeem(): void {
		$code = Pairing::decode( (string) Pairing::create_code() )['c'];
		$this->other_answers_health_as( self::OTHER );

		$response = $this->server->dispatch( $this->pair_request( $code ) );
		$data     = $response->get_data();
		$peer     = get_option( Peers::OPTION )[ self::OTHER ];

		$this->assertSame( 200, $response->get_status() );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $data['secret'] );
		$this->assertSame( Store::id(), $data['id'] );

		$this->assertSame( self::OTHER_URL . '/health', $this->sent[0]['url'] );
		$this->assertSame( Store::id(), $this->sent[0]['args']['headers']['X-WSS-Store'] );
		$this->assertTrue( self::signed_with( $this->sent[0]['args'], $code, '/wss/v1/health' ), 'el callback va firmado con el código' );

		$this->assertSame( self::OTHER_URL, $peer['rest_url'] );
		$this->assertSame( $data['secret'], $peer['secret'] );
		$this->assertSame( 'Mayorista', $peer['label'] );
		$this->assertSame( Peers::SECONDARY, $peer['role'], 'la que canjeó es la secundaria' );
		$this->assertTrue( Peers::this_is_master(), 'la que emitió el código es la maestra' );
		$this->assertSame( Store::fingerprint(), get_option( Store::OPTION_FINGERPRINT ) );
		$this->assertSame( '', Pairing::pending_code(), 'el código vale para un solo canje exitoso' );
	}

	/**
	 * PAR-03 — El mismo código, una segunda vez, ya no vale.
	 */
	public function test_a_code_is_single_use(): void {
		$code = Pairing::decode( (string) Pairing::create_code() )['c'];
		$this->other_answers_health_as( self::OTHER );

		$this->server->dispatch( $this->pair_request( $code ) );
		$again = $this->server->dispatch( $this->pair_request( $code ) );

		$this->assertSame( 401, $again->get_status() );
		$this->assertSame( 'wss_pairing_invalid', $again->get_data()['code'] );
	}

	/**
	 * PAR-04 — Un código vencido no vale.
	 */
	public function test_an_expired_code_is_invalid(): void {
		$code = Pairing::decode( (string) Pairing::create_code() )['c'];
		update_option(
			Pairing::OPTION_PAIRING,
			array(
				'code'    => $code,
				'expires' => time() - 1,
			),
			false
		);

		$response = $this->server->dispatch( $this->pair_request( $code ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'wss_pairing_invalid', $response->get_data()['code'] );
	}

	/**
	 * Firmado con otro código es `wss_pairing_invalid`, no `wss_bad_signature`, y el código
	 * de verdad sigue valiendo: el dueño puede reintentar.
	 *
	 * PAR-05
	 */
	public function test_a_wrong_code_is_invalid_and_the_real_one_survives(): void {
		$code = Pairing::decode( (string) Pairing::create_code() )['c'];

		$response = $this->server->dispatch( $this->pair_request( str_repeat( 'a', 64 ) ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'wss_pairing_invalid', $response->get_data()['code'] );
		$this->assertSame( $code, Pairing::pending_code() );
	}

	/**
	 * El callback responde, pero declara ser otra tienda: no se empareja, y el código sigue
	 * valiendo para reintentar.
	 *
	 * PAR-06
	 */
	public function test_a_callback_from_another_store_fails(): void {
		$code = Pairing::decode( (string) Pairing::create_code() )['c'];
		$this->other_answers_health_as( 'c2c2c2c2-0000-4000-8000-000000000000' );

		$response = $this->server->dispatch( $this->pair_request( $code ) );

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'wss_callback_failed', $response->get_data()['code'] );
		$this->assertFalse( get_option( Peers::OPTION, false ) );
		$this->assertSame( $code, Pairing::pending_code() );
	}

	/**
	 * Sin https no se empareja: ni esta tienda emite un código, ni acepta una secundaria en
	 * http. Es lo que pide ADR-007 para la huella.
	 *
	 * PAR-07
	 */
	public function test_without_https_there_is_no_pairing(): void {
		$code = Pairing::decode( (string) Pairing::create_code() )['c'];

		$response = $this->server->dispatch( $this->pair_request( $code, array( 'url' => 'http://otra.test/wp-json/wss/v1' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wss_insecure', $response->get_data()['code'] );

		update_option( 'home', 'http://example.org' );

		$this->assertSame( 'wss_insecure', Pairing::create_code()->get_error_code() );
	}

	/**
	 * Emparejar borra la URL y el secreto de cualquier otra entrada —en v1 hay una sola
	 * socia— y conserva el `purged_through`, que usa la purga.
	 *
	 * PAR-08
	 */
	public function test_pairing_replaces_the_previous_partner(): void {
		update_option(
			Peers::OPTION,
			array(
				'vieja' => array(
					'rest_url'       => 'https://vieja.test/wp-json/wss/v1',
					'secret'         => 'viejo',
					'purged_through' => 77,
				),
			),
			false
		);

		Peers::pair( self::OTHER, self::OTHER_URL, str_repeat( 'b', 64 ), 'Mayorista', Peers::SECONDARY );

		$peers = get_option( Peers::OPTION );

		$this->assertSame( array( 'purged_through' => 77 ), $peers['vieja'] );
		$this->assertSame( self::OTHER, Peers::only()['store'] );
	}

	/**
	 * El canje completo, del lado de la secundaria. Mientras dura, el callback de la
	 * maestra firmado con el código pasa `health`; al terminar se guarda la socia.
	 *
	 * PAR-09
	 */
	public function test_the_secondary_redeems_a_code(): void {
		$code    = str_repeat( 'c', 64 );
		$secret  = str_repeat( 'd', 64 );
		$health  = null;
		$server  = $this->server;
		$request = $this->callback_request( $code );

		$this->other = function ( string $url, array $args ) use ( $code, $secret, &$health, $server, $request ) {
			$this->assertSame( self::OTHER_URL . '/pair', $url );
			$this->assertTrue( self::signed_with( $args, $code, Pairing::ROUTE ), 'el POST /pair va firmado con el código' );

			// Lo que haría la maestra en el medio: el callback.
			$health = $server->dispatch( $request );

			return self::reply(
				200,
				array(
					'secret' => $secret,
					'id'     => self::OTHER,
					'label'  => 'Minorista',
				)
			);
		};

		$this->assertTrue( Pairing::redeem( self::code_from_other( $code ) ) );

		$this->assertSame( 200, $health->get_status(), 'el callback pasa mientras dura el canje' );
		$this->assertSame( Store::id(), $health->get_data()['store'] );
		$this->assertSame( $secret, Peers::secret( self::OTHER ) );
		$this->assertSame( self::OTHER_URL, Peers::only()['rest_url'] );
		$this->assertTrue( Peers::is_master( self::OTHER ), 'la que emitió el código es la maestra' );
		$this->assertFalse( Peers::this_is_master() );
		$this->assertSame( Store::fingerprint(), get_option( Store::OPTION_FINGERPRINT ) );
		$this->assertFalse( get_option( Pairing::OPTION_REDEEMING, false ) );
	}

	/**
	 * PAR-10 — Terminado el canje, el código ya no autentica un `health`.
	 */
	public function test_after_redeeming_the_code_authenticates_nothing(): void {
		$code        = str_repeat( 'c', 64 );
		$this->other = static fn () => self::reply(
			200,
			array(
				'secret' => str_repeat( 'd', 64 ),
				'id'     => self::OTHER,
				'label'  => 'Minorista',
			)
		);

		$this->assertTrue( Pairing::redeem( self::code_from_other( $code ) ), 'sin un canje que termine bien, el 401 no prueba nada' );

		$this->assertSame( 401, $this->server->dispatch( $this->callback_request( $code ) )->get_status() );
	}

	/**
	 * Re-emparejar es también la rotación: con un secreto viejo de la maestra, el callback
	 * firmado con el código tiene que pasar igual.
	 *
	 * PAR-11
	 */
	public function test_re_pairing_passes_the_callback_with_an_old_secret(): void {
		Peers::pair( self::OTHER, self::OTHER_URL, str_repeat( 'e', 64 ), 'Minorista', Peers::MASTER );

		$code    = str_repeat( 'c', 64 );
		$health  = null;
		$server  = $this->server;
		$request = $this->callback_request( $code );

		$this->other = function () use ( &$health, $server, $request ) {
			$health = $server->dispatch( $request );

			return self::reply(
				200,
				array(
					'secret' => str_repeat( 'f', 64 ),
					'id'     => self::OTHER,
					'label'  => 'Minorista',
				)
			);
		};

		$this->assertTrue( Pairing::redeem( self::code_from_other( $code ) ) );
		$this->assertSame( 200, $health->get_status() );
		$this->assertSame( str_repeat( 'f', 64 ), Peers::secret( self::OTHER ) );
	}

	/**
	 * Si la maestra rechaza el canje, la secundaria recibe su código de error, no guarda
	 * nada y cierra la ventana del canje.
	 *
	 * PAR-12
	 */
	public function test_a_rejected_redeem_leaves_the_secondary_unpaired(): void {
		$this->other = static fn () => self::reply(
			401,
			array(
				'code'    => 'wss_pairing_invalid',
				'message' => 'x',
			)
		);

		$result = Pairing::redeem( self::code_from_other( str_repeat( 'c', 64 ) ) );

		$this->assertSame( 'wss_pairing_invalid', $result->get_error_code() );
		$this->assertFalse( get_option( Peers::OPTION, false ) );
		$this->assertFalse( get_option( Pairing::OPTION_REDEEMING, false ) );
	}

	/**
	 * Quien contesta el canje tiene que ser la tienda que emitió el código.
	 *
	 * PAR-13
	 */
	public function test_an_answer_from_another_store_is_rejected(): void {
		$this->other = static fn () => self::reply(
			200,
			array(
				'secret' => str_repeat( 'd', 64 ),
				'id'     => 'c2c2c2c2-0000-4000-8000-000000000000',
				'label'  => 'Otra',
			)
		);

		$result = Pairing::redeem( self::code_from_other( str_repeat( 'c', 64 ) ) );

		$this->assertSame( 'wss_pairing_failed', $result->get_error_code() );
		$this->assertFalse( get_option( Peers::OPTION, false ) );
	}

	/**
	 * Lo que no es un código no se intenta canjear, y un código cuya maestra está en http
	 * tampoco: el secreto volvería en claro.
	 *
	 * PAR-14
	 */
	public function test_a_bad_or_insecure_code_is_not_redeemed(): void {
		$this->assertSame( 'wss_pairing_invalid', Pairing::redeem( 'no es un código' )->get_error_code() );

		$payload = (string) wp_json_encode(
			array(
				'url' => 'http://otra.test/wp-json/wss/v1',
				'id'  => self::OTHER,
				'c'   => str_repeat( 'c', 64 ),
			)
		);
		$code    = Pairing::PREFIX . rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$this->assertSame( 'wss_insecure', Pairing::redeem( $code )->get_error_code() );
		$this->assertSame( array(), $this->sent, 'no sale nada' );
	}

	/**
	 * PAR-15 — Un cuerpo sin `id` es `wss_bad_request`, y el código sigue valiendo.
	 */
	public function test_a_body_without_id_is_a_bad_request(): void {
		$code = Pairing::decode( (string) Pairing::create_code() )['c'];

		$response = $this->server->dispatch( $this->pair_request( $code, array( 'id' => '' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wss_bad_request', $response->get_data()['code'] );
		$this->assertSame( $code, Pairing::pending_code() );
	}

	/**
	 * El código del canje autentica sólo a la maestra que lo emitió: otra tienda que firme
	 * un `health` con ese mismo código, mientras dura el canje, no pasa.
	 *
	 * PAR-16
	 */
	public function test_the_redeeming_code_only_serves_that_master(): void {
		$code    = str_repeat( 'c', 64 );
		$health  = null;
		$server  = $this->server;
		$request = new WP_REST_Request( 'GET', '/wss/v1/health' );

		foreach ( Signature::headers( 'c2c2c2c2-0000-4000-8000-000000000000', $code, 'GET', '/wss/v1/health', array(), '' ) as $name => $value ) {
			$request->set_header( $name, $value );
		}

		$this->other = function () use ( &$health, $server, $request ) {
			$health = $server->dispatch( $request );

			return self::reply( 401, array( 'code' => 'wss_callback_failed' ) );
		};

		Pairing::redeem( self::code_from_other( $code ) );

		$this->assertSame( 401, $health->get_status() );
	}

	/**
	 * Una copia de la base de esta tienda trae el mismo identificador. La maestra la rechaza:
	 * no se guarda a sí misma como socia, no toca a la real, no guarda la huella, y el código
	 * sigue valiendo para la tienda de verdad.
	 *
	 * PAR-17
	 */
	public function test_the_master_rejects_its_own_copy(): void {
		Peers::pair( self::OTHER, self::OTHER_URL, str_repeat( 'e', 64 ), 'Mayorista', Peers::SECONDARY );
		$peers = get_option( Peers::OPTION );

		$code = Pairing::decode( (string) Pairing::create_code() )['c'];
		$this->other_answers_health_as( Store::id() );

		$response = $this->server->dispatch( $this->pair_request( $code, array( 'id' => Store::id() ) ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wss_same_store', $response->get_data()['code'] );
		$this->assertSame( $peers, get_option( Peers::OPTION ), 'ni se guarda a sí misma ni borra a la real' );
		$this->assertFalse( get_option( Store::OPTION_FINGERPRINT, false ) );
		$this->assertSame( $code, Pairing::pending_code() );
		$this->assertSame( array(), $this->sent, 'ni siquiera hace el callback' );
	}

	/**
	 * La secundaria corta antes de salir a la red si el código es de su propio identificador.
	 *
	 * PAR-18
	 */
	public function test_the_secondary_does_not_redeem_its_own_code(): void {
		$payload = (string) wp_json_encode(
			array(
				'url' => self::OTHER_URL,
				'id'  => Store::id(),
				'c'   => str_repeat( 'c', 64 ),
			)
		);
		$code    = Pairing::PREFIX . rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$result = Pairing::redeem( $code );

		$this->assertSame( 'wss_same_store', $result->get_error_code() );
		$this->assertSame( array(), $this->sent );
		$this->assertFalse( get_option( Peers::OPTION, false ) );
	}

	/**
	 * Un emparejamiento anterior a ADR-008 no tiene rol: no es maestra ninguna de las dos, y
	 * no se vincula hasta re-emparejar.
	 *
	 * PAR-19
	 */
	public function test_a_pairing_without_role_has_no_master(): void {
		update_option(
			Peers::OPTION,
			array(
				self::OTHER => array(
					'rest_url' => self::OTHER_URL,
					'secret'   => str_repeat( 'e', 64 ),
				),
			),
			false
		);

		$this->assertFalse( Peers::this_is_master() );
		$this->assertFalse( Peers::is_master( self::OTHER ) );
	}
}
