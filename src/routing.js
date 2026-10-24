/**
 * Las pantallas por #/ruta, como en woonderful-sync (ADR-013 §1).
 */

// Las de la sidebar, en orden.
export const NAV = [ 'dashboard', 'products', 'logs', 'settings', 'manual' ];

// Las que existen pero no están en la sidebar: la configuración inicial se abre sola.
export const HIDDEN = [ 'setup' ];

export const screenFromHash = ( hash ) => {
	const key = hash.replace( '#/', '' ).split( '?' )[ 0 ];
	return NAV.includes( key ) || HIDDEN.includes( key ) ? key : 'dashboard';
};
