/**
 * Flow card component.
 */

/**
 * WordPress dependencies
 */
import { useCallback, useState, useRef, useEffect } from '@wordpress/element';
/**
 * External dependencies
 */
import { Card, CardBody, CardDivider } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import FlowHeader from './FlowHeader';
import FlowSteps from './FlowSteps';
import FlowFooter from './FlowFooter';

import {
	useDeleteFlow,
	useDuplicateFlow,
	useRunFlow,
} from '../../queries/flows';
import { useStepTypes } from '../../queries/config';
import { useUIStore } from '../../stores/uiStore';
import useFlowReconciliation from '../../hooks/useFlowReconciliation';

import { MODAL_TYPES } from '../../utils/constants';
import { isSameId } from '../../utils/ids';

export default function FlowCard( props ) {
	const { flow, pipelineConfig, onFlowDeleted, onFlowDuplicated } = props;

	return (
		<FlowCardContent
			flow={ flow }
			pipelineConfig={ pipelineConfig }
			onFlowDeleted={ onFlowDeleted }
			onFlowDuplicated={ onFlowDuplicated }
		/>
	);
}

function FlowCardContent( props ) {
	const { flow, pipelineConfig, onFlowDeleted, onFlowDuplicated } = props;

	// Use mutations
	const deleteFlowMutation = useDeleteFlow();
	const duplicateFlowMutation = useDuplicateFlow();
	const runFlowMutation = useRunFlow();
	const { data: stepTypes = {} } = useStepTypes();
	const { openModal } = useUIStore();
	const { optimisticLastRunDisplay, reconcile } = useFlowReconciliation();

	// Run success state for temporary button feedback
	const [ runSuccess, setRunSuccess ] = useState( false );
	const successTimeout = useRef( null );

	// Cleanup timeout on unmount
	useEffect( () => {
		return () => {
			if ( successTimeout.current ) {
				clearTimeout( successTimeout.current );
			}
		};
	}, [] );

	// Presentational: Use flow data passed as prop
	const currentFlowData = flow;

	/**
	 * Handle flow name change
	 */
	const handleNameChange = useCallback( () => {
		// Name change already saved by FlowHeader
		// Queries will automatically refetch
	}, [] );

	/**
	 * Handle flow deletion
	 */
	const handleDelete = useCallback(
		async ( flowId ) => {
			try {
				await deleteFlowMutation.mutateAsync( {
					flowId,
					pipelineId: currentFlowData.pipeline_id,
				} );
				// Delete affects pipeline - trigger pipeline refresh
				if ( onFlowDeleted ) {
					onFlowDeleted( flowId );
				}
			} catch ( error ) {
				// eslint-disable-next-line no-console
				console.error( 'Flow deletion error:', error );
				// eslint-disable-next-line no-alert, no-undef
				alert(
					__(
						'An error occurred while deleting the flow',
						'data-machine'
					)
				);
			}
		},
		[ deleteFlowMutation, onFlowDeleted, currentFlowData.pipeline_id ]
	);

	/**
	 * Handle flow duplication
	 */
	const handleDuplicate = useCallback(
		async ( flowId ) => {
			try {
				await duplicateFlowMutation.mutateAsync( {
					flowId,
					pipelineId: currentFlowData.pipeline_id,
				} );
				// Duplicate affects pipeline - trigger pipeline refresh
				if ( onFlowDuplicated ) {
					onFlowDuplicated( flowId );
				}
			} catch ( error ) {
				// eslint-disable-next-line no-console
				console.error( 'Flow duplication error:', error );
				// eslint-disable-next-line no-alert, no-undef
				alert(
					__(
						'An error occurred while duplicating the flow',
						'data-machine'
					)
				);
			}
		},
		[ duplicateFlowMutation, onFlowDuplicated, currentFlowData.pipeline_id ]
	);

	/**
	 * Handle flow execution
	 */
	const handleRun = useCallback(
		async ( flowId ) => {
			try {
				await runFlowMutation.mutateAsync( flowId );
				setRunSuccess( true );
				successTimeout.current = setTimeout( () => {
					setRunSuccess( false );
				}, 2000 );

				reconcile( {
					flowId,
					pipelineId: currentFlowData.pipeline_id,
					baselineLastRun: currentFlowData.last_run,
				} );
			} catch ( error ) {
				// eslint-disable-next-line no-console
				console.error( 'Flow execution error:', error );
				// eslint-disable-next-line no-alert, no-undef
				alert(
					__(
						'An error occurred while running the flow',
						'data-machine'
					)
				);
			}
		},
		[
			currentFlowData.last_run,
			currentFlowData.pipeline_id,
			reconcile,
			runFlowMutation,
		]
	);

	/**
	 * Handle schedule button click
	 */
	const handleSchedule = useCallback(
		( flowId ) => {
			openModal( MODAL_TYPES.FLOW_SCHEDULE, {
				flowId,
				flowName: currentFlowData.flow_name,
				currentInterval:
					currentFlowData.scheduling_config?.interval || 'manual',
			} );
		},
		[
			currentFlowData.flow_name,
			currentFlowData.scheduling_config,
			openModal,
		]
	);

	/**
	 * Handle memory files button click - opens flow memory files modal
	 */
	const handleMemoryFiles = useCallback(
		( flowId ) => {
			openModal( MODAL_TYPES.FLOW_MEMORY_FILES, {
				flowId,
				flowName: currentFlowData.flow_name,
			} );
		},
		[ currentFlowData.flow_id, currentFlowData.flow_name, openModal ]
	);

	/**
	 * Handle queue button click - opens queue management modal
	 */
	const handleQueue = useCallback(
		( flowStepId ) => {
			openModal( MODAL_TYPES.FLOW_QUEUE, {
				flowId: currentFlowData.flow_id,
				flowStepId,
				flowName: currentFlowData.flow_name,
				pipelineId: currentFlowData.pipeline_id,
			} );
		},
		[ currentFlowData.flow_id, currentFlowData.flow_name, currentFlowData.pipeline_id, openModal ]
	);

	/**
	 * Handle step configuration.
	 *
	 * @param {string}      flowStepId      Flow step ID.
	 * @param {string|null} specificHandler  Handler slug to configure, or null.
	 * @param {boolean}     addMode         When true, opens selection modal to add another handler.
	 */
	const handleStepConfigured = useCallback(
		( flowStepId, specificHandler = null, addMode = false ) => {
			const flowStepConfig =
				currentFlowData.flow_config?.[ flowStepId ] || {};
			const pipelineStepId = flowStepConfig.pipeline_step_id;
			const pipelineStep = Object.values( pipelineConfig ).find( ( s ) =>
				isSameId( s.pipeline_step_id, pipelineStepId )
			);

			const stepType = pipelineStep?.step_type || flowStepConfig.step_type;
			const isMultiHandlerStep = stepTypes[ stepType ]?.multi_handler === true;
			const handlerSlugs = isMultiHandlerStep
				? ( flowStepConfig.handler_slugs || [] )
				: ( flowStepConfig.handler_slug ? [ flowStepConfig.handler_slug ] : [] );
			const primarySlug = handlerSlugs[0] || '';

			// Build data for handler modals
			const data = {
				flowStepId,
				handlerSlug: specificHandler || primarySlug,
				handlerSlugs,
				stepType,
				pipelineId: currentFlowData.pipeline_id,
				flowId: currentFlowData.flow_id,
				currentSettings: specificHandler
					? ( flowStepConfig.handler_configs?.[ specificHandler ] || {} )
					: ( isMultiHandlerStep ? ( flowStepConfig.handler_configs?.[ primarySlug ] || {} ) : ( flowStepConfig.handler_config || {} ) ),
				addMode,
			};

			if ( addMode || ! primarySlug ) {
				// Adding a new handler or no handler yet — open selection modal.
				openModal( MODAL_TYPES.HANDLER_SELECTION, {
					...data,
					addMode: true,
				} );
			} else if ( specificHandler ) {
				// Configuring a specific existing handler.
				openModal( MODAL_TYPES.HANDLER_SETTINGS, {
					...data,
					handlerSlug: specificHandler,
					currentSettings: isMultiHandlerStep ? ( flowStepConfig.handler_configs?.[ specificHandler ] || {} ) : ( flowStepConfig.handler_config || {} ),
				} );
			} else {
				// Default: open settings for primary handler.
				openModal( MODAL_TYPES.HANDLER_SETTINGS, data );
			}
		},
		[
			currentFlowData.flow_config,
			currentFlowData.pipeline_id,
			currentFlowData.flow_id,
			pipelineConfig,
			stepTypes,
			openModal,
		]
	);

	if ( ! currentFlowData ) {
		return null;
	}

	return (
		<Card
			className="datamachine-flow-card datamachine-flow-instance-card"
			size="large"
		>
			<CardBody>
				<FlowHeader
					flowId={ currentFlowData.flow_id }
					flowName={ currentFlowData.flow_name }
					onNameChange={ handleNameChange }
					onDelete={ handleDelete }
					onDuplicate={ handleDuplicate }
					onRun={ handleRun }
					onSchedule={ handleSchedule }
					onMemoryFiles={ handleMemoryFiles }
					runSuccess={ runSuccess }
				/>

				<CardDivider />

				<FlowSteps
					flowId={ currentFlowData.flow_id }
					pipelineId={ currentFlowData.pipeline_id }
					flowConfig={ currentFlowData.flow_config || {} }
					pipelineConfig={ pipelineConfig }
					onStepConfigured={ handleStepConfigured }
					onQueueClick={ handleQueue }
				/>

				<CardDivider />

				<FlowFooter
					flowId={ currentFlowData.flow_id }
					scheduling={ {
						interval: currentFlowData.scheduling_config?.interval,
						scheduled_time:
							currentFlowData.scheduling_config?.scheduled_time,
						last_run_display:
							optimisticLastRunDisplay ||
							currentFlowData.last_run_display,
						last_run_status: currentFlowData.last_run_status,
						is_running: currentFlowData.is_running,
						next_run_display: currentFlowData.next_run_display,
					} }
				/>
			</CardBody>
		</Card>
	);
}
