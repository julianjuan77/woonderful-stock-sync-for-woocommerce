import { useState, useEffect, useRef, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { MANUAL_HTML, MANUAL_TOC } from '../manual-content';

/**
 * El manual, con su índice al costado.
 *
 * El texto se escribe en `docs/MANUAL.md` y lo convierte `npm run manual`: acá no hay
 * contenido, sólo cómo se lee.
 */
const Manual = () => {
	const [ active, setActive ] = useState(
		MANUAL_TOC.find( ( { level } ) => 2 === level )?.id
	);
	const body = useRef( null );

	// Las capturas se guardan en assets/manual/ y su URL depende de dónde esté instalado el
	// plugin, así que el HTML generado trae un marcador que se resuelve acá.
	const html = useMemo(
		() =>
			MANUAL_HTML.split( '%%WSS_MANUAL_URL%%' ).join(
				window.wssAdmin?.manualUrl ?? ''
			),
		[]
	);

	// El índice sigue a la lectura: se marca la última sección que pasó por arriba.
	useEffect( () => {
		const headings = MANUAL_TOC.filter( ( { level } ) => 2 === level )
			.map( ( { id } ) => document.getElementById( id ) )
			.filter( Boolean );

		if ( ! headings.length ) {
			return;
		}

		const onScroll = () => {
			// 120 px de margen: la sección se marca cuando su título llega arriba de todo,
			// no cuando se va de la pantalla.
			const current = headings
				.filter( ( h ) => h.getBoundingClientRect().top <= 120 )
				.pop();
			setActive( ( current ?? headings[ 0 ] ).id );
		};

		onScroll();
		window.addEventListener( 'scroll', onScroll, { passive: true } );
		return () => window.removeEventListener( 'scroll', onScroll );
	}, [] );

	// Al abrir con #/manual?s=<sección>, se baja sola. Va en un cuadro después del montaje:
	// el navegador restaura el scroll de la visita anterior y si se baja antes, lo pisa.
	useEffect( () => {
		const section = new URLSearchParams(
			window.location.hash.split( '?' )[ 1 ] || ''
		).get( 's' );
		if ( ! section ) {
			return;
		}

		const frame = window.requestAnimationFrame( () => {
			window.requestAnimationFrame( () => {
				document
					.getElementById( section )
					?.scrollIntoView( { block: 'start' } );
			} );
		} );

		return () => window.cancelAnimationFrame( frame );
	}, [] );

	const goTo = ( id ) => {
		document.getElementById( id )?.scrollIntoView( {
			behavior: 'smooth',
			block: 'start',
		} );
		setActive( id );
	};

	return (
		<div className="wss-manual">
			<header className="wss-header">
				<div>
					<h1 className="wss-header__title">
						{ __(
							'Manual',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</h1>
					<p className="wss-header__subtitle">
						{ __(
							'Cómo funciona el plugin, con ejemplos. Para leer de corrido la primera vez y después buscar lo puntual.',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</p>
				</div>
			</header>

			<div className="wss-manual__layout">
				<nav
					className="wss-manual__toc"
					aria-label={ __(
						'Secciones del manual',
						'woonderful-stock-sync-for-woocommerce'
					) }
				>
					<ol>
						{ MANUAL_TOC.map( ( { id, level, text } ) => (
							<li key={ id }>
								{ 1 === level ? (
									<span className="wss-manual__toc-part">
										{ text }
									</span>
								) : (
									<button
										className={ `wss-manual__toc-item ${
											active === id ? 'is-active' : ''
										}` }
										onClick={ () => goTo( id ) }
									>
										{ text }
									</button>
								) }
							</li>
						) ) }
					</ol>
				</nav>

				{ /* Generado al compilar desde docs/MANUAL.md: no entra nada de afuera. */ }
				<article
					className="wss-manual__body"
					ref={ body }
					// eslint-disable-next-line react/no-danger
					dangerouslySetInnerHTML={ { __html: html } }
				/>
			</div>
		</div>
	);
};

export default Manual;
