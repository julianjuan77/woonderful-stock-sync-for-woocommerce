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

	public function tear_down(): void {
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
	 * Se guarda el hash y no la URL porque un search-replace de restore reescribiría la
	 * URL en claro y el clon se daría por sano.
	 *
	 * ID-05
	 */
	public function test_la_huella_es_un_sha256_y_no_contiene_la_url(): void {
		$huella = Store::fingerprint();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $huella );
		$this->assertStringNotContainsString( wp_parse_url( home_url(), PHP_URL_HOST ), $huella );
	}

	/**
	 * ID-06 — Sin huella guardada no hay clon: todavía no se emparejó.
	 */
	public function test_sin_huella_guardada_no_hay_clon(): void {
		delete_option( Store::OPTION_FINGERPRINT );

		$this->assertFalse( Store::is_cloned_site() );
	}

	/**
	 * ID-07 — La misma URL no es un clon.
	 */
	public function test_la_misma_url_no_es_un_clon(): void {
		update_option( Store::OPTION_FINGERPRINT, Store::fingerprint() );

		$this->assertFalse( Store::is_cloned_site() );
	}

	/**
	 * ID-08 — Otra URL es un clon y suspende la sincronización.
	 */
	public function test_otra_url_es_un_clon(): void {
		update_option( Store::OPTION_FINGERPRINT, hash( 'sha256', 'https://otra-tienda.com' ) );

		$this->assertTrue( Store::is_cloned_site() );
	}
}
