/**
 * Las pantallas por #/ruta (ADR-013 §1).
 */
import { screenFromHash, NAV, HIDDEN } from '../routing';

describe( 'screenFromHash', () => {
	it( 'elige la pantalla de la ruta, también con parámetros', () => {
		expect( screenFromHash( '#/products' ) ).toBe( 'products' );
		expect( screenFromHash( '#/logs?kind=pair_broken' ) ).toBe( 'logs' );
		expect( screenFromHash( '#/setup' ) ).toBe( 'setup' );
	} );

	it( 'vuelve al resumen con una ruta que no existe o sin ruta', () => {
		expect( screenFromHash( '#/nada' ) ).toBe( 'dashboard' );
		expect( screenFromHash( '' ) ).toBe( 'dashboard' );
	} );

	it( 'el manual es una ruta más', () => {
		expect( screenFromHash( '#/manual' ) ).toBe( 'manual' );
		expect( screenFromHash( '#/manual?s=4-vincular-los-productos' ) ).toBe(
			'manual'
		);
	} );

	it( 'la sidebar tiene las cinco pantallas en orden, y la configuración inicial no', () => {
		expect( NAV ).toEqual( [
			'dashboard',
			'products',
			'logs',
			'manual',
			'settings',
		] );
		expect( HIDDEN ).toEqual( [ 'setup' ] );
	} );
} );
