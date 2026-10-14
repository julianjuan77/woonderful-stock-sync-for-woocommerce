import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';
import Pagination from '../components/Pagination';
import StockChange from '../components/StockChange';

const LEVELS = [
	[ '', __( 'Todos', 'woonderful-stock-sync-for-woocommerce' ) ],
	[ 'info', __( 'Info', 'woonderful-stock-sync-for-woocommerce' ) ],
	[ 'warning', __( 'Avisos', 'woonderful-stock-sync-for-woocommerce' ) ],
	[ 'error', __( 'Errores', 'woonderful-stock-sync-for-woocommerce' ) ],
];

const PERIODS = [
	[ 1, __( 'Hoy', 'woonderful-stock-sync-for-woocommerce' ) ],
	[ 7, __( '7 días', 'woonderful-stock-sync-for-woocommerce' ) ],
	[ 30, __( '30 días', 'woonderful-stock-sync-for-woocommerce' ) ],
	[ 0, __( 'Todo', 'woonderful-stock-sync-for-woocommerce' ) ],
];

// Los motivos de un movimiento, para el filtro de la pestaña Movimientos (ADR-023).
const REASONS = [
	[ 'sale', __( 'Venta', 'woonderful-stock-sync-for-woocommerce' ) ],
	[ 'cancel', __( 'Cancelación', 'woonderful-stock-sync-for-woocommerce' ) ],
	[ 'refund', __( 'Devolución', 'woonderful-stock-sync-for-woocommerce' ) ],
	[ 'adjustment', __( 'Ajuste', 'woonderful-stock-sync-for-woocommerce' ) ],
	[
		'bootstrap',
		__(
			'Stock inicial del vínculo',
			'woonderful-stock-sync-for-woocommerce'
		),
	],
	[
		'unlink',
		__( 'Desvinculación', 'woonderful-stock-sync-for-woocommerce' ),
	],
];

// Cómo terminó un movimiento, sólo cuando no terminó bien: entregado y aplicado no se dicen.
const MOVEMENT_STATES = {
	// Los del stock inicial de un vínculo: en camino, sin confirmar, o rechazado por la otra.
	syncing: [
		'info',
		__( 'esperando', 'woonderful-stock-sync-for-woocommerce' ),
	],
	uncertain: [
		'info',
		__( 'esperando', 'woonderful-stock-sync-for-woocommerce' ),
	],
	failed: [
		'error',
		__( 'rechazado', 'woonderful-stock-sync-for-woocommerce' ),
	],
	pending: [
		'info',
		__( 'esperando', 'woonderful-stock-sync-for-woocommerce' ),
	],
	in_flight: [
		'info',
		__( 'esperando', 'woonderful-stock-sync-for-woocommerce' ),
	],
	abandoned: [
		'error',
		__( 'abandonado', 'woonderful-stock-sync-for-woocommerce' ),
	],
	held: [
		'warning',
		__( 'retenido', 'woonderful-stock-sync-for-woocommerce' ),
	],
	discarded: [
		'warning',
		__( 'descartado', 'woonderful-stock-sync-for-woocommerce' ),
	],
};

const movementState = ( entry ) =>
	MOVEMENT_STATES[ entry.context?.delivery ] ??
	MOVEMENT_STATES[ entry.context?.application ] ??
	null;

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
	paused: __( 'En pausa', 'woonderful-stock-sync-for-woocommerce' ),
	resumed: __( 'Reanudada', 'woonderful-stock-sync-for-woocommerce' ),
	unpaired: __( 'Desemparejadas', 'woonderful-stock-sync-for-woocommerce' ),
	held_applied: __(
		'Retenidas aplicadas',
		'woonderful-stock-sync-for-woocommerce'
	),
	held_discarded: __(
		'Retenidas sin aplicar',
		'woonderful-stock-sync-for-woocommerce'
	),
	managed_again: __(
		'Stock reactivado',
		'woonderful-stock-sync-for-woocommerce'
	),
	cross_diff: __(
		'Stock distinto entre tiendas',
		'woonderful-stock-sync-for-woocommerce'
	),
	cross_diff_closed: __(
		'Stock igual de nuevo',
		'woonderful-stock-sync-for-woocommerce'
	),
	equalized: __( 'Stock igualado', 'woonderful-stock-sync-for-woocommerce' ),
};

