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

// Daily memory hooks.

const DAILY_KEYS = {
	list: [ 'daily-files' ],
	detail: ( year, month, day ) => [ 'daily-files', year, month, day ],
};

export const useDailyFiles = () =>
	useQuery( {
		queryKey: DAILY_KEYS.list,
		queryFn: api.listDailyFiles,
		select: ( response ) => response?.data ?? response ?? {},
	} );

export const useDailyFile = ( year, month, day ) =>
	useQuery( {
		queryKey: DAILY_KEYS.detail( year, month, day ),
		queryFn: () => api.getDailyFile( year, month, day ),
		enabled: !! year && !! month && !! day,
		select: ( response ) => response?.data ?? response ?? {},
	} );

export const useSaveDailyFile = () => {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: ( { year, month, day, content } ) =>
			api.putDailyFile( year, month, day, content ),
		onSuccess: ( _data, { year, month, day } ) => {
			queryClient.invalidateQueries( { queryKey: DAILY_KEYS.list } );
			queryClient.invalidateQueries( {
				queryKey: DAILY_KEYS.detail( year, month, day ),
			} );
			// Also refresh the agent files list (includes daily summary).
			queryClient.invalidateQueries( { queryKey: KEYS.list } );
		},
	} );
};

export const useDeleteDailyFile = () => {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: ( { year, month, day } ) =>
			api.deleteDailyFile( year, month, day ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: DAILY_KEYS.list } );
			queryClient.invalidateQueries( { queryKey: KEYS.list } );
		},
	} );
};
