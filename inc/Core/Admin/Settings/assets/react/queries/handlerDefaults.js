/**
 * Handler Defaults Query Hooks
 *
 * TanStack Query hooks for handler defaults API operations.
 */

/**
 * External dependencies
 */
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { client } from '@shared/utils/api';

/**
 * Query key for handler defaults
 */
export const HANDLER_DEFAULTS_KEY = [ 'handlerDefaults' ];

/**
 * Fetch all handler defaults grouped by step type
 */
export const useHandlerDefaults = () => {
	return useQuery( {
		queryKey: HANDLER_DEFAULTS_KEY,
		queryFn: async () => {
			const response = await client.get( '/settings/handler-defaults' );
			if ( ! response.success ) {
				throw new Error(
					response.message || 'Failed to fetch handler defaults'
				);
			}
			return response.data;
		},
	} );
};

/**
 * Update defaults for a specific handler
 */
export const useUpdateHandlerDefaults = () => {
	const queryClient = useQueryClient();

	return useMutation( {
		mutationFn: async ( { handlerSlug, defaults } ) => {
			const response = await client.put(
				`/settings/handler-defaults/${ handlerSlug }`,
				{ defaults }
			);
			if ( ! response.success ) {
				throw new Error(
					response.message || 'Failed to update handler defaults'
				);
			}
			return response.data;
		},
		onSuccess: () => {
			// Invalidate handler defaults query to refetch
			queryClient.invalidateQueries( { queryKey: HANDLER_DEFAULTS_KEY } );
		},
	} );
};
