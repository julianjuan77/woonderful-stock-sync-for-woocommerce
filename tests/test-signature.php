<?php
/**
 * Tests de la firma de los requests entre tiendas.
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Transport\Signature;

/**
 * La firma cubre método, ruta, query, timestamp, nonce y cuerpo (ADR-001 §1).
 */
class Test_Signature extends WP_UnitTestCase {

	private const SECRET = 'secreto-de-prueba';

	private const NOW = 1758300000;

	/**
	 * Firma el request de referencia, con lo que se le quiera cambiar.
	 *
	 * @param array<string, mixed> $overrides Campos a pisar.
	 */
	private function sign( array $overrides = array() ): string {
		$request = array_merge( $this->request(), $overrides );

		return Signature::sign( self::SECRET, $request['method'], $request['route'], $request['query'], $request['timestamp'], $request['nonce'], $request['body'] );
	}

	/**
	 * Verifica una firma contra el request de referencia, con lo que se le quiera cambiar.
	 *
	 * @param string               $signature La firma.
	 * @param array<string, mixed> $overrides Campos a pisar del lado del receptor.
	 */
	private function verify( string $signature, array $overrides = array() ): bool {
		$request = array_merge( $this->request(), $overrides );

		return Signature::verify( $request['secret'], $request['method'], $request['route'], $request['query'], $request['timestamp'], $request['nonce'], $request['body'], $signature, $request['now'] );
	}

	/**
	 * El request de referencia.
	 *
	 * @return array<string, mixed>
	 */
	private function request(): array {
		return array(
			'secret'    => self::SECRET,
			'method'    => 'POST',
			'route'     => '/wss/v1/movements',
			'query'     => array(
				'q'    => 'a b&c',
				'page' => 3,
			),
			'timestamp' => self::NOW,
			'nonce'     => '7f3c1b9e4a2d',
			'body'      => '{"movements":[]}',
			'now'       => self::NOW,
		);
	}

	/**
	 * La firma sale del texto que fija el ADR, y no de lo que el código crea que es. El valor
	 * esperado se calculó fuera de este código, con `hmac.new()` de Python sobre el mismo
	 * texto y el mismo secreto.
	 *
	 * Si este test falla, las dos tiendas ya no se entienden con otra implementación del ADR.
	 *
	 * SIG-01
	 */
	public function test_the_signature_matches_an_independent_implementation(): void {
		$this->assertSame( '4b0cbc04340720811213bb93fbd9e10ee50285dd977856dbe0a82fac883a5872', $this->sign() );
	}

	/**
	 * Las seis líneas, en el orden del ADR y sin salto al final. Sin query —el caso de
	 * `POST /movements`, el endpoint principal— la línea queda vacía pero está: sacarla
	 * correría el resto y la otra tienda firmaría otro texto.
	 *
	 * SIG-02
	 */
	public function test_the_canonical_text_has_six_lines(): void {
		$this->assertSame(
			"POST\n/wss/v1/movements\npage=3&q=a%20b%26c\n1758300000\n7f3c1b9e4a2d\n" . hash( 'sha256', '{"movements":[]}' ),
			Signature::canonical(
				'post',
				'/wss/v1/movements',
				array(
					'q'    => 'a b&c',
					'page' => 3,
				),
				self::NOW,
				'7f3c1b9e4a2d',
				'{"movements":[]}'
			)
		);

		$this->assertSame(
			"POST\n/wss/v1/movements\n\n1758300000\n7f3c1b9e4a2d\n" . hash( 'sha256', '' ),
			Signature::canonical( 'POST', '/wss/v1/movements', array(), self::NOW, '7f3c1b9e4a2d', '' )
		);
	}

	/**
	 * SIG-03 — El receptor acepta un request bien firmado.
	 */
	public function test_a_valid_signature_verifies(): void {
		$this->assertTrue( $this->verify( $this->sign() ) );
	}

	/**
	 * Cada una de las seis líneas está cubierta: cambiar cualquiera rompe la firma.
	 *
	 * SIG-04
	 */
	public function test_changing_any_part_breaks_the_signature(): void {
		$signature = $this->sign();

		$changes = array(
			'el método'    => array( 'method' => 'GET' ),
			'la ruta'      => array( 'route' => '/wss/v1/products' ),
			'la query'     => array(
				'query' => array(
					'q'    => 'a b&c',
					'page' => 4,
				),
			),
			'el timestamp' => array( 'timestamp' => self::NOW + 1 ),
			'el nonce'     => array( 'nonce' => '7f3c1b9e4a2e' ),
			'el cuerpo'    => array( 'body' => '{"movements":[1]}' ),
			'el secreto'   => array( 'secret' => 'otro-secreto' ),
		);

		foreach ( $changes as $what => $overrides ) {
			$this->assertFalse( $this->verify( $signature, $overrides ), "cambiar {$what} tiene que romper la firma" );
		}
	}

