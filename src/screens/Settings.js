import { useState, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import Pairing from '../components/Pairing';
import Requirements from '../components/Requirements';

export default function Settings( { apiFetch, status, onStatus } ) {
	const [ saved, setSaved ] = useState( null );
	const [ form, setForm ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ repairing, setRepairing ] = useState( false );
	const [ requirements, setRequirements ] = useState( false );
	const [ toast, setToast ] = useState( null );
	const toastTimer = useRef();

	const [ loaded, setLoaded ] = useState( null );

	useEffect( () => {
		apiFetch( { path: '/settings' } )
			.then( setLoaded )
			.catch( ( e ) => setError( e?.message ) );
	}, [ apiFetch ] );

	// El formulario arranca cuando están los ajustes y el estado, que trae el nombre: al
	// entrar directo a esta pantalla, los ajustes pueden llegar antes.
	const storeLabel = status?.store.label;
	useEffect( () => {
		if ( loaded && undefined !== storeLabel && null === saved ) {
			const start = { ...loaded, label: storeLabel };
			setSaved( start );
			setForm( start );
		}
	}, [ loaded, storeLabel, saved ] );

	useEffect( () => () => clearTimeout( toastTimer.current ), [] );

	const say = ( text ) => {
		setToast( text );
		clearTimeout( toastTimer.current );
		toastTimer.current = setTimeout( () => setToast( null ), 4000 );
	};

	const set = ( key, value ) =>
		setForm( ( prev ) => ( { ...prev, [ key ]: value } ) );

	const dirty =
		form && saved && JSON.stringify( form ) !== JSON.stringify( saved );

	const save = async () => {
		setSaving( true );
		setError( null );
		try {
			const { label, ...settings } = form;
			const next = await apiFetch( {
				path: '/settings',
				method: 'POST',
				data: settings,
			} );
			if ( label !== saved.label ) {
				onStatus(
					await apiFetch( {
						path: '/store',
						method: 'POST',
						data: { label },
					} )
				);
			}
			const stored = { ...next, label };
			setSaved( stored );
			setForm( stored );
			say(
				__(
					'Cambios guardados.',
					'woonderful-stock-sync-for-woocommerce'
				)
			);
		} catch ( err ) {
			setError( err?.message );
		} finally {
			setSaving( false );
		}
	};

	const onPaired = ( next ) => {
		setRepairing( false );
		onStatus( next );
	};

	if ( ! form || ! status ) {
		return error ? (
			<div className="woon-notice woon-notice--error">
				<p>{ error }</p>
			</div>
		) : (
			<div className="woon-loading">
				{ __( 'Cargando…', 'woonderful-stock-sync-for-woocommerce' ) }
			</div>
		);
	}

	return (
		<div className="wss-settings">
			<header className="wss-header">
				<div>
					<h1 className="wss-header__title">
						{ __(
							'Configuración',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</h1>
					<p className="wss-header__subtitle">
						{ __(
							'La conexión con la otra tienda, los avisos y qué pasa con tus datos.',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</p>
				</div>
			</header>

			<div className="wss-settings__wrap">
				<section>
					<h2 className="wss-settings__title">
						{ __(
							'Emparejamiento',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</h2>
					{ status.paired && ! repairing ? (
						<Connection
							apiFetch={ apiFetch }
							status={ status }
							onStatus={ onStatus }
							onRepair={ () => setRepairing( true ) }
							say={ say }
						/>
					) : (
						<div className="woon-card wss-pairing">
							{ repairing && (
								<div className="woon-notice woon-notice--warning">
									<p>
										{ __(
											'Emparejar de nuevo reemplaza el emparejamiento actual. Si volvés a emparejar con la misma tienda, los vínculos se mantienen.',
											'woonderful-stock-sync-for-woocommerce'
										) }
									</p>
								</div>
							) }
							<Pairing
								apiFetch={ apiFetch }
								status={ status }
								onStatus={ onPaired }
							/>
							{ repairing && (
								<button
									className="woon-btn woon-btn--ghost woon-btn--sm"
									onClick={ () => setRepairing( false ) }
								>
									{ __(
										'Cancelar',
										'woonderful-stock-sync-for-woocommerce'
									) }
								</button>
							) }
						</div>
					) }
				</section>

				<section>
					<h2 className="wss-settings__title">
						{ __(
							'Esta tienda',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</h2>
					<div className="woon-card wss-settings__card">
						<Field
							id="wss-label"
							title={ __(
								'Nombre',
								'woonderful-stock-sync-for-woocommerce'
							) }
							help={ __(
								'Así la nombran los avisos y la otra tienda. Si lo cambiás, la otra lo recibe al guardar.',
								'woonderful-stock-sync-for-woocommerce'
							) }
						>
							<input
								id="wss-label"
								className="wss-input"
								value={ form.label }
								onChange={ ( e ) =>
									set( 'label', e.target.value )
								}
							/>
						</Field>
					</div>
				</section>

				<section>
					<h2 className="wss-settings__title">
						{ __(
							'Avisos por email',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</h2>
					<div className="woon-card wss-settings__card">
						<Toggle
							title={ __(
								'Avisarme por email',
								'woonderful-stock-sync-for-woocommerce'
							) }
							help={
								form.notify_enabled
									? __(
											'Cuando la otra tienda no responde, cuando algo falla y cuando hay productos que no sincronizan.',
											'woonderful-stock-sync-for-woocommerce'
									  )
									: __(
											'Apagado. Todo se sigue registrando en Registros.',
											'woonderful-stock-sync-for-woocommerce'
									  )
							}
							on={ form.notify_enabled }
							onChange={ ( on ) => set( 'notify_enabled', on ) }
						/>
						<div
							className={ `wss-settings__dep ${
								form.notify_enabled ? '' : 'is-off'
							}` }
						>
							<Field
								id="wss-notify-email"
								title={ __(
									'A quién',
									'woonderful-stock-sync-for-woocommerce'
								) }
								help={ __(
									'Vacío, al email del administrador.',
									'woonderful-stock-sync-for-woocommerce'
								) }
							>
								<input
									id="wss-notify-email"
									type="email"
									className="wss-input"
									placeholder={ form.admin_email }
									value={ form.notify_email }
									disabled={ ! form.notify_enabled }
									onChange={ ( e ) =>
										set( 'notify_email', e.target.value )
									}
								/>
							</Field>
							<Field
								id="wss-notify-after"
								title={ __(
									'Plazo de lo que no sincroniza',
									'woonderful-stock-sync-for-woocommerce'
								) }
								help={ __(
									'Horas que un producto puede estar sin vincular antes de entrar en el resumen de la noche.',
									'woonderful-stock-sync-for-woocommerce'
								) }
							>
								<div className="wss-hours">
									<input
										id="wss-notify-after"
										type="number"
										min="1"
										className="wss-input"
										value={ form.notify_after }
										disabled={ ! form.notify_enabled }
										onChange={ ( e ) =>
											set(
												'notify_after',
												e.target.value
											)
										}
									/>
									<span>
										{ __(
											'horas',
											'woonderful-stock-sync-for-woocommerce'
										) }
									</span>
								</div>
							</Field>
						</div>
					</div>
				</section>

				<section>
					<h2 className="wss-settings__title">
						{ __(
							'Datos',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</h2>
					<div className="woon-card wss-settings__card">
						<Toggle
							title={ __(
								'Eliminar todos los datos al desinstalar',
								'woonderful-stock-sync-for-woocommerce'
							) }
							help={
								form.delete_data
									? __(
											'Al borrar el plugin se pierden para siempre los vínculos, el libro de movimientos —el historial de stock—, los registros y la configuración.',
											'woonderful-stock-sync-for-woocommerce'
									  )
									: __(
											'Si borrás el plugin, los vínculos y los registros quedan guardados por si lo volvés a instalar.',
											'woonderful-stock-sync-for-woocommerce'
									  )
							}
							on={ form.delete_data }
							onChange={ ( on ) => set( 'delete_data', on ) }
						/>
						<div className="wss-settings__foot">
							<button
								type="button"
								className="wss-link"
								aria-expanded={ requirements }
								onClick={ () =>
									setRequirements( ! requirements )
								}
							>
								{ requirements
									? __(
											'Ocultar los requisitos',
											'woonderful-stock-sync-for-woocommerce'
									  )
									: __(
											'Revisar los requisitos del plugin',
											'woonderful-stock-sync-for-woocommerce'
									  ) }
							</button>
							{ requirements && (
								<div className="wss-settings__requirements">
									<Requirements apiFetch={ apiFetch } />
								</div>
							) }
						</div>
					</div>
				</section>

				{ error && (
					<div className="woon-notice woon-notice--error">
						<p>{ error }</p>
					</div>
				) }
			</div>

			{ dirty && (
				<div className="wss-bulkbar">
					<span>
						{ __(
							'Tenés cambios sin guardar',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</span>
					<div className="wss-bulkbar__acts">
						<button
							className="woon-btn wss-bulkbar__ghost"
							onClick={ () => setForm( saved ) }
							disabled={ saving }
						>
							{ __(
								'Descartar',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</button>
						<button
							className="woon-btn woon-btn--primary"
							onClick={ save }
							disabled={ saving }
						>
							{ saving
								? __(
										'Guardando…',
										'woonderful-stock-sync-for-woocommerce'
								  )
								: __(
										'Guardar cambios',
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

// La tarjeta del par: las dos tiendas, la prueba de conexión, la pausa y el corte
// (ADR-015). La pausa y el corte se aplican al momento: no esperan a "Guardar".
function Connection( { apiFetch, status, onStatus, onRepair, say } ) {
	const [ testing, setTesting ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ confirm, setConfirm ] = useState( false );
	const paused = status.paused;

	const probe = async () => {
		setTesting( 'run' );
		try {
			await apiFetch( { path: '/peer' } );
			setTesting( 'ok' );
		} catch ( e ) {
			setTesting( e?.message || 'error' );
		}
	};

	const act = async ( path, data, done ) => {
		setBusy( true );
		try {
			const next = await apiFetch( { path, method: 'POST', data } );
			onStatus( next );
			say(
				next.peer_told
					? done
					: `${ done } ${ __(
							'La otra tienda no respondió: se va a enterar en cuanto vuelva.',
							'woonderful-stock-sync-for-woocommerce'
					  ) }`
			);
		} catch ( e ) {
			say( e?.message );
		} finally {
			setBusy( false );
			setConfirm( false );
		}
	};

	const pausedText = () => {
		if ( ! paused ) {
			return __(
				'Lo que se vende en una tienda se descuenta en la otra.',
				'woonderful-stock-sync-for-woocommerce'
			);
		}
		const who =
			'peer' === paused.by
				? sprintf(
						/* translators: %s: la otra tienda. */
						__(
							'desde %s',
							'woonderful-stock-sync-for-woocommerce'
						),
						status.peer.label
				  )
				: paused.user ??
				  __( 'desde acá', 'woonderful-stock-sync-for-woocommerce' );
		return sprintf(
			/* translators: 1: cuándo, 2: quién. */
			__(
				'En pausa desde %1$s (%2$s). Las ventas se siguen anotando y se mandan al reanudar.',
				'woonderful-stock-sync-for-woocommerce'
			),
			paused.at,
			who
		);
	};

	return (
		<div className="woon-card wss-pair-card">
			<div className="wss-pair-card__top">
				<div className="wss-pair-card__stores">
					<div>
						<span className="wss-pair-card__name">
							{ status.store.label }
						</span>
						<span className="woon-badge woon-badge--brand">
							{ status.store.master
								? __(
										'Maestra',
										'woonderful-stock-sync-for-woocommerce'
								  )
								: __(
										'Secundaria',
										'woonderful-stock-sync-for-woocommerce'
								  ) }
						</span>
					</div>
					<span className="wss-pair-card__arrow" aria-hidden="true">
						↔
					</span>
					<div>
						<span className="wss-pair-card__name">
							{ status.peer.label }
						</span>
						<span
							className={ `woon-badge woon-badge--${
								paused ? 'warning' : 'success'
							}` }
						>
							{ paused
								? __(
										'En pausa',
										'woonderful-stock-sync-for-woocommerce'
								  )
								: __(
										'Emparejada',
										'woonderful-stock-sync-for-woocommerce'
								  ) }
						</span>
					</div>
				</div>
				<div className="wss-pair-card__actions">
					<button
						className="woon-btn woon-btn--ghost woon-btn--sm"
						onClick={ probe }
						disabled={ 'run' === testing }
					>
						{ 'run' === testing
							? __(
									'Probando…',
									'woonderful-stock-sync-for-woocommerce'
							  )
							: __(
									'Probar conexión',
									'woonderful-stock-sync-for-woocommerce'
							  ) }
					</button>
					<button
						className="woon-btn woon-btn--ghost woon-btn--sm"
						onClick={ onRepair }
					>
						{ __(
							'Volver a emparejar',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</button>
				</div>
			</div>

			<dl className="wss-pair-card__meta">
				{ status.peer.host && (
					<div>
						<dt>
							{ __(
								'Dirección',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</dt>
						<dd>{ status.peer.host }</dd>
					</div>
				) }
				{ status.peer.paired_at && (
					<div>
						<dt>
							{ __(
								'Emparejadas desde',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</dt>
						<dd>{ status.peer.paired_at.slice( 0, 10 ) }</dd>
					</div>
				) }
			</dl>

			{ 'ok' === testing && (
				<div className="wss-pair-card__result is-ok">
					{ __(
						'La otra tienda responde bien.',
						'woonderful-stock-sync-for-woocommerce'
					) }
				</div>
			) }
			{ testing && ! [ 'ok', 'run' ].includes( testing ) && (
				<div className="wss-pair-card__result is-error">
					{ sprintf(
						/* translators: %s: el error. */
						__(
							'La otra tienda no respondió: %s',
							'woonderful-stock-sync-for-woocommerce'
						),
						testing
					) }
				</div>
			) }

			<div className="wss-pair-card__controls">
				<Toggle
					title={ __(
						'Sincronización activa',
						'woonderful-stock-sync-for-woocommerce'
					) }
					help={ pausedText() }
					on={ ! paused }
					disabled={ busy }
					onChange={ ( on ) =>
						act(
							'/pause',
							{ paused: ! on },
							on
								? __(
										'Sincronización reanudada: lo anotado sale en la próxima pasada.',
										'woonderful-stock-sync-for-woocommerce'
								  )
								: __(
										'Sincronización en pausa en las dos tiendas.',
										'woonderful-stock-sync-for-woocommerce'
								  )
						)
					}
				/>
				<div className="wss-toggle">
					<div>
						<div className="wss-toggle__title">
							{ __(
								'Desemparejar',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</div>
						<p className="wss-toggle__help">
							{ __(
								'Corta del todo: las dos tiendas olvidan la conexión. Para volver hace falta un código nuevo.',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</p>
					</div>
					<button
						className="woon-btn woon-btn--ghost woon-btn--sm wss-danger"
						onClick={ () => setConfirm( true ) }
						disabled={ busy }
					>
						{ __(
							'Desemparejar',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</button>
				</div>
			</div>

			{ confirm && (
				<div
					className="woon-overlay"
					role="presentation"
					onClick={ () => setConfirm( false ) }
					onKeyDown={ ( e ) =>
						e.key === 'Escape' && setConfirm( false )
					}
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
									/* translators: %s: la otra tienda. */
									__(
										'¿Desemparejar de %s?',
										'woonderful-stock-sync-for-woocommerce'
									),
									status.peer.label
								) }
							</h2>
							<p className="woon-muted">
								{ __(
									'Las dos tiendas dejan de sincronizar. Los vínculos se guardan por si vuelven a emparejar. Lo que se venda mientras tanto se manda al re-emparejar con esta misma tienda.',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</p>
						</div>
						<div className="woon-modal__foot">
							<button
								className="woon-btn woon-btn--ghost"
								onClick={ () => setConfirm( false ) }
							>
								{ __(
									'Cancelar',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</button>
							<button
								className="woon-btn woon-btn--danger"
								disabled={ busy }
								onClick={ () =>
									act(
										'/unpair',
										{},
										__(
											'Las dos tiendas quedaron desemparejadas.',
											'woonderful-stock-sync-for-woocommerce'
										)
									)
								}
							>
								{ __(
									'Desemparejar',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</button>
						</div>
					</div>
				</div>
			) }
		</div>
	);
}

function Field( { id, title, help, children } ) {
	return (
		<div className="wss-field">
			<div>
				<label className="wss-field__title" htmlFor={ id }>
					{ title }
				</label>
				{ help && <p className="wss-field__help">{ help }</p> }
			</div>
			<div className="wss-field__control">{ children }</div>
		</div>
	);
}

function Toggle( { title, help, on, disabled, onChange } ) {
	return (
		<div className="wss-toggle">
			<div>
				<div className="wss-toggle__title">{ title }</div>
				{ help && <p className="wss-toggle__help">{ help }</p> }
			</div>
			<button
				type="button"
				role="switch"
				aria-checked={ on }
				aria-label={ title }
				className={ `wss-switch ${ on ? 'is-on' : '' }` }
				disabled={ disabled }
				onClick={ () => onChange( ! on ) }
			>
				<span />
			</button>
		</div>
	);
}
