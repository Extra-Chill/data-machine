/**
 * Agent Files TanStack Query Hooks
 *
 * Query and mutation hooks for agent memory file operations.
 * All query keys include the selected agent ID so each agent's files
 * are cached independently and refetch automatically on agent switch.
 */

/**
 * External dependencies
 */
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';

/**
 * Internal dependencies
 */
import * as api from '../api/agentFiles';
import { useAgentStore } from '@shared/stores/agentStore';

/**
 * Hook to read the current agent ID from the store (reactive).
 *
 * @return {number|null} Selected agent ID.
 */
const useSelectedAgentId = () =>
	useAgentStore( ( state ) => state.selectedAgentId );

const KEYS = {
	list: ( agentId ) => [ 'agent-files', { agentId } ],
	detail: ( filename, agentId ) => [ 'agent-files', filename, { agentId } ],
};

export const useAgentFiles = () => {
	const agentId = useSelectedAgentId();
	return useQuery( {
		queryKey: KEYS.list( agentId ),
		queryFn: api.listAgentFiles,
		select: ( response ) => response?.data ?? response ?? [],
	} );
};

export const useAgentFile = ( filename ) => {
	const agentId = useSelectedAgentId();
	return useQuery( {
		queryKey: KEYS.detail( filename, agentId ),
		queryFn: () => api.getAgentFile( filename ),
		enabled: !! filename,
		select: ( response ) => response?.data ?? response ?? {},
	} );
};

export const useSaveAgentFile = () => {
	const queryClient = useQueryClient();
	const agentId = useSelectedAgentId();
	return useMutation( {
		mutationFn: ( { filename, content } ) =>
			api.putAgentFile( filename, content ),
		onSuccess: ( _data, { filename } ) => {
			queryClient.invalidateQueries( {
				queryKey: KEYS.list( agentId ),
			} );
			queryClient.invalidateQueries( {
				queryKey: KEYS.detail( filename, agentId ),
			} );
		},
	} );
};

export const useDeleteAgentFile = () => {
	const queryClient = useQueryClient();
	const agentId = useSelectedAgentId();
	return useMutation( {
		mutationFn: ( filename ) => api.deleteAgentFile( filename ),
		onSuccess: () => {
			queryClient.invalidateQueries( {
				queryKey: KEYS.list( agentId ),
			} );
		},
	} );
};

// Daily memory hooks.

const DAILY_KEYS = {
	list: ( agentId ) => [ 'daily-files', { agentId } ],
	detail: ( year, month, day, agentId ) => [
		'daily-files',
		year,
		month,
		day,
		{ agentId },
	],
};

export const useDailyFiles = () => {
	const agentId = useSelectedAgentId();
	return useQuery( {
		queryKey: DAILY_KEYS.list( agentId ),
		queryFn: api.listDailyFiles,
		select: ( response ) => response?.data ?? response ?? {},
	} );
};

export const useDailyFile = ( year, month, day ) => {
	const agentId = useSelectedAgentId();
	return useQuery( {
		queryKey: DAILY_KEYS.detail( year, month, day, agentId ),
		queryFn: () => api.getDailyFile( year, month, day ),
		enabled: !! year && !! month && !! day,
		select: ( response ) => response?.data ?? response ?? {},
	} );
};

export const useSaveDailyFile = () => {
	const queryClient = useQueryClient();
	const agentId = useSelectedAgentId();
	return useMutation( {
		mutationFn: ( { year, month, day, content } ) =>
			api.putDailyFile( year, month, day, content ),
		onSuccess: ( _data, { year, month, day } ) => {
			queryClient.invalidateQueries( {
				queryKey: DAILY_KEYS.list( agentId ),
			} );
			queryClient.invalidateQueries( {
				queryKey: DAILY_KEYS.detail( year, month, day, agentId ),
			} );
			// Also refresh the agent files list (includes daily summary).
			queryClient.invalidateQueries( {
				queryKey: KEYS.list( agentId ),
			} );
		},
	} );
};

export const useDeleteDailyFile = () => {
	const queryClient = useQueryClient();
	const agentId = useSelectedAgentId();
	return useMutation( {
		mutationFn: ( { year, month, day } ) =>
			api.deleteDailyFile( year, month, day ),
		onSuccess: () => {
			queryClient.invalidateQueries( {
				queryKey: DAILY_KEYS.list( agentId ),
			} );
			queryClient.invalidateQueries( {
				queryKey: KEYS.list( agentId ),
			} );
		},
	} );
};

// Context memory hooks.

const CONTEXT_KEYS = {
	detail: ( slug, agentId ) => [ 'context-files', slug, { agentId } ],
};

export const useContextFile = ( slug ) => {
	const agentId = useSelectedAgentId();
	return useQuery( {
		queryKey: CONTEXT_KEYS.detail( slug, agentId ),
		queryFn: () => api.getContextFile( slug ),
		enabled: !! slug,
		select: ( response ) => response?.data ?? response ?? {},
	} );
};

export const useSaveContextFile = () => {
	const queryClient = useQueryClient();
	const agentId = useSelectedAgentId();
	return useMutation( {
		mutationFn: ( { slug, content } ) =>
			api.putContextFile( slug, content ),
		onSuccess: ( _data, { slug } ) => {
			queryClient.invalidateQueries( {
				queryKey: CONTEXT_KEYS.detail( slug, agentId ),
			} );
			queryClient.invalidateQueries( {
				queryKey: KEYS.list( agentId ),
			} );
		},
	} );
};
