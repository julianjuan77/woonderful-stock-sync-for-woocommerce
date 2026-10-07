import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import Pairing from '../components/Pairing';

export default function Settings( { apiFetch, navigate, status, onStatus } ) {
	const [ form, setForm ] = useState( null );
	const [ label, setLabel ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ saved, setSaved ] = useState( false );
	const [ repairing, setRepairing ] = useState( false );

	useEffect( () => {
		apiFetch( { path: '/settings' } )
			.then( setForm )
			.catch( ( e ) => setError( e?.message ) );
	}, [ apiFetch ] );

	useEffect( () => {
		if ( status ) {
			setLabel( status.store.label );
		}
	}, [ status ] );

	const set = ( key, value ) => {
		setForm( ( prev ) => ( { ...prev, [ key ]: value } ) );
		setSaved( false );
	};

	const handleSave = async ( e ) => {
		e.preventDefault();
		setSaving( true );
		setError( null );
		try {
			setForm(
				await apiFetch( {
					path: '/settings',
					method: 'POST',
					data: form,
				} )
			);
			if ( label !== status.store.label ) {
				onStatus(
					await apiFetch( {
						path: '/store',
						method: 'POST',
						data: { label },
					} )
				);
			}
			setSaved( true );
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
		<div className="woon-settings">
			<div
				className="woon-card"
				style={ { marginBottom: 'var(--woon-sp-5)' } }
			>
				<div className="woon-card__head">
					<h2 className="woon-card__title">
						{ __(
							'Emparejamiento',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</h2>
				</div>
				<div className="woon-card__body">
					{ status.paired && (
						<div
							style={ {
								display: 'flex',
								alignItems: 'center',
								gap: '12px',
								marginBottom: repairing ? '16px' : 0,
							} }
						>
							<span className="woon-badge woon-badge--success">
								{ __(
									'Emparejada',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</span>
							<span>
								{ sprintf(
									/* translators: 1: la otra tienda, 2: el rol de ésta. */
									__(
										'Con %1$s. Esta tienda es la %2$s.',
										'woonderful-stock-sync-for-woocommerce'
									),
									status.peer.label,
									status.store.master
										? __(
												'maestra',
												'woonderful-stock-sync-for-woocommerce'
										  )
										: __(
												'secundaria',
												'woonderful-stock-sync-for-woocommerce'
										  )
								) }
							</span>
							{ ! repairing && (
								<button
									className="woon-btn woon-btn--ghost woon-btn--sm"
									style={ { marginLeft: 'auto' } }
									onClick={ () => setRepairing( true ) }
								>
									{ __(
										'Volver a emparejar',
										'woonderful-stock-sync-for-woocommerce'
									) }
								</button>
							) }
						</div>
					) }
					{ status.paired && repairing && (
						<div className="woon-notice woon-notice--warning">
							<p>
								{ __(
									'Emparejar de nuevo reemplaza el emparejamiento actual. Hacelo si cambiaste de tienda o si la otra rechaza los pedidos de ésta.',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</p>
						</div>
					) }
					{ ( ! status.paired || repairing ) && (
						<Pairing
							apiFetch={ apiFetch }
							status={ status }
							onStatus={ onPaired }
						/>
					) }
				</div>
			</div>

			<form onSubmit={ handleSave }>
				<div
					className="woon-card"
					style={ { marginBottom: 'var(--woon-sp-5)' } }
				>
					<div className="woon-card__head">
						<h2 className="woon-card__title">
							{ __(
								'Esta tienda',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</h2>
					</div>
					<div className="woon-card__body">
						<div className="woon-field">
							<label className="woon-label" htmlFor="wss-label">
								{ __(
									'Nombre',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</label>
							<div className="woon-help">
								{ __(
									'Con el que la nombran los avisos y la otra tienda. La otra lo recibe al emparejarse.',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</div>
							<input
								id="wss-label"
								className="woon-input"
								style={ { maxWidth: 320 } }
								value={ label }
								onChange={ ( e ) => {
									setLabel( e.target.value );
									setSaved( false );
								} }
							/>
						</div>
					</div>
				</div>

				<div
					className="woon-card"
					style={ { marginBottom: 'var(--woon-sp-5)' } }
				>
					<div className="woon-card__head">
						<h2 className="woon-card__title">
							{ __(
								'Avisos por email',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</h2>
					</div>
					<div className="woon-card__body">
						<div
							className="woon-field"
							style={ {
								display: 'flex',
								alignItems: 'center',
								gap: 'var(--woon-sp-3)',
							} }
						>
							<input
								id="wss-notify-enabled"
								type="checkbox"
								checked={ form.notify_enabled }
								onChange={ ( e ) =>
									set( 'notify_enabled', e.target.checked )
								}
							/>
							<label
								htmlFor="wss-notify-enabled"
								style={ { cursor: 'pointer', margin: 0 } }
							>
								{ __(
									'Avisar por email cuando la otra tienda no responde, cuando algo falla, y lo que no sincroniza',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</label>
						</div>
						<div
							className="woon-help"
							style={ { marginBottom: 16 } }
						>
							{ __(
								'Apagado, todo se sigue registrando en Registros.',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</div>
						<div className="woon-field">
							<label
								className="woon-label"
								htmlFor="wss-notify-email"
							>
								{ __(
									'A quién',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</label>
							<div className="woon-help">
								{ __(
									'Vacío, al email del administrador.',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</div>
							<input
								id="wss-notify-email"
								type="email"
								className="woon-input"
								style={ { maxWidth: 320 } }
								placeholder={ form.admin_email }
								value={ form.notify_email }
								disabled={ ! form.notify_enabled }
								onChange={ ( e ) =>
									set( 'notify_email', e.target.value )
								}
							/>
						</div>
						<div className="woon-field">
							<label
								className="woon-label"
								htmlFor="wss-notify-after"
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
								id="wss-notify-after"
								type="number"
								min="1"
								className="woon-input"
								style={ { maxWidth: 100 } }
								value={ form.notify_after }
								disabled={ ! form.notify_enabled }
								onChange={ ( e ) =>
									set( 'notify_after', e.target.value )
								}
							/>
						</div>
					</div>
				</div>

				<div
					className="woon-card"
					style={ { marginBottom: 'var(--woon-sp-5)' } }
				>
					<div className="woon-card__head">
						<h2 className="woon-card__title">
							{ __(
								'Datos',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</h2>
					</div>
					<div className="woon-card__body">
						<div
							style={ {
								display: 'flex',
								alignItems: 'center',
								gap: 'var(--woon-sp-3)',
							} }
						>
							<input
								id="wss-delete-data"
								type="checkbox"
								checked={ form.delete_data }
								onChange={ ( e ) =>
									set( 'delete_data', e.target.checked )
								}
							/>
							<label
								htmlFor="wss-delete-data"
								style={ { cursor: 'pointer', margin: 0 } }
							>
								{ __(
									'Eliminar todos los datos al desinstalar el plugin',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</label>
						</div>
						{ form.delete_data && (
							<div
								className="woon-notice woon-notice--warning"
								style={ { marginTop: 'var(--woon-sp-4)' } }
							>
								<p>
									{ __(
										'Al desinstalar se eliminan para siempre el libro de movimientos —el historial de stock—, los vínculos, los registros y la configuración.',
										'woonderful-stock-sync-for-woocommerce'
									) }
								</p>
							</div>
						) }
						<button
							type="button"
							className="woon-btn-link"
							style={ { marginTop: 'var(--woon-sp-4)' } }
							onClick={ () => navigate( 'setup' ) }
						>
							{ __(
								'Revisar los requisitos del plugin',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</button>
					</div>
				</div>

				{ error && (
					<div className="woon-notice woon-notice--error">
						<p>{ error }</p>
					</div>
				) }

				<div
					style={ {
						display: 'flex',
						alignItems: 'center',
						gap: 'var(--woon-sp-3)',
					} }
				>
					<button
						type="submit"
						className="woon-btn woon-btn--primary"
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
					{ saved && (
						<span className="woon-badge woon-badge--success">
							{ __(
								'¡Guardado!',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</span>
					) }
				</div>
			</form>
		</div>
	);
}
