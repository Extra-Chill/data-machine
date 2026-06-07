/**
 * Logs TanStack Query Hooks
 *
 * Query and mutation hooks for database-backed log operations.
 */

/**
 * External dependencies
 */
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
/**
 * Internal dependencies
 */
import * as logsApi from '../api/logs';

/**
 * Query key factory for logs
 */
export const logsKeys = {
	all: [ 'logs' ],
	list: ( filters ) => [ ...logsKeys.all, 'list', filters ],
	metadata: ( agentId ) => [ ...logsKeys.all, 'metadata', agentId ],
};

/**
 * Fetch log entries with filters and pagination.
 * @param {Object} filters Query filters.
 */
export const useLogs = ( filters = {} ) =>
	useQuery( {
		queryKey: logsKeys.list( filters ),
		queryFn: async () => {
			const response = await logsApi.fetchLogs( filters );
			if ( ! response.success ) {
				throw new Error(
					response.message || 'Failed to fetch logs'
				);
			}
			return response;
		},
	} );

/**
 * Fetch log metadata.
 * @param {number|undefined} agentId Optional agent ID.
 */
export const useLogMetadata = ( agentId ) =>
	useQuery( {
		queryKey: logsKeys.metadata( agentId ),
		queryFn: async () => {
			const params = {};
			if ( agentId !== undefined ) {
				params.agent_id = agentId;
			}
			const response = await logsApi.fetchLogMetadata( params );
			if ( ! response.success ) {
				throw new Error(
					response.message || 'Failed to fetch log metadata'
				);
			}
			return response;
		},
	} );

/**
 * Clear logs mutation.
 */
export const useClearLogs = () => {
	const queryClient = useQueryClient();

	return useMutation( {
		mutationFn: logsApi.clearLogs,
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: logsKeys.all } );
		},
	} );
};
