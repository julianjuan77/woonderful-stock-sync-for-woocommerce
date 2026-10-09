import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import Pagination from '../components/Pagination';
import StockChange from '../components/StockChange';

const LEVEL_LABELS = {
	info: __( 'Info', 'woonderful-stock-sync-for-woocommerce' ),
	warning: __( 'Aviso', 'woonderful-stock-sync-for-woocommerce' ),
	error: __( 'Error', 'woonderful-stock-sync-for-woocommerce' ),
};

// El color de woonderful-sync para cada nivel.
const LEVEL_CLASS = {
	info: 'success',
	warning: 'conflict',
	error: 'error',
};

const BADGE_CLASS = {
	info: 'info',
	warning: 'warning',
	error: 'danger',
};

const KIND_LABELS = {
	linked: __( 'Vinculado', 'woonderful-stock-sync-for-woocommerce' ),
	link_failed: __(
		'Vinculación fallida',
		'woonderful-stock-sync-for-woocommerce'
	),
	unlinked: __( 'Desvinculado', 'woonderful-stock-sync-for-woocommerce' ),
	link_unresolved: __(
		'Vínculo sin confirmar',
		'woonderful-stock-sync-for-woocommerce'
	),
	link_removed: __(
		'Vínculo borrado',
		'woonderful-stock-sync-for-woocommerce'
	),
	discarded: __( 'Descartado', 'woonderful-stock-sync-for-woocommerce' ),
	undiscarded: __(
		'Descarte deshecho',
		'woonderful-stock-sync-for-woocommerce'
	),
	peer_unreachable: __(
		'La otra no responde',
		'woonderful-stock-sync-for-woocommerce'
	),
	peer_back: __( 'La otra volvió', 'woonderful-stock-sync-for-woocommerce' ),
	peer_rejected: __(
		'La otra rechaza',
		'woonderful-stock-sync-for-woocommerce'
	),
	peer_accepted: __(
		'La otra acepta de nuevo',
		'woonderful-stock-sync-for-woocommerce'
	),
	abandoned: __(
		'Movimientos abandonados',
		'woonderful-stock-sync-for-woocommerce'
	),
	ledger_error: __(
		'Error del libro',
		'woonderful-stock-sync-for-woocommerce'
	),
	reconciled: __( 'Reconciliación', 'woonderful-stock-sync-for-woocommerce' ),
	discrepancy_opened: __(
		'Discrepancia',
		'woonderful-stock-sync-for-woocommerce'
	),
	discrepancy_closed: __(
		'Discrepancia cerrada',
		'woonderful-stock-sync-for-woocommerce'
	),
	discrepancy_accepted: __(
		'Discrepancia aceptada',
		'woonderful-stock-sync-for-woocommerce'
	),
	discrepancy_restored: __(
		'Discrepancia restaurada',
		'woonderful-stock-sync-for-woocommerce'
	),
	pair_broken: __( 'Par roto', 'woonderful-stock-sync-for-woocommerce' ),
	pair_restored: __(
		'Par restaurado',
		'woonderful-stock-sync-for-woocommerce'
	),
	digest_sent: __(
		'Resumen enviado',
		'woonderful-stock-sync-for-woocommerce'
	),
	notified: __( 'Producto avisado', 'woonderful-stock-sync-for-woocommerce' ),
	notify_failed: __(
		'Aviso sin enviar',
		'woonderful-stock-sync-for-woocommerce'
	),
};

// Filtros iniciales desde el hash (ej. #/logs?level=error o #/logs?product=12), para que la
// navegación desde otra pantalla aterrice ya filtrada.
const getInitialFilters = () => {
	const params = new URLSearchParams(
		window.location.hash.split( '?' )[ 1 ] || ''
	);
	return {
		level: params.get( 'level' ) || '',
		kind: params.get( 'kind' ) || '',
		from: '',
		to: '',
		product: params.get( 'product' ) || '',
	};
};

