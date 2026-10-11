import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

// Igualar el stock de un par que difiere: los dos números en el momento, y el dueño elige cuál
// vale (ADR-022). Corregir a mano no sirve: viaja como diferencia y la otra se mueve lo mismo.
export default function EqualizeModal( { apiFetch, row, onDone, onClose } ) {
	const [ fresh, setFresh ] = useState( null );
	const [ busy, setBusy ] = useState( null );
	const [ error, setError ] = useState( null );

	const readFresh = useCallback( async () => {
		setFresh( null );
		setError( null );
		try {
			setFresh(
				await apiFetch( {
					path: `/catalog/fresh/${ row.remote.id }?local=${ row.id }`,
				} )
			);
		} catch ( e ) {
			setError( e?.message );
		}
	}, [ apiFetch, row.id, row.remote.id ] );

	useEffect( () => {
		readFresh();
	}, [ readFresh ] );

	const here = fresh?.local?.stock ?? null;
	const there = fresh?.remote?.stock ?? null;
	const equal = null !== here && here === there;

	const equalize = async ( keep ) => {
		setBusy( keep );
		setError( null );
		try {
			const data = await apiFetch( {
				path: '/equalize',
				method: 'POST',
				data: {
					local: row.id,
					keep,
					stock: here,
					remote_stock: there,
				},
			} );
			onDone( data, 'here' === keep ? here : there );
		} catch ( e ) {
			setError( e?.message );
			// Algún stock cambió, o ya coinciden: se vuelven a leer los dos.
			if ( [ 'stock_changed', 'already_equal' ].includes( e?.code ) ) {
				readFresh();
			}
		} finally {
			setBusy( null );
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
						{ __(
							'Igualar stock',
							'woonderful-stock-sync-for-woocommerce'
						) }
					</h2>
					<button className="woon-modal__close" onClick={ onClose }>
						&#x2715;
					</button>
				</div>
				<div className="woon-modal__body">
					<p className="woon-modal__subtitle">
						<strong>{ row.name }</strong>{ ' ' }
						{ row.sku && `(${ row.sku })` }
					</p>

					{ error && (
						<div className="woon-notice woon-notice--error">
							<p>{ error }</p>
						</div>
					) }

					{ ! fresh && ! error && (
						<div className="woon-loading">
							{ __(
								'Leyendo los dos stocks…',
								'woonderful-stock-sync-for-woocommerce'
							) }
						</div>
					) }

					{ fresh && (
						<>
							<table
								className="woon-table"
								style={ { marginBottom: 12 } }
							>
								<tbody>
									<tr>
										<td>
											{ __(
												'Stock en esta tienda',
												'woonderful-stock-sync-for-woocommerce'
											) }
										</td>
										<td className="wss-r wss-num">
											{ here ?? '—' }
										</td>
									</tr>
									<tr>
										<td>
											{ __(
												'Stock en la otra tienda',
												'woonderful-stock-sync-for-woocommerce'
											) }
										</td>
										<td className="wss-r wss-num">
											{ there ?? '—' }
										</td>
									</tr>
								</tbody>
							</table>
							<p className="woon-muted">
								{ equal
									? __(
											'Las dos tiendas ya tienen el mismo stock: no hay nada que igualar.',
											'woonderful-stock-sync-for-woocommerce'
									  )
									: __(
											'Contá lo que hay en el depósito y elegí cuál de los dos números es el correcto: las dos tiendas quedan con ese. Corregirlo a mano no sirve, porque el cambio se manda a la otra como diferencia.',
											'woonderful-stock-sync-for-woocommerce'
									  ) }
							</p>
						</>
					) }
				</div>
				<div className="woon-modal__foot">
					<button
						className="woon-btn woon-btn--ghost"
						onClick={ onClose }
					>
						{ equal
							? __(
									'Cerrar',
									'woonderful-stock-sync-for-woocommerce'
							  )
							: __(
									'Cancelar',
									'woonderful-stock-sync-for-woocommerce'
							  ) }
					</button>
					{ fresh && ! equal && null !== here && null !== there && (
						<>
							<button
								className="woon-btn woon-btn--secondary"
								onClick={ () => equalize( 'there' ) }
								disabled={ null !== busy }
							>
								{ sprintf(
									/* translators: %d: el stock de la otra tienda. */
									__(
										'Vale el de la otra (%d)',
										'woonderful-stock-sync-for-woocommerce'
									),
									there
								) }
							</button>
							<button
								className="woon-btn woon-btn--primary"
								onClick={ () => equalize( 'here' ) }
								disabled={ null !== busy }
							>
								{ sprintf(
									/* translators: %d: el stock de esta tienda. */
									__(
										'Vale el de esta tienda (%d)',
										'woonderful-stock-sync-for-woocommerce'
									),
									here
								) }
							</button>
						</>
					) }
				</div>
			</div>
		</div>
	);
}