// Lo que vuelve a la normalidad lleva el tilde, aunque su nivel sea info.
const RECOVERED = [
	'resumed',
	'peer_back',
	'peer_accepted',
	'pair_restored',
	'discrepancy_closed',
	'cross_diff_closed',
];

// Los que suelen venir de a muchos —una vinculación masiva, un resumen—: seguidos y en
// menos de 3 minutos, van en un solo renglón que se abre.
const GROUPED = {
	linked: ( n ) =>
		sprintf(
			/* translators: %d: cuántos productos. */
			_n(
				'Se vinculó %d producto.',
				'Se vincularon %d productos.',
				n,
				'woonderful-stock-sync-for-woocommerce'
			),
			n
		),
	unlinked: ( n ) =>
		sprintf(
			/* translators: %d: cuántos productos. */
			_n(
				'Se desvinculó %d producto.',
				'Se desvincularon %d productos.',
				n,
				'woonderful-stock-sync-for-woocommerce'
			),
			n
		),
	discarded: ( n ) =>
		sprintf(
			/* translators: %d: cuántos productos. */
			_n(
				'Se descartó %d producto.',
				'Se descartaron %d productos.',
				n,
				'woonderful-stock-sync-for-woocommerce'
			),
			n
		),
	link_failed: ( n ) =>
		sprintf(
			/* translators: %d: cuántas. */
			_n(
				'Falló %d vinculación.',
				'Fallaron %d vinculaciones.',
				n,
				'woonderful-stock-sync-for-woocommerce'
			),
			n
		),
	notified: ( n ) =>
		sprintf(
			/* translators: %d: cuántos productos. */
			_n(
				'%d producto avisado.',
				'%d productos avisados.',
				n,
				'woonderful-stock-sync-for-woocommerce'
			),
			n
		),
};

const GROUP_WINDOW = 180;

// Lo del contexto que se lee en el detalle; lo demás, en "Ver JSON".
const CONTEXT_LABELS = {
	remote: [
		__( 'En la otra tienda', 'woonderful-stock-sync-for-woocommerce' ),
		( v ) => `#${ v }`,
	],
	error: [ __( 'Error', 'woonderful-stock-sync-for-woocommerce' ) ],
	minutes: [
		__( 'Minutos sin responder', 'woonderful-stock-sync-for-woocommerce' ),
	],
	count: [ __( 'Productos', 'woonderful-stock-sync-for-woocommerce' ) ],
	total: [ __( 'En total', 'woonderful-stock-sync-for-woocommerce' ) ],
	expected: [
		__( 'El libro decía', 'woonderful-stock-sync-for-woocommerce' ),
	],
	found: [ __( 'Había', 'woonderful-stock-sync-for-woocommerce' ) ],
	difference: [ __( 'Diferencia', 'woonderful-stock-sync-for-woocommerce' ) ],
};

// Filtros iniciales desde el hash (ej. #/logs?level=error o #/logs?product=12), para que la
// navegación desde otra pantalla aterrice ya filtrada.
const getInitialFilters = () => {
	const params = new URLSearchParams(
		window.location.hash.split( '?' )[ 1 ] || ''
	);
	return {
		view: 'movements' === params.get( 'view' ) ? 'movements' : 'events',
		level: params.get( 'level' ) || '',
		kind: params.get( 'kind' ) || '',
		reason: '',
		days: 0,
		search: '',
		product: params.get( 'product' ) || '',
	};
};

const pad = ( n ) => String( n ).padStart( 2, '0' );
const ymd = ( d ) =>
	`${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) }`;
