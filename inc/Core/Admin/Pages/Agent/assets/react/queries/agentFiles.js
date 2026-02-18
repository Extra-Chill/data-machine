/**
 * Agent Files TanStack Query Hooks
 *
 * Query and mutation hooks for agent memory file operations.
 */

/**
 * External dependencies
 */
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';

/**
 * Internal dependencies
 */
import * as api from '../api/agentFiles';

const KEYS = {
	list: [ 'agent-files' ],
	detail: ( filename ) => [ 'agent-files', filename ],
};

export const useAgentFiles = () =>
	useQuery( {
		queryKey: KEYS.list,
		queryFn: api.listAgentFiles,
		select: ( response ) => response?.data ?? response ?? [],
	} );

export const useAgentFile = ( filename ) =>
	useQuery( {
		queryKey: KEYS.detail( filename ),
		queryFn: () => api.getAgentFile( filename ),
		enabled: !! filename,
		select: ( response ) => response?.data ?? response ?? {},
	} );

export const useSaveAgentFile = () => {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: ( { filename, content } ) =>
			api.putAgentFile( filename, content ),
		onSuccess: ( _data, { filename } ) => {
			queryClient.invalidateQueries( { queryKey: KEYS.list } );
			queryClient.invalidateQueries( {
				queryKey: KEYS.detail( filename ),
			} );
		},
	} );
};

export const useDeleteAgentFile = () => {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: ( filename ) => api.deleteAgentFile( filename ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: KEYS.list } );
		},
	} );
};
