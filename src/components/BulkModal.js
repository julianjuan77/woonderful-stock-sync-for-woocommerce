import { useState, useEffect } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';

const BATCH = 20;

// La vinculación masiva: cuántos pares, el aviso del stock de la maestra, las tandas de 20 con
// su avance, y al final los vinculados y los omitidos con su motivo (ADR-014 §6).
export default function BulkModal( { apiFetch, onClose } ) {
	const [ preview, setPreview ] = useState( null );
	const [ progress, setProgress ] = useState( null );
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		apiFetch( { path: '/link/bulk' } )
			.then( setPreview )
			.catch( ( e ) => setError( e?.message ) );
	}, [ apiFetch ] );

	const run = async () => {
		const linked = [];
		const omitted = [];
		setError( null );
		try {
			for ( let i = 0; i < preview.pairs.length; i += BATCH ) {
				setProgress( { done: i, total: preview.pairs.length } );
				const batch = await apiFetch( {
					path: '/link/bulk',
					method: 'POST',
					data: { pairs: preview.pairs.slice( i, i + BATCH ) },
				} );
				linked.push( ...batch.linked );
				omitted.push( ...batch.omitted );
			}
		} catch ( e ) {
			setError( e?.message );
		}
		setProgress( null );
		setResult( { linked, omitted } );
	};

	const running = null !== progress;

	return (
		<div
			className="woon-overlay"
			role="presentation"
			onKeyDown={ ( e ) =>
				e.key === 'Escape' && ! running && onClose( result )
			}
		>
			<div
				className="woon-modal"
				role="presentation"
				onClick={ ( e ) => e.stopPropagation() }
				onKeyDown={ ( e ) => e.stopPropagation() }
			>
				<div className="woon-modal__head">
					<h2>
						{ __(
							'Vinculación masiva',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</h2>
					{ ! running && (
						<button
							className="woon-modal__close"
							onClick={ () => onClose( result ) }
						>
							&#x2715;
						</button>
					) }
				</div>
				<div className="woon-modal__body">
					{ error && (
						<div className="woon-notice woon-notice--error">
							<p>{ error }</p>
						</div>
					) }
					{ ! preview && ! error && (
						<div className="woon-loading">
							{ __(
								'Cargando…',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</div>
					) }
					{ preview && ! result && (
						<>
							<p style={ { marginBottom: 12 } }>
								{ sprintf(
									/* translators: %d: cuántos pares. */
									_n(
										'Se va a vincular %d par con el mismo SKU en las dos tiendas.',
										'Se van a vincular %d pares con el mismo SKU en las dos tiendas.',
										preview.pairs.length,
										'woonderful-stock-sync-for-woocommerce'
									),
									preview.pairs.length
								) }
							</p>
							<div className="woon-notice woon-notice--warning">
								<p>
									{ __(
										'Todos arrancan con el stock de la maestra: el de la otra tienda se reemplaza por el de ésta.',
										'woonderful-stock-sync-for-woocommerce'
									) }
								</p>
							</div>
							{ preview.ambiguous > 0 && (
								<p className="woon-muted">
									{ sprintf(
										/* translators: %d: cuántos. */
										_n(
											'%d producto con el SKU repetido queda aparte: se vincula a mano.',
											'%d productos con el SKU repetido quedan aparte: se vinculan a mano.',
											preview.ambiguous,
											'woonderful-stock-sync-for-woocommerce'
										),
										preview.ambiguous
									) }
								</p>
							) }
							{ running && (
								<p>
									{ sprintf(
										/* translators: 1: hechos, 2: total. */
										__(
											'Vinculando: %1$d de %2$d…',
											'woonderful-stock-sync-for-woocommerce'
										),
										progress.done,
										progress.total
									) }
								</p>
							) }
						</>
					) }
					{ result && (
						<>
							<p style={ { marginBottom: 12 } }>
								{ sprintf(
									/* translators: 1: vinculados, 2: omitidos. */
									__(
										'Vinculados: %1$d. Omitidos: %2$d.',
										'woonderful-stock-sync-for-woocommerce'
									),
									result.linked.length,
									result.omitted.length
								) }
							</p>
							{ result.omitted.length > 0 && (
								<table className="woon-table">
									<tbody>
										{ result.omitted.map( ( item ) => (
											<tr key={ item.local }>
												<td>#{ item.local }</td>
												<td>{ item.text }</td>
											</tr>
										) ) }
									</tbody>
								</table>
							) }
						</>
					) }
				</div>
				<div className="woon-modal__foot">
					{ result ? (
						<button
							className="woon-btn woon-btn--primary"
							onClick={ () => onClose( result ) }
						>
							{ __(
								'Listo',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</button>
					) : (
						<>
							<button
								className="woon-btn woon-btn--ghost"
								onClick={ () => onClose( null ) }
								disabled={ running }
							>
								{ __(
									'Cancelar',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</button>
							<button
								className="woon-btn woon-btn--primary"
								onClick={ run }
								disabled={
									running ||
									! preview ||
									0 === preview.pairs.length
								}
							>
								{ __(
									'Vincular',
									'woonderful-stock-sync-for-woocommerce'
								) }
							</button>
						</>
					) }
				</div>
			</div>
		</div>
	);
}
