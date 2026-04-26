/**
 * Flow Queue Queries and Mutations
 *
 * TanStack Query hooks for flow queue operations.
 */

/**
 * External dependencies
 */
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
/**
 * Internal dependencies
 */
import {
	fetchFlowQueue,
	addToFlowQueue,
	clearFlowQueue,
	removeFromFlowQueue,
	updateFlowQueueItem,
	updateFlowQueueMode,
} from '../utils/api';
import { normalizeId } from '../utils/ids';

/**
 * Valid queue access modes (must match the server-side enum on
 * /flows/{id}/queue/mode and AIStep / FetchStep config).
 */
const QUEUE_MODES = [ 'drain', 'loop', 'static' ];

/**
 * Normalize a queue mode value, falling back to "static" for unknown
 * or missing values. Mirrors the server-side default in AIStep and
 * FetchStep when the key is absent on the flow_step_config.
 *
 * @param {*} value Raw value from the server or cache.
 * @return {string} Normalized mode.
 */
const normalizeQueueMode = ( value ) =>
	QUEUE_MODES.includes( value ) ? value : 'static';

/**
 * Invalidate flows cache scoped to a specific pipeline when available.
 * Falls back to invalidating all flows if pipelineId is not provided.
 *
 * @param {Object}        queryClient - TanStack Query client
 * @param {number|string} pipelineId  - Pipeline ID (optional)
 */
const invalidateFlows = ( queryClient, pipelineId ) => {
	const cachedPipelineId = pipelineId ? normalizeId( pipelineId ) : null;
	if ( cachedPipelineId ) {
		queryClient.invalidateQueries( {
			queryKey: [ 'flows', cachedPipelineId ],
		} );
	} else {
		queryClient.invalidateQueries( {
			queryKey: [ 'flows' ],
		} );
	}
};

/**
 * Fetch queue for a flow
 *
 * @param {number} flowId     - Flow ID
 * @param          flowStepId
 * @return {Object} Query result with queue data
 */
export const useFlowQueue = ( flowId, flowStepId ) => {
	const cachedFlowId = normalizeId( flowId );
	const cachedFlowStepId = flowStepId ? String( flowStepId ) : null;

	return useQuery( {
		queryKey: [ 'flowQueue', cachedFlowId, cachedFlowStepId ],
		queryFn: async () => {
			const response = await fetchFlowQueue( flowId, flowStepId );
			if ( ! response.success ) {
				throw new Error( response.message || 'Failed to fetch queue' );
			}
			return {
				queue: response.data?.queue || [],
				count: response.data?.count || 0,
				queueMode: normalizeQueueMode( response.data?.queue_mode ),
			};
		},
		enabled: !! cachedFlowId && !! cachedFlowStepId,
	} );
};

/**
 * Add prompt(s) to flow queue
 *
 * @return {Object} Mutation result
 */
export const useAddToQueue = () => {
	const queryClient = useQueryClient();

	return useMutation( {
		mutationFn: ( { flowId, flowStepId, prompts } ) =>
			addToFlowQueue( flowId, flowStepId, prompts ),
		onSuccess: ( response, { flowId, flowStepId, pipelineId } ) => {
			if ( ! response?.success ) {
				return;
			}

			const cachedFlowId = normalizeId( flowId );
			const cachedFlowStepId = flowStepId ? String( flowStepId ) : null;
			queryClient.invalidateQueries( {
				queryKey: [ 'flowQueue', cachedFlowId, cachedFlowStepId ],
			} );

			invalidateFlows( queryClient, pipelineId );
		},
	} );
};

/**
 * Clear all prompts from flow queue
 *
 * @return {Object} Mutation result
 */
export const useClearQueue = () => {
	const queryClient = useQueryClient();

	return useMutation( {
		mutationFn: ( { flowId, flowStepId } ) =>
			clearFlowQueue( flowId, flowStepId ),
		onSuccess: ( response, { flowId, flowStepId, pipelineId } ) => {
			if ( ! response?.success ) {
				return;
			}

			const cachedFlowId = normalizeId( flowId );
			const cachedFlowStepId = flowStepId ? String( flowStepId ) : null;
			const previousQueue = queryClient.getQueryData( [
				'flowQueue',
				cachedFlowId,
				cachedFlowStepId,
			] );

			// Optimistically clear the cache
			queryClient.setQueryData(
				[ 'flowQueue', cachedFlowId, cachedFlowStepId ],
				{
					queue: [],
					count: 0,
					queueMode: normalizeQueueMode( previousQueue?.queueMode ),
				}
			);

			invalidateFlows( queryClient, pipelineId );
		},
	} );
};

/**
 * Remove a specific prompt from flow queue
 *
 * @return {Object} Mutation result
 */
