// La de woonderful-sync.
export default function StatCard( {
	title,
	value,
	onClick,
	urgent = false,
	warning = false,
} ) {
	let modifier = '';
	if ( urgent ) {
		modifier = 'woon-stat-card--urgent';
	} else if ( warning ) {
		modifier = 'woon-stat-card--warning';
	}
	return (
		<div
			className={ `woon-stat-card ${ modifier } ${
				onClick ? 'woon-stat-card--clickable' : ''
			}` }
			onClick={ onClick }
			onKeyDown={
				onClick
					? ( e ) =>
							( e.key === 'Enter' || e.key === ' ' ) &&
							onClick( e )
					: undefined
			}
			role={ onClick ? 'button' : undefined }
			tabIndex={ onClick ? 0 : undefined }
		>
			<div className="woon-stat-card__value">
				{ value !== undefined && value !== null ? value : '—' }
			</div>
			<div className="woon-stat-card__label">{ title }</div>
		</div>
	);
}
