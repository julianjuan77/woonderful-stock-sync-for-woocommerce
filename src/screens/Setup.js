import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import Pairing from '../components/Pairing';
import Requirements from '../components/Requirements';

const STEPS = [
	{
		id: 'requirements',
		label: __( 'Requisitos', 'woonderful-stock-sync-for-woocommerce' ),
	},
	{
		id: 'store',
		label: __( 'Esta tienda', 'woonderful-stock-sync-for-woocommerce' ),
	},
	{
		id: 'pair',
		label: __( 'Emparejar', 'woonderful-stock-sync-for-woocommerce' ),
	},
	{
		id: 'notify',
		label: __( 'Avisos', 'woonderful-stock-sync-for-woocommerce' ),
	},
	{
		id: 'done',
		label: __( 'Listo', 'woonderful-stock-sync-for-woocommerce' ),
	},
];

// La configuración inicial, como el Setup.js de woonderful-sync: la barra de pasos y
// "configurar más tarde" en cada uno (ADR-013 §3).
export default function Setup( { apiFetch, navigate, status, onStatus } ) {
	const [ step, setStep ] = useState( 0 );
	const [ role, setRole ] = useState( null );

	// Ya emparejada, el rol es el del emparejamiento. El estado llega después de montar.
	useEffect( () => {
		if ( status?.paired && null === role ) {
			setRole( status.store.master ? 'master' : 'secondary' );
		}
	}, [ status, role ] );

	const skip = () => {
		window.sessionStorage.setItem( 'wss_setup_skipped', '1' );
		navigate( 'dashboard' );
	};

	const next = () => setStep( ( s ) => s + 1 );
	const back = () => setStep( ( s ) => s - 1 );

	return (
		<div className="woon-setup">
			<div className="woon-setup__header">
				<h1 className="woon-setup__title">
					{ __(
						'Configuración inicial',
						'woonderful-stock-sync-for-woocommerce'
					) }
				</h1>
				<p className="woon-setup__subtitle">
					{ __(
						'Revisamos lo que el plugin necesita, nombramos la tienda, la emparejamos con la otra y configuramos los avisos.',
						'woonderful-stock-sync-for-woocommerce'
					) }
				</p>
			</div>

			<div className="woon-setup__stepper">
				{ STEPS.map( ( s, i ) => (
					<div
						key={ s.id }
						className={ [
							'woon-setup__stepper-item',
							i === step
								? 'woon-setup__stepper-item--active'
								: '',
							i < step ? 'woon-setup__stepper-item--done' : '',
						]
							.filter( Boolean )
							.join( ' ' ) }
					>
						<span className="woon-setup__stepper-num">
							{ i < step ? '✓' : i + 1 }
						</span>
						<span className="woon-setup__stepper-label">
							{ s.label }
						</span>
					</div>
				) ) }
			</div>

			<div className="woon-card woon-setup__card">
				{ 0 === step && (
					<StepRequirements
						apiFetch={ apiFetch }
						onContinue={ next }
						onSkip={ skip }
					/>
				) }
				{ 1 === step && (
					<StepStore
						apiFetch={ apiFetch }
						status={ status }
						onStatus={ onStatus }
						role={ role }
						setRole={ setRole }
						onContinue={ next }
						onBack={ back }
						onSkip={ skip }
					/>
				) }
				{ 2 === step && (
					<StepPair
						apiFetch={ apiFetch }
						status={ status }
						onStatus={ onStatus }
						role={ role }
						onContinue={ next }
						onBack={ back }
						onSkip={ skip }
					/>
				) }
				{ 3 === step && (
					<StepNotify
						apiFetch={ apiFetch }
						onContinue={ next }
						onBack={ back }
						onSkip={ skip }
					/>
				) }
				{ 4 === step && (
					<StepDone
						master={ status?.store?.master ?? 'master' === role }
						navigate={ navigate }
					/>
				) }
			</div>
		</div>
	);
}

function Actions( { onBack, onSkip, onContinue, disabled, label } ) {
	return (
		<div
			style={ {
				display: 'flex',
				gap: '8px',
				marginTop: 'var(--woon-sp-5)',
				alignItems: 'center',
			} }
		>
			{ onBack && (
				<button className="woon-btn woon-btn--ghost" onClick={ onBack }>
					{ __( 'Atrás', 'woonderful-stock-sync-for-woocommerce' ) }
				</button>
			) }
			<button className="woon-btn-link" onClick={ onSkip }>
				{ __(
					'Configurar más tarde',
					'woonderful-stock-sync-for-woocommerce'
				) }
			</button>
			{ onContinue && (
				<button
					className="woon-btn woon-btn--primary"
					style={ { marginLeft: 'auto' } }
					onClick={ onContinue }
					disabled={ disabled }
				>
					{ label ??
						__(
							'Continuar',
							'woonderful-stock-sync-for-woocommerce'
						) }
				</button>
			) }
		</div>
	);
}