export default function Logs( { apiFetch, navigate } ) {
	const [ entries, setEntries ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ name, setName ] = useState( null );
	const [ page, setPage ] = useState( 1 );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ expanded, setExpanded ] = useState( null );
	const [ filters, setFilters ] = useState( getInitialFilters );

	const fetchLogs = useCallback( async () => {
		setLoading( true );
		setError( null );
		try {
			const params = new URLSearchParams(
				Object.entries( { page, ...filters } ).filter(
					( [ , value ] ) => '' !== value
				)
			);
			const data = await apiFetch( { path: `/log?${ params }` } );
			setEntries( data.items );
			setTotal( data.total );
			setName( data.name ?? null );
		} catch ( e ) {
			setError(
				e?.message ||
					__(
						'Error al cargar registros.',
						'woonderful-stock-sync-for-woocommerce'
					)
			);
		} finally {
			setLoading( false );
		}
	}, [ apiFetch, page, filters ] );

	useEffect( () => {
		fetchLogs();
	}, [ fetchLogs ] );

	const updateFilter = ( key, value ) => {
		setFilters( ( prev ) => ( { ...prev, [ key ]: value } ) );
		setPage( 1 );
	};

	const showAll = () => {
		navigate( 'logs' );
		updateFilter( 'product', '' );
	};

	const toggleExpand = ( key ) =>
		setExpanded( ( prev ) => ( prev === key ? null : key ) );

	return (
		<div>
			{ filters.product ? (
				<div className="woon-toolbar">
					<h3 className="woon-dashboard__section-title">
						{ sprintf(
							/* translators: %s: el producto. */
							__(
								'Historial de %s',
								'woonderful-stock-sync-for-woocommerce'
							),
							name ?? `#${ filters.product }`
						) }
					</h3>
					<button
						className="woon-btn woon-btn--ghost"
						onClick={ showAll }
					>
						{ __(
							'Ver todo el registro',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</button>
				</div>
			) : (
				<div
					className="woon-toolbar"
					style={ { flexWrap: 'wrap', gap: '8px' } }
				>
					<select
						className="woon-select"
						style={ { maxWidth: 200 } }
						value={ filters.level }
						onChange={ ( e ) =>
							updateFilter( 'level', e.target.value )
						}
					>
						<option value="">
							{ __(
								'Todos los niveles',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</option>
						{ Object.entries( LEVEL_LABELS ).map( ( [ v, l ] ) => (
							<option key={ v } value={ v }>
								{ l }
							</option>
						) ) }
					</select>
					<select
						className="woon-select"
						style={ { maxWidth: 220 } }
						value={ filters.kind }
						onChange={ ( e ) =>
							updateFilter( 'kind', e.target.value )
						}
					>
						<option value="">
							{ __(
								'Todos los tipos',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</option>
						{ Object.entries( KIND_LABELS ).map( ( [ v, l ] ) => (
							<option key={ v } value={ v }>
								{ l }
							</option>
						) ) }
					</select>
					<input
						className="woon-input"
						style={ { maxWidth: 160 } }
						type="date"
						value={ filters.from }
						aria-label={ __(
							'Desde',
							'woonderful-stock-sync-for-woocommerce'
						) }
						onChange={ ( e ) =>
							updateFilter( 'from', e.target.value )
						}
					/>
					<input
						className="woon-input"
						style={ { maxWidth: 160 } }
						type="date"
						value={ filters.to }
						aria-label={ __(
							'Hasta',
							'woonderful-stock-sync-for-woocommerce'
						) }
						onChange={ ( e ) =>
							updateFilter( 'to', e.target.value )
						}
					/>
				</div>
			) }

			{ error && (
				<div className="woon-notice woon-notice--error">
					<p>{ error }</p>
				</div>
			) }
			{ loading && (
				<div className="woon-loading">
					{ __(
						'Cargando registros…',
						'woonderful-stock-sync-for-woocommerce'
					) }
				</div>
			) }
			{ ! loading && ! error && entries.length === 0 && (
				<div className="woon-empty">
					<div className="woon-empty__title">
						{ __(
							'Sin registros',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</div>
					<p className="woon-empty__text">
						{ __(
							'No hay registros con estos filtros.',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</p>
				</div>
			) }

			{ ! loading && entries.length > 0 && (
				<div className="woon-log-list">
					{ entries.map( ( entry, index ) => {
						const key = entry.id ?? `m${ index }`;
						const level = entry.level ?? 'info';
						return (
							<div
								key={ key }
								className={ `woon-log-entry woon-log-entry--${ LEVEL_CLASS[ level ] }` }
							>
								<div
									className="woon-log-entry__summary"
									onClick={ () => toggleExpand( key ) }
									role="button"
									tabIndex={ 0 }
									onKeyDown={ ( e ) =>
										e.key === 'Enter' && toggleExpand( key )
									}
								>
									<span
										className={ `woon-badge woon-badge--${
											'movement' === entry.type
												? 'brand'
												: BADGE_CLASS[ level ]
										}` }
									>
										{ 'movement' === entry.type
											? __(
													'Movimiento',
													'woonderful-stock-sync-for-woocommerce'
											  )
											: KIND_LABELS[ entry.kind ] ||
											  entry.kind }
									</span>
									<span
										style={ {
											fontSize: 'var(--woon-fs-sm)',
										} }
									>
										{ entry.text }
									</span>
									<StockChange stocks={ entry.stocks } />
									<span
										style={ {
											color: 'var(--woon-text-faint)',
											fontSize: 'var(--woon-fs-xs)',
											marginLeft: 'auto',
											whiteSpace: 'nowrap',
										} }
									>
										{ entry.at }
									</span>
									<span
										className="woon-log-entry__toggle"
										style={ { marginLeft: 0 } }
									>
										{ expanded === key ? '▲' : '▼' }
									</span>
								</div>

								{ expanded === key && (
									<Detail
										entry={ entry }
										onHistory={ ( product ) => {
											navigate( 'logs', { product } );
											updateFilter(
												'product',
												String( product )
											);
										} }
										inHistory={ '' !== filters.product }
									/>
								) }
							</div>
						);
					} ) }
				</div>
			) }

			{ ! filters.product && (
				<Pagination
					page={ page }
					total={ total }
					perPage={ 50 }
					onPageChange={ setPage }
				/>
			) }
		</div>
	);
}

function Detail( { entry, onHistory, inHistory } ) {
	const rows = [
		[
			__( 'Producto', 'woonderful-stock-sync-for-woocommerce' ),
			entry.product && `${ entry.name ?? '' } #${ entry.product }`.trim(),
		],
		[
			__( 'Por qué falló', 'woonderful-stock-sync-for-woocommerce' ),
			entry.cause,
		],
		[ __( 'Quién', 'woonderful-stock-sync-for-woocommerce' ), entry.user ],
	].filter( ( [ , value ] ) => value );

	const context = entry.context ?? {};

	return (
		<div className="woon-log-entry__detail">
			{ rows.length > 0 && (
				<div
					className="woon-log-conflict-resolution"
					style={ { marginBottom: '12px' } }
				>
					{ rows.map( ( [ label, value ] ) => (
						<div
							key={ label }
							className="woon-log-conflict-resolution__item"
						>
							<span className="woon-log-conflict-resolution__label">
								{ label }
							</span>
							<span>{ value }</span>
						</div>
					) ) }
				</div>
			) }
			{ Object.keys( context ).length > 0 && (
				<details>
					<summary>
						{ __(
							'Datos',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</summary>
					<pre className="woon-log-entry__code">
						{ JSON.stringify( context, null, 2 ) }
					</pre>
				</details>
			) }
			{ entry.product && ! inHistory && (
				<button
					className="woon-btn woon-btn--ghost woon-btn--sm"
					style={ { marginTop: '12px' } }
					onClick={ () => onHistory( entry.product ) }
				>
					{ __(
						'Ver el historial del producto',
						'woonderful-stock-sync-for-woocommerce'
					) }
				</button>
			) }
		</div>
	);
}
