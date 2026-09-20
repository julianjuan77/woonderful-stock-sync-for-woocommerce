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

	public function test_genera_un_identificador_de_128_bits(): void {
		delete_option( Store::OPTION_ID );

		Store::ensure_identity();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', Store::id() );
	}

	public function test_no_lo_regenera_si_ya_existe(): void {
		delete_option( Store::OPTION_ID );

		Store::ensure_identity();
		$primero = Store::id();

		Store::ensure_identity();

		$this->assertSame( $primero, Store::id() );
	}

	public function test_dos_tiendas_no_sacan_el_mismo_identificador(): void {
		delete_option( Store::OPTION_ID );
		Store::ensure_identity();
		$una = Store::id();

		delete_option( Store::OPTION_ID );
		Store::ensure_identity();

		$this->assertNotSame( $una, Store::id() );
	}

	public function test_la_etiqueta_cae_en_el_identificador_si_esta_vacia(): void {
		delete_option( Store::OPTION_ID );
		Store::ensure_identity();

		update_option( Store::OPTION_LABEL, '' );

		$this->assertSame( Store::id(), Store::label() );
	}

	/**
	 * Se guarda el hash y no la URL porque un search-replace de restore reescribiría la
	 * URL en claro y el clon se daría por sano.
	 */
	public function test_la_huella_es_un_sha256_y_no_contiene_la_url(): void {
		$huella = Store::fingerprint();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $huella );
		$this->assertStringNotContainsString( wp_parse_url( home_url(), PHP_URL_HOST ), $huella );
	}

	public function test_sin_huella_guardada_no_hay_clon(): void {
		delete_option( Store::OPTION_FINGERPRINT );

		$this->assertFalse( Store::is_cloned_site() );
	}

	public function test_la_misma_url_no_es_un_clon(): void {
		update_option( Store::OPTION_FINGERPRINT, Store::fingerprint() );

		$this->assertFalse( Store::is_cloned_site() );
	}

	public function test_otra_url_es_un_clon(): void {
		update_option( Store::OPTION_FINGERPRINT, hash( 'sha256', 'https://otra-tienda.com' ) );

		$this->assertTrue( Store::is_cloned_site() );
	}
}
