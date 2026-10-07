import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

// Vincular a mano, o reasignar: buscar el de allá en la copia, pedirlo en el momento, mostrar
// los dos stocks y cuál queda, y confirmar con los stocks vistos (ADR-014 §5).
export default function LinkModal( {
	apiFetch,
	row,
	reassign,
	onDone,
	onClose,
} ) {
	const [ query, setQuery ] = useState( '' );
	const [ results, setResults ] = useState( null );
	const [ picked, setPicked ] = useState( reassign ? null : row.suggestion );
	const [ fresh, setFresh ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	const search = async () => {
		setError( null );
		try {
			const data = await apiFetch( {
				path: `/catalog/search?q=${ encodeURIComponent( query ) }`,
			} );
			setResults( data.items );
		} catch ( e ) {
			setError( e?.message );
		}
	};

	// Antes de confirmar, el de allá y el de acá en el momento: la copia puede estar atrasada.
	const readFresh = useCallback( async () => {
		if ( ! picked ) {
			return;
		}
		setFresh( null );
		setError( null );
		try {
			setFresh(
				await apiFetch( {
					path: `/catalog/fresh/${ picked.id }?local=${ row.id }`,
				} )
			);
		} catch ( e ) {
			setError( e?.message );
		}
	}, [ apiFetch, picked, row.id ] );

	useEffect( () => {
		readFresh();
	}, [ readFresh ] );

	const confirm = async () => {
		setBusy( true );
		setError( null );
		try {
			const data = await apiFetch( {
				path: reassign ? '/reassign' : '/link',
				method: 'POST',
				data: {
					local: row.id,
					remote: picked.id,
					stock: fresh.local.stock,
					remote_stock: fresh.remote.stock,
				},
			} );
			onDone( data );
		} catch ( e ) {
			setError( e?.message );
			// Algún stock cambió: se vuelven a leer los dos.
			if ( 'stock_changed' === e?.code ) {
				readFresh();
			}
		} finally {
			setBusy( false );
		}
	};

	return (
		<div
			className="woon-overlay"
			role="presentation"
			onKeyDown={ ( e ) => e.key === 'Escape' && onClose() }
		>
			<div
				className="woon-modal"
				role="presentation"
				onClick={ ( e ) => e.stopPropagation() }
				onKeyDown={ ( e ) => e.stopPropagation() }
			>
				<div className="woon-modal__head">
					<h2>
						{ reassign
							? __(
									'Reasignar',
									'woonderful-stock-sync-for-woocommerce'
							  )
							: __(
									'Vincular',
									'woonderful-stock-sync-for-woocommerce'
							  ) }
					</h2>
					<button className="woon-modal__close" onClick={ onClose }>
						&#x2715;
					</button>
				</div>
				<div className="woon-modal__body">
					<p className="woon-modal__subtitle">
						{ __(
							'Producto de acá:',
							'woonderful-stock-sync-for-woocommerce'
						) }{ ' ' }
						<strong>{ row.name }</strong>{ ' ' }
						{ row.sku && `(${ row.sku })` }
					</p>

					{ error && (
						<div className="woon-notice woon-notice--error">
							<p>{ error }</p>
						</div>
					) }

					{ ! picked && (
						<>
							<div className="woon-modal__search">
								<input
									type="text"
									className="woon-input"
									// eslint-disable-next-line jsx-a11y/no-autofocus
									autoFocus
									value={ query }
									placeholder={ __(
										'Nombre o SKU en la otra tienda…',
										'woonderful-stock-sync-for-woocommerce'
									) }
									onChange={ ( e ) =>
										setQuery( e.target.value )
									}
									onKeyDown={ ( e ) =>
										e.key === 'Enter' && search()
									}
								/>
								<button
									className="woon-btn woon-btn--secondary"
									onClick={ search }
									disabled={ '' === query.trim() }
								>
									{ __(
										'Buscar',
										'woonderful-stock-sync-for-woocommerce'
									) }
								</button>
							</div>
							{ results && 0 === results.length && (
								<p className="woon-muted">
									{ __(
										'No hay productos sin vincular con ese nombre o SKU en la otra tienda.',
										'woonderful-stock-sync-for-woocommerce'
									) }
								</p>
							) }
							{ results && results.length > 0 && (
								<ul className="woon-ml-results">
									{ results.map( ( item ) => (
										<li key={ item.id }>
											<button
												type="button"
												className="woon-ml-result"
												style={ {
													width: '100%',
													textAlign: 'left',
													background: 'none',
												} }
												onClick={ () =>
													setPicked( item )
												}
											>
												<span className="woon-ml-result__info">
													<strong>
														{ item.parent_name
															? `${ item.parent_name } — ${ item.name }`
															: item.name }
													</strong>
													<small>
														{ item.sku || '—' } · #
														{ item.id } ·{ ' ' }
														{ sprintf(
															/* translators: %d: stock. */
															__(
																'stock %d',
																'woonderful-stock-sync-for-woocommerce'
															),
															item.stock
														) }
													</small>
												</span>
											</button>
										</li>
									) ) }
								</ul>
							) }
						</>
					) }

					{ picked && (
						<div>
							<p style={ { marginBottom: 12 } }>
								{ __(
									'Con:',
									'woonderful-stock-sync-for-woocommerce'
								) }{ ' ' }
								<strong>{ picked.name }</strong> (#{ picked.id }
								){ ' ' }
								<button
									className="woon-btn-link"
									onClick={ () => {
										setPicked( null );
										setFresh( null );
									} }
								>
									{ __(
										'elegir otro',
										'woonderful-stock-sync-for-woocommerce'
									) }
								</button>
							</p>
							{ ! fresh && ! error && (
								<div className="woon-loading">
									{ __(
										'Leyendo los dos stocks…',
										'woonderful-stock-sync-for-woocommerce'
									) }
								</div>
							) }
							{ fresh && (
								<table
									className="woon-table"
									style={ { marginBottom: 12 } }
								>
									<tbody>
										<tr>
											<td>
												{ __(
													'Stock acá',
													'woonderful-stock-sync-for-woocommerce'
												) }
											</td>
											<td>{ fresh.local.stock }</td>
										</tr>
										<tr>
											<td>
												{ __(
													'Stock en la otra tienda',
													'woonderful-stock-sync-for-woocommerce'
												) }
											</td>
											<td>{ fresh.remote.stock }</td>
										</tr>
										<tr>
											<td>
												<strong>
													{ __(
														'Las dos quedan con',
														'woonderful-stock-sync-for-woocommerce'
													) }
												</strong>
											</td>
											<td>
												<strong>
													{ fresh.result }
												</strong>{ ' ' }
												<span className="woon-muted">
													{ __(
														'(el de la maestra)',
														'woonderful-stock-sync-for-woocommerce'
													) }
												</span>
											</td>
										</tr>
									</tbody>
								</table>
							) }
						</div>
					) }
				</div>
				<div className="woon-modal__foot">
					<button
						className="woon-btn woon-btn--ghost"
						onClick={ onClose }
					>
						{ __(
							'Cancelar',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</button>
					<button
						className="woon-btn woon-btn--primary"
						onClick={ confirm }
						disabled={ busy || ! fresh }
					>
						{ busy
							? __(
									'Vinculando…',
									'woonderful-stock-sync-for-woocommerce'
							  )
							: __(
									'Confirmar',
									'woonderful-stock-sync-for-woocommerce'
							  ) }
					</button>
				</div>
			</div>
		</div>
	);
}
