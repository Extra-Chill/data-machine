/**
 * Tool Configuration Query Hooks
 *
 * TanStack Query hooks for tool configuration endpoints.
 */

/**
 * External dependencies
 */
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { client } from '@shared/utils/api';
/**
 * Internal dependencies
 */
import { SETTINGS_KEY } from './settings';

export const toolConfigKey = ( toolId ) => [ 'settings', 'tools', toolId ];

export const useToolConfig = ( toolId, enabled = true ) => {
	return useQuery( {
		queryKey: toolConfigKey( toolId ),
		enabled: Boolean( toolId && enabled ),
		queryFn: async () => {
			const response = await client.get( `/settings/tools/${ toolId }` );
			if ( ! response.success ) {
				throw new Error(
					response.message || 'Failed to fetch tool configuration'
				);
			}
			return response.data;
		},
	} );
};

export const useSaveToolConfig = () => {
	const queryClient = useQueryClient();

	return useMutation( {
		mutationFn: async ( { toolId, configData } ) => {
			const response = await client.post( `/settings/tools/${ toolId }`, {
				config_data: configData,
			} );
			if ( ! response.success ) {
				throw new Error(
					response.message || 'Failed to save tool configuration'
				);
			}
			return response.data;
		},
		onSuccess: ( _data, variables ) => {
			queryClient.invalidateQueries( {
				queryKey: toolConfigKey( variables.toolId ),
			} );
			queryClient.invalidateQueries( { queryKey: SETTINGS_KEY } );
		},
	} );
};