const seconds = ( at ) => new Date( at.replace( ' ', 'T' ) ).getTime() / 1000;

function dayLabel( day ) {
	const today = new Date();
	const yesterday = new Date();
	yesterday.setDate( today.getDate() - 1 );

	if ( day === ymd( today ) ) {
		return __( 'Hoy', 'woonderful-stock-sync-for-woocommerce' );
	}
	if ( day === ymd( yesterday ) ) {
		return __( 'Ayer', 'woonderful-stock-sync-for-woocommerce' );
	}
	return new Date( `${ day }T00:00:00` ).toLocaleDateString( undefined, {
		day: 'numeric',
		month: 'short',
	} );
}

// Las entradas de la página, por día, con los seguidos del mismo tipo juntos.
function byDay( entries ) {
	const days = [];

	entries.forEach( ( entry, index ) => {
		const key = entry.id ?? `m${ index }`;
		const day = entry.at.slice( 0, 10 );
		let current = days[ days.length - 1 ];

		if ( ! current || current.day !== day ) {
			current = { day, items: [] };
			days.push( current );
		}

		const last = current.items[ current.items.length - 1 ];
		const lastEntry = last?.group
			? last.group[ last.group.length - 1 ].entry
			: last?.entry;

		if (
			last &&
			GROUPED[ entry.kind ] &&
			lastEntry.kind === entry.kind &&
			seconds( lastEntry.at ) - seconds( entry.at ) <= GROUP_WINDOW
		) {
			if ( ! last.group ) {
				last.group = [ { key: last.key, entry: last.entry } ];
				last.key = `g${ last.key }`;
			}
			last.group.push( { key, entry } );
		} else {
			current.items.push( { key, entry } );
		}
	} );

	return days;
}

