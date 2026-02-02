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
	updateFlowQueueSettings,
} from '../utils/api';
import { normalizeId } from '../utils/ids';

/**
 * Fetch queue for a flow
 *
 * @param {number} flowId - Flow ID
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
				queueEnabled: !! response.data?.queue_enabled,
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
		onSuccess: ( response, { flowId, flowStepId } ) => {
			if ( ! response?.success ) {
				return;
			}

			const cachedFlowId = normalizeId( flowId );
			const cachedFlowStepId = flowStepId ? String( flowStepId ) : null;
			queryClient.invalidateQueries( {
				queryKey: [ 'flowQueue', cachedFlowId, cachedFlowStepId ],
			} );

			queryClient.invalidateQueries( {
				queryKey: [ 'flows' ],
			} );
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
		onSuccess: ( response, { flowId, flowStepId } ) => {
			if ( ! response?.success ) {
				return;
			}

			const cachedFlowId = normalizeId( flowId );
			const cachedFlowStepId = flowStepId ? String( flowStepId ) : null;
			// Optimistically clear the cache
			queryClient.setQueryData(
				[ 'flowQueue', cachedFlowId, cachedFlowStepId ],
				{
					queue: [],
					count: 0,
					queueEnabled: false,
				}
			);
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
						queueEnabled: previousQueue.queueEnabled,
					}
				);
			}

			return { previousQueue, cachedFlowId, cachedFlowStepId };
		},
		onError: ( err, variables, context ) => {
			// Rollback on error
			if ( context?.previousQueue ) {
				queryClient.setQueryData(
					[ 'flowQueue', context.cachedFlowId, context.cachedFlowStepId ],
					context.previousQueue
				);
			}
		},
		onSettled: ( response, error, { flowId, flowStepId } ) => {
			// Refetch to ensure we're in sync
			const cachedFlowId = normalizeId( flowId );
			const cachedFlowStepId = flowStepId ? String( flowStepId ) : null;
			queryClient.invalidateQueries( {
				queryKey: [ 'flowQueue', cachedFlowId, cachedFlowStepId ],
			} );
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
						queueEnabled: previousQueue.queueEnabled,
					}
				);
			}

			return { previousQueue, cachedFlowId, cachedFlowStepId };
		},
		onError: ( err, variables, context ) => {
			// Rollback on error
			if ( context?.previousQueue ) {
				queryClient.setQueryData(
					[ 'flowQueue', context.cachedFlowId, context.cachedFlowStepId ],
					context.previousQueue
				);
			}
		},
		onSettled: ( response, error, { flowId, flowStepId } ) => {
			// Refetch to ensure we're in sync
			const cachedFlowId = normalizeId( flowId );
			const cachedFlowStepId = flowStepId ? String( flowStepId ) : null;
			queryClient.invalidateQueries( {
				queryKey: [ 'flowQueue', cachedFlowId, cachedFlowStepId ],
			} );

			// Also invalidate the flows cache since prompt_queue is in flow_config
			queryClient.invalidateQueries( {
				queryKey: [ 'flows' ],
			} );
		},
	} );
};

/**
 * Update queue settings for a flow step
 *
 * @return {Object} Mutation result
 */
export const useUpdateQueueSettings = () => {
	const queryClient = useQueryClient();

	return useMutation( {
		mutationFn: ( { flowId, flowStepId, queueEnabled } ) =>
			updateFlowQueueSettings( flowId, flowStepId, queueEnabled ),
		onMutate: async ( { flowId, flowStepId, queueEnabled } ) => {
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
						queueEnabled: !! queueEnabled,
					}
				);
			}

			return { previousQueue, cachedFlowId, cachedFlowStepId };
		},
		onError: ( err, variables, context ) => {
			if ( context?.previousQueue ) {
				queryClient.setQueryData(
					[ 'flowQueue', context.cachedFlowId, context.cachedFlowStepId ],
					context.previousQueue
				);
			}
		},
		onSettled: ( response, error, { flowId, flowStepId } ) => {
			const cachedFlowId = normalizeId( flowId );
			const cachedFlowStepId = flowStepId ? String( flowStepId ) : null;
			queryClient.invalidateQueries( {
				queryKey: [ 'flowQueue', cachedFlowId, cachedFlowStepId ],
			} );
			queryClient.invalidateQueries( {
				queryKey: [ 'flows' ],
			} );
		},
	} );
};
