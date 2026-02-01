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
} from '../utils/api';
import { normalizeId } from '../utils/ids';

/**
 * Fetch queue for a flow
 *
 * @param {number} flowId - Flow ID
 * @return {Object} Query result with queue data
 */
export const useFlowQueue = ( flowId ) => {
	const cachedFlowId = normalizeId( flowId );

	return useQuery( {
		queryKey: [ 'flowQueue', cachedFlowId ],
		queryFn: async () => {
			const response = await fetchFlowQueue( flowId );
			if ( ! response.success ) {
				throw new Error( response.message || 'Failed to fetch queue' );
			}
			return {
				queue: response.data?.queue || [],
				count: response.data?.count || 0,
			};
		},
		enabled: !! cachedFlowId,
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
		mutationFn: ( { flowId, prompts } ) =>
			addToFlowQueue( flowId, prompts ),
		onSuccess: ( response, { flowId } ) => {
			if ( ! response?.success ) {
				return;
			}

			const cachedFlowId = normalizeId( flowId );
			queryClient.invalidateQueries( {
				queryKey: [ 'flowQueue', cachedFlowId ],
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
		mutationFn: ( { flowId } ) => clearFlowQueue( flowId ),
		onSuccess: ( response, { flowId } ) => {
			if ( ! response?.success ) {
				return;
			}

			const cachedFlowId = normalizeId( flowId );
			// Optimistically clear the cache
			queryClient.setQueryData( [ 'flowQueue', cachedFlowId ], {
				queue: [],
				count: 0,
			} );
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
		mutationFn: ( { flowId, index } ) =>
			removeFromFlowQueue( flowId, index ),
		onMutate: async ( { flowId, index } ) => {
			const cachedFlowId = normalizeId( flowId );

			// Cancel any outgoing refetches
			await queryClient.cancelQueries( {
				queryKey: [ 'flowQueue', cachedFlowId ],
			} );

			// Snapshot the previous value
			const previousQueue = queryClient.getQueryData( [
				'flowQueue',
				cachedFlowId,
			] );

			// Optimistically update
			if ( previousQueue?.queue ) {
				queryClient.setQueryData( [ 'flowQueue', cachedFlowId ], {
					queue: previousQueue.queue.filter(
						( _, i ) => i !== index
					),
					count: Math.max( 0, previousQueue.count - 1 ),
				} );
			}

			return { previousQueue, cachedFlowId };
		},
		onError: ( err, variables, context ) => {
			// Rollback on error
			if ( context?.previousQueue ) {
				queryClient.setQueryData(
					[ 'flowQueue', context.cachedFlowId ],
					context.previousQueue
				);
			}
		},
		onSettled: ( response, error, { flowId } ) => {
			// Refetch to ensure we're in sync
			const cachedFlowId = normalizeId( flowId );
			queryClient.invalidateQueries( {
				queryKey: [ 'flowQueue', cachedFlowId ],
			} );
		},
	} );
};