export default function Logs( { apiFetch, navigate } ) {
	const [ entries, setEntries ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ levels, setLevels ] = useState( null );
	const [ name, setName ] = useState( null );
	const [ page, setPage ] = useState( 1 );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ open, setOpen ] = useState( {} );
	const [ filters, setFilters ] = useState( getInitialFilters );
	const [ search, setSearch ] = useState( '' );
	const searchTimer = useRef();
	const lastFetch = useRef( 0 );

	const fetchLogs = useCallback( async () => {
		// Sólo vale la respuesta del último pedido: al cambiar de pestaña, una más lenta de la
		// otra llegaría después y mostraría avisos en Movimientos.
		const ticket = ++lastFetch.current;
		setLoading( true );
		setError( null );
		try {
			const { days, view, level, kind, reason, ...rest } = filters;
			const movements = 'movements' === view && '' === rest.product;
			const query = movements
				? { page, reason, ...rest }
				: { page, level, kind, ...rest };
			if ( days > 0 ) {
				const from = new Date();
				from.setDate( from.getDate() - ( days - 1 ) );
				query.from = ymd( from );
			}
			const params = new URLSearchParams(
				Object.entries( query ).filter(
					( [ , value ] ) => '' !== value
				)
			);
			const data = await apiFetch( {
				path: `${ movements ? '/movements' : '/log' }?${ params }`,
			} );
			if ( ticket !== lastFetch.current ) {
				return;
			}
			setEntries( data.items );
			setTotal( data.total );
			setLevels( data.levels ?? null );
			setName( data.name ?? null );
		} catch ( e ) {
			if ( ticket !== lastFetch.current ) {
				return;
			}
			setError(
				e?.message ||
					__(
						'Error al cargar registros.',
						'woonderful-stock-sync-for-woocommerce'
					)
			);
		} finally {
			if ( ticket === lastFetch.current ) {
				setLoading( false );
			}
		}
	}, [ apiFetch, page, filters ] );

	useEffect( () => {
		fetchLogs();
	}, [ fetchLogs ] );

	useEffect( () => () => clearTimeout( searchTimer.current ), [] );

	const updateFilter = ( key, value ) => {
		setFilters( ( prev ) => ( { ...prev, [ key ]: value } ) );
		setPage( 1 );
		setOpen( {} );
	};

	const onSearch = ( value ) => {
		setSearch( value );
		clearTimeout( searchTimer.current );
		searchTimer.current = setTimeout(
			() => updateFilter( 'search', value.trim() ),
			300
		);
	};

	const clearFilters = () => {
		setSearch( '' );
		setFilters( ( prev ) => ( {
			...prev,
			level: '',
			kind: '',
			reason: '',
			days: 0,
			search: '',
		} ) );
		setPage( 1 );
	};

	// La pestaña va en la dirección: al recargar, sigue en la misma.
	const viewParams = ( view ) =>
		'movements' === view ? { view: 'movements' } : undefined;

	const pickView = ( view ) => {
		navigate( 'logs', viewParams( view ) );
		updateFilter( 'view', view );
	};

	const showAll = () => {
		navigate( 'logs', viewParams( filters.view ) );
		updateFilter( 'product', '' );
	};

	const showHistory = ( product ) => {
		navigate( 'logs', { product } );
		updateFilter( 'product', String( product ) );
	};

	const toggle = ( key ) =>
		setOpen( ( prev ) => ( { ...prev, [ key ]: ! prev[ key ] } ) );

	const history = '' !== filters.product;
	const movements = 'movements' === filters.view && ! history;

	let subtitle = __(
		'Lo que pasó entre las dos tiendas: vínculos, reconciliaciones, discrepancias y cortes de conexión.',
		'woonderful-stock-sync-for-woocommerce'
	);
	let emptyTitle = __(
		'No hay registros con estos filtros',
		'woonderful-stock-sync-for-woocommerce'
	);
	if ( history ) {
		subtitle = __(
			'Sus movimientos de stock y lo que le pasó, de lo más nuevo a lo más viejo.',
			'woonderful-stock-sync-for-woocommerce'
		);
		emptyTitle = __(
			'Todavía no tiene movimientos ni eventos',
			'woonderful-stock-sync-for-woocommerce'
		);
	} else if ( movements ) {
		subtitle = __(
			'Cada movimiento de stock de esta tienda: lo que pasó acá y lo que llegó de la otra.',
			'woonderful-stock-sync-for-woocommerce'
		);
		emptyTitle = __(
			'No hay movimientos con estos filtros',
			'woonderful-stock-sync-for-woocommerce'
		);
	}
	const counts = levels
		? { '': levels.info + levels.warning + levels.error, ...levels }
		: {};

	return (
		<div className="wss-logs">
			<header className="wss-header">
				<div>
					<h1 className="wss-header__title">
						{ history
							? sprintf(
									/* translators: %s: el producto. */
									__(
										'Historial de %s',
										'woonderful-stock-sync-for-woocommerce'
									),
									name ?? `#${ filters.product }`
							  )
							: __(
									'Registros',
									'woonderful-stock-sync-for-woocommerce'
							  ) }
					</h1>
					<p className="wss-header__subtitle">{ subtitle }</p>
				</div>
				{ history && (
					<div className="wss-header__actions">
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
				) }
			</header>

			{ ! history && (
				<div className="wss-tabs wss-log-tabs" role="tablist">
					{ [
						[
							'events',
							__(
								'Avisos',
								'woonderful-stock-sync-for-woocommerce'
							),
						],
						[
							'movements',
							__(
								'Movimientos',
								'woonderful-stock-sync-for-woocommerce'
							),
						],
					].map( ( [ value, label ] ) => (
						<button
							key={ value }
							role="tab"
							aria-selected={ filters.view === value }
							className={ `wss-tab ${
								filters.view === value ? 'is-active' : ''
							}` }
							onClick={ () => pickView( value ) }
						>
							{ label }
						</button>
					) ) }
				</div>
			) }

			{ movements && (
				<div className="wss-log-filters">
					<div className="wss-select">
						<select
							aria-label={ __(
								'Motivo',
								'woonderful-stock-sync-for-woocommerce'
							) }
							value={ filters.reason }
							onChange={ ( e ) =>
								updateFilter( 'reason', e.target.value )
							}
						>
							<option value="">
								{ __(
									'Todos los motivos',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</option>
							{ REASONS.map( ( [ value, label ] ) => (
								<option key={ value } value={ value }>
									{ label }
								</option>
							) ) }
						</select>
					</div>
					<Periods
						filters={ filters }
						updateFilter={ updateFilter }
					/>
					<SearchBox search={ search } onSearch={ onSearch } />
				</div>
			) }

			{ ! history && ! movements && (
				<div className="wss-log-filters">
					<div
						className="wss-seg"
						role="group"
						aria-label={ __(
							'Nivel',
							'woonderful-stock-sync-for-woocommerce'
						) }
					>
						{ LEVELS.map( ( [ value, label ] ) => (
							<button
								key={ value || 'all' }
								className={ `wss-seg__btn ${
									filters.level === value ? 'is-on' : ''
								}` }
								aria-pressed={ filters.level === value }
								onClick={ () => updateFilter( 'level', value ) }
							>
								{ value && (
									<span
										className={ `wss-dot wss-dot--${ value }` }
									/>
								) }
								{ label }
								{ levels && (
									<span className="wss-count">
										{ counts[ value ] }
									</span>
								) }
							</button>
						) ) }
					</div>
					<div className="wss-select">
						<select
							aria-label={ __(
								'Tipo',
								'woonderful-stock-sync-for-woocommerce'
							) }
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
							{ Object.entries( KIND_LABELS ).map(
								( [ value, label ] ) => (
									<option key={ value } value={ value }>
										{ label }
									</option>
								)
							) }
						</select>
					</div>
					<Periods
						filters={ filters }
						updateFilter={ updateFilter }
					/>
					<SearchBox search={ search } onSearch={ onSearch } />
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
				<div className="woon-card">
					<div className="woon-empty">
						<h3 className="woon-empty__title">{ emptyTitle }</h3>
						{ ! history && (
							<>
								<p className="woon-empty__text">
									{ __(
										'Probá con otro período o tipo.',
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
							</>
						) }
					</div>
				</div>
			) }

			{ ! loading &&
				byDay( entries ).map( ( { day, items } ) => (
					<section key={ day } className="wss-log-day">
						<h2 className="wss-log-day__title">
							{ dayLabel( day ) }
							<span>{ day }</span>
						</h2>
						<div className="wss-log-list">
							{ items.map( ( item ) =>
								item.group ? (
									<Group
										key={ item.key }
										item={ item }
										open={ open }
										toggle={ toggle }
										onHistory={ showHistory }
										inHistory={ history }
									/>
								) : (
									<Entry
										key={ item.key }
										entry={ item.entry }
										open={ !! open[ item.key ] }
										onToggle={ () => toggle( item.key ) }
										onHistory={ showHistory }
										inHistory={ history }
									/>
								)
							) }
						</div>
					</section>
				) ) }

			{ ! history && (
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

function Periods( { filters, updateFilter } ) {
	return (
		<div
			className="wss-seg"
			role="group"
			aria-label={ __(
				'Período',
				'woonderful-stock-sync-for-woocommerce'
			) }
		>
			{ PERIODS.map( ( [ days, label ] ) => (
				<button
					key={ days }
					className={ `wss-seg__btn ${
						filters.days === days ? 'is-on' : ''
					}` }
					aria-pressed={ filters.days === days }
					onClick={ () => updateFilter( 'days', days ) }
				>
					{ label }
				</button>
			) ) }
		</div>
	);
}

function SearchBox( { search, onSearch } ) {
	return (
		<div className="wss-search wss-log-search">
			<IconSearch />
			<input
				type="search"
				value={ search }
				aria-label={ __(
					'Buscar producto',
					'woonderful-stock-sync-for-woocommerce'
				) }
				placeholder={ __(
					'Producto, SKU o ID',
					'woonderful-stock-sync-for-woocommerce'
				) }
				onChange={ ( e ) => onSearch( e.target.value ) }
			/>
		</div>
	);
}

function Group( { item, open, toggle, onHistory, inHistory } ) {
	const first = item.group[ 0 ].entry;
	const last = item.group[ item.group.length - 1 ].entry;
	const isOpen = !! open[ item.key ];

	return (
		<div className={ `wss-log-item ${ isOpen ? 'is-open' : '' }` }>
			<button
				className="wss-log-row"
				onClick={ () => toggle( item.key ) }
				aria-expanded={ isOpen }
			>
				<span className="wss-log-row__time">
					{ first.at.slice( 11 ) }
				</span>
				<Level level={ first.level } />
				<span className="wss-log-row__type">
					{ KIND_LABELS[ first.kind ] ?? first.kind }
				</span>
				<span className="wss-log-row__text">
					{ GROUPED[ first.kind ]( item.group.length ) }
					<span className="wss-log-row__extra">
						{ last.at.slice( 11, 16 ) } –{ ' ' }
						{ first.at.slice( 11, 16 ) }
					</span>
				</span>
				<span className="wss-log-row__stack">
					{ item.group.length }
				</span>
			</button>
			{ isOpen && (
				<div className="wss-log-children">
					{ item.group.map( ( { key, entry } ) => (
						<Entry
							key={ key }
							entry={ entry }
							child
							open={ !! open[ key ] }
							onToggle={ () => toggle( key ) }
							onHistory={ onHistory }
							inHistory={ inHistory }
						/>
					) ) }
				</div>
			) }
		</div>
	);
}

function Entry( { entry, child, open, onToggle, onHistory, inHistory } ) {
	const movement = 'movement' === entry.type;
	const state = movement ? movementState( entry ) : null;
	const level = RECOVERED.includes( entry.kind )
		? 'ok'
		: entry.level ?? state?.[ 0 ] ?? 'info';
	// En la pestaña Movimientos el producto no se sobreentiende, como en su historial.
	let product = null;
	if ( movement && ! inHistory ) {
		product =
			entry.name ??
			( entry.product
				? `#${ entry.product }`
				: sprintf(
						/* translators: %d: el producto de la otra tienda. */
						__(
							'#%d de la otra tienda',
							'woonderful-stock-sync-for-woocommerce'
						),
						entry.remote_product
				  ) );
	}
	const extra =
		'peer_back' === entry.kind && entry.context?.minutes
			? sprintf(
					/* translators: %d: minutos. */
					__(
						'Sin responder %d min',
						'woonderful-stock-sync-for-woocommerce'
					),
					entry.context.minutes
			  )
			: null;

	return (
		<div
			className={ `wss-log-item ${ open ? 'is-open' : '' } ${
				child ? 'is-child' : ''
			}` }
		>
			<button
				className="wss-log-row"
				onClick={ onToggle }
				aria-expanded={ open }
			>
				<span className="wss-log-row__time">
					{ entry.at.slice( 11 ) }
				</span>
				<Level level={ level } />
				<span className="wss-log-row__type">
					{ movement
						? __(
								'Movimiento',
								'woonderful-stock-sync-for-woocommerce'
						  )
						: KIND_LABELS[ entry.kind ] ?? entry.kind }
				</span>
				<span className="wss-log-row__text">
					{ product && (
						<b
							className={ `wss-log-row__product ${
								entry.product ? 'is-link' : ''
							}` }
							role={ entry.product ? 'link' : undefined }
							tabIndex={ entry.product ? 0 : undefined }
							onClick={ ( e ) => {
								if ( entry.product ) {
									e.stopPropagation();
									onHistory( entry.product );
								}
							} }
							onKeyDown={ ( e ) => {
								if ( entry.product && 'Enter' === e.key ) {
									e.stopPropagation();
									onHistory( entry.product );
								}
							} }
						>
							{ product }
						</b>
					) }
					{ entry.text }
					<StockChange stocks={ entry.stocks } />
					{ state && (
						<span className="wss-log-row__extra">
							{ state[ 1 ] }
						</span>
					) }
					{ extra && (
						<span className="wss-log-row__extra">{ extra }</span>
					) }
				</span>
				<span className="wss-log-row__chev">
					<IconChevron />
				</span>
			</button>
			{ open && (
				<Detail
					entry={ entry }
					onHistory={ onHistory }
					inHistory={ inHistory }
				/>
			) }
		</div>
	);
}

function Detail( { entry, onHistory, inHistory } ) {
	const [ raw, setRaw ] = useState( false );
	const [ copied, setCopied ] = useState( false );
	const context = entry.context ?? {};
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
		...Object.entries( context )
			.filter(
				( [ key, value ] ) =>
					CONTEXT_LABELS[ key ] &&
					null !== value &&
					'object' !== typeof value
			)
			.map( ( [ key, value ] ) => {
				const [ label, format = String ] = CONTEXT_LABELS[ key ];
				return [ label, format( value ) ];
			} ),
	].filter( ( [ , value ] ) => value );

	const json = JSON.stringify( entry, null, 2 );

	const copy = async () => {
		try {
			await window.navigator.clipboard.writeText( json );
			setCopied( true );
			setTimeout( () => setCopied( false ), 2000 );
		} catch ( e ) {
			setRaw( true );
		}
	};

	return (
		<div className="wss-log-detail">
			{ rows.length > 0 && (
				<dl className="wss-log-kv">
					{ rows.map( ( [ label, value ] ) => (
						<div key={ label } className="wss-log-kv__row">
							<dt>{ label }</dt>
							<dd>{ value }</dd>
						</div>
					) ) }
				</dl>
			) }
			<div className="wss-log-detail__actions">
				{ entry.product && ! inHistory && (
					<button
						className="wss-link"
						onClick={ () => onHistory( entry.product ) }
					>
						{ __(
							'Ver el historial del producto',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</button>
				) }
				<button className="wss-link" onClick={ () => setRaw( ! raw ) }>
					{ raw
						? __(
								'Ocultar JSON',
								'woonderful-stock-sync-for-woocommerce'
						  )
						: __(
								'Ver JSON',
								'woonderful-stock-sync-for-woocommerce'
						  ) }
				</button>
				<button className="wss-link" onClick={ copy }>
					{ copied
						? __(
								'Copiado',
								'woonderful-stock-sync-for-woocommerce'
						  )
						: __(
								'Copiar',
								'woonderful-stock-sync-for-woocommerce'
						  ) }
				</button>
			</div>
			{ raw && <pre className="wss-log-raw">{ json }</pre> }
		</div>
	);
}

function Level( { level } ) {
	const labels = {
		info: __( 'Info', 'woonderful-stock-sync-for-woocommerce' ),
		ok: __( 'Info', 'woonderful-stock-sync-for-woocommerce' ),
		warning: __( 'Aviso', 'woonderful-stock-sync-for-woocommerce' ),
		error: __( 'Error', 'woonderful-stock-sync-for-woocommerce' ),
	};
	const marks = { ok: '✓', warning: '!', error: '!' };

	return (
		<span
			className={ `wss-level wss-level--${ level }` }
			title={ labels[ level ] }
		>
			{ marks[ level ] ?? '' }
		</span>
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

function IconChevron() {
	return (
		<svg width="14" height="14" viewBox="0 0 16 16" aria-hidden="true">
			<path
				d="M4 6l4 4 4-4"
				fill="none"
				stroke="currentColor"
				strokeWidth="1.6"
				strokeLinecap="round"
				strokeLinejoin="round"
			/>
		</svg>
	);
}
