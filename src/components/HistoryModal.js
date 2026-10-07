import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

// El historial de un producto en un pop-up: sus movimientos y sus eventos, sin salir de
// Productos (UC-03).
export default function HistoryModal( { apiFetch, row, onClose } ) {
	const [ entries, setEntries ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		apiFetch( { path: `/log?product=${ row.id }` } )
			.then( ( data ) => setEntries( data.items ) )
			.catch( ( e ) => setError( e?.message ) );
	}, [ apiFetch, row.id ] );

	return (
		<div
			className="woon-overlay"
			role="presentation"
			onClick={ onClose }
			onKeyDown={ ( e ) => e.key === 'Escape' && onClose() }
		>
			<div
				className="woon-modal woon-modal--wide"
				role="presentation"
				onClick={ ( e ) => e.stopPropagation() }
				onKeyDown={ ( e ) => e.stopPropagation() }
			>
				<div className="woon-modal__head">
					<h2>
						{ sprintf(
							/* translators: %s: el producto. */
							__(
								'Historial de %s',
								'woonderful-stock-sync-for-woocommerce'
							),
							row.name
						) }
					</h2>
					<button className="woon-modal__close" onClick={ onClose }>
						&#x2715;
					</button>
				</div>
				<div className="woon-modal__body">
					{ error && (
						<div className="woon-notice woon-notice--error">
							<p>{ error }</p>
						</div>
					) }
					{ ! entries && ! error && (
						<div className="woon-loading">
							{ __(
								'Cargando…',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</div>
					) }
					{ entries && 0 === entries.length && (
						<p className="woon-muted">
							{ __(
								'Todavía no tiene movimientos ni eventos.',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</p>
					) }
					{ entries && entries.length > 0 && (
						<div className="woon-log-list">
							{ entries.map( ( entry, index ) => (
								<div
									key={ entry.id ?? `m${ index }` }
									className="woon-log-entry"
								>
									<div
										className="woon-log-entry__summary"
										style={ { cursor: 'default' } }
									>
										<span
											className={ `woon-badge woon-badge--${
												'movement' === entry.type
													? 'brand'
													: 'info'
											}` }
										>
											{ 'movement' === entry.type
												? __(
														'Movimiento',
														'woonderful-stock-sync-for-woocommerce'
												  )
												: __(
														'Evento',
														'woonderful-stock-sync-for-woocommerce'
												  ) }
										</span>
										<span
											style={ {
												fontSize: 'var(--woon-fs-sm)',
											} }
										>
											{ entry.text }
										</span>
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
									</div>
								</div>
							) ) }
						</div>
					) }
				</div>
			</div>
		</div>
	);
}