function StepRequirements( { apiFetch, onContinue, onSkip } ) {
	const [ checks, setChecks ] = useState( null );
	const criticalFails = ( checks ?? [] ).filter(
		( check ) => check.critical && false === check.pass
	);

	return (
		<>
			<div className="woon-card__head">
				<h2 className="woon-card__title">
					{ __(
						'Requisitos',
						'woonderful-stock-sync-for-woocommerce'
					) }
				</h2>
			</div>
			<div className="woon-card__body">
				<Requirements apiFetch={ apiFetch } onChecks={ setChecks } />
				<Actions
					onSkip={ onSkip }
					onContinue={ onContinue }
					disabled={ ! checks || criticalFails.length > 0 }
				/>
			</div>
		</>
	);
}

function StepStore( {
	apiFetch,
	status,
	onStatus,
	role,
	setRole,
	onContinue,
	onBack,
	onSkip,
} ) {
	const [ label, setLabel ] = useState( status?.store?.label ?? '' );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const save = async () => {
		setBusy( true );
		setError( null );
		try {
			onStatus(
				await apiFetch( {
					path: '/store',
					method: 'POST',
					data: { label },
				} )
			);
			onContinue();
		} catch ( e ) {
			setError( e?.message );
		} finally {
			setBusy( false );
		}
	};

	const roles = [
		[
			'master',
			__( 'Maestra', 'woonderful-stock-sync-for-woocommerce' ),
			__(
				'La que manda: la vinculación se hace desde acá, y al vincular queda su stock. Genera el código de emparejamiento.',
				'woonderful-stock-sync-for-woocommerce'
			),
		],
		[
			'secondary',
			__( 'Secundaria', 'woonderful-stock-sync-for-woocommerce' ),
			__(
				'Sigue a la maestra: ve todo en modo lectura. Pega el código que generó la maestra.',
				'woonderful-stock-sync-for-woocommerce'
			),
		],
	];

	return (
		<>
			<div className="woon-card__head">
				<h2 className="woon-card__title">
					{ __(
						'Esta tienda',
						'woonderful-stock-sync-for-woocommerce'
					) }
				</h2>
			</div>
			<div className="woon-card__body">
				{ error && (
					<div className="woon-notice woon-notice--error">
						<p>{ error }</p>
					</div>
				) }
				<div className="woon-field">
					<label className="woon-label" htmlFor="wss-setup-label">
						{ __(
							'Nombre',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</label>
					<div className="woon-help">
						{ __(
							'Con el que la ve la otra tienda y la nombran los avisos.',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</div>
					<input
						id="wss-setup-label"
						className="woon-input"
						style={ { maxWidth: 320 } }
						value={ label }
						onChange={ ( e ) => setLabel( e.target.value ) }
					/>
				</div>
				<div className="woon-field">
					<span className="woon-label">
						{ __( 'Rol', 'woonderful-stock-sync-for-woocommerce' ) }
					</span>
					<div className="woon-check-list">
						{ roles.map( ( [ value, name, help ] ) => (
							<div key={ value } className="woon-check-item">
								<input
									id={ `wss-role-${ value }` }
									type="radio"
									name="wss-role"
									checked={ role === value }
									onChange={ () => setRole( value ) }
								/>
								<label
									htmlFor={ `wss-role-${ value }` }
									className="woon-check-item__content"
									style={ {
										cursor: 'pointer',
										display: 'flex',
										flexDirection: 'column',
									} }
								>
									<span className="woon-check-item__label">
										{ name }
									</span>
									<span className="woon-check-item__fix">
										{ help }
									</span>
								</label>
							</div>
						) ) }
					</div>
				</div>
				<Actions
					onBack={ onBack }
					onSkip={ onSkip }
					onContinue={ save }
					disabled={ busy || '' === label.trim() || ! role }
				/>
			</div>
		</>
	);
}

function StepPair( {
	apiFetch,
	status,
	onStatus,
	role,
	onContinue,
	onBack,
	onSkip,
} ) {
	const [ check, setCheck ] = useState( null );

	// Al terminar se prueba la conexión: la otra tiene que contestar su /health.
	const paired = async ( next ) => {
		onStatus( next );
		setCheck( 'checking' );
		try {
			const peer = await apiFetch( { path: '/peer' } );
			setCheck( { ok: true, label: peer.label } );
		} catch ( e ) {
			setCheck( { ok: false, error: e?.message } );
		}
	};

	return (
		<>
			<div className="woon-card__head">
				<h2 className="woon-card__title">
					{ __(
						'Emparejar',
						'woonderful-stock-sync-for-woocommerce'
					) }
				</h2>
			</div>
			<div className="woon-card__body">
				{ ! check && (
					<Pairing
						apiFetch={ apiFetch }
						status={ status }
						onStatus={ paired }
						fixedMode={ role }
					/>
				) }
				{ 'checking' === check && (
					<div className="woon-loading">
						{ __(
							'Probando la conexión…',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</div>
				) }
				{ check?.ok && (
					<div className="woon-notice woon-notice--success">
						<p>
							{ sprintf(
								/* translators: %s: la otra tienda. */
								__(
									'Emparejada con %s, y la conexión responde.',
									'woonderful-stock-sync-for-woocommerce'
								),
								check.label
							) }
						</p>
					</div>
				) }
				{ false === check?.ok && (
					<div className="woon-notice woon-notice--warning">
						<p>
							{ sprintf(
								/* translators: %s: el error. */
								__(
									'Quedó emparejada, pero la otra tienda no respondió a la prueba (%s). Revisá sus requisitos.',
									'woonderful-stock-sync-for-woocommerce'
								),
								check.error
							) }
						</p>
					</div>
				) }
				<Actions
					onBack={ onBack }
					onSkip={ onSkip }
					onContinue={ onContinue }
					disabled={ ! status?.paired || 'checking' === check }
				/>
			</div>
		</>
	);
}

function StepNotify( { apiFetch, onContinue, onBack, onSkip } ) {
	const [ form, setForm ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		apiFetch( { path: '/settings' } )
			.then( setForm )
			.catch( ( e ) => setError( e?.message ) );
	}, [ apiFetch ] );

	const save = async () => {
		setError( null );
		try {
			await apiFetch( {
				path: '/settings',
				method: 'POST',
				data: {
					notify_email: form.notify_email,
					notify_after: form.notify_after,
				},
			} );
			onContinue();
		} catch ( e ) {
			setError( e?.message );
		}
	};

	return (
		<>
			<div className="woon-card__head">
				<h2 className="woon-card__title">
					{ __(
						'Avisos por email',
						'woonderful-stock-sync-for-woocommerce'
					) }
				</h2>
			</div>
			<div className="woon-card__body">
				{ error && (
					<div className="woon-notice woon-notice--error">
						<p>{ error }</p>
					</div>
				) }
				{ form && (
					<>
						<div className="woon-field">
							<label
								className="woon-label"
								htmlFor="wss-setup-email"
							>
								{ __(
									'A quién',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</label>
							<div className="woon-help">
								{ __(
									'Vacío, al email del administrador. Avisa si la otra tienda no responde, si algo falla, y lo que no sincroniza.',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</div>
							<input
								id="wss-setup-email"
								type="email"
								className="woon-input"
								style={ { maxWidth: 320 } }
								placeholder={ form.admin_email }
								value={ form.notify_email }
								onChange={ ( e ) =>
									setForm( {
										...form,
										notify_email: e.target.value,
									} )
								}
							/>
						</div>
						<div className="woon-field">
							<label
								className="woon-label"
								htmlFor="wss-setup-after"
							>
								{ __(
									'Plazo de lo que no sincroniza',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</label>
							<div className="woon-help">
								{ __(
									'Horas que un producto puede estar sin vincular antes de entrar en el resumen de la noche.',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</div>
							<input
								id="wss-setup-after"
								type="number"
								min="1"
								className="woon-input"
								style={ { maxWidth: 100 } }
								value={ form.notify_after }
								onChange={ ( e ) =>
									setForm( {
										...form,
										notify_after: e.target.value,
									} )
								}
							/>
						</div>
					</>
				) }
				<Actions
					onBack={ onBack }
					onSkip={ onSkip }
					onContinue={ save }
					disabled={ ! form }
				/>
			</div>
		</>
	);
}

function StepDone( { master, navigate } ) {
	return (
		<div className="woon-setup__success">
			<div className="woon-setup__success-icon">✓</div>
			<h2 className="woon-setup__success-title">
				{ __( 'Listo', 'woonderful-stock-sync-for-woocommerce' ) }
			</h2>
			<p className="woon-muted" style={ { marginBottom: 16 } }>
				{ master
					? __(
							'Ahora vinculá los productos: la vinculación masiva propone los pares con el mismo SKU.',
							'woonderful-stock-sync-for-woocommerce'
					  )
					: __(
							'La vinculación se hace desde la maestra. Acá ves cómo va la sincronización.',
							'woonderful-stock-sync-for-woocommerce'
					  ) }
			</p>
			<button
				className="woon-btn woon-btn--primary"
				onClick={ () => navigate( master ? 'products' : 'dashboard' ) }
			>
				{ master
					? __(
							'Ir a Productos',
							'woonderful-stock-sync-for-woocommerce'
					  )
					: __(
							'Ir al Resumen',
							'woonderful-stock-sync-for-woocommerce'
					  ) }
			</button>
		</div>
	);
}
