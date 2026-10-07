import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

// Generar el código o canjearlo. La usan Configuración y la configuración inicial.
export default function Pairing( {
	apiFetch,
	status,
	onStatus,
	fixedMode = null,
} ) {
	const [ mode, setMode ] = useState( fixedMode );
	const [ code, setCode ] = useState( null );
	const [ pasted, setPasted ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ copied, setCopied ] = useState( false );

	// Mientras el código espera, se pregunta si la otra ya lo canjeó.
	useEffect( () => {
		if ( ! code ) {
			return;
		}
		const timer = setInterval( () => {
			apiFetch( { path: '/status' } )
				.then( ( next ) => {
					if (
						next.paired &&
						next.peer.paired_at !== status?.peer?.paired_at
					) {
						clearInterval( timer );
						setCode( null );
						onStatus( next );
					}
				} )
				.catch( () => {} );
		}, 5000 );
		return () => clearInterval( timer );
	}, [ apiFetch, code, status, onStatus ] );

	const generate = async () => {
		setBusy( true );
		setError( null );
		try {
			setCode(
				await apiFetch( { path: '/pairing/code', method: 'POST' } )
			);
		} catch ( e ) {
			setError( e?.message );
		} finally {
			setBusy( false );
		}
	};

	const redeem = async () => {
		setBusy( true );
		setError( null );
		try {
			onStatus(
				await apiFetch( {
					path: '/pairing/redeem',
					method: 'POST',
					data: { code: pasted.trim() },
				} )
			);
			setPasted( '' );
			setMode( null );
		} catch ( e ) {
			setError( e?.message );
		} finally {
			setBusy( false );
		}
	};

	const copy = () => {
		window.navigator.clipboard?.writeText( code.code ).then( () => {
			setCopied( true );
			setTimeout( () => setCopied( false ), 2000 );
		} );
	};

	return (
		<div>
			{ error && (
				<div className="woon-notice woon-notice--error">
					<p>{ error }</p>
				</div>
			) }

			{ ! fixedMode && (
				<div
					style={ {
						display: 'flex',
						gap: '8px',
						marginBottom: '16px',
					} }
				>
					<button
						className={ `woon-btn ${
							'master' === mode
								? 'woon-btn--primary'
								: 'woon-btn--secondary'
						}` }
						onClick={ () => setMode( 'master' ) }
					>
						{ __(
							'Esta es la maestra: generar el código',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</button>
					<button
						className={ `woon-btn ${
							'secondary' === mode
								? 'woon-btn--primary'
								: 'woon-btn--secondary'
						}` }
						onClick={ () => setMode( 'secondary' ) }
					>
						{ __(
							'Esta es la secundaria: pegar el código',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</button>
				</div>
			) }

			{ 'master' === mode && (
				<div>
					<p className="woon-muted" style={ { marginBottom: 12 } }>
						{ __(
							'La maestra es la que manda: al vincular, el stock que queda es el suyo, y la vinculación se hace desde acá.',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</p>
					{ code ? (
						<>
							<div style={ { display: 'flex', gap: '8px' } }>
								<input
									className="woon-input"
									readOnly
									value={ code.code }
									onFocus={ ( e ) => e.target.select() }
								/>
								<button
									className="woon-btn woon-btn--secondary"
									onClick={ copy }
								>
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
							<p
								className="woon-muted"
								style={ { marginTop: '8px' } }
							>
								{ sprintf(
									/* translators: %d: minutos. */
									__(
										'Pegalo en Configuración de la otra tienda. Vale %d minutos y para un solo canje. Esta pantalla se actualiza sola cuando la otra lo canjee.',
										'woonderful-stock-sync-for-woocommerce'
									),
									code.minutes
								) }
							</p>
						</>
					) : (
						<button
							className="woon-btn woon-btn--primary"
							onClick={ generate }
							disabled={ busy }
						>
							{ __(
								'Generar el código',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</button>
					) }
				</div>
			) }

			{ 'secondary' === mode && (
				<div className="woon-field">
					<label className="woon-label" htmlFor="wss-pairing-code">
						{ __(
							'El código de la maestra',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</label>
					<div style={ { display: 'flex', gap: '8px' } }>
						<input
							id="wss-pairing-code"
							className="woon-input"
							value={ pasted }
							placeholder="wss_…"
							onChange={ ( e ) => setPasted( e.target.value ) }
						/>
						<button
							className="woon-btn woon-btn--primary"
							onClick={ redeem }
							disabled={ busy || '' === pasted.trim() }
						>
							{ busy
								? __(
										'Emparejando…',
										'woonderful-stock-sync-for-woocommerce'
								  )
								: __(
										'Emparejar',
										'woonderful-stock-sync-for-woocommerce'
								  ) }
						</button>
					</div>
				</div>
			) }
		</div>
	);
}
