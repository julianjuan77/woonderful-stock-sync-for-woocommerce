import { useState, useEffect, useRef } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';

export default function Dashboard( { apiFetch, navigate, status, onStatus } ) {
	const [ busy, setBusy ] = useState( null );
	const [ toast, setToast ] = useState( null );
	const toastTimer = useRef();

	useEffect( () => () => clearTimeout( toastTimer.current ), [] );

	if ( ! status ) {
		return (
			<div className="woon-loading">
				{ __( 'Cargando…', 'woonderful-stock-sync-for-woocommerce' ) }
			</div>
		);
	}

	if ( ! status.paired ) {
		return (
			<div className="woon-notice woon-notice--warning">
				<div>
					<p>
						{ __(
							'Esta tienda todavía no está emparejada con la otra.',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</p>
					<button
						className="woon-btn woon-btn--primary"
						style={ { marginTop: '12px' } }
						onClick={ () => navigate( 'setup' ) }
					>
						{ __(
							'Configurar',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</button>
				</div>
			</div>
		);
	}

	const say = ( text ) => {
		setToast( text );
		clearTimeout( toastTimer.current );
		toastTimer.current = setTimeout( () => setToast( null ), 3500 );
	};

	// Cada acción devuelve el estado nuevo: la pantalla muestra lo que dice el servidor.
	const run = async ( key, options, done ) => {
		setBusy( key );
		try {
			const next = await apiFetch( { method: 'POST', ...options } );
			onStatus( next );
			say( done( next ) );
		} catch ( e ) {
			say(
				e?.message ||
					__(
						'Error al conectar con la API.',
						'woonderful-stock-sync-for-woocommerce'
					)
			);
		} finally {
			setBusy( null );
		}
	};

	const reconcile = () =>
		run(
			'reconcile',
			{ path: '/reconcile' },
			( next ) => next.reconciliation?.text
		);

	const resolve = ( product, action ) =>
		run(
			`${ action }-${ product }`,
			{
				path: `/discrepancies/${ product }/${ action }`,
			},
			() =>
				'accept' === action
					? __(
							'Anotado como ajuste. La otra tienda lo recibe en la próxima pasada.',
							'woonderful-stock-sync-for-woocommerce'
					  )
					: __(
							'El stock volvió a lo que dice el libro.',
							'woonderful-stock-sync-for-woocommerce'
					  )
		);

	const master = status.store.master;
	const suspended = status.store.suspended;
	const off = null !== suspended || 'ok' !== status.peer.state;
	const own = status.discrepancies.own;
	const peer = status.discrepancies.peer;
	const open = own.length + ( peer?.length ?? 0 );

	return (
		<div className="wss-summary">
			<header className="wss-header">
				<div>
					<h1 className="wss-header__title">
						{ __(
							'Resumen',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</h1>
					<p className="wss-header__subtitle">
						{ __(
							'Estado de la sincronización entre tus dos tiendas.',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</p>
				</div>
				{ master && (
					<div className="wss-header__actions">
						<button
							className="woon-btn woon-btn--primary"
							onClick={ reconcile }
							disabled={ null !== busy || off }
						>
							{ 'reconcile' === busy
								? __(
										'Reconciliando…',
										'woonderful-stock-sync-for-woocommerce'
								  )
								: __(
										'Reconciliar ahora',
										'woonderful-stock-sync-for-woocommerce'
								  ) }
						</button>
					</div>
				) }
			</header>

			<Hero
				status={ status }
				running={ 'reconcile' === busy }
				open={ open }
				navigate={ navigate }
			/>

			<div className="wss-summary__grid">
				<Reconciliation status={ status } />
				<Linking status={ status } navigate={ navigate } />
			</div>

			<section className="woon-card wss-card">
				<header className="wss-card__head">
					<h3>
						{ __(
							'Discrepancias',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</h3>
					{ open > 0 && (
						<span className="woon-badge woon-badge--warning">
							{ sprintf(
								/* translators: %d: cuántas. */
								_n(
									'%d abierta',
									'%d abiertas',
									open,
									'woonderful-stock-sync-for-woocommerce'
								),
								open
							) }
						</span>
					) }
				</header>
				<p className="wss-card__lead woon-muted">
					{ __(
						'Productos cuyo stock no coincide con lo que dice el libro de movimientos.',
						'woonderful-stock-sync-for-woocommerce'
					) }
				</p>
				<div className="wss-disc-grid">
					<DiscrepancyList
						store={ status.store.label }
						rows={ own }
						empty={ __(
							'No hay discrepancias en esta tienda.',
							'woonderful-stock-sync-for-woocommerce'
						) }
						actions={ ( row ) => (
							<ResolveMenu
								disabled={ null !== busy }
								onResolve={ ( action ) =>
									resolve( row.product, action )
								}
							/>
						) }
					/>
					{ master && (
						<DiscrepancyList
							store={ status.peer.label }
							rows={ peer ?? [] }
							empty={
								null === peer
									? __(
											'No se sabe: la última reconciliación no llegó a preguntarlas.',
											'woonderful-stock-sync-for-woocommerce'
									  )
									: __(
											'No hay discrepancias en la otra tienda.',
											'woonderful-stock-sync-for-woocommerce'
									  )
							}
							unknown={ null === peer }
							note={ sprintf(
								/* translators: %s: la otra tienda. */
								__(
									'Se resuelven desde %s.',
									'woonderful-stock-sync-for-woocommerce'
								),
								status.peer.label
							) }
						/>
					) }
				</div>
			</section>

			<div
				className={ `wss-toast ${ toast ? 'is-on' : '' }` }
				aria-live="polite"
			>
				{ toast }
			</div>
		</div>
	);
}

// Lo primero que se lee: si hay algo que hacer, y qué.
function Hero( { status, running, open, navigate } ) {
	const peer = status.peer.label;
	const off = null !== status.store.suspended || 'ok' !== status.peer.state;
	let tone = 'ok';
	let icon = <IconOk />;
	let title = __(
		'Todo sincronizado',
		'woonderful-stock-sync-for-woocommerce'
	);
	let text =
		status.linking.unlinked > 0
			? sprintf(
					/* translators: %d: cuántos productos. */
					_n(
						'Los productos vinculados tienen el mismo stock en las dos tiendas. %d sin vincular no se sincroniza.',
						'Los productos vinculados tienen el mismo stock en las dos tiendas. Los %d sin vincular no se sincronizan.',
						status.linking.unlinked,
						'woonderful-stock-sync-for-woocommerce'
					),
					status.linking.unlinked
			  )
			: __(
					'Las dos tiendas coinciden con el libro de movimientos.',
					'woonderful-stock-sync-for-woocommerce'
			  );
	let action = null;
	let detail = null;

	if ( running ) {
		tone = 'run';
		icon = (
			<span className="wss-spin">
				<IconOk />
			</span>
		);
		title = __( 'Reconciliando…', 'woonderful-stock-sync-for-woocommerce' );
		text = __(
			'Comparamos los movimientos de las dos tiendas y volvemos a mandar lo que le falte a cada una.',
			'woonderful-stock-sync-for-woocommerce'
		);
	} else if ( null !== status.store.suspended ) {
		tone = 'off';
		icon = <IconOff />;
		title = __(
			'La sincronización está suspendida',
			'woonderful-stock-sync-for-woocommerce'
		);
		text =
			'cloned_site' === status.store.suspended
				? __(
						'Este sitio no es aquel donde se emparejó, así que parece una copia. Volvé a emparejarlo para reanudar.',
						'woonderful-stock-sync-for-woocommerce'
				  )
				: __(
						'Faltan tablas del plugin o están dañadas. Revisá los requisitos.',
						'woonderful-stock-sync-for-woocommerce'
				  );
		action = __(
			'Ir a Configuración',
			'woonderful-stock-sync-for-woocommerce'
		);
	} else if ( 'rejected' === status.peer.state ) {
		tone = 'off';
		icon = <IconOff />;
		title = sprintf(
			/* translators: %s: la otra tienda. */
			__(
				'%s rechaza los pedidos de esta tienda',
				'woonderful-stock-sync-for-woocommerce'
			),
			peer
		);
		text = sprintf(
			/* translators: %s: desde cuándo. */
			__(
				'Desde %s. Esperar no lo arregla: revisá el emparejamiento. Los cambios quedan en la cola mientras tanto.',
				'woonderful-stock-sync-for-woocommerce'
			),
			status.peer.since
		);
		action = __(
			'Revisar emparejamiento',
			'woonderful-stock-sync-for-woocommerce'
		);
		detail = status.peer.error;
	} else if ( off ) {
		tone = 'off';
		icon = <IconOff />;
		title = sprintf(
			/* translators: %s: la otra tienda. */
			__(
				'Sin conexión con %s',
				'woonderful-stock-sync-for-woocommerce'
			),
			peer
		);
		text = sprintf(
			/* translators: %s: desde cuándo. */
			__(
				'Desde %s. Los cambios quedan en la cola y se mandan solos cuando vuelva: no hace falta hacer nada.',
				'woonderful-stock-sync-for-woocommerce'
			),
			status.peer.since
		);
		detail = status.peer.error;
	} else if ( open > 0 ) {
		tone = 'warn';
		icon = <IconWarn />;
		title = sprintf(
			/* translators: %d: cuántos productos. */
			_n(
				'%d producto con stock distinto al del libro',
				'%d productos con stock distinto al del libro',
				open,
				'woonderful-stock-sync-for-woocommerce'
			),
			open
		);
		text = __(
			'Revisalos abajo. El resto del catálogo se sigue sincronizando.',
			'woonderful-stock-sync-for-woocommerce'
		);
	}

	return (
		<section className={ `wss-hero wss-hero--${ tone }` }>
			<div className="wss-hero__main">
				<span className="wss-hero__icon">{ icon }</span>
				<div>
					<h2 className="wss-hero__title">{ title }</h2>
					<p className="wss-hero__text">{ text }</p>
					{ detail && <p className="wss-hero__detail">{ detail }</p> }
					{ action && (
						<button
							className="woon-btn woon-btn--ghost woon-btn--sm wss-hero__cta"
							onClick={ () => navigate( 'settings' ) }
						>
							{ action }
						</button>
					) }
				</div>
			</div>
			<div className="wss-pair">
				<div className="wss-pair__store">
					<span className="wss-pair__name">
						{ status.store.label }
					</span>
					<span className="wss-pair__role">
						{ status.store.master
							? __(
									'Maestra · esta tienda',
									'woonderful-stock-sync-for-woocommerce'
							  )
							: __(
									'Secundaria · esta tienda',
									'woonderful-stock-sync-for-woocommerce'
							  ) }
					</span>
				</div>
				<div className={ `wss-pair__line ${ off ? 'is-off' : '' }` }>
					<span className="wss-pair__rule" />
					<span className="wss-pair__label">
						{ off
							? __(
									'Cortada',
									'woonderful-stock-sync-for-woocommerce'
							  )
							: __(
									'Stock ↔',
									'woonderful-stock-sync-for-woocommerce'
							  ) }
					</span>
				</div>
				<div className="wss-pair__store">
					<span className="wss-pair__name">{ peer }</span>
					<span className="wss-pair__role">
						{ status.store.master
							? __(
									'Secundaria',
									'woonderful-stock-sync-for-woocommerce'
							  )
							: __(
									'Maestra',
									'woonderful-stock-sync-for-woocommerce'
							  ) }
					</span>
				</div>
			</div>
			{ running && <div className="wss-hero__progress" /> }
		</section>
	);
}

function Reconciliation( { status } ) {
	const last = status.reconciliation;
	const queued = last ? last.reopened + last.peer_reopened : 0;

	return (
		<section className="woon-card wss-card">
			<header className="wss-card__head">
				<h3>
					{ __(
						'Última reconciliación',
						'woonderful-stock-sync-for-woocommerce'
					) }
				</h3>
				{ last && <span className="wss-card__meta">{ last.at }</span> }
			</header>
			{ ! last && (
				<p className="wss-card__lead woon-muted">
					{ __(
						'Todavía no corrió ninguna. La maestra la lanza cada noche.',
						'woonderful-stock-sync-for-woocommerce'
					) }
				</p>
			) }
			{ last && <p className="wss-card__lead">{ last.text }</p> }
			{ last && 'done' === last.outcome && (
				<>
					<div className="wss-recon">
						<span />
						<span className="wss-recon__col">
							{ status.store.label }
							<em>
								{ __(
									'acá',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</em>
						</span>
						<span className="wss-recon__col">
							{ status.peer.label }
							<em>
								{ __(
									'la otra',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</em>
						</span>
						<span className="wss-recon__label">
							{ __(
								'Confirmados',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</span>
						<span className="wss-recon__value">
							{ last.confirmed }
						</span>
						<span className="wss-recon__value">
							{ last.peer_confirmed }
						</span>
						<span className="wss-recon__label">
							{ __(
								'Vueltos a la cola',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</span>
						<span
							className={ `wss-recon__value ${
								last.reopened ? 'is-warn' : ''
							}` }
						>
							{ last.reopened }
						</span>
						<span
							className={ `wss-recon__value ${
								last.peer_reopened ? 'is-warn' : ''
							}` }
						>
							{ last.peer_reopened }
						</span>
					</div>
					<p className="wss-card__note">
						{ queued
							? sprintf(
									/* translators: %d: cuántos movimientos. */
									_n(
										'%d movimiento no había llegado y volvió a la cola: sale en la próxima pasada.',
										'%d movimientos no habían llegado y volvieron a la cola: salen en la próxima pasada.',
										queued,
										'woonderful-stock-sync-for-woocommerce'
									),
									queued
							  )
							: __(
									'No faltaba nada en ninguna de las dos.',
									'woonderful-stock-sync-for-woocommerce'
							  ) }
					</p>
				</>
			) }
		</section>
	);
}

// Un solo número de lo que no sincroniza: el mismo del filtro "Sin vincular" de Productos.
function Linking( { status, navigate } ) {
	const { unlinked, linked } = status.linking;
	const total = unlinked + linked;

	return (
		<section className="woon-card wss-card">
			<header className="wss-card__head">
				<h3>
					{ __(
						'Vinculación',
						'woonderful-stock-sync-for-woocommerce'
					) }
				</h3>
				<span className="wss-card__meta">
					{ sprintf(
						/* translators: %d: cuántos productos. */
						_n(
							'%d producto',
							'%d productos',
							total,
							'woonderful-stock-sync-for-woocommerce'
						),
						total
					) }
				</span>
			</header>
			<div className="wss-linking">
				<span
					className={ `wss-linking__number ${
						unlinked ? 'is-warn' : ''
					}` }
				>
					{ unlinked }
				</span>
				<span className="woon-muted">
					{ __(
						'sin vincular: su stock no se sincroniza',
						'woonderful-stock-sync-for-woocommerce'
					) }
				</span>
			</div>
			<div className="wss-linking__bar">
				<div
					style={ {
						width: `${ total ? ( linked / total ) * 100 : 0 }%`,
					} }
				/>
			</div>
			<p className="wss-card__note">
				{ sprintf(
					/* translators: 1: vinculados, 2: de cuántos. */
					__(
						'%1$d de %2$d vinculados',
						'woonderful-stock-sync-for-woocommerce'
					),
					linked,
					total
				) }
			</p>
			{ unlinked > 0 && (
				<div className="wss-card__actions">
					<button
						className="woon-btn woon-btn--secondary woon-btn--sm"
						onClick={ () =>
							navigate( 'products', { state: 'unlinked' } )
						}
					>
						{ __(
							'Ver sin vincular',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</button>
				</div>
			) }
		</section>
	);
}

function DiscrepancyList( { store, rows, empty, unknown, actions, note } ) {
	return (
		<div className="wss-disc">
			<div className="wss-disc__head">
				<span>
					{ sprintf(
						/* translators: %s: la tienda. */
						__( 'En %s', 'woonderful-stock-sync-for-woocommerce' ),
						store
					) }
				</span>
				{ rows.length > 0 && (
					<span className="woon-badge woon-badge--warning">
						{ rows.length }
					</span>
				) }
			</div>
			{ 0 === rows.length && (
				<div className="wss-disc__empty">
					{ ! unknown && (
						<span className="wss-disc__ok">
							<IconOk />
						</span>
					) }
					{ empty }
				</div>
			) }
			{ rows.length > 0 && (
				<div>
					<div className="wss-disc__row wss-disc__row--head">
						<span>
							{ __(
								'Producto',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</span>
						<span className="wss-r">
							{ __(
								'Hay',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</span>
						<span className="wss-r">
							{ __(
								'Libro',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</span>
						<span />
					</div>
					{ rows.map( ( row ) => (
						<div className="wss-disc__row" key={ row.product }>
							<span className="wss-disc__product">
								<span className="wss-disc__name">
									{ row.name ??
										sprintf(
											/* translators: %d: ID del producto. */
											__(
												'Producto #%d',
												'woonderful-stock-sync-for-woocommerce'
											),
											row.local ?? row.product
										) }
								</span>
								<span className="wss-sku">
									{ [
										row.sku,
										row.since &&
											sprintf(
												/* translators: %s: fecha. */
												__(
													'desde %s',
													'woonderful-stock-sync-for-woocommerce'
												),
												row.since
											),
									]
										.filter( Boolean )
										.join( ' · ' ) }
								</span>
							</span>
							<span className="wss-r wss-num">
								{ row.found }{ ' ' }
								<span className="wss-disc__delta">
									{ row.found > row.expected ? '+' : '−' }
									{ Math.abs( row.found - row.expected ) }
								</span>
							</span>
							<span className="wss-r wss-num">
								{ row.expected }
							</span>
							<span className="wss-r">
								{ actions ? actions( row ) : null }
							</span>
						</div>
					) ) }
					{ note && <p className="wss-disc__note">{ note }</p> }
				</div>
			) }
		</div>
	);
}

// Las dos salidas, con lo que hace cada una: la diferencia viaja o no viaja.
function ResolveMenu( { disabled, onResolve } ) {
	const [ open, setOpen ] = useState( false );
	const ref = useRef();

	useEffect( () => {
		if ( ! open ) {
			return;
		}
		const close = ( e ) => {
			if ( ! ref.current?.contains( e.target ) ) {
				setOpen( false );
			}
		};
		document.addEventListener( 'mousedown', close );
		return () => document.removeEventListener( 'mousedown', close );
	}, [ open ] );

	const items = [
		[
			'accept',
			__( 'El stock está bien', 'woonderful-stock-sync-for-woocommerce' ),
			__(
				'La diferencia se anota como un ajuste y viaja a la otra tienda.',
				'woonderful-stock-sync-for-woocommerce'
			),
		],
		[
			'restore',
			__( 'El libro está bien', 'woonderful-stock-sync-for-woocommerce' ),
			__(
				'El stock vuelve a lo que dice el libro. La otra tienda no se entera.',
				'woonderful-stock-sync-for-woocommerce'
			),
		],
	];

	return (
		<div className="wss-menu" ref={ ref }>
			<button
				className="woon-btn woon-btn--ghost woon-btn--sm"
				aria-expanded={ open }
				disabled={ disabled }
				onClick={ () => setOpen( ! open ) }
			>
				{ __( 'Resolver', 'woonderful-stock-sync-for-woocommerce' ) }
			</button>
			{ open && (
				<div className="wss-pop wss-pop--wide">
					{ items.map( ( [ action, label, hint ] ) => (
						<button
							key={ action }
							className="wss-pop__item"
							onClick={ () => {
								setOpen( false );
								onResolve( action );
							} }
						>
							{ label }
							<span className="wss-pop__hint">{ hint }</span>
						</button>
					) ) }
				</div>
			) }
		</div>
	);
}

function IconOk() {
	return (
		<svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true">
			<path
				d="M5 10.5l3.2 3L15 6.5"
				fill="none"
				stroke="currentColor"
				strokeWidth="2"
				strokeLinecap="round"
				strokeLinejoin="round"
			/>
		</svg>
	);
}

function IconWarn() {
	return (
		<svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true">
			<path
				d="M10 6v5M10 14v.01"
				stroke="currentColor"
				strokeWidth="2"
				strokeLinecap="round"
			/>
		</svg>
	);
}

function IconOff() {
	return (
		<svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true">
			<path
				d="M6 6l8 8M14 6l-8 8"
				stroke="currentColor"
				strokeWidth="2"
				strokeLinecap="round"
			/>
		</svg>
	);
}
