/**
 * Chat API Queries
 *
 * TanStack Query hooks for chat session management.
 * Message sending and continuation loops are handled by
 * @extrachill/chat's useChat hook — see ChatSidebar.jsx.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
/**
 * External dependencies
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
/**
 * Internal dependencies
 */
import { client } from '@shared/utils/api';

/**
 * Fetch list of chat sessions for current user
 *
 * Uses the shared API client so the agent interceptor automatically
 * injects agent_id when an agent is selected in the AgentSwitcher.
 *
 * @param {number} limit     - Maximum sessions to return
	 * @param {string|null} context - Optional session context filter
	 * @return {Object} TanStack Query object with sessions data
	 */
export function useChatSessions( limit = 20, context = null ) {
	return useQuery( {
		queryKey: [ 'chat-sessions', limit, context ],
		queryFn: async () => {
			const response = await client.get( '/chat/sessions', {
				limit,
				context: context || undefined,
			} );

			if ( ! response.success ) {
				throw new Error(
					response.message || 'Failed to fetch sessions'
				);
			}

			return response.data;
		},
		staleTime: 30000, // 30 seconds
	} );
}

/**
 * Delete a chat session mutation
 *
 * @return {Object} TanStack Query mutation object
 */
export function useDeleteChatSession() {
	const queryClient = useQueryClient();

	return useMutation( {
		mutationFn: async ( sessionId ) => {
			const response = await apiFetch( {
				path: `/datamachine/v1/chat/${ sessionId }`,
				method: 'DELETE',
			} );

			if ( ! response.success ) {
				throw new Error(
					response.message || 'Failed to delete session'
				);
			}

			return response.data;
		},
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: [ 'chat-sessions' ] } );
		},
	} );
}
