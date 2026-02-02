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
import { useQueryClient } from '@tanstack/react-query';
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
import { fetchFlow } from '../../utils/api';
import { useUIStore } from '../../stores/uiStore';

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
	const queryClient = useQueryClient();
	const { openModal } = useUIStore();

	// Run success state for temporary button feedback
	const [ runSuccess, setRunSuccess ] = useState( false );
	const [ optimisticLastRunDisplay, setOptimisticLastRunDisplay ] =
		useState( null );
	const reconcileTokenRef = useRef( 0 );
	const successTimeout = useRef( null );

	// Cleanup timeout on unmount
	useEffect( () => {
		return () => {
			reconcileTokenRef.current += 1;
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

	const sleep = useCallback(
		( ms ) => new Promise( ( r ) => setTimeout( r, ms ) ),
		[]
	);

	const reconcileFlowAfterRun = useCallback(
		async ( flowId, pipelineId, baselineLastRun, token ) => {
			const delays = [ 500, 1000, 2000, 4000, 8000 ];

			for ( const delay of delays ) {
				await sleep( delay );

				if ( reconcileTokenRef.current !== token ) {
					return;
				}

				try {
					const response = await fetchFlow( flowId );
					if ( ! response?.success || ! response?.data ) {
						continue;
					}

					const updatedFlow = response.data;

					queryClient.setQueryData(
						[ 'flows', 'single', flowId ],
						updatedFlow
					);

					if ( pipelineId ) {
						queryClient.setQueriesData(
							{ queryKey: [ 'flows', pipelineId ], exact: false },
							( oldData ) => {
								if (
									! oldData?.flows ||
									! Array.isArray( oldData.flows )
								) {
									return oldData;
								}

								return {
									...oldData,
									flows: oldData.flows.map(
										( existingFlow ) =>
											isSameId(
												existingFlow.flow_id,
												flowId
											)
												? updatedFlow
												: existingFlow
									),
								};
							}
						);
					}

					if (
						updatedFlow.last_run &&
						updatedFlow.last_run !== baselineLastRun
					) {
						setOptimisticLastRunDisplay( null );
						return;
					}
				} catch ( err ) {
					continue;
				}
			}
		},
		[ queryClient, sleep ]
	);

	/**
	 * Handle flow execution
	 */
	const handleRun = useCallback(
		async ( flowId ) => {
			const token = reconcileTokenRef.current + 1;
			reconcileTokenRef.current = token;

			setOptimisticLastRunDisplay( __( 'Queued', 'data-machine' ) );

			try {
				await runFlowMutation.mutateAsync( flowId );
				setRunSuccess( true );
				successTimeout.current = setTimeout( () => {
					setRunSuccess( false );
				}, 2000 );

				reconcileFlowAfterRun(
					flowId,
					currentFlowData.pipeline_id,
					currentFlowData.last_run,
					token
				);
			} catch ( error ) {
				setOptimisticLastRunDisplay( null );
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
			reconcileFlowAfterRun,
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
	 * Handle queue button click - opens queue management modal
	 */
	const handleQueue = useCallback(
		( flowStepId ) => {
			openModal( MODAL_TYPES.FLOW_QUEUE, {
				flowId: currentFlowData.flow_id,
				flowStepId,
				flowName: currentFlowData.flow_name,
			} );
		},
		[ currentFlowData.flow_id, currentFlowData.flow_name, openModal ]
	);

	/**
	 * Handle step configuration
	 */
	const handleStepConfigured = useCallback(
		( flowStepId ) => {
			const flowStepConfig =
				currentFlowData.flow_config?.[ flowStepId ] || {};
			const pipelineStepId = flowStepConfig.pipeline_step_id;
			const pipelineStep = Object.values( pipelineConfig ).find( ( s ) =>
				isSameId( s.pipeline_step_id, pipelineStepId )
			);

			// Build data for handler modals
			const data = {
				flowStepId,
				handlerSlug: flowStepConfig.handler_slug || '',
				stepType: pipelineStep?.step_type || flowStepConfig.step_type,
				pipelineId: currentFlowData.pipeline_id,
				flowId: currentFlowData.flow_id,
				currentSettings: flowStepConfig.handler_config || {},
			};

			// If no handler selected, open handler selection modal first
			if ( ! flowStepConfig.handler_slug ) {
				openModal( MODAL_TYPES.HANDLER_SELECTION, {
					stepType: data.stepType,
					flowStepId: data.flowStepId,
					pipelineId: data.pipelineId,
					flowId: data.flowId,
				} );
			} else {
				// If handler already selected, open settings modal directly
				openModal( MODAL_TYPES.HANDLER_SETTINGS, data );
			}
		},
		[
			currentFlowData.flow_config,
			currentFlowData.pipeline_id,
			currentFlowData.flow_id,
			pipelineConfig,
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
