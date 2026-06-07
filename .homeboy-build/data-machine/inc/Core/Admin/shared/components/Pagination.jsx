/**
 * Pagination Component
 *
 * Shared pagination controls for admin list views.
 * Per-page configuration is managed via Settings, not inline selectors.
 */

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const Pagination = ( {
	page,
	perPage,
	total,
	onPageChange,
	itemLabel = 'items',
} ) => {
	const totalPages = Math.ceil( total / perPage );
	const startItem = total > 0 ? ( page - 1 ) * perPage + 1 : 0;
	const endItem = Math.min( page * perPage, total );

	const hasPrevious = page > 1;
	const hasNext = page < totalPages;

	if ( total <= perPage ) {
		return null;
	}

	return (
		<div className="datamachine-pagination">
			<div className="datamachine-pagination__info">
				{ total > 0 ? (
					<span>
						{ __( 'Showing', 'data-machine' ) }{ ' ' }
						<strong>
							{ startItem }-{ endItem }
						</strong>{ ' ' }
						{ __( 'of', 'data-machine' ) }{ ' ' }
						<strong>{ total }</strong> { itemLabel }
					</span>
				) : (
					<span>
						{ __( 'No', 'data-machine' ) } { itemLabel }
					</span>
				) }
			</div>

			<div className="datamachine-pagination__controls">
				<div className="datamachine-pagination__buttons">
					<Button
						variant="secondary"
						disabled={ ! hasPrevious }
						onClick={ () => onPageChange( page - 1 ) }
					>
						{ __( 'Previous', 'data-machine' ) }
					</Button>

					<span className="datamachine-pagination__current">
						{ page } / { totalPages || 1 }
					</span>

					<Button
						variant="secondary"
						disabled={ ! hasNext }
						onClick={ () => onPageChange( page + 1 ) }
					>
						{ __( 'Next', 'data-machine' ) }
					</Button>
				</div>
			</div>
		</div>
	);
};

export default Pagination;
