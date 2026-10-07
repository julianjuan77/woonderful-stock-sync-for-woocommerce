import { __ } from '@wordpress/i18n';

// El stock de antes y el de después de un movimiento: la diferencia con su signo, y los dos
// números. Sin verde ni rojo: un descuento no es un error.
export default function StockChange( { stocks } ) {
	if ( ! stocks ) {
		return null;
	}

	const delta = stocks.after - stocks.before;

	return (
		<span
			title={ __(
				'Stock antes → después',
				'woonderful-stock-sync-for-woocommerce'
			) }
			style={ {
				fontFamily: 'var(--woon-font-mono)',
				fontSize: 'var(--woon-fs-sm)',
				fontWeight: 600,
				whiteSpace: 'nowrap',
				color: 'var(--woon-ink)',
			} }
		>
			{ `${ delta > 0 ? '+' : '' }${ delta } (${ stocks.before } → ${
				stocks.after
			})` }
		</span>
	);
}
