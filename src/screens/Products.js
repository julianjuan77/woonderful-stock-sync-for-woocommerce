import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import Pagination from '../components/Pagination';
import LinkModal from '../components/LinkModal';

const STATE_FILTERS = [
	{
		value: 'all',
		label: __( 'Todos', 'woonderful-stock-sync-for-woocommerce' ),
	},
	{
		value: 'suggestion',
		label: __( 'Con sugerencia', 'woonderful-stock-sync-for-woocommerce' ),
	},
	{
		value: 'unlinked',
		label: __( 'Sin vincular', 'woonderful-stock-sync-for-woocommerce' ),
	},
	{
		value: 'linked',
		label: __( 'Vinculados', 'woonderful-stock-sync-for-woocommerce' ),
	},
	{
		value: 'suspended',
		label: __( 'Suspendidos', 'woonderful-stock-sync-for-woocommerce' ),
	},
];

const MOTIVES = {
	no_sku: __( 'Sin SKU', 'woonderful-stock-sync-for-woocommerce' ),
	duplicate_sku: __(
		'SKU duplicado',
		'woonderful-stock-sync-for-woocommerce'
	),
	no_match: __( 'Sin coincidencia', 'woonderful-stock-sync-for-woocommerce' ),
	trashed: __( 'En la papelera', 'woonderful-stock-sync-for-woocommerce' ),
};

const MOTIVE_TEXT = {
	...MOTIVES,
	suggestion: __( 'Con sugerencia', 'woonderful-stock-sync-for-woocommerce' ),
	discarded: __(
		'Sugerencia descartada',
		'woonderful-stock-sync-for-woocommerce'
	),
	stock_in_parent: __(
		'El stock está en el padre: llevalo a cada variación',
		'woonderful-stock-sync-for-woocommerce'
	),
};

const STATE_BADGE = {
	linked: [
		'success',
		__( 'Vinculado', 'woonderful-stock-sync-for-woocommerce' ),
	],
	unlinked: [
		'muted',
		__( 'Sin vincular', 'woonderful-stock-sync-for-woocommerce' ),
	],
	suspended: [
		'danger',
		__( 'Suspendido', 'woonderful-stock-sync-for-woocommerce' ),
	],
	uncertain: [
		'warning',
		__( 'Sin confirmar', 'woonderful-stock-sync-for-woocommerce' ),
	],
};

const initialState = () => {
	const params = new URLSearchParams(
		window.location.hash.split( '?' )[ 1 ] || ''
	);
	const state = params.get( 'state' );
	return STATE_FILTERS.some( ( f ) => f.value === state ) ? state : 'all';
};

