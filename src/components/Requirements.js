import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

// La lista de requisitos, con el email de prueba: la usan la configuración inicial y
// Configuración, que la muestra sin abrir el asistente.
export default function Requirements( { apiFetch, onChecks } ) {
	const [ checks, setChecks ] = useState( null );
	const [ error, setError ] = useState( null );

	const load = () => {
		setChecks( null );
		onChecks?.( null );
		apiFetch( { path: '/requirements' } )
			.then( ( data ) => {
				setChecks( data.items );
				onChecks?.( data.items );
			} )
			.catch( ( e ) => setError( e?.message ) );
	};

	// eslint-disable-next-line react-hooks/exhaustive-deps
	useEffect( load, [ apiFetch ] );

	// El correo no se puede saber sin probar: si el email de prueba sale, queda en verde.
	const mailSent = () => {
		const next = checks.map( ( check ) =>
			'mail' === check.id ? { ...check, pass: true, fix: null } : check
		);
		setChecks( next );
		onChecks?.( next );
	};

	const criticalFails = ( checks ?? [] ).filter(
		( check ) => check.critical && false === check.pass
	);

	return (
		<>
			{ error && (
				<div className="woon-notice woon-notice--error">
					<p>{ error }</p>
				</div>
			) }
			{ ! checks && ! error && (
				<div className="woon-loading">
					{ __(
						'Revisando…',
						'woonderful-stock-sync-for-woocommerce'
					) }
				</div>
			) }
			{ checks && (
				<div className="woon-check-list">
					{ checks.map( ( check ) => {
						const [ iconClass, icon ] = checkIcon( check );
						return (
							<div key={ check.id } className="woon-check-item">
								<span
									className={ `woon-check-item__icon ${ iconClass }` }
								>
									{ icon }
								</span>
								<div className="woon-check-item__content">
									<span className="woon-check-item__label">
										{ check.label }
										{ check.value && (
											<span className="woon-check-item__value">
												{ ' ' }
												({ check.value })
											</span>
										) }
									</span>
									{ check.fix && (
										<p className="woon-check-item__fix">
											{ check.fix }
										</p>
									) }
									{ 'mail' === check.id && (
										<TestEmail
											apiFetch={ apiFetch }
											onSent={ mailSent }
										/>
									) }
								</div>
								{ ! check.critical && true !== check.pass && (
									<span className="woon-badge woon-badge--warning">
										{ __(
											'Recomendado',
											'woonderful-stock-sync-for-woocommerce'
										) }
									</span>
								) }
							</div>
						);
					} ) }
				</div>
			) }
			{ criticalFails.length > 0 && (
				<div
					className="woon-notice woon-notice--danger"
					style={ { marginTop: 16 } }
				>
					<p>
						{ __(
							'Hay requisitos críticos sin cumplir: arreglalos y volvé a revisar.',
							'woonderful-stock-sync-for-woocommerce'
						) }{ ' ' }
						<button className="woon-btn-link" onClick={ load }>
							{ __(
								'Volver a revisar',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</button>
					</p>
				</div>
			) }
		</>
	);
}

function checkIcon( check ) {
	if ( true === check.pass ) {
		return [ 'woon-check-item__icon--pass', '✓' ];
	}
	if ( false === check.pass && check.critical ) {
		return [ 'woon-check-item__icon--fail', '✕' ];
	}
	return [ 'woon-check-item__icon--warn', '!' ];
}

function TestEmail( { apiFetch, onSent } ) {
	const [ result, setResult ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const send = async () => {
		setBusy( true );
		try {
			const sent = await apiFetch( {
				path: '/test-email',
				method: 'POST',
			} );
			setResult( sent );
			if ( sent.sent ) {
				onSent?.();
			}
		} catch ( e ) {
			setResult( { sent: false } );
		} finally {
			setBusy( false );
		}
	};

	return (
		<div style={ { marginTop: 8 } }>
			<button
				className="woon-btn woon-btn--secondary woon-btn--sm"
				onClick={ send }
				disabled={ busy }
			>
				{ __(
					'Mandar email de prueba',
					'woonderful-stock-sync-for-woocommerce'
				) }
			</button>{ ' ' }
			{ result && (
				<span className="woon-muted">
					{ result.sent
						? sprintf(
								/* translators: %s: el destinatario. */
								__(
									'Salió a %s: fijate si llegó, también en spam.',
									'woonderful-stock-sync-for-woocommerce'
								),
								result.to
						  )
						: __(
								'No salió: el hosting no manda correo.',
								'woonderful-stock-sync-for-woocommerce'
						  ) }
				</span>
			) }
		</div>
	);
}