	/**
	 * Los dos lados pueden armar la query en otro orden: el texto firmado es el mismo.
	 *
	 * SIG-05
	 */
	public function test_the_query_order_does_not_matter(): void {
		$query = array(
			'page' => '3',
			'q'    => 'a b&c',
		);

		$this->assertTrue( $this->verify( $this->sign(), array( 'query' => $query ) ) );
	}

	/**
	 * Con permalinks sin formato WordPress mete la ruta en la query. El mismo request tiene
	 * que verificar igual en una tienda con permalinks y en una sin ellos.
	 *
	 * SIG-06
	 */
	public function test_rest_route_in_the_query_is_ignored(): void {
		$query = array(
			'rest_route' => '/wss/v1/movements',
			'q'          => 'a b&c',
			'page'       => '3',
		);

		$this->assertTrue( $this->verify( $this->sign(), array( 'query' => $query ) ) );
	}

	/**
	 * Un parámetro que no es escalar no verifica, y no se puede firmar: normalizar listas
	 * es donde los dos lados se desincronizan (ADR-001 §1).
	 *
	 * SIG-07
	 */
	public function test_a_list_in_the_query_does_not_verify(): void {
		$this->assertFalse( $this->verify( $this->sign(), array( 'query' => array( 'page' => array( 3, 4 ) ) ) ) );

		$this->expectException( InvalidArgumentException::class );
		$this->sign( array( 'query' => array( 'page' => array( 3, 4 ) ) ) );
	}

	/**
	 * Justo en el borde de los cinco minutos todavía vale; un segundo después, no. Para los
	 * dos lados: un reloj adelantado es tan sospechoso como uno atrasado.
	 *
	 * SIG-08
	 */
	public function test_the_timestamp_window_is_five_minutes(): void {
		$signature = $this->sign();

		$this->assertTrue( $this->verify( $signature, array( 'now' => self::NOW + 300 ) ) );
		$this->assertTrue( $this->verify( $signature, array( 'now' => self::NOW - 300 ) ) );
		$this->assertFalse( $this->verify( $signature, array( 'now' => self::NOW + 301 ) ) );
		$this->assertFalse( $this->verify( $signature, array( 'now' => self::NOW - 301 ) ) );
	}

	/**
	 * SIG-09 — La firma va en hexadecimal en minúsculas: una en mayúsculas no verifica.
	 */
	public function test_the_signature_is_lowercase_hex(): void {
		$signature = $this->sign();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $signature );
		$this->assertFalse( $this->verify( strtoupper( $signature ) ) );
	}

	/**
	 * Las cabeceras que arma el emisor verifican del lado del receptor, y cada request lleva
	 * su propio nonce.
	 *
	 * SIG-10
	 */
	public function test_the_headers_verify_and_carry_a_fresh_nonce(): void {
		$query   = array( 'page' => '3' );
		$headers = Signature::headers( 'tienda-a', self::SECRET, 'GET', '/wss/v1/products', $query, '' );
		$again   = Signature::headers( 'tienda-a', self::SECRET, 'GET', '/wss/v1/products', $query, '' );

		$this->assertSame( 'tienda-a', $headers['X-WSS-Store'] );
		$this->assertNotSame( $headers['X-WSS-Nonce'], $again['X-WSS-Nonce'] );
		$this->assertTrue(
			Signature::verify(
				self::SECRET,
				'GET',
				'/wss/v1/products',
				$query,
				(int) $headers['X-WSS-Timestamp'],
				$headers['X-WSS-Nonce'],
				'',
				$headers['X-WSS-Signature'],
				time()
			)
		);
	}

	/**
	 * Un HMAC con clave vacía lo calcula cualquiera: si el receptor no encuentra el secreto
	 * de una tienda y le queda '', el request no puede pasar por firmado.
	 *
	 * SIG-11
	 */
	public function test_an_empty_secret_never_verifies(): void {
		$signature = Signature::sign( '', 'POST', '/wss/v1/movements', array(), self::NOW, '7f3c1b9e4a2d', '' );

		$this->assertFalse(
			Signature::verify( '', 'POST', '/wss/v1/movements', array(), self::NOW, '7f3c1b9e4a2d', '', $signature, self::NOW )
		);
	}
}
