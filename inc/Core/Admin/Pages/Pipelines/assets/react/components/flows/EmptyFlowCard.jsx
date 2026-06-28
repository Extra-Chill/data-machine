/**
 * Empty Flow Card Component
 *
 * Placeholder card for adding new flows to a pipeline.
 */

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Empty Flow Card Component
 *
 * @param {Object}   props            - Component props
 * @param {number}   props.pipelineId - Pipeline ID
 * @param {Function} props.onAddFlow  - Add flow handler
 * @return {React.ReactElement} Empty flow card
 */
export default function EmptyFlowCard( { pipelineId, onAddFlow } ) {
	return (
		<div className="datamachine-flow-card--empty">
			<Button
				variant="secondary"
				onClick={ () => onAddFlow && onAddFlow( pipelineId ) }
			>
				{ __( 'Add Flow', 'data-machine' ) }
			</Button>
		</div>
	);
}
