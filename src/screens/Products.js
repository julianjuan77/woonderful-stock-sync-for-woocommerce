import { __ } from '@wordpress/i18n';

// Se completa en las tareas siguientes del bloque 8.
export default function Products() {
	return (
		<div className="woon-card">
			<h2>
				{ __( 'Productos', 'woonderful-stock-sync-for-woocommerce' ) }
			</h2>
			<p className="woon-muted">
				{ __(
					'La vinculación de los productos de las dos tiendas.',
					'woonderful-stock-sync-for-woocommerce'
				) }
			</p>
		</div>
	);
}
