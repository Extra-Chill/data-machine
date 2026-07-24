/**
 * Settings Query Hooks
 *
 * Shared TanStack Query hooks for fetching and updating plugin settings.
 */

/**
 * External dependencies
 */
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { client } from '@shared/utils/api';

export const SETTINGS_KEY = [ 'settings' ];

/**
 * Fetch plugin settings
 *
 * @return {Object} Query result with settings data
 */
export const useSettings = () =>
	useQuery( {
		queryKey: SETTINGS_KEY,
		queryFn: async () => {
			const result = await client.get( '/settings' );
			if ( ! result.success ) {
				throw new Error( result.message || 'Failed to fetch settings' );
			}
			return result.data;
		},
		staleTime: 5 * 60 * 1000, // 5 minutes
	} );

/**
 * Update settings (partial update)
 */
export const useUpdateSettings = () => {
	const queryClient = useQueryClient();

	return useMutation( {
		mutationFn: async ( updates ) => {
			const response = await client.patch( '/settings', updates );
			if ( ! response.success ) {
				throw new Error(
					response.message || 'Failed to update settings'
				);
			}
			return response;
		},
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: SETTINGS_KEY } );
		},
	} );
};
