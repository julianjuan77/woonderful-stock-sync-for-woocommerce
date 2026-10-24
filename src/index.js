import { createRoot, useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import Dashboard from './screens/Dashboard';
import Products from './screens/Products';
import Logs from './screens/Logs';
import Settings from './screens/Settings';
import Setup from './screens/Setup';
import Manual from './screens/Manual';
import { NAV, screenFromHash } from './routing';
import './style.scss';

apiFetch.use( apiFetch.createNonceMiddleware( window.wssAdmin?.nonce ) );

const NS = '/wss-admin/v1';

// Las rutas de la app van al namespace del admin, separado del que usan las tiendas entre
// sí (ADR-013 §4).
const wssFetch = ( options ) => {
	if ( typeof options.path === 'string' && ! options.url ) {
		return apiFetch( { ...options, path: `${ NS }${ options.path }` } );
	}
	return apiFetch( options );
};

const SCREENS = {
	dashboard: {
		component: Dashboard,
		label: __( 'Resumen', 'woonderful-stock-sync-for-woocommerce' ),
		icon: IconDashboard,
	},
	products: {
		component: Products,
		label: __( 'Productos', 'woonderful-stock-sync-for-woocommerce' ),
		icon: IconProducts,
	},
	logs: {
		component: Logs,
		label: __( 'Registros', 'woonderful-stock-sync-for-woocommerce' ),
		icon: IconLogs,
	},
	settings: {
		component: Settings,
		label: __( 'Configuración', 'woonderful-stock-sync-for-woocommerce' ),
		icon: IconSettings,
	},
	manual: {
		component: Manual,
		label: __( 'Manual', 'woonderful-stock-sync-for-woocommerce' ),
		icon: IconManual,
	},
	setup: {
		component: Setup,
		label: __(
			'Configuración inicial',
			'woonderful-stock-sync-for-woocommerce'
		),
		icon: IconSettings,
	},
};

const App = () => {
	const [ currentScreen, setCurrentScreen ] = useState( () =>
		screenFromHash( window.location.hash )
	);
	const [ status, setStatus ] = useState( null );

	useEffect( () => {
		const onHashChange = () =>
			setCurrentScreen( screenFromHash( window.location.hash ) );
		window.addEventListener( 'hashchange', onHashChange );
		return () => window.removeEventListener( 'hashchange', onHashChange );
	}, [] );

	// El submenú de WordPress marca la página, no la ruta: sin esto, siempre resalta Resumen.
	useEffect( () => {
		document
			.querySelectorAll(
				'#toplevel_page_woonderful-stock-sync .wp-submenu a'
			)
			.forEach( ( link ) => {
				const route = link.getAttribute( 'href' ).split( '#/' )[ 1 ];
				const active = ( route || 'dashboard' ) === currentScreen;
				link.classList.toggle( 'current', active );
				link.parentElement.classList.toggle( 'current', active );
			} );
	}, [ currentScreen ] );

	const navigate = ( screen, params ) => {
		const query = params
			? `?${ new URLSearchParams( params ).toString() }`
			: '';
		window.location.hash = `/${ screen }${ query }`;
		setCurrentScreen( screen );
	};

	useEffect( () => {
		wssFetch( { path: '/status' } )
			.then( ( st ) => {
				setStatus( st );
				// La configuración inicial, mientras la tienda no esté emparejada (ADR-013 §3).
				if (
					! st.paired &&
					'dashboard' === currentScreen &&
					! window.sessionStorage.getItem( 'wss_setup_skipped' )
				) {
					navigate( 'setup' );
				}
			} )
			.catch( () => {} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const Screen = SCREENS[ currentScreen ]?.component || Dashboard;
	// Resumen lo dice en su cabecera: el aviso, en las demás.
	const peerDown =
		status && 'ok' !== status.peer?.state && 'dashboard' !== currentScreen;

	return (
		<div className="woon-root">
			<aside className="woon-sidebar">
				<div className="woon-sidebar__logo">
					<WoonderfulLogo />
					<span className="woon-sidebar__badge">Stock</span>
				</div>

				<nav className="woon-sidebar__nav">
					{ NAV.map( ( key ) => ( { key, ...SCREENS[ key ] } ) ).map(
						( { key, label, icon: Icon } ) => (
							<button
								key={ key }
								className={ `woon-nav-item ${
									currentScreen === key
										? 'woon-nav-item--active'
										: ''
								}` }
								onClick={ () => navigate( key ) }
							>
								<Icon className="woon-nav-item__icon" />
								{ label }
							</button>
						)
					) }
				</nav>

				{ status?.paired && (
					<div className="wss-side-status">
						<span
							className={ `wss-side-status__dot ${ sideState(
								status
							) }` }
							aria-hidden="true"
						/>
						<div>
							<div className="wss-side-status__title">
								{ status.paused &&
									sprintf(
										/* translators: %s: la otra tienda. */
										__(
											'En pausa con %s',
											'woonderful-stock-sync-for-woocommerce'
										),
										status.peer.label
									) }
								{ ! status.paused && 'ok' === status.peer.state
									? sprintf(
											/* translators: %s: la otra tienda. */
											__(
												'Conectada con %s',
												'woonderful-stock-sync-for-woocommerce'
											),
											status.peer.label
									  )
									: ! status.paused &&
									  sprintf(
											/* translators: %s: la otra tienda. */
											__(
												'%s no responde',
												'woonderful-stock-sync-for-woocommerce'
											),
											status.peer.label
									  ) }
							</div>
							{ ! status.paused && status.peer.since && (
								<div className="wss-side-status__sub">
									{ sprintf(
										/* translators: %s: desde cuándo. */
										__(
											'Desde %s',
											'woonderful-stock-sync-for-woocommerce'
										),
										status.peer.since
									) }
								</div>
							) }
						</div>
					</div>
				) }
			</aside>

			<main className="woon-main">
				{ peerDown && (
					<div className="woon-notice woon-notice--warning">
						{ 'rejected' === status.peer.state
							? sprintf(
									/* translators: %s: desde cuándo. */
									__(
										'La otra tienda rechaza los pedidos de ésta desde %s. Esperar no lo arregla: revisá el emparejamiento en Configuración.',
										'woonderful-stock-sync-for-woocommerce'
									),
									status.peer.since
							  )
							: sprintf(
									/* translators: %s: desde cuándo. */
									__(
										'La otra tienda no responde desde %s. Los stocks pueden verse descuadrados: la cola y la reconciliación lo reparan solas cuando vuelva.',
										'woonderful-stock-sync-for-woocommerce'
									),
									status.peer.since
							  ) }
					</div>
				) }
				<div className="woon-page">
					<Screen
						apiFetch={ wssFetch }
						navigate={ navigate }
						status={ status }
						onStatus={ setStatus }
					/>
				</div>
			</main>
		</div>
	);
};

const container = document.getElementById( 'wss-admin-app' );
if ( container ) {
	createRoot( container ).render( <App /> );
}

/* ── Logo e íconos, los de woonderful-sync ──────────────────────────── */

// El logo como en el rediseño: tipográfico, con las "oo" en lila y la estrella sobre ellas.
function WoonderfulLogo() {
	return (
		<span className="wss-logo" role="img" aria-label="Woonderful">
			W
			<span className="wss-logo__oo">
				oo
				<svg viewBox="0 0 32 32" aria-hidden="true">
					<path
						d="M16 0 L19 13 L32 16 L19 19 L16 32 L13 19 L0 16 L13 13 Z"
						fill="#FFD66B"
					/>
				</svg>
			</span>
			nderful
		</span>
	);
}

function IconDashboard( { className } ) {
	return (
		<svg
			className={ className }
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="2"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<rect x="3" y="3" width="7" height="7" />
			<rect x="14" y="3" width="7" height="7" />
			<rect x="14" y="14" width="7" height="7" />
			<rect x="3" y="14" width="7" height="7" />
		</svg>
	);
}

function IconProducts( { className } ) {
	return (
		<svg
			className={ className }
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="2"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
			<polyline points="3.27 6.96 12 12.01 20.73 6.96" />
			<line x1="12" y1="22.08" x2="12" y2="12" />
		</svg>
	);
}

function IconLogs( { className } ) {
	return (
		<svg
			className={ className }
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="2"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
			<polyline points="14 2 14 8 20 8" />
			<line x1="16" y1="13" x2="8" y2="13" />
			<line x1="16" y1="17" x2="8" y2="17" />
			<polyline points="10 9 9 9 8 9" />
		</svg>
	);
}

function IconSettings( { className } ) {
	return (
		<svg
			className={ className }
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="2"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<circle cx="12" cy="12" r="3" />
			<path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
		</svg>
	);
}

function IconManual( { className } ) {
	return (
		<svg
			className={ className }
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="2"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
			<path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" />
			<line x1="8" y1="7" x2="16" y2="7" />
			<line x1="8" y1="11" x2="13" y2="11" />
		</svg>
	);
}

// El punto de la barra lateral: verde conectada, amarillo en pausa, rojo sin respuesta.
function sideState( status ) {
	if ( status.paused ) {
		return 'is-paused';
	}
	return 'ok' === status.peer.state ? 'is-ok' : 'is-down';
}
