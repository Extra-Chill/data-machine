/**
 * Agents Management Query Hooks
 *
 * TanStack Query hooks for agent CRUD operations on the Agent admin page.
 * Separate from the shared useAgents() hook (which is read-only for the switcher).
 */

/**
 * External dependencies
 */
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';

/**
 * Internal dependencies
 */
import * as agentsApi from '../api/agents';

/**
 * Shared dependencies — re-use the same query key so mutations invalidate
 * both the management list and the AgentSwitcher dropdown.
 */
import { AGENTS_KEY } from '@shared/queries/agents';

/**
 * Query key for single agent detail.
 *
 * @param {number} agentId Agent ID.
 * @return {Array} Query key.
 */
const agentDetailKey = ( agentId ) => [ ...AGENTS_KEY, 'detail', agentId ];

/**
 * Query key for agent access grants.
 *
 * @param {number} agentId Agent ID.
 * @return {Array} Query key.
 */
const agentAccessKey = ( agentId ) => [ ...AGENTS_KEY, 'access', agentId ];

/**
 * Fetch the management agent list (includes timestamps).
 *
 * @return {Object} TanStack Query result.
 */
export const useManageAgents = () =>
	useQuery( {
		queryKey: AGENTS_KEY,
		queryFn: async () => {
			const result = await agentsApi.fetchAgents();
			if ( ! result.success ) {
				throw new Error(
					result.message || 'Failed to fetch agents'
				);
			}
			return result.data;
		},
		staleTime: 30_000, // 30s — management view needs fresher data than the switcher
	} );

/**
 * Fetch a single agent by ID.
 *
 * @param {number|null} agentId Agent ID.
 * @return {Object} TanStack Query result.
 */
export const useAgent = ( agentId ) =>
	useQuery( {
		queryKey: agentDetailKey( agentId ),
		queryFn: async () => {
			const result = await agentsApi.fetchAgent( agentId );
			if ( ! result.success ) {
				throw new Error(
					result.message || 'Failed to fetch agent'
				);
			}
			return result.data;
		},
		enabled: !! agentId,
	} );

/**
 * Create a new agent.
 *
 * @return {Object} TanStack Mutation result.
 */
export const useCreateAgent = () => {
	const queryClient = useQueryClient();

	return useMutation( {
		mutationFn: agentsApi.createAgent,
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: AGENTS_KEY } );
		},
	} );
};

/**
 * Update an agent.
 *
 * @return {Object} TanStack Mutation result.
 */
export const useUpdateAgent = () => {
	const queryClient = useQueryClient();

	return useMutation( {
		mutationFn: ( { agentId, ...data } ) =>
			agentsApi.updateAgent( agentId, data ),
		onSuccess: ( _data, variables ) => {
			queryClient.invalidateQueries( { queryKey: AGENTS_KEY } );
			queryClient.invalidateQueries( {
				queryKey: agentDetailKey( variables.agentId ),
			} );
		},
	} );
};

/**
 * Delete an agent.
 *
 * @return {Object} TanStack Mutation result.
 */
export const useDeleteAgent = () => {
	const queryClient = useQueryClient();

	return useMutation( {
		mutationFn: ( { agentId, deleteFiles = false } ) =>
			agentsApi.deleteAgent( agentId, deleteFiles ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: AGENTS_KEY } );
		},
	} );
};

/**
 * Fetch access grants for an agent.
 *
 * @param {number|null} agentId Agent ID.
 * @return {Object} TanStack Query result.
 */
export const useAgentAccess = ( agentId ) =>
	useQuery( {
		queryKey: agentAccessKey( agentId ),
		queryFn: async () => {
			const result = await agentsApi.fetchAgentAccess( agentId );
			if ( ! result.success ) {
				throw new Error(
					result.message || 'Failed to fetch access grants'
				);
			}
			return result.data;
		},
		enabled: !! agentId,
	} );

/**
 * Grant access to an agent.
 *
 * @return {Object} TanStack Mutation result.
 */
export const useGrantAccess = () => {
	const queryClient = useQueryClient();

	return useMutation( {
		mutationFn: ( { agentId, userId, role } ) =>
			agentsApi.grantAccess( agentId, userId, role ),
		onSuccess: ( _data, variables ) => {
			queryClient.invalidateQueries( {
				queryKey: agentAccessKey( variables.agentId ),
			} );
		},
	} );
};

/**
 * Revoke access from an agent.
 *
 * @return {Object} TanStack Mutation result.
 */
export const useRevokeAccess = () => {
	const queryClient = useQueryClient();

	return useMutation( {
		mutationFn: ( { agentId, userId } ) =>
			agentsApi.revokeAccess( agentId, userId ),
		onSuccess: ( _data, variables ) => {
			queryClient.invalidateQueries( {
				queryKey: agentAccessKey( variables.agentId ),
			} );
		},
	} );
};
