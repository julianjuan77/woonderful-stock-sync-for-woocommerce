import { __ } from '@wordpress/i18n';

export default function Pagination( { page, total, perPage, onPageChange } ) {
	const totalPages = Math.ceil( total / perPage );

	if ( totalPages <= 1 ) {
		return null;
	}

	const start = ( page - 1 ) * perPage + 1;
	const end = Math.min( page * perPage, total );

	return (
		<div className="woon-pagination">
			<span className="woon-pagination__info">
				{ start }–{ end }{ ' ' }
				{ __( 'de', 'woonderful-stock-sync-for-woocommerce' ) }{ ' ' }
				{ total }
			</span>
			<div className="woon-pagination__controls">
				<button
					className="woon-btn woon-btn--ghost woon-btn--sm"
					disabled={ page <= 1 }
					onClick={ () => onPageChange( 1 ) }
					aria-label={ __(
						'Primera página',
						'woonderful-stock-sync-for-woocommerce'
					) }
				>
					«
				</button>
				<button
					className="woon-btn woon-btn--ghost woon-btn--sm"
					disabled={ page <= 1 }
					onClick={ () => onPageChange( page - 1 ) }
					aria-label={ __(
						'Página anterior',
						'woonderful-stock-sync-for-woocommerce'
					) }
				>
					‹
				</button>
				<span className="woon-pagination__current">
					{ page } / { totalPages }
				</span>
				<button
					className="woon-btn woon-btn--ghost woon-btn--sm"
					disabled={ page >= totalPages }
					onClick={ () => onPageChange( page + 1 ) }
					aria-label={ __(
						'Página siguiente',
						'woonderful-stock-sync-for-woocommerce'
					) }
				>
					›
				</button>
				<button
					className="woon-btn woon-btn--ghost woon-btn--sm"
					disabled={ page >= totalPages }
					onClick={ () => onPageChange( totalPages ) }
					aria-label={ __(
						'Última página',
						'woonderful-stock-sync-for-woocommerce'
					) }
				>
					»
				</button>
			</div>
		</div>
	);
}