export default function Products( { apiFetch, navigate, status } ) {
	const [ data, setData ] = useState( null );
	const [ page, setPage ] = useState( 1 );
	const [ state, setState ] = useState( initialState );
	const [ motive, setMotive ] = useState( '' );
	const [ showDiscarded, setShowDiscarded ] = useState( false );
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ refreshing, setRefreshing ] = useState( null );
	const [ modal, setModal ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const autoRefreshed = useRef( false );

	// La búsqueda espera a que se deje de escribir.
	useEffect( () => {
		const timer = setTimeout( () => {
			setQuery( search );
			setPage( 1 );
		}, 300 );
		return () => clearTimeout( timer );
	}, [ search ] );

	const load = useCallback( async () => {
		setLoading( true );
		setError( null );
		try {
			const params = new URLSearchParams( {
				page,
				state,
				motive,
				search: query,
				show_discarded: showDiscarded ? '1' : '',
			} );
			const next = await apiFetch( { path: `/products?${ params }` } );
			setData( next );
			return next;
		} catch ( e ) {
			setError(
				e?.message ||
					__(
						'Error al cargar los productos.',
						'woonderful-stock-sync-for-woocommerce'
					)
			);
			return null;
		} finally {
			setLoading( false );
		}
	}, [ apiFetch, page, state, motive, query, showDiscarded ] );

	// La copia se refresca de a una página por request, con el avance (ADR-014 §1).
	const refresh = useCallback( async () => {
		setError( null );
		let current = 1;
		try {
			for (;;) {
				const step = await apiFetch( {
					path: '/catalog/refresh',
					method: 'POST',
					data: { page: current },
				} );
				setRefreshing( step );
				if ( step.done ) {
					break;
				}
				current++;
			}
		} catch ( e ) {
			setError(
				sprintf(
					/* translators: %s: el error. */
					__(
						'No se pudo actualizar el catálogo de la otra tienda (%s). Se muestra la última copia.',
						'woonderful-stock-sync-for-woocommerce'
					),
					e?.message || '?'
				)
			);
		} finally {
			setRefreshing( null );
		}
		await load();
	}, [ apiFetch, load ] );

	useEffect( () => {
		load().then( ( next ) => {
			if ( next?.catalog?.stale && ! autoRefreshed.current ) {
				autoRefreshed.current = true;
				refresh();
			}
		} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ load ] );

	// La fila se reemplaza con lo que devuelve el servidor, no con lo que se esperaba (UC-05);
	// después se recarga la página, por los conteos.
	const replaceRow = ( id, row ) => {
		setData( ( prev ) => ( {
			...prev,
			rows: prev.rows.map( ( r ) =>
				r.id === id ? { ...r, ...( row ?? {} ) } : r
			),
		} ) );
		load();
	};

	const act = async ( path, row ) => {
		setNotice( null );
		try {
			const result = await apiFetch( {
				path,
				method: 'POST',
				data: { local: row.id },
			} );
			replaceRow( row.id, result.row );
		} catch ( e ) {
			setNotice( { type: 'error', text: e?.message } );
		}
	};

	const linked = ( id, result ) => {
		setModal( null );
		replaceRow( id, result.row );
		setNotice(
			'uncertain' === result.result
				? {
						type: 'warning',
						text: __(
							'La otra tienda no respondió: no se sabe si lo vinculó. El plugin le pregunta solo dentro de 10 minutos.',
							'woonderful-stock-sync-for-woocommerce'
						),
				  }
				: null
		);
	};

	const actions = status?.store?.master
		? {
				link: ( row ) => setModal( { row, reassign: false } ),
				reassign: ( row ) => setModal( { row, reassign: true } ),
				unlink: ( row ) => act( '/unlink', row ),
				discard: ( row ) => act( '/discard', row ),
				undiscard: ( row ) => act( '/undiscard', row ),
		  }
		: null;

	const pickState = ( value ) => {
		setState( value );
		setMotive( '' );
		setPage( 1 );
	};

	if ( status && ! status.paired ) {
		return (
			<div className="woon-notice woon-notice--warning">
				<p>
					{ __(
						'Esta tienda todavía no está emparejada: no hay con qué vincular.',
						'woonderful-stock-sync-for-woocommerce'
					) }
				</p>
			</div>
		);
	}

	const counts = data?.counts ?? {};

	return (
		<div>
			<div className="woon-toolbar">
				<input
					className="woon-search"
					type="search"
					value={ search }
					placeholder={ __(
						'Buscar por nombre o SKU, de las dos tiendas…',
						'woonderful-stock-sync-for-woocommerce'
					) }
					onChange={ ( e ) => setSearch( e.target.value ) }
				/>
				<div className="woon-filters">
					{ STATE_FILTERS.map( ( f ) => (
						<button
							key={ f.value }
							className={ `woon-filter ${
								state === f.value ? 'woon-filter--active' : ''
							}` }
							onClick={ () => pickState( f.value ) }
						>
							{ f.label }
							{ undefined !== counts[ f.value ] &&
								` (${ counts[ f.value ] })` }
						</button>
					) ) }
				</div>
				<div className="woon-toolbar__actions">
					<button
						className="woon-btn woon-btn--ghost woon-btn--sm"
						onClick={ refresh }
						disabled={ null !== refreshing }
					>
						{ __(
							'Actualizar catálogo',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</button>
				</div>
			</div>

			<div className="woon-toolbar" style={ { marginTop: '-8px' } }>
				<select
					className="woon-select"
					style={ { maxWidth: 220 } }
					value={ motive }
					onChange={ ( e ) => {
						setMotive( e.target.value );
						setPage( 1 );
					} }
				>
					<option value="">
						{ __(
							'Todos los motivos',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</option>
					{ Object.entries( MOTIVES ).map( ( [ v, l ] ) => (
						<option key={ v } value={ v }>
							{ l }
						</option>
					) ) }
				</select>
				<label
					className="woon-log-moved-toggle"
					htmlFor="wss-show-discarded"
				>
					<input
						id="wss-show-discarded"
						type="checkbox"
						checked={ showDiscarded }
						onChange={ ( e ) => {
							setShowDiscarded( e.target.checked );
							setPage( 1 );
						} }
					/>
					{ __(
						'Mostrar descartados',
						'woonderful-stock-sync-for-woocommerce'
					) }
				</label>
				{ data?.catalog?.age !== null && data?.catalog && (
					<span
						className="woon-muted"
						style={ { marginLeft: 'auto' } }
					>
						{ sprintf(
							/* translators: %d: minutos. */
							__(
								'Catálogo de la otra tienda: actualizado hace %d min',
								'woonderful-stock-sync-for-woocommerce'
							),
							Math.floor( data.catalog.age / 60 )
						) }
					</span>
				) }
			</div>

			{ refreshing && (
				<div className="woon-notice woon-notice--info">
					<p>
						{ sprintf(
							/* translators: 1: página, 2: de cuántas. */
							__(
								'Actualizando el catálogo de la otra tienda: página %1$d de %2$d…',
								'woonderful-stock-sync-for-woocommerce'
							),
							refreshing.page,
							refreshing.pages
						) }
					</p>
				</div>
			) }
			{ error && (
				<div className="woon-notice woon-notice--error">
					<p>{ error }</p>
				</div>
			) }
			{ notice && (
				<div className={ `woon-notice woon-notice--${ notice.type }` }>
					<p>{ notice.text }</p>
				</div>
			) }
			{ modal && (
				<LinkModal
					apiFetch={ apiFetch }
					row={ modal.row }
					reassign={ modal.reassign }
					onClose={ () => setModal( null ) }
					onDone={ ( result ) => linked( modal.row.id, result ) }
				/>
			) }
			{ loading && ! data && (
				<div className="woon-loading">
					{ __(
						'Cargando…',
						'woonderful-stock-sync-for-woocommerce'
					) }
				</div>
			) }

			{ data && 0 === data.rows.length && (
				<div className="woon-empty">
					<div className="woon-empty__title">
						{ __(
							'Sin productos',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</div>
					<p className="woon-empty__text">
						{ __(
							'No hay productos con estos filtros.',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</p>
				</div>
			) }

			{ data && data.rows.length > 0 && (
				<table className="woon-table">
					<thead>
						<tr>
							<th>
								{ __(
									'Esta tienda',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</th>
							<th>
								{ __(
									'Stock',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</th>
							<th>
								{ __(
									'La otra tienda',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</th>
							<th>
								{ __(
									'Stock',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</th>
							<th>
								{ __(
									'Estado',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ data.rows.map( ( row, index ) => {
							const previous = data.rows[ index - 1 ];
							const header =
								row.parent > 0 &&
								( ! previous || previous.group !== row.group );
							return [
								header && (
									<tr key={ `g${ row.group }` }>
										<td
											colSpan={ actions ? 6 : 5 }
											style={ {
												fontWeight: 600,
												background:
													'var(--woon-surface-2)',
											} }
										>
											{ row.parent_name ??
												`#${ row.parent }` }
										</td>
									</tr>
								),
								<Row
									key={ row.id }
									row={ row }
									actions={ actions }
									onHistory={ () =>
										navigate( 'logs', {
											product: row.id,
										} )
									}
								/>,
							];
						} ) }
					</tbody>
				</table>
			) }

			{ data && (
				<Pagination
					page={ page }
					total={ data.total }
					perPage={ 50 }
					onPageChange={ setPage }
				/>
			) }
		</div>
	);
}

function Row( { row, actions, onHistory } ) {
	const [ badge, label ] = STATE_BADGE[ row.state ] ?? [ 'muted', row.state ];
	const other = row.remote ?? row.suggestion;
	return (
		<tr>
			<td style={ { paddingLeft: row.parent > 0 ? 32 : undefined } }>
				<button
					className="woon-btn-link"
					onClick={ onHistory }
					title={ __(
						'Ver el historial',
						'woonderful-stock-sync-for-woocommerce'
					) }
				>
					{ row.name }
				</button>
				<div className="woon-muted">
					{ row.sku || '—' } · #{ row.id }
				</div>
			</td>
			<td>{ row.stock ?? '—' }</td>
			<td>
				{ other ? (
					<>
						{ row.suggestion && (
							<span className="woon-muted">
								{ __(
									'Sugerencia:',
									'woonderful-stock-sync-for-woocommerce'
								) }{ ' ' }
							</span>
						) }
						{ other.name ?? `#${ other.id }` }
						<div className="woon-muted">
							{ other.sku || '—' } · #{ other.id }
						</div>
					</>
				) : (
					<span className="woon-muted">—</span>
				) }
			</td>
			<td>{ other?.stock ?? '—' }</td>
			<td>
				<span className={ `woon-badge woon-badge--${ badge }` }>
					{ label }
				</span>
				{ ( row.reason || row.motive ) && (
					<div className="woon-muted" style={ { marginTop: 4 } }>
						{ row.reason ?? MOTIVE_TEXT[ row.motive ] }
					</div>
				) }
			</td>
			{ actions && (
				<td style={ { whiteSpace: 'nowrap' } }>
					<RowActions row={ row } actions={ actions } />
				</td>
			) }
		</tr>
	);
}

function RowActions( { row, actions } ) {
	const [ confirming, setConfirming ] = useState( false );

	if ( 'stock_in_parent' === row.motive ) {
		return null;
	}

	if ( 'unlinked' !== row.state ) {
		if ( confirming ) {
			return (
				<span>
					<span className="woon-muted">
						{ __(
							'Deja de sincronizar; el stock no se toca.',
							'woonderful-stock-sync-for-woocommerce'
						) }{ ' ' }
					</span>
					<button
						className="woon-btn woon-btn--danger woon-btn--sm"
						onClick={ () => actions.unlink( row ) }
					>
						{ __(
							'Desvincular',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</button>{ ' ' }
					<button
						className="woon-btn woon-btn--ghost woon-btn--sm"
						onClick={ () => setConfirming( false ) }
					>
						{ __(
							'Cancelar',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</button>
				</span>
			);
		}
		return (
			<span>
				<button
					className="woon-btn woon-btn--ghost woon-btn--sm"
					onClick={ () => actions.reassign( row ) }
				>
					{ __(
						'Reasignar',
						'woonderful-stock-sync-for-woocommerce'
					) }
				</button>{ ' ' }
				<button
					className="woon-btn woon-btn--ghost woon-btn--sm"
					onClick={ () => setConfirming( true ) }
				>
					{ __(
						'Desvincular',
						'woonderful-stock-sync-for-woocommerce'
					) }
				</button>
			</span>
		);
	}

	if ( 'trashed' === row.motive ) {
		return null;
	}

	return (
		<span>
			<button
				className="woon-btn woon-btn--secondary woon-btn--sm"
				onClick={ () => actions.link( row ) }
			>
				{ 'suggestion' === row.motive
					? __(
							'Vincular sugerencia',
							'woonderful-stock-sync-for-woocommerce'
					  )
					: __(
							'Vincular',
							'woonderful-stock-sync-for-woocommerce'
					  ) }
			</button>{ ' ' }
			{ 'suggestion' === row.motive && (
				<button
					className="woon-btn woon-btn--ghost woon-btn--sm"
					onClick={ () => actions.discard( row ) }
				>
					{ __(
						'Descartar',
						'woonderful-stock-sync-for-woocommerce'
					) }
				</button>
			) }
			{ 'discarded' === row.motive && (
				<button
					className="woon-btn woon-btn--ghost woon-btn--sm"
					onClick={ () => actions.undiscard( row ) }
				>
					{ __(
						'Deshacer descarte',
						'woonderful-stock-sync-for-woocommerce'
					) }
				</button>
			) }
		</span>
	);
}
