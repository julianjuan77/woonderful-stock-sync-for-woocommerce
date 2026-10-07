import { __ } from '@wordpress/i18n';

// Se completa en las tareas siguientes del bloque 8.
export default function Settings() {
	return (
		<div className="woon-card">
			<h2>
				{ __(
					'Configuración',
					'woonderful-stock-sync-for-woocommerce'
				) }
			</h2>
			<p className="woon-muted">
				{ __(
					'El emparejamiento, los avisos y la casilla de borrado.',
					'woonderful-stock-sync-for-woocommerce'
				) }
			</p>
		</div>
	);
}
