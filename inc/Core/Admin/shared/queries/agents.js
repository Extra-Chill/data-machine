/**
 * Agent Query Hooks
 *
 * Shared TanStack Query hooks for fetching agents.
 * Used by AgentSwitcher across all DM admin pages.
 */

/**
 * External dependencies
 */
import { useQuery } from '@tanstack/react-query';
import { client } from '@shared/utils/api';

export const AGENTS_KEY = [ 'agents' ];

/**
 * Fetch all agents the current user has access to.
 *
 * @return {Object} Query result with agents array.
 */
export const useAgents = () =>
	useQuery( {
		queryKey: AGENTS_KEY,
		queryFn: async () => {
			const result = await client.get( '/agents' );
			if ( ! result.success ) {
				throw new Error(
					result.message || 'Failed to fetch agents'
				);
			}
			return result.data;
		},
		staleTime: 10 * 60 * 1000, // 10 minutes — agents rarely change
	} );
