import { __ } from '@wordpress/i18n';

// El stock de antes y el de después de un movimiento, como en woonderful-sync: la diferencia
// con su signo y color, y los dos números.
export default function StockChange( { stocks } ) {
	if ( ! stocks ) {
		return null;
	}

	const delta = stocks.after - stocks.before;
	let color = 'var(--woon-text-muted)';
	if ( delta > 0 ) {
		color = 'var(--woon-success)';
	} else if ( delta < 0 ) {
		color = 'var(--woon-danger)';
	}

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
				color,
			} }
		>
			{ `${ delta > 0 ? '+' : '' }${ delta } (${ stocks.before } → ${
				stocks.after
			})` }
		</span>
	);
}
