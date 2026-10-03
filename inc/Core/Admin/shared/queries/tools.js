/**
 * Tool Configuration Query Hooks
 *
 * TanStack Query hooks for tool configuration abilities.
 */

/**
 * External dependencies
 */
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { executeAbility } from '@shared/utils/api';
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
			const response = await executeAbility( 'get-tool-config', {
				tool_id: toolId,
			} );
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
			const response = await executeAbility( 'save-tool-config', {
				tool_id: toolId,
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
