import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import StatCard from '../components/StatCard';

export default function Dashboard( { apiFetch, navigate, status, onStatus } ) {
	const [ busy, setBusy ] = useState( null );
	const [ notice, setNotice ] = useState( null );

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

	// Cada acción devuelve el estado nuevo: la pantalla muestra lo que dice el servidor.
	const run = async ( key, options, done ) => {
		setBusy( key );
		setNotice( null );
		try {
			const next = await apiFetch( { method: 'POST', ...options } );
			onStatus( next );
			setNotice( done( next ) );
		} catch ( e ) {
			setNotice( {
				type: 'error',
				text:
					e?.message ||
					__(
						'Error al conectar con la API.',
						'woonderful-stock-sync-for-woocommerce'
					),
			} );
		} finally {
			setBusy( null );
		}
	};

	const reconcile = () =>
		run( 'reconcile', { path: '/reconcile' }, ( next ) =>
			'done' === next.reconciliation?.outcome
				? { type: 'success', text: next.reconciliation.text }
				: { type: 'warning', text: next.reconciliation?.text }
		);

	const resolve = ( product, action ) =>
		run(
			`${ action }-${ product }`,
			{ path: `/discrepancies/${ product }/${ action }` },
			() => ( {
				type: 'success',
				text:
					'accept' === action
						? __(
								'Anotado como ajuste. La otra tienda lo recibe en la próxima pasada.',
								'woonderful-stock-sync-for-woocommerce'
						  )
						: __(
								'El stock volvió a lo que dice el libro.',
								'woonderful-stock-sync-for-woocommerce'
						  ),
			} )
		);

	const ok = 'ok' === status.peer.state;
	const own = status.discrepancies.own;
	const peer = status.discrepancies.peer;

	return (
		<div>
			{ notice?.text && (
				<div className={ `woon-notice woon-notice--${ notice.type }` }>
					<p>{ notice.text }</p>
				</div>
			) }

			<div className="woon-dashboard__sync">
				<div
					style={ {
						display: 'flex',
						alignItems: 'center',
						gap: '12px',
					} }
				>
					<span
						className={ `woon-badge woon-badge--${
							ok ? 'success' : 'danger'
						}` }
					>
						{ ok
							? __(
									'Conectada',
									'woonderful-stock-sync-for-woocommerce'
							  )
							: __(
									'Sin respuesta',
									'woonderful-stock-sync-for-woocommerce'
							  ) }
					</span>
					<span
						style={ {
							color: 'var(--woon-text-muted)',
							fontWeight: 600,
						} }
					>
						{ sprintf(
							/* translators: 1: esta tienda, 2: su rol, 3: la otra. */
							__(
								'%1$s (%2$s) ↔ %3$s',
								'woonderful-stock-sync-for-woocommerce'
							),
							status.store.label,
							status.store.master
								? __(
										'maestra',
										'woonderful-stock-sync-for-woocommerce'
								  )
								: __(
										'secundaria',
										'woonderful-stock-sync-for-woocommerce'
								  ),
							status.peer.label
						) }
					</span>
				</div>
				{ status.store.master && (
					<button
						className="woon-btn woon-btn--primary"
						onClick={ reconcile }
						disabled={ null !== busy }
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
				) }
			</div>

			<div className="woon-dashboard__section">
				<div className="woon-dashboard__section-header">
					<h3 className="woon-dashboard__section-title">
						{ __(
							'Última reconciliación',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</h3>
					{ status.reconciliation && (
						<span className="woon-muted">
							{ status.reconciliation.at }
						</span>
					) }
				</div>
				{ status.reconciliation ? (
					<>
						<p style={ { marginBottom: '16px' } }>
							{ status.reconciliation.text }
						</p>
						{ 'done' === status.reconciliation.outcome && (
							<div className="woon-dashboard__cards">
								<StatCard
									title={ __(
										'Vueltos a la cola, acá',
										'woonderful-stock-sync-for-woocommerce'
									) }
									value={ status.reconciliation.reopened }
								/>
								<StatCard
									title={ __(
										'Confirmados, acá',
										'woonderful-stock-sync-for-woocommerce'
									) }
									value={ status.reconciliation.confirmed }
								/>
								<StatCard
									title={ __(
										'Vueltos a la cola, en la otra',
										'woonderful-stock-sync-for-woocommerce'
									) }
									value={
										status.reconciliation.peer_reopened
									}
								/>
								<StatCard
									title={ __(
										'Confirmados, en la otra',
										'woonderful-stock-sync-for-woocommerce'
									) }
									value={
										status.reconciliation.peer_confirmed
									}
								/>
							</div>
						) }
					</>
				) : (
					<p
						className="woon-muted"
						style={ { marginBottom: '24px' } }
					>
						{ __(
							'Todavía no corrió ninguna. La maestra la lanza cada noche.',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</p>
				) }
			</div>

			<div className="woon-dashboard__section">
				<div className="woon-dashboard__section-header">
					<h3 className="woon-dashboard__section-title">
						{ __(
							'Discrepancias',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</h3>
				</div>
				<p className="woon-muted" style={ { marginBottom: '12px' } }>
					{ __(
						'Productos cuyo stock no coincide con lo que dice el libro de movimientos.',
						'woonderful-stock-sync-for-woocommerce'
					) }
				</p>
				<DiscrepancyTable
					title={ sprintf(
						/* translators: %s: esta tienda. */
						__( 'En %s', 'woonderful-stock-sync-for-woocommerce' ),
						status.store.label
					) }
					rows={ own }
					empty={ __(
						'No hay discrepancias en esta tienda.',
						'woonderful-stock-sync-for-woocommerce'
					) }
					actions={ ( row ) => (
						<div style={ { display: 'flex', gap: '8px' } }>
							<button
								className="woon-btn woon-btn--secondary woon-btn--sm"
								disabled={ null !== busy }
								onClick={ () =>
									resolve( row.product, 'accept' )
								}
								title={ __(
									'La diferencia se anota como un ajuste y viaja a la otra tienda.',
									'woonderful-stock-sync-for-woocommerce'
								) }
							>
								{ __(
									'El stock está bien',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</button>
							<button
								className="woon-btn woon-btn--ghost woon-btn--sm"
								disabled={ null !== busy }
								onClick={ () =>
									resolve( row.product, 'restore' )
								}
								title={ __(
									'El stock vuelve a lo que dice el libro. La otra tienda no se entera.',
									'woonderful-stock-sync-for-woocommerce'
								) }
							>
								{ __(
									'El libro está bien',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</button>
						</div>
					) }
				/>
				{ status.store.master && (
					<DiscrepancyTable
						title={ sprintf(
							/* translators: %s: la otra tienda. */
							__(
								'En %s',
								'woonderful-stock-sync-for-woocommerce'
							),
							status.peer.label
						) }
						rows={ peer }
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
						note={ __(
							'Se resuelven desde la otra tienda.',
							'woonderful-stock-sync-for-woocommerce'
						) }
					/>
				) }
			</div>

			<div className="woon-dashboard__section">
				<div className="woon-dashboard__section-header">
					<h3 className="woon-dashboard__section-title">
						{ __(
							'Sin sincronizar',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</h3>
				</div>
				<div className="woon-dashboard__cards woon-dashboard__cards--sm">
					<StatCard
						title={ __(
							'Productos sin vincular hace más del plazo',
							'woonderful-stock-sync-for-woocommerce'
						) }
						value={ status.unsynced }
						warning={ status.unsynced > 0 }
						onClick={ () =>
							navigate( 'products', { state: 'unlinked' } )
						}
					/>
				</div>
			</div>
		</div>
	);
}

function DiscrepancyTable( { title, rows, empty, actions, note } ) {
	return (
		<div style={ { marginBottom: '24px' } }>
			<h4 style={ { marginBottom: '8px' } }>
				{ title }
				{ note && rows?.length > 0 && (
					<span className="woon-muted"> — { note }</span>
				) }
			</h4>
			{ rows?.length ? (
				<table className="woon-table">
					<thead>
						<tr>
							<th>
								{ __(
									'Producto',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</th>
							<th>
								{ __(
									'El libro dice',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</th>
							<th>
								{ __(
									'Hay',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</th>
							<th>
								{ __(
									'Desde',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</th>
							{ actions && <th /> }
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( row ) => (
							<tr key={ row.product }>
								<td>
									{ row.name ??
										sprintf(
											/* translators: %d: ID del producto. */
											__(
												'Producto #%d',
												'woonderful-stock-sync-for-woocommerce'
											),
											row.local ?? row.product
										) }
								</td>
								<td>{ row.expected }</td>
								<td>{ row.found }</td>
								<td className="woon-muted">
									{ row.since ?? '—' }
								</td>
								{ actions && <td>{ actions( row ) }</td> }
							</tr>
						) ) }
					</tbody>
				</table>
			) : (
				<p className="woon-muted">{ empty }</p>
			) }
		</div>
	);
}
