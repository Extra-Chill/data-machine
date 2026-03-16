/**
 * System Task Prompts Queries
 *
 * TanStack Query hooks for system task prompt CRUD via REST API.
 *
 * @since 0.43.0
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { client } from '@shared/utils/api';

const PROMPTS_KEY = [ 'system-task-prompts' ];

/**
 * Fetch all system task prompt definitions with overrides.
 */
export const useSystemTaskPrompts = () =>
	useQuery( {
		queryKey: PROMPTS_KEY,
		queryFn: async () => {
			const result = await client.get( '/system/tasks/prompts' );
			if ( ! result.success ) {
				throw new Error(
					result.message || 'Failed to fetch task prompts'
				);
			}
			return result.data;
		},
		staleTime: 60 * 1000,
	} );

/**
 * Save a prompt override.
 */
export const useSavePrompt = () => {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: async ( { taskType, promptKey, prompt } ) => {
			const result = await client.put(
				`/system/tasks/prompts/${ taskType }/${ promptKey }`,
				{ prompt }
			);
			if ( ! result.success ) {
				throw new Error(
					result.message || 'Failed to save prompt'
				);
			}
			return result.data;
		},
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: PROMPTS_KEY } );
		},
	} );
};

/**
 * Reset a prompt to its default.
 */
export const useResetPrompt = () => {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: async ( { taskType, promptKey } ) => {
			const result = await client.delete(
				`/system/tasks/prompts/${ taskType }/${ promptKey }`
			);
			if ( ! result.success ) {
				throw new Error(
					result.message || 'Failed to reset prompt'
				);
			}
			return result.data;
		},
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: PROMPTS_KEY } );
		},
	} );
};
