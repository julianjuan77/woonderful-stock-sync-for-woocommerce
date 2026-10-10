import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';
import Pagination from '../components/Pagination';
import LinkModal from '../components/LinkModal';
import BulkModal from '../components/BulkModal';
import HistoryModal from '../components/HistoryModal';

const STATE_TABS = [
	[ 'all', __( 'Todos', 'woonderful-stock-sync-for-woocommerce' ) ],
	[
		'unlinked',
		__( 'Sin vincular', 'woonderful-stock-sync-for-woocommerce' ),
	],
	[ 'linked', __( 'Vinculados', 'woonderful-stock-sync-for-woocommerce' ) ],
	[
		'suspended',
		__( 'Suspendidos', 'woonderful-stock-sync-for-woocommerce' ),
	],
];

// Los motivos de un sin vincular: reemplazan a las pestañas de estado al entrar en "Sin
// vincular", así nunca hay dos filas de filtros.
const MOTIVE_TABS = [
	[ '', __( 'Todos', 'woonderful-stock-sync-for-woocommerce' ) ],
	[
		'suggestion',
		__( 'Con sugerencia', 'woonderful-stock-sync-for-woocommerce' ),
	],
	[
		'no_match',
		__( 'Sin coincidencia', 'woonderful-stock-sync-for-woocommerce' ),
	],
	[ 'no_sku', __( 'Sin SKU', 'woonderful-stock-sync-for-woocommerce' ) ],
	[
		'stock_in_parent',
		__( 'Stock en el padre', 'woonderful-stock-sync-for-woocommerce' ),
	],
	[
		'duplicate_sku',
		__( 'SKU duplicado', 'woonderful-stock-sync-for-woocommerce' ),
	],
	[
		'trashed',
		__( 'En la papelera', 'woonderful-stock-sync-for-woocommerce' ),
	],
	[
		'discarded',
		__( 'Descartados', 'woonderful-stock-sync-for-woocommerce' ),
	],
];

