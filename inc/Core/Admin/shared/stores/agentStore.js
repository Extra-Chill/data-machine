/**
 * Agent Store
 *
 * Shared Zustand store for managing the active agent across all DM admin pages.
 * Persists selectedAgentId to localStorage so the choice survives page navigation.
 */

/**
 * External dependencies
 */
import { create } from 'zustand';
import { persist } from 'zustand/middleware';

export const useAgentStore = create(
	persist(
		( set, get ) => ( {
			/**
			 * Currently selected agent ID.
			 * null = show all agents (admin default).
			 */
			selectedAgentId: null,

			/**
			 * Set the active agent.
			 *
			 * @param {number|null} agentId Agent ID or null for all.
			 */
			setSelectedAgentId: ( agentId ) => {
				if ( agentId === null || agentId === undefined || agentId === '' ) {
					set( { selectedAgentId: null } );
					return;
				}
				set( { selectedAgentId: Number( agentId ) } );
			},

			/**
			 * Get the current agent ID.
			 *
			 * @return {number|null} Selected agent ID.
			 */
			getSelectedAgentId: () => get().selectedAgentId,
		} ),
		{
			name: 'datamachine-agent-store',
			version: 1,
		}
	)
);
