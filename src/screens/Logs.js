import { __ } from '@wordpress/i18n';

// Se completa en las tareas siguientes del bloque 8.
export default function Logs() {
	return (
		<div className="woon-card">
			<h2>
				{ __( 'Registros', 'woonderful-stock-sync-for-woocommerce' ) }
			</h2>
			<p className="woon-muted">
				{ __(
					'Lo que pasó y no es un movimiento de stock.',
					'woonderful-stock-sync-for-woocommerce'
				) }
			</p>
		</div>
	);
}