const MOTIVE_TEXT = {
	no_sku: __( 'Sin SKU', 'woonderful-stock-sync-for-woocommerce' ),
	duplicate_sku: __(
		'SKU duplicado',
		'woonderful-stock-sync-for-woocommerce'
	),
	no_match: __( 'Sin coincidencia', 'woonderful-stock-sync-for-woocommerce' ),
	trashed: __( 'En la papelera', 'woonderful-stock-sync-for-woocommerce' ),
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

const hashParams = () =>
	new URLSearchParams( window.location.hash.split( '?' )[ 1 ] || '' );

// "Con sugerencia" es un motivo de "Sin vincular", no un estado: un enlace con
// `state=suggestion` aterriza ahí.
const initialState = () => {
	const state = hashParams().get( 'state' );
	if ( 'suggestion' === state ) {
		return 'unlinked';
	}
	return STATE_TABS.some( ( [ value ] ) => value === state ) ? state : 'all';
};

const initialMotive = () =>
	'suggestion' === hashParams().get( 'state' ) ? 'suggestion' : '';

// El nombre de una variación sin el del padre, que ya está en la fila de arriba.
const shortName = ( row ) =>
	row.parent > 0 && row.parent_name && row.name.startsWith( row.parent_name )
		? row.name
				.slice( row.parent_name.length )
				.replace( /^\s*[-–—]\s*/, '' ) || row.name
		: row.name;

export default function Products( { apiFetch, status } ) {
	const [ data, setData ] = useState( null );
	const [ page, setPage ] = useState( 1 );
	const [ state, setState ] = useState( initialState );
	const [ motive, setMotive ] = useState( initialMotive );
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ refreshing, setRefreshing ] = useState( null );
	const [ modal, setModal ] = useState( null );
	const [ bulk, setBulk ] = useState( null );
	const [ selected, setSelected ] = useState( {} );
	const [ history, setHistory ] = useState( null );
	const [ confirm, setConfirm ] = useState( null );
	const [ toast, setToast ] = useState( null );
	// Lo que el dueño abrió o cerró a mano, por padre; lo demás sigue la regla de abajo.
	const [ opened, setOpened ] = useState( {} );
	const autoRefreshed = useRef( false );
	const toastTimer = useRef( null );

	// La búsqueda espera a que se deje de escribir.
	useEffect( () => {
		const timer = setTimeout( () => {
			setQuery( search );
			setPage( 1 );
		}, 300 );
		return () => clearTimeout( timer );
	}, [ search ] );

	// Con otro filtro o búsqueda, vuelve a la regla: lo abierto a mano era para la vista anterior.
	useEffect( () => {
		setOpened( {} );
	}, [ state, motive, query ] );

	const say = ( text ) => {
		setToast( text );
		clearTimeout( toastTimer.current );
		toastTimer.current = setTimeout( () => setToast( null ), 3500 );
	};

	const load = useCallback( async () => {
		setLoading( true );
		setError( null );
		try {
			const params = new URLSearchParams( {
				page,
				state,
				motive,
				search: query,
				// Los descartados son un motivo más: sólo se piden al elegirlo.
				show_discarded: 'discarded' === motive ? '1' : '',
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
	}, [ apiFetch, page, state, motive, query ] );

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

	const act = async ( path, row, done ) => {
		setError( null );
		try {
			const result = await apiFetch( {
				path,
				method: 'POST',
				data: { local: row.id },
			} );
			replaceRow( row.id, result.row );
			say( done );
		} catch ( e ) {
			setError( e?.message );
		}
	};

	const linked = ( id, result ) => {
		setModal( null );
		replaceRow( id, result.row );
		if ( 'uncertain' === result.result ) {
			setError(
				__(
					'La otra tienda no respondió: no se sabe si lo vinculó. El plugin le pregunta solo dentro de 10 minutos.',
					'woonderful-stock-sync-for-woocommerce'
				)
			);
		} else {
			say(
				__(
					'Listo, quedaron vinculados. El stock se sincroniza desde ahora.',
					'woonderful-stock-sync-for-woocommerce'
				)
			);
		}
	};

	const actions = status?.store?.master
		? {
				link: ( row ) => setModal( { row, reassign: false } ),
				choose: ( row ) =>
					setModal( { row, reassign: false, choose: true } ),
				reassign: ( row ) => setModal( { row, reassign: true } ),
				unlink: ( row ) => setConfirm( row ),
				discard: ( row ) =>
					act(
						'/discard',
						row,
						__(
							'Sugerencia descartada.',
							'woonderful-stock-sync-for-woocommerce'
						)
					),
				undiscard: ( row ) =>
					act(
						'/undiscard',
						row,
						__(
							'Se deshizo el descarte.',
							'woonderful-stock-sync-for-woocommerce'
						)
					),
		  }
		: null;

	const pickState = ( value ) => {
		setState( value );
		setMotive( '' );
		setPage( 1 );
	};

	const clearFilters = () => {
		pickState( 'all' );
		setSearch( '' );
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
	const motives = data?.motives ?? {};
	const rows = data?.rows ?? [];

	// Sólo se tildan las que tienen sugerencia: es lo que vincula la masiva (ADR-014 §6).
	const selectable = actions
		? rows.filter( ( r ) => 'suggestion' === r.motive )
		: [];
	const toggle = ( list, on ) =>
		setSelected( ( prev ) => {
			const next = { ...prev };
			list.forEach( ( r ) => {
				if ( on ) {
					next[ r.id ] = { local: r.id, remote: r.suggestion.id };
				} else {
					delete next[ r.id ];
				}
			} );
			return next;
		} );
	const allOn = ( list ) =>
		list.length > 0 && list.every( ( r ) => selected[ r.id ] );
	const someOn = ( list ) => list.some( ( r ) => selected[ r.id ] );
	const picked = Object.values( selected );

	// Contraídos siempre, también con un filtro. Con una búsqueda, abiertos: lo que se busca
	// suele ser una variación, y se ve sin abrir cada padre.
	const openByDefault = '' !== query;
	const isOpen = ( group ) => opened[ group ] ?? openByDefault;

	const tabs =
		'unlinked' === state
			? MOTIVE_TABS
					// Un motivo sin productos no suma: se ve sólo si está elegido.
					.filter(
						( [ value ] ) =>
							'' === value ||
							motive === value ||
							( motives[ value ] ?? 0 ) > 0
					)
					.map( ( [ value, label ] ) => ( {
						key: value || 'all-motives',
						label,
						count:
							'' === value
								? counts.unlinked
								: motives[ value ] ?? 0,
						active: motive === value,
						onClick: () => {
							setMotive( value );
							setPage( 1 );
						},
					} ) )
			: STATE_TABS.map( ( [ value, label ] ) => ( {
					key: value,
					label,
					count: counts[ value ],
					active: state === value,
					onClick: () => pickState( value ),
			  } ) );

	return (
		<div className="wss-products">
			{ status && ! status.store.master && (
				<MasterNotice apiFetch={ apiFetch } status={ status } />
			) }

			<header className="wss-header">
				<div>
					<h1 className="wss-header__title">
						{ __(
							'Productos',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</h1>
					<p className="wss-header__subtitle">
						{ __(
							'Vinculá cada producto con su par en la otra tienda para que el stock se mantenga igual en las dos.',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</p>
				</div>
				<div className="wss-header__actions">
					<button
						className="woon-btn woon-btn--ghost"
						onClick={ refresh }
						disabled={ null !== refreshing }
					>
						<span className={ refreshing ? 'wss-spin' : '' }>
							<IconRefresh />
						</span>
						{ refreshing
							? sprintf(
									/* translators: 1: página, 2: de cuántas. */
									__(
										'Actualizando %1$d de %2$d…',
										'woonderful-stock-sync-for-woocommerce'
									),
									refreshing.page,
									refreshing.pages
							  )
							: __(
									'Actualizar catálogo',
									'woonderful-stock-sync-for-woocommerce'
							  ) }
					</button>
					{ actions && (
						<button
							className="woon-btn woon-btn--primary"
							onClick={ () => setBulk( 'all' ) }
							disabled={ null !== refreshing }
						>
							{ __(
								'Vinculación masiva',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</button>
					) }
				</div>
			</header>

			<div className="wss-toolbar">
				<div className="wss-tabs" role="tablist">
					{ 'unlinked' === state && (
						<button
							className="wss-tab wss-tab--back"
							onClick={ () => pickState( 'all' ) }
						>
							{ __(
								'← Todos los estados',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</button>
					) }
					{ 'unlinked' === state && (
						<span className="wss-tabs__label">
							{ __(
								'Sin vincular:',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</span>
					) }
					{ tabs.map( ( tab ) => (
						<button
							key={ tab.key }
							role="tab"
							aria-selected={ tab.active }
							className={ `wss-tab ${
								tab.active ? 'is-active' : ''
							}` }
							onClick={ tab.onClick }
						>
							{ tab.label }
							{ undefined !== tab.count && (
								<span className="wss-count">{ tab.count }</span>
							) }
						</button>
					) ) }
				</div>
				<div className="wss-search">
					<IconSearch />
					<input
						type="search"
						value={ search }
						placeholder={ __(
							'Buscar por nombre o SKU, en las dos tiendas',
							'woonderful-stock-sync-for-woocommerce'
						) }
						onChange={ ( e ) => setSearch( e.target.value ) }
					/>
				</div>
			</div>

			{ data?.catalog && (
				<p className="wss-meta">
					{ null === data.catalog.age
						? __(
								'Todavía no se trajo el catálogo de la otra tienda.',
								'woonderful-stock-sync-for-woocommerce'
						  )
						: sprintf(
								/* translators: %d: minutos. */
								__(
									'Catálogo de la otra tienda actualizado hace %d min.',
									'woonderful-stock-sync-for-woocommerce'
								),
								Math.floor( data.catalog.age / 60 )
						  ) }
				</p>
			) }

			{ error && (
				<div className="woon-notice woon-notice--error">
					<p>{ error }</p>
				</div>
			) }
			{ bulk && (
				<BulkModal
					apiFetch={ apiFetch }
					pairs={ Array.isArray( bulk ) ? bulk : null }
					onClose={ ( result ) => {
						setBulk( null );
						if ( result ) {
							setSelected( {} );
							load();
						}
					} }
				/>
			) }
			{ history && (
				<HistoryModal
					apiFetch={ apiFetch }
					row={ history }
					onClose={ () => setHistory( null ) }
				/>
			) }
			{ modal && (
				<LinkModal
					apiFetch={ apiFetch }
					row={ modal.row }
					reassign={ modal.reassign }
					choose={ modal.choose }
					onClose={ () => setModal( null ) }
					onDone={ ( result ) => linked( modal.row.id, result ) }
				/>
			) }
			{ confirm && (
				<ConfirmUnlink
					row={ confirm }
					onCancel={ () => setConfirm( null ) }
					onConfirm={ () => {
						const row = confirm;
						setConfirm( null );
						act(
							'/unlink',
							row,
							__(
								'Desvinculado.',
								'woonderful-stock-sync-for-woocommerce'
							)
						);
					} }
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

			{ data && 0 === rows.length && (
				<div className="woon-empty">
					<div className="woon-empty__title">
						{ __(
							'No hay productos que coincidan',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</div>
					<p className="woon-empty__text">
						{ __(
							'Probá con otro nombre o SKU, o cambiá el filtro.',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</p>
					<button
						className="woon-btn woon-btn--ghost"
						onClick={ clearFilters }
					>
						{ __(
							'Limpiar filtros',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</button>
				</div>
			) }

			{ rows.length > 0 && (
				// Anchos fijos: si no, al abrir o cerrar un padre las columnas se corren.
				<table className="woon-table wss-table">
					<colgroup>
						{ actions && <col style={ { width: 44 } } /> }
						<col />
						<col style={ { width: 72 } } />
						<col style={ { width: 32 } } />
						<col />
						<col style={ { width: 72 } } />
						<col style={ { width: 230 } } />
						<col style={ { width: 170 } } />
					</colgroup>
					<thead>
						<tr>
							{ actions && (
								<th>
									<Check
										checked={ allOn( selectable ) }
										indeterminate={
											! allOn( selectable ) &&
											someOn( selectable )
										}
										disabled={ 0 === selectable.length }
										onChange={ () =>
											toggle(
												selectable,
												! allOn( selectable )
											)
										}
										label={ __(
											'Seleccionar todas las sugerencias de la página',
											'woonderful-stock-sync-for-woocommerce'
										) }
									/>
								</th>
							) }
							<th>
								{ __(
									'Esta tienda',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</th>
							<th className="wss-r">
								{ __(
									'Stock',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</th>
							<th />
							<th>
								{ __(
									'La otra tienda',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</th>
							<th className="wss-r">
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
							<th />
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( row, index ) => {
							const previous = rows[ index - 1 ];
							const header =
								row.parent > 0 &&
								( ! previous || previous.group !== row.group );
							const open = isOpen( row.group );
							const group = rows.filter(
								( r ) => r.group === row.group
							);
							return [
								header && (
									<GroupRow
										key={ `g${ row.group }` }
										row={ row }
										open={ open }
										actions={ actions }
										summary={ data.summary?.[ row.group ] }
										selectable={ group.filter(
											( r ) => 'suggestion' === r.motive
										) }
										allOn={ allOn }
										someOn={ someOn }
										toggle={ toggle }
										onLinkSuggestions={ ( list ) =>
											setBulk(
												list.map( ( r ) => ( {
													local: r.id,
													remote: r.suggestion.id,
												} ) )
											)
										}
										onToggle={ () =>
											setOpened( ( prev ) => ( {
												...prev,
												[ row.group ]: ! open,
											} ) )
										}
									/>
								),
								( row.parent === 0 || open ) && (
									<Row
										key={ row.id }
										row={ row }
										actions={ actions }
										selected={ !! selected[ row.id ] }
										onSelect={
											'suggestion' === row.motive
												? ( on ) =>
														toggle( [ row ], on )
												: null
										}
										onHistory={ () => setHistory( row ) }
									/>
								),
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

			{ picked.length > 0 && (
				<div
					className="wss-bulkbar"
					role="region"
					aria-label={ __(
						'Acciones en lote',
						'woonderful-stock-sync-for-woocommerce'
					) }
				>
					<span>
						<b>{ picked.length }</b>{ ' ' }
						{ _n(
							'sugerencia seleccionada',
							'sugerencias seleccionadas',
							picked.length,
							'woonderful-stock-sync-for-woocommerce'
						) }
					</span>
					<div className="wss-bulkbar__acts">
						<button
							className="woon-btn wss-bulkbar__btn"
							onClick={ () => setBulk( picked ) }
						>
							{ __(
								'Vincular',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</button>
						<button
							className="woon-btn wss-bulkbar__ghost"
							onClick={ () => setSelected( {} ) }
						>
							{ __(
								'Cancelar',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</button>
					</div>
				</div>
			) }

			<div
				className={ `wss-toast ${ toast ? 'is-on' : '' }` }
				aria-live="polite"
			>
				{ toast }
			</div>
		</div>
	);
}

function Check( { checked, indeterminate, disabled, onChange, label } ) {
	const ref = useRef();
	useEffect( () => {
		if ( ref.current ) {
			ref.current.indeterminate = !! indeterminate;
		}
	}, [ indeterminate ] );
	return (
		<input
			ref={ ref }
			type="checkbox"
			className="wss-check"
			checked={ checked }
			disabled={ disabled }
			onChange={ onChange }
			onClick={ ( e ) => e.stopPropagation() }
			aria-label={ label }
		/>
	);
}

// El padre de unas variaciones: contraído, su avance y su estado dicen si hay algo por hacer.
function GroupRow( {
	row,
	open,
	actions,
	summary,
	selectable,
	allOn,
	someOn,
	toggle,
	onLinkSuggestions,
	onToggle,
} ) {
	const total = summary?.total ?? 0;
	const linked = summary?.linked ?? 0;
	let badge = [
		'muted',
		__( 'Sin vincular', 'woonderful-stock-sync-for-woocommerce' ),
	];
	if ( total > 0 && linked === total ) {
		badge = [
			'success',
			__( 'Vinculado', 'woonderful-stock-sync-for-woocommerce' ),
		];
	} else if ( total > 0 && ( summary?.suspended ?? 0 ) === total ) {
		badge = [
			'danger',
			__( 'Suspendido', 'woonderful-stock-sync-for-woocommerce' ),
		];
	} else if ( linked > 0 ) {
		badge = [
			'info',
			__( 'Parcial', 'woonderful-stock-sync-for-woocommerce' ),
		];
	}

	return (
		<tr className={ `wss-group-row ${ open ? 'is-open' : '' }` }>
			{ actions && (
				<td>
					{ selectable.length > 0 && (
						<Check
							checked={ allOn( selectable ) }
							indeterminate={
								! allOn( selectable ) && someOn( selectable )
							}
							onChange={ () =>
								toggle( selectable, ! allOn( selectable ) )
							}
							label={ sprintf(
								/* translators: %s: el producto. */
								__(
									'Seleccionar las variaciones de %s',
									'woonderful-stock-sync-for-woocommerce'
								),
								row.parent_name
							) }
						/>
					) }
				</td>
			) }
			<td colSpan={ 3 }>
				<button
					type="button"
					className="wss-group-toggle"
					aria-expanded={ open }
					onClick={ ( e ) => {
						e.stopPropagation();
						onToggle();
					} }
				>
					<span className="wss-chev" aria-hidden="true">
						<IconChevron />
					</span>
					<span>
						<span className="wss-group-toggle__name">
							{ row.parent_name ?? `#${ row.parent }` }
						</span>
						<span className="wss-group-toggle__meta">
							{ sprintf(
								/* translators: %d: cuántas. */
								_n(
									'Variable · %d variación',
									'Variable · %d variaciones',
									total,
									'woonderful-stock-sync-for-woocommerce'
								),
								total
							) }
						</span>
					</span>
				</button>
			</td>
			<td colSpan={ 2 }>
				<div className="wss-progress">
					<div className="wss-progress__track">
						<div
							className="wss-progress__bar"
							style={ {
								width: `${
									total ? ( linked / total ) * 100 : 0
								}%`,
							} }
						/>
					</div>
					<span className="wss-progress__txt">
						{ sprintf(
							/* translators: 1: vinculadas, 2: total. */
							__(
								'%1$d/%2$d vinculadas',
								'woonderful-stock-sync-for-woocommerce'
							),
							linked,
							total
						) }
					</span>
				</div>
			</td>
			<td>
				<span className="wss-badges">
					<span
						className={ `woon-badge woon-badge--${ badge[ 0 ] }` }
					>
						{ badge[ 1 ] }
					</span>
					{ selectable.length > 0 && <SuggestionBadge /> }
				</span>
			</td>
			<td className="wss-actions">
				{ actions && selectable.length > 0 && (
					<button
						className="woon-btn woon-btn--secondary woon-btn--sm"
						onClick={ ( e ) => {
							e.stopPropagation();
							onLinkSuggestions( selectable );
						} }
					>
						{ sprintf(
							/* translators: %d: cuántas sugerencias. */
							__(
								'Vincular %d',
								'woonderful-stock-sync-for-woocommerce'
							),
							selectable.length
						) }
					</button>
				) }
			</td>
		</tr>
	);
}

function Row( { row, actions, selected, onSelect, onHistory } ) {
	const [ badge, label ] = STATE_BADGE[ row.state ] ?? [ 'muted', row.state ];
	const other = row.remote;
	const on = 'linked' === row.state;
	// Vinculados con distinto stock: en tránsito, o una discrepancia. Vale mirarlo.
	const differs =
		other && null !== row.stock && null !== other.stock
			? row.stock !== other.stock
			: false;

	return (
		<tr className={ selected ? 'is-selected' : '' }>
			{ actions && (
				<td>
					{ onSelect && (
						<Check
							checked={ selected }
							onChange={ ( e ) => onSelect( e.target.checked ) }
							label={ sprintf(
								/* translators: %s: el producto. */
								__(
									'Seleccionar %s',
									'woonderful-stock-sync-for-woocommerce'
								),
								row.name
							) }
						/>
					) }
				</td>
			) }
			<td className={ row.parent > 0 ? 'wss-indent' : '' }>
				<button
					className="woon-btn-link wss-name"
					onClick={ onHistory }
					title={ __(
						'Ver el historial',
						'woonderful-stock-sync-for-woocommerce'
					) }
				>
					{ shortName( row ) }
				</button>
				<span className="wss-sku">
					{ row.sku || '—' } · #{ row.id }
				</span>
			</td>
			<td className="wss-r wss-num">{ row.stock ?? '—' }</td>
			<td>
				{ other && (
					<span
						className={ `wss-linkicon ${ on ? 'is-on' : '' }` }
						aria-hidden="true"
					>
						<IconLink />
					</span>
				) }
			</td>
			<td>
				{ other && (
					<>
						<span className={ `wss-other ${ on ? '' : 'is-dim' }` }>
							{ other.name ?? `#${ other.id }` }
						</span>
						<span className="wss-sku">
							{ other.sku || '—' } · #{ other.id }
						</span>
					</>
				) }
				{ ! other && row.suggestion && (
					<div className="wss-suggest">
						<span className="wss-other">
							{ row.suggestion.name }
						</span>
						<span className="wss-sku">
							{ __(
								'Sugerido · mismo SKU',
								'woonderful-stock-sync-for-woocommerce'
							) }{ ' ' }
							· #{ row.suggestion.id }
						</span>
					</div>
				) }
				{ ! other && ! row.suggestion && (
					<span className="wss-placeholder">
						{ __(
							'Sin par en la otra tienda',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</span>
				) }
			</td>
			<td className={ `wss-r wss-num ${ differs ? 'is-diff' : '' }` }>
				{ other?.stock ?? row.suggestion?.stock ?? '—' }
			</td>
			<td>
				<span className="wss-badges">
					<span className={ `woon-badge woon-badge--${ badge }` }>
						{ label }
					</span>
					{ 'suggestion' === row.motive && <SuggestionBadge /> }
				</span>
				{ ( row.reason ||
					( row.motive && 'suggestion' !== row.motive ) ) && (
					<span className="wss-why">
						{ row.reason ?? MOTIVE_TEXT[ row.motive ] }
					</span>
				) }
			</td>
			<td className="wss-actions">
				{ actions && <RowActions row={ row } actions={ actions } /> }
			</td>
		</tr>
	);
}

// Al lado del estado, no en su lugar: un producto con sugerencia sigue sin vincular.
function SuggestionBadge() {
	return (
		<span className="woon-badge woon-badge--brand">
			{ __( 'Con sugerencia', 'woonderful-stock-sync-for-woocommerce' ) }
		</span>
	);
}

function RowActions( { row, actions } ) {
	if ( 'stock_in_parent' === row.motive || 'trashed' === row.motive ) {
		return null;
	}

	if ( 'unlinked' !== row.state ) {
		return (
			<Menu
				items={ [
					[
						__(
							'Reasignar',
							'woonderful-stock-sync-for-woocommerce'
						),
						() => actions.reassign( row ),
					],
					[
						__(
							'Desvincular',
							'woonderful-stock-sync-for-woocommerce'
						),
						() => actions.unlink( row ),
						true,
					],
				] }
			/>
		);
	}

	if ( 'suggestion' === row.motive ) {
		return (
			<>
				<button
					className="woon-btn woon-btn--secondary woon-btn--sm"
					onClick={ () => actions.link( row ) }
				>
					{ __( 'Aceptar', 'woonderful-stock-sync-for-woocommerce' ) }
				</button>
				<Menu
					items={ [
						[
							__(
								'Elegir otro',
								'woonderful-stock-sync-for-woocommerce'
							),
							() => actions.choose( row ),
						],
						[
							__(
								'Descartar',
								'woonderful-stock-sync-for-woocommerce'
							),
							() => actions.discard( row ),
						],
					] }
				/>
			</>
		);
	}

	if ( 'discarded' === row.motive ) {
		return (
			<button
				className="woon-btn woon-btn--ghost woon-btn--sm"
				onClick={ () => actions.undiscard( row ) }
			>
				{ __(
					'Deshacer descarte',
					'woonderful-stock-sync-for-woocommerce'
				) }
			</button>
		);
	}

	return (
		<button
			className="woon-btn woon-btn--ghost woon-btn--sm"
			onClick={ () => actions.choose( row ) }
		>
			{ __( 'Vincular', 'woonderful-stock-sync-for-woocommerce' ) }
		</button>
	);
}

function Menu( { items } ) {
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

	return (
		<div className="wss-menu" ref={ ref }>
			<button
				className="wss-iconbtn"
				aria-label={ __(
					'Más acciones',
					'woonderful-stock-sync-for-woocommerce'
				) }
				aria-expanded={ open }
				onClick={ () => setOpen( ! open ) }
			>
				<IconDots />
			</button>
			{ open && (
				<div className="wss-pop">
					{ items.map( ( [ label, run, danger ] ) => (
						<button
							key={ label }
							className={ `wss-pop__item ${
								danger ? 'is-danger' : ''
							}` }
							onClick={ () => {
								setOpen( false );
								run();
							} }
						>
							{ label }
						</button>
					) ) }
				</div>
			) }
		</div>
	);
}

function ConfirmUnlink( { row, onCancel, onConfirm } ) {
	return (
		<div
			className="woon-overlay"
			role="presentation"
			onClick={ onCancel }
			onKeyDown={ ( e ) => e.key === 'Escape' && onCancel() }
		>
			<div
				className="woon-modal woon-modal--sm"
				role="presentation"
				onClick={ ( e ) => e.stopPropagation() }
				onKeyDown={ ( e ) => e.stopPropagation() }
			>
				<div className="woon-modal__body">
					<h2 className="wss-confirm__title">
						{ sprintf(
							/* translators: %s: el producto. */
							__(
								'¿Desvincular %s?',
								'woonderful-stock-sync-for-woocommerce'
							),
							row.name
						) }
					</h2>
					<p className="woon-muted">
						{ __(
							'El stock deja de sincronizarse entre las dos tiendas. El stock de cada una no se toca, y podés volver a vincular cuando quieras.',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</p>
				</div>
				<div className="woon-modal__foot">
					<button
						className="woon-btn woon-btn--ghost"
						onClick={ onCancel }
					>
						{ __(
							'Cancelar',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</button>
					<button
						className="woon-btn woon-btn--danger"
						onClick={ onConfirm }
					>
						{ __(
							'Desvincular',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</button>
				</div>
			</div>
		</div>
	);
}

// La secundaria no vincula: dice desde dónde se hace, con el enlace a la maestra (UC-05).
function MasterNotice( { apiFetch, status } ) {
	const [ peer, setPeer ] = useState( null );

	useEffect( () => {
		apiFetch( { path: '/peer' } )
			.then( setPeer )
			.catch( () => setPeer( {} ) );
	}, [ apiFetch ] );

	const label = peer?.label || status.peer.label;

	return (
		<div className="woon-notice woon-notice--info">
			<p>
				{ sprintf(
					/* translators: %s: la tienda maestra. */
					__(
						'Esta tienda es la secundaria: la vinculación se hace desde %s, la maestra. Acá se ve en modo lectura.',
						'woonderful-stock-sync-for-woocommerce'
					),
					label
				) }{ ' ' }
				{ peer?.admin_url && (
					<a href={ `${ peer.admin_url }#/products` }>
						{ sprintf(
							/* translators: %s: la tienda maestra. */
							__(
								'Ir a Productos en %s',
								'woonderful-stock-sync-for-woocommerce'
							),
							label
						) }
					</a>
				) }
			</p>
		</div>
	);
}

/* ── Íconos ─────────────────────────────────────────────────────────── */

function IconChevron() {
	return (
		<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true">
			<path
				d="M6 4l4 4-4 4"
				fill="none"
				stroke="currentColor"
				strokeWidth="1.6"
				strokeLinecap="round"
				strokeLinejoin="round"
			/>
		</svg>
	);
}

function IconLink() {
	return (
		<svg width="14" height="14" viewBox="0 0 16 16" aria-hidden="true">
			<path
				d="M6.5 9.5l3-3M7 4.5l1-1a2.8 2.8 0 014 4l-1 1M9 11.5l-1 1a2.8 2.8 0 01-4-4l1-1"
				fill="none"
				stroke="currentColor"
				strokeWidth="1.5"
				strokeLinecap="round"
			/>
		</svg>
	);
}

function IconDots() {
	return (
		<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true">
			<circle cx="3.5" cy="8" r="1.3" fill="currentColor" />
			<circle cx="8" cy="8" r="1.3" fill="currentColor" />
			<circle cx="12.5" cy="8" r="1.3" fill="currentColor" />
		</svg>
	);
}

function IconSearch() {
	return (
		<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true">
			<circle
				cx="7"
				cy="7"
				r="4.5"
				fill="none"
				stroke="currentColor"
				strokeWidth="1.5"
			/>
			<path
				d="M10.5 10.5L14 14"
				stroke="currentColor"
				strokeWidth="1.5"
				strokeLinecap="round"
			/>
		</svg>
	);
}

function IconRefresh() {
	return (
		<svg width="14" height="14" viewBox="0 0 16 16" aria-hidden="true">
			<path
				d="M13 8a5 5 0 11-1.5-3.6M13 2.5v2.8h-2.8"
				fill="none"
				stroke="currentColor"
				strokeWidth="1.5"
				strokeLinecap="round"
				strokeLinejoin="round"
			/>
		</svg>
	);
}
