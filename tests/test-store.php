<?php
/**
 * Tests de la identidad de tienda (ADR-002) y de la detección de clon (ADR-001 §1).
 *
 * @package Woonderful\StockSync
 */

declare( strict_types = 1 );

use Woonderful\StockSync\Identity\Store;

/**
 * El identificador se genera una vez y no cambia; la huella detecta el sitio clonado.
 */
class Test_Store extends WP_UnitTestCase {

	/**
	 * La URL del sitio, que varios tests cambian para simular un restore.
	 *
	 * @var string
	 */
	private string $original_home = '';

	public function set_up(): void {
		parent::set_up();

		$this->original_home = (string) get_option( 'home' );
	}

	public function tear_down(): void {
		unset( $_SERVER['HTTPS'] );
		update_option( 'home', $this->original_home );
		delete_option( Store::OPTION_ID );
		delete_option( Store::OPTION_LABEL );
		delete_option( Store::OPTION_FINGERPRINT );

		parent::tear_down();
	}

	/**
	 * ID-01 — La activación genera un identificador de 128 bits.
	 */
	public function test_it_generates_a_128_bit_identifier(): void {
		delete_option( Store::OPTION_ID );

		Store::ensure_identity();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', Store::id() );
	}

	/**
	 * ID-02 — El identificador no se regenera si ya existe.
	 */
	public function test_it_does_not_regenerate_an_existing_identifier(): void {
		delete_option( Store::OPTION_ID );

		Store::ensure_identity();
		$first = Store::id();

		Store::ensure_identity();

		$this->assertSame( $first, Store::id() );
	}

	/**
	 * ID-03 — Dos instalaciones no sacan el mismo identificador.
	 */
	public function test_two_installs_get_different_identifiers(): void {
		delete_option( Store::OPTION_ID );
		Store::ensure_identity();
		$first_id = Store::id();

		delete_option( Store::OPTION_ID );
		Store::ensure_identity();

		$this->assertNotSame( $first_id, Store::id() );
	}

	/**
	 * ID-04 — La etiqueta cae en el identificador si está vacía.
	 */
	public function test_the_label_falls_back_to_the_identifier_when_empty(): void {
		delete_option( Store::OPTION_ID );
		Store::ensure_identity();

		update_option( Store::OPTION_LABEL, '' );

		$this->assertSame( Store::id(), Store::label() );
	}

	/**
	 * La huella tiene que seguir a la URL del sitio: es lo único que un restore no puede
	 * reescribir, y de eso depende toda la detección de clon.
	 *
	 * ID-05
	 */
	public function test_the_fingerprint_follows_the_site_url(): void {
		$before = Store::fingerprint();

		update_option( 'home', 'https://tienda-restaurada.test' );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', Store::fingerprint() );
		$this->assertNotSame( $before, Store::fingerprint() );
		$this->assertSame( hash( 'sha256', 'https://tienda-restaurada.test' ), Store::fingerprint() );
	}

	/**
	 * ID-06 — Sin huella guardada no hay clon: todavía no se emparejó.
	 */
	public function test_no_stored_fingerprint_means_no_clone(): void {
		delete_option( Store::OPTION_FINGERPRINT );

		$this->assertFalse( Store::is_cloned_site() );
	}

	/**
	 * ID-07 — El sitio donde se emparejó no es un clon.
	 */
	public function test_the_site_where_it_was_paired_is_not_a_clone(): void {
		update_option( Store::OPTION_FINGERPRINT, Store::fingerprint() );

		$this->assertFalse( Store::is_cloned_site() );
	}

	/**
	 * El caso real: se emparejó en una URL y la base se restauró en otra. La huella
	 * guardada sigue siendo la vieja y la actual ya no coincide.
	 *
	 * ID-08
	 */
	public function test_a_site_restored_on_another_url_is_a_clone(): void {
		update_option( Store::OPTION_FINGERPRINT, Store::fingerprint() );

		update_option( 'home', 'https://tienda-restaurada.test' );

		$this->assertTrue( Store::is_cloned_site() );
	}

	/**
	 * ID-09 — Un plugin que filtra home_url no mueve la huella (ADR-007).
	 */
	public function test_a_home_url_filter_does_not_change_the_fingerprint(): void {
		update_option( 'home', 'https://tienda-a.test' );

		add_filter(
			'home_url',
			static function (): string {
				return 'http://tienda-a.test';
			}
		);

		$this->assertSame( hash( 'sha256', 'https://tienda-a.test' ), Store::fingerprint() );
	}

	/**
	 * ID-10 — Un request por HTTPS no mueve la huella (ADR-007).
	 */
	public function test_an_https_request_does_not_change_the_fingerprint(): void {
		update_option( 'home', 'http://tienda-a.test' );
		$_SERVER['HTTPS'] = 'on';

		$this->assertSame( hash( 'sha256', 'http://tienda-a.test' ), Store::fingerprint() );
	}

	/**
	 * El clon por /etc/hosts: mismo dominio, servido por http porque la copia local no
	 * tiene certificado (ADR-007).
	 *
	 * ID-11
	 */
	public function test_the_same_host_over_http_is_a_clone(): void {
		update_option( 'home', 'https://tienda-a.test' );
		update_option( Store::OPTION_FINGERPRINT, Store::fingerprint() );

		update_option( 'home', 'http://tienda-a.test' );

		$this->assertTrue( Store::is_cloned_site() );
	}

	/**
	 * ID-12 — La instalación deja como etiqueta el nombre del sitio.
	 */
	public function test_install_labels_the_store_with_the_site_name(): void {
		delete_option( Store::OPTION_ID );
		delete_option( Store::OPTION_LABEL );
		update_option( 'blogname', 'Minorista' );

		Store::ensure_identity();

		$this->assertSame( 'Minorista', Store::label() );
	}
}
