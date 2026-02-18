/**
 * Pipeline Card Component
 *
 * Presentational component that displays pipeline data and renders child components.
 * @pattern Presentational - Receives pipeline and flows data as props
 */

/**
 * WordPress dependencies
 */
import { useCallback } from '@wordpress/element';
import { Card, CardBody, CardDivider } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import { useDeletePipelineStep } from '../../queries/pipelines';
import { useUIStore } from '../../stores/uiStore';
import PipelineHeader from './PipelineHeader';
import PipelineSteps from './PipelineSteps';
import FlowsSection from '../flows/FlowsSection';
import { MODAL_TYPES } from '../../utils/constants';

/**
 * Pipeline Card Component
 *
 * @param {Object}   props                   - Component props
 * @param {Object}   props.pipeline          - Pipeline data
 * @param {Array}    props.flows             - Associated flows
 * @param {number}   props.flowsTotal        - Total number of flows
 * @param {number}   props.flowsPage         - Current page
 * @param {number}   props.flowsPerPage      - Items per page
 * @param {Function} props.onFlowsPageChange - Page change handler
 * @return {React.ReactElement} Pipeline card
 */
export default function PipelineCard( {
	pipeline,
	flows,
	flowsTotal = 0,
	flowsPage = 1,
	flowsPerPage = 20,
	onFlowsPageChange,
} ) {
	// Use mutations
	const deleteStepMutation = useDeletePipelineStep();
	const { openModal } = useUIStore();

	if ( ! pipeline ) {
		return null;
	}

	/**
	 * Handle pipeline name change
	 */
	const handleNameChange = useCallback( ( newName ) => {
		// Name change already saved by PipelineHeader
		// Queries will automatically refetch
	}, [] );

	/**
	 * Handle pipeline deletion
	 */
	const handleDelete = useCallback( ( pipelineId ) => {
		// Deletion already complete - queries will automatically refetch
	}, [] );

	/**
	 * Handle step addition
	 */
	const handleStepAdded = useCallback(
		( pipelineId ) => {
			const nextOrder = ( pipeline.pipeline_steps || [] ).length + 1;
			openModal( MODAL_TYPES.STEP_SELECTION, {
				pipelineId,
				nextExecutionOrder: nextOrder,
			} );
		},
		[ pipeline.pipeline_steps, openModal ]
	);

	/**
	 * Handle step removal
	 */
	const handleStepRemoved = useCallback(
		async ( stepId ) => {
			try {
				await deleteStepMutation.mutateAsync( {
					pipelineId: pipeline.pipeline_id,
					stepId,
				} );
			} catch ( error ) {
				console.error( 'Step deletion error:', error );
				alert(
					__(
						'An error occurred while deleting the step',
						'data-machine'
					)
				);
			}
		},
		[ pipeline.pipeline_id, deleteStepMutation ]
	);

	/**
	 * Handle step configuration
	 */
	const handleStepConfigured = useCallback(
		( step ) => {
			const currentConfig =
				pipeline.pipeline_config?.[ step.pipeline_step_id ] || {};
			openModal( MODAL_TYPES.CONFIGURE_STEP, {
				pipelineId: pipeline.pipeline_id,
				pipelineStepId: step.pipeline_step_id,
				stepType: step.step_type,
				currentConfig,
			} );
		},
		[ pipeline.pipeline_id, pipeline.pipeline_config, openModal ]
	);

	/**
	 * Handle context files modal open
	 */
	const handleOpenContextFiles = useCallback( () => {
		openModal( MODAL_TYPES.CONTEXT_FILES, {
			pipelineId: pipeline.pipeline_id,
		} );
	}, [ pipeline.pipeline_id, openModal ] );

	/**
	 * Handle memory files modal open
	 */
	const handleOpenMemoryFiles = useCallback( () => {
		openModal( MODAL_TYPES.MEMORY_FILES, {
			pipelineId: pipeline.pipeline_id,
		} );
	}, [ pipeline.pipeline_id, openModal ] );

	return (
		<Card className="datamachine-pipeline-card" size="large">
			<CardBody>
				<PipelineHeader
					pipelineId={ pipeline.pipeline_id }
					pipelineName={ pipeline.pipeline_name }
					onNameChange={ handleNameChange }
					onDelete={ handleDelete }
					onOpenContextFiles={ handleOpenContextFiles }
					onOpenMemoryFiles={ handleOpenMemoryFiles }
				/>

				<CardDivider />

				<PipelineSteps
					pipelineId={ pipeline.pipeline_id }
					pipelineConfig={ pipeline.pipeline_config || {} }
					onStepAdded={ handleStepAdded }
					onStepRemoved={ handleStepRemoved }
					onStepConfigured={ handleStepConfigured }
				/>

				<CardDivider />

				<FlowsSection
					pipelineId={ pipeline.pipeline_id }
					flows={ flows }
					pipelineConfig={ pipeline.pipeline_config || {} }
					total={ flowsTotal }
					page={ flowsPage }
					perPage={ flowsPerPage }
					onPageChange={ onFlowsPageChange }
				/>
			</CardBody>
		</Card>
	);
}
