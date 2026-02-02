/**
 * Flow Steps Container Component
 *
 * Container for flow step list with data flow arrows.
 */

/**
 * WordPress dependencies
 */
import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import FlowStepCard from './FlowStepCard';
import DataFlowArrow from '../shared/DataFlowArrow';
import { isSameId } from '../../utils/ids';

/**
 * Flow Steps Container Component.
 *
 * @param {Object}   props                  - Component props.
 * @param {number}   props.flowId           - Flow ID.
 * @param {number}   props.pipelineId       - Pipeline ID.
 * @param {Object}   props.flowConfig       - Flow configuration (keyed by flow_step_id).
 * @param {Object}   props.pipelineConfig   - Pipeline configuration (keyed by pipeline_step_id).
 * @param {Function} props.onStepConfigured - Configure step handler.
 * @param {Function} props.onQueueClick     - Queue button click handler (opens modal).
 * @return {JSX.Element} Flow steps container.
 */
export default function FlowSteps( {
	flowId,
	pipelineId,
	flowConfig,
	pipelineConfig,
	onStepConfigured,
	onQueueClick,
} ) {
	// Extract prompt_queue from flow config (it's at the flow level, not step level)
	const promptQueue = flowConfig?.prompt_queue || [];

	/**
	 * Sort flow steps by execution order and match with pipeline steps
	 */
	const sortedFlowSteps = useMemo( () => {
		if (
			! flowConfig ||
			! pipelineConfig ||
			typeof pipelineConfig !== 'object'
		) {
			return [];
		}

		// Convert flow config object to array (excluding prompt_queue which is flow-level)
		const flowStepsArray = Object.entries( flowConfig )
			.filter( ( [ key ] ) => key !== 'prompt_queue' )
			.map( ( [ flowStepId, config ] ) => ( {
				flowStepId,
				...config,
			} ) );

		// Sort by execution order
		const sorted = flowStepsArray.sort( ( a, b ) => {
			const orderA = a.execution_order || 0;
			const orderB = b.execution_order || 0;
			return orderA - orderB;
		} );

		// Convert pipeline config to array for matching
		const pipelineStepsArray = Object.values( pipelineConfig );

		// Match with pipeline steps
		return sorted.map( ( flowStep ) => {
			const pipelineStep = pipelineStepsArray.find( ( ps ) =>
				isSameId( ps.pipeline_step_id, flowStep.pipeline_step_id )
			);

			return {
				flowStepId: flowStep.flowStepId,
				flowStepConfig: flowStep,
				pipelineStep: pipelineStep || {
					pipeline_step_id: flowStep.pipeline_step_id,
					step_type: flowStep.step_type,
					label: 'Unknown Step',
				},
			};
		} );
	}, [ flowConfig, pipelineConfig ] );

	/**
	 * Empty state
	 */
	if ( sortedFlowSteps.length === 0 ) {
		return (
			<div className="datamachine-flow-steps--empty">
				<p>
					{ __(
						'No steps configured for this flow.',
						'data-machine'
					) }
				</p>
			</div>
		);
	}

	/**
	 * Render steps with arrows
	 * Structure: card, arrow, card, arrow, card
	 */
	const renderItems = () => {
		const items = [];

		sortedFlowSteps.forEach( ( step, index ) => {
			// Add card
			items.push(
				<div
					key={ step.flowStepId }
					className="datamachine-flow-step-container"
				>
					<FlowStepCard
						flowId={ flowId }
						pipelineId={ pipelineId }
						flowStepId={ step.flowStepId }
						flowStepConfig={ step.flowStepConfig }
						pipelineStep={ step.pipelineStep }
						pipelineConfig={ pipelineConfig }
						promptQueue={ promptQueue }
						onConfigure={ onStepConfigured }
						onQueueClick={ onQueueClick }
					/>
				</div>
			);

			// Add arrow after card (except after last card)
			if ( index < sortedFlowSteps.length - 1 ) {
				items.push( <DataFlowArrow key={ `arrow-${ index }` } /> );
			}
		} );

		return items;
	};

	return <div className="datamachine-flow-steps">{ renderItems() }</div>;
}
