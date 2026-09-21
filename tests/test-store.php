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
	private string $home_original = '';

	public function set_up(): void {
		parent::set_up();

		$this->home_original = (string) get_option( 'home' );
	}

	public function tear_down(): void {
		update_option( 'home', $this->home_original );
		delete_option( Store::OPTION_ID );
		delete_option( Store::OPTION_LABEL );
		delete_option( Store::OPTION_FINGERPRINT );

		parent::tear_down();
	}

	/**
	 * ID-01 — La activación genera un identificador de 128 bits.
	 */
	public function test_genera_un_identificador_de_128_bits(): void {
		delete_option( Store::OPTION_ID );

		Store::ensure_identity();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', Store::id() );
	}

	/**
	 * ID-02 — El identificador no se regenera si ya existe.
	 */
	public function test_no_lo_regenera_si_ya_existe(): void {
		delete_option( Store::OPTION_ID );

		Store::ensure_identity();
		$primero = Store::id();

		Store::ensure_identity();

		$this->assertSame( $primero, Store::id() );
	}

	/**
	 * ID-03 — Dos instalaciones no sacan el mismo identificador.
	 */
	public function test_dos_tiendas_no_sacan_el_mismo_identificador(): void {
		delete_option( Store::OPTION_ID );
		Store::ensure_identity();
		$una = Store::id();

		delete_option( Store::OPTION_ID );
		Store::ensure_identity();

		$this->assertNotSame( $una, Store::id() );
	}

	/**
	 * ID-04 — La etiqueta cae en el identificador si está vacía.
	 */
	public function test_la_etiqueta_cae_en_el_identificador_si_esta_vacia(): void {
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
	public function test_la_huella_sigue_a_la_url_del_sitio(): void {
		$antes = Store::fingerprint();

		update_option( 'home', 'https://tienda-restaurada.test' );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', Store::fingerprint() );
		$this->assertNotSame( $antes, Store::fingerprint() );
		$this->assertSame( hash( 'sha256', 'https://tienda-restaurada.test' ), Store::fingerprint() );
	}

	/**
	 * ID-06 — Sin huella guardada no hay clon: todavía no se emparejó.
	 */
	public function test_sin_huella_guardada_no_hay_clon(): void {
		delete_option( Store::OPTION_FINGERPRINT );

		$this->assertFalse( Store::is_cloned_site() );
	}

	/**
	 * ID-07 — El sitio donde se emparejó no es un clon.
	 */
	public function test_el_sitio_donde_se_emparejo_no_es_un_clon(): void {
		update_option( Store::OPTION_FINGERPRINT, Store::fingerprint() );

		$this->assertFalse( Store::is_cloned_site() );
	}

	/**
	 * El caso real: se emparejó en una URL y la base se restauró en otra. La huella
	 * guardada sigue siendo la vieja y la actual ya no coincide.
	 *
	 * ID-08
	 */
	public function test_el_sitio_restaurado_en_otra_url_es_un_clon(): void {
		update_option( Store::OPTION_FINGERPRINT, Store::fingerprint() );

		update_option( 'home', 'https://tienda-restaurada.test' );

		$this->assertTrue( Store::is_cloned_site() );
	}
}
