/**
 * Chat Query Invalidation Hook
 *
 * Analyzes tool_calls from chat responses and performs
 * granular query invalidation for affected data.
 */

/**
 * External dependencies
 */
import { useQueryClient } from '@tanstack/react-query';
/**
 * WordPress dependencies
 */
import { useCallback } from '@wordpress/element';
/**
 * Internal dependencies
 */
import { normalizeId } from '../utils/ids';

const PIPELINE_TOOLS = [
	'create_pipeline',
	'add_pipeline_step',
	'configure_pipeline_step',
];

const FLOW_TOOLS = [
	'create_pipeline',
	'create_flow',
	'update_flow',
	'copy_flow',
	'add_pipeline_step',
	'configure_flow_steps',
];

const HANDLER_TOOLS = [ 'authenticate_handler' ];

/**
 * Extract flow IDs from configure_flow_steps parameters.
 *
 * Handles multiple parameter formats:
 * - Single mode: flow_step_id format is {pipeline_step_id}_{flow_id}
 * - Cross-pipeline mode: updates array contains flow_id
 * - Bulk mode: flow_configs array contains flow_id
 *
 * @param {Object} params - Tool call parameters
 * @return {Set<string>} Set of flow IDs
 */
const extractFlowIdsFromParams = ( params ) => {
	const flowIds = new Set();

	// Single mode: flow_step_id format is {pipeline_step_id}_{flow_id}
	if ( params.flow_step_id ) {
		const parts = params.flow_step_id.split( '_' );
		if ( parts.length >= 2 ) {
			flowIds.add( parts[ parts.length - 1 ] );
		}
	}

	// Cross-pipeline mode: updates array contains flow_id
	if ( Array.isArray( params.updates ) ) {
		params.updates.forEach( ( update ) => {
			if ( update.flow_id ) {
				flowIds.add( String( update.flow_id ) );
			}
		} );
	}

	// Bulk mode with flow_configs
	if ( Array.isArray( params.flow_configs ) ) {
		params.flow_configs.forEach( ( config ) => {
			if ( config.flow_id ) {
				flowIds.add( String( config.flow_id ) );
			}
		} );
	}

	return flowIds;
};

export function useChatQueryInvalidation() {
	const queryClient = useQueryClient();

	const invalidateFromToolCalls = useCallback(
		( toolCalls, selectedPipelineId ) => {
			if ( ! toolCalls?.length ) {
				return;
			}

			let shouldInvalidatePipelines = false;
			let shouldInvalidateHandlers = false;
			const pipelineIdsToInvalidate = new Set();
			const flowIdsToInvalidate = new Set();

			for ( const toolCall of toolCalls ) {
				const toolName = toolCall.name;
				const params = toolCall.parameters || {};

				if ( PIPELINE_TOOLS.includes( toolName ) ) {
					shouldInvalidatePipelines = true;
				}

				if ( FLOW_TOOLS.includes( toolName ) ) {
					const pipelineId =
						params.pipeline_id || params.target_pipeline_id;

					if ( pipelineId ) {
						pipelineIdsToInvalidate.add(
							normalizeId( pipelineId )
						);
					}

					// Extract flow IDs from configure_flow_steps
					if ( toolName === 'configure_flow_steps' ) {
						const flowIds = extractFlowIdsFromParams( params );
						flowIds.forEach( ( id ) =>
							flowIdsToInvalidate.add( id )
						);
					}

					// Fallback to selected pipeline
					if ( ! pipelineId && selectedPipelineId ) {
						pipelineIdsToInvalidate.add(
							normalizeId( selectedPipelineId )
						);
					}
				}

				if ( HANDLER_TOOLS.includes( toolName ) ) {
					shouldInvalidateHandlers = true;
				}
			}

			if ( shouldInvalidatePipelines ) {
				queryClient.invalidateQueries( { queryKey: [ 'pipelines' ] } );
			}

			for ( const pipelineId of pipelineIdsToInvalidate ) {
				queryClient.invalidateQueries( {
					queryKey: [ 'flows', pipelineId ],
				} );
			}

			// Invalidate specific flow queries
			for ( const flowId of flowIdsToInvalidate ) {
				queryClient.invalidateQueries( {
					queryKey: [ 'flows', 'single', normalizeId( flowId ) ],
				} );
			}

			if ( shouldInvalidateHandlers ) {
				queryClient.invalidateQueries( { queryKey: [ 'handlers' ] } );
			}
		},
		[ queryClient ]
	);

	return { invalidateFromToolCalls };
}
