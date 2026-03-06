/**
 * Logs TanStack Query Hooks
 *
 * Query and mutation hooks for log operations.
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
	agentTypes: () => [ ...logsKeys.all, 'agent-types' ],
	metadata: ( agentType ) => [ ...logsKeys.all, 'metadata', agentType ],
	content: ( agentType, mode, limit ) => [
		...logsKeys.all,
		'content',
		agentType,
		mode,
		limit,
	],
};

/**
 * Fetch available contexts
 */
export const useAgentTypes = () =>
	useQuery( {
		queryKey: logsKeys.agentTypes(),
		queryFn: async () => {
			const response = await logsApi.fetchAgentTypes();
			if ( ! response.success ) {
				throw new Error(
					response.message || 'Failed to fetch contexts'
				);
			}
			return response.data;
		},
		staleTime: 10 * 60 * 1000, // Context types rarely change
	} );

/**
 * Fetch log metadata for a specific context
 * @param agentType
 */
export const useLogMetadata = ( agentType ) =>
	useQuery( {
		queryKey: logsKeys.metadata( agentType ),
		queryFn: async () => {
			const response = await logsApi.fetchLogMetadata( agentType );
			if ( ! response.success ) {
				throw new Error(
					response.message || 'Failed to fetch log metadata'
				);
			}
			return response;
		},
		enabled: !! agentType,
	} );

/**
 * Fetch log content for a specific context
 * @param agentType
 * @param mode
 * @param limit
 */
export const useLogContent = ( agentType, mode = 'recent', limit = 200 ) =>
	useQuery( {
		queryKey: logsKeys.content( agentType, mode, limit ),
		queryFn: async () => {
			const response = await logsApi.fetchLogContent(
				agentType,
				mode,
				limit
			);
			if ( ! response.success ) {
				throw new Error(
					response.message || 'Failed to fetch log content'
				);
			}
			return response;
		},
		enabled: !! agentType,
	} );

/**
 * Clear logs for a specific context
 */
export const useClearLogs = () => {
	const queryClient = useQueryClient();

	return useMutation( {
		mutationFn: logsApi.clearLogs,
		onMutate: async ( agentType ) => {
			await queryClient.cancelQueries( {
				queryKey: logsKeys.content( agentType ),
			} );
			await queryClient.cancelQueries( {
				queryKey: logsKeys.metadata( agentType ),
			} );

			const previousContent = queryClient.getQueryData(
				logsKeys.content( agentType, 'recent', 200 )
			);
			const previousMetadata = queryClient.getQueryData(
				logsKeys.metadata( agentType )
			);

			queryClient.setQueryData(
				logsKeys.content( agentType, 'recent', 200 ),
				( old ) => ( {
					...old,
					content: '',
					total_lines: 0,
				} )
			);
			queryClient.setQueryData(
				logsKeys.metadata( agentType ),
				( old ) => ( {
					...old,
					log_file: {
						...old?.log_file,
						size_formatted: '0 bytes',
						size_bytes: 0,
					},
				} )
			);

			return { previousContent, previousMetadata };
		},
		onError: ( err, agentType, context ) => {
			queryClient.setQueryData(
				logsKeys.content( agentType, 'recent', 200 ),
				context.previousContent
			);
			queryClient.setQueryData(
				logsKeys.metadata( agentType ),
				context.previousMetadata
			);
		},
		onSuccess: ( _, agentType ) => {
			queryClient.invalidateQueries( {
				queryKey: logsKeys.content( agentType ),
			} );
			queryClient.invalidateQueries( {
				queryKey: logsKeys.metadata( agentType ),
			} );
		},
	} );
};

/**
 * Clear all logs
 */
export const useClearAllLogs = () => {
	const queryClient = useQueryClient();

	return useMutation( {
		mutationFn: logsApi.clearAllLogs,
		onMutate: async () => {
			await queryClient.cancelQueries( { queryKey: logsKeys.all } );

			const previousData = queryClient.getQueriesData( {
				queryKey: logsKeys.all,
			} );

			queryClient.setQueriesData(
				{ queryKey: [ 'logs', 'content' ] },
				( old ) => ( {
					...old,
					content: '',
					total_lines: 0,
				} )
			);
			queryClient.setQueriesData(
				{ queryKey: [ 'logs', 'metadata' ] },
				( old ) => ( {
					...old,
					log_file: {
						...old?.log_file,
						size_formatted: '0 bytes',
						size_bytes: 0,
					},
				} )
			);

			return { previousData };
		},
		onError: ( err, _, context ) => {
			context.previousData.forEach( ( [ queryKey, data ] ) => {
				queryClient.setQueryData( queryKey, data );
			} );
		},
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: logsKeys.all } );
		},
	} );
};

/**
 * Update log level for a specific context
 */
export const useUpdateLogLevel = () => {
	const queryClient = useQueryClient();

	return useMutation( {
		mutationFn: ( { agentType, level } ) =>
			logsApi.updateLogLevel( agentType, level ),
		onSuccess: ( _, { agentType } ) => {
			// Invalidate metadata to reflect new log level
			queryClient.invalidateQueries( {
				queryKey: logsKeys.metadata( agentType ),
			} );
		},
	} );
};