export const useRemoveFromQueue = () => {
	const queryClient = useQueryClient();

	return useMutation( {
		mutationFn: ( { flowId, flowStepId, index } ) =>
			removeFromFlowQueue( flowId, flowStepId, index ),
		onMutate: async ( { flowId, flowStepId, index } ) => {
			const cachedFlowId = normalizeId( flowId );
			const cachedFlowStepId = flowStepId ? String( flowStepId ) : null;

			// Cancel any outgoing refetches
			await queryClient.cancelQueries( {
				queryKey: [ 'flowQueue', cachedFlowId, cachedFlowStepId ],
			} );

			// Snapshot the previous value
			const previousQueue = queryClient.getQueryData( [
				'flowQueue',
				cachedFlowId,
				cachedFlowStepId,
			] );

			// Optimistically update
			if ( previousQueue?.queue ) {
				queryClient.setQueryData(
					[ 'flowQueue', cachedFlowId, cachedFlowStepId ],
					{
						queue: previousQueue.queue.filter(
							( _, i ) => i !== index
						),
						count: Math.max( 0, previousQueue.count - 1 ),
						queueMode: normalizeQueueMode(
							previousQueue.queueMode
						),
					}
				);
			}

			return { previousQueue, cachedFlowId, cachedFlowStepId };
		},
		onError: ( err, variables, context ) => {
			// Rollback on error
			if ( context?.previousQueue ) {
				queryClient.setQueryData(
					[
						'flowQueue',
						context.cachedFlowId,
						context.cachedFlowStepId,
					],
					context.previousQueue
				);
			}
		},
		onSettled: ( response, error, { flowId, flowStepId, pipelineId } ) => {
			// Refetch to ensure we're in sync
			const cachedFlowId = normalizeId( flowId );
			const cachedFlowStepId = flowStepId ? String( flowStepId ) : null;
			queryClient.invalidateQueries( {
				queryKey: [ 'flowQueue', cachedFlowId, cachedFlowStepId ],
			} );

			invalidateFlows( queryClient, pipelineId );
		},
	} );
};

/**
 * Update a specific prompt in the flow queue
 *
 * @return {Object} Mutation result
 */
export const useUpdateQueueItem = () => {
	const queryClient = useQueryClient();

	return useMutation( {
		mutationFn: ( { flowId, flowStepId, index, prompt } ) =>
			updateFlowQueueItem( flowId, flowStepId, index, prompt ),
		onMutate: async ( { flowId, flowStepId, index, prompt } ) => {
			const cachedFlowId = normalizeId( flowId );
			const cachedFlowStepId = flowStepId ? String( flowStepId ) : null;

			// Cancel any outgoing refetches
			await queryClient.cancelQueries( {
				queryKey: [ 'flowQueue', cachedFlowId, cachedFlowStepId ],
			} );

			// Snapshot the previous value
			const previousQueue = queryClient.getQueryData( [
				'flowQueue',
				cachedFlowId,
				cachedFlowStepId,
			] );

			// Optimistically update
			if ( previousQueue?.queue ) {
				const newQueue = [ ...previousQueue.queue ];
				if ( index < newQueue.length ) {
					newQueue[ index ] = {
						...newQueue[ index ],
						prompt,
					};
				} else if ( index === 0 && newQueue.length === 0 && prompt ) {
					// Creating first item
					newQueue.push( {
						prompt,
						added_at: new Date().toISOString(),
					} );
				}

				queryClient.setQueryData(
					[ 'flowQueue', cachedFlowId, cachedFlowStepId ],
					{
						queue: newQueue,
						count: newQueue.length,
						queueMode: normalizeQueueMode(
							previousQueue.queueMode
						),
					}
				);
			}

			return { previousQueue, cachedFlowId, cachedFlowStepId };
		},
		onError: ( err, variables, context ) => {
			// Rollback on error
			if ( context?.previousQueue ) {
				queryClient.setQueryData(
					[
						'flowQueue',
						context.cachedFlowId,
						context.cachedFlowStepId,
					],
					context.previousQueue
				);
			}
		},
		onSettled: ( response, error, { flowId, flowStepId, pipelineId } ) => {
			// Refetch to ensure we're in sync
			const cachedFlowId = normalizeId( flowId );
			const cachedFlowStepId = flowStepId ? String( flowStepId ) : null;
			queryClient.invalidateQueries( {
				queryKey: [ 'flowQueue', cachedFlowId, cachedFlowStepId ],
			} );

			// Also invalidate the flows cache since prompt_queue is in flow_config
			invalidateFlows( queryClient, pipelineId );
		},
	} );
};

/**
 * Update queue mode for a flow step
 *
 * Replaces the legacy `useUpdateQueueSettings` boolean toggle. Post-#1291
 * the queue access pattern is a three-state enum (drain | loop | static)
 * stored on `flow_step_config.queue_mode` and read by AIStep, FetchStep,
 * and AgentPingTask via the same path.
 *
 * @return {Object} Mutation result
 */
export const useUpdateQueueMode = () => {
	const queryClient = useQueryClient();

	return useMutation( {
		mutationFn: ( { flowId, flowStepId, mode } ) =>
			updateFlowQueueMode( flowId, flowStepId, mode ),
		onMutate: async ( { flowId, flowStepId, mode } ) => {
			const cachedFlowId = normalizeId( flowId );
			const cachedFlowStepId = flowStepId ? String( flowStepId ) : null;

			await queryClient.cancelQueries( {
				queryKey: [ 'flowQueue', cachedFlowId, cachedFlowStepId ],
			} );

			const previousQueue = queryClient.getQueryData( [
				'flowQueue',
				cachedFlowId,
				cachedFlowStepId,
			] );

			if ( previousQueue ) {
				queryClient.setQueryData(
					[ 'flowQueue', cachedFlowId, cachedFlowStepId ],
					{
						...previousQueue,
						queueMode: normalizeQueueMode( mode ),
					}
				);
			}

			return { previousQueue, cachedFlowId, cachedFlowStepId };
		},
		onError: ( err, variables, context ) => {
			if ( context?.previousQueue ) {
				queryClient.setQueryData(
					[
						'flowQueue',
						context.cachedFlowId,
						context.cachedFlowStepId,
					],
					context.previousQueue
				);
			}
		},
		onSettled: ( response, error, { flowId, flowStepId, pipelineId } ) => {
			const cachedFlowId = normalizeId( flowId );
			const cachedFlowStepId = flowStepId ? String( flowStepId ) : null;
			queryClient.invalidateQueries( {
				queryKey: [ 'flowQueue', cachedFlowId, cachedFlowStepId ],
			} );
			invalidateFlows( queryClient, pipelineId );
		},
	} );
};
