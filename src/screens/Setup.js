import { __ } from '@wordpress/i18n';

// Se completa en las tareas siguientes del bloque 8.
export default function Setup() {
	return (
		<div className="woon-card">
			<h2>
				{ __(
					'Configuración inicial',
					'woonderful-stock-sync-for-woocommerce'
				) }
			</h2>
			<p className="woon-muted">
				{ __(
					'Los requisitos, el nombre y el rol de esta tienda, el emparejamiento y los avisos.',
					'woonderful-stock-sync-for-woocommerce'
				) }
			</p>
		</div>
	);
}
