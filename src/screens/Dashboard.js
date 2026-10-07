import { __ } from '@wordpress/i18n';

// Se completa en las tareas siguientes del bloque 8.
export default function Dashboard() {
	return (
		<div className="woon-card">
			<h2>
				{ __( 'Resumen', 'woonderful-stock-sync-for-woocommerce' ) }
			</h2>
			<p className="woon-muted">
				{ __(
					'El estado de la otra tienda, la última reconciliación y las discrepancias de las dos tiendas.',
					'woonderful-stock-sync-for-woocommerce'
				) }
			</p>
		</div>
	);
}
