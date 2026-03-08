/**
 * Agent Switcher Component
 *
 * Global dropdown for switching the active agent across all DM admin pages.
 * Persists selection via Zustand (localStorage). Invalidates all TanStack Query
 * caches when agent changes so data refetches with the new scope.
 */

/**
 * WordPress dependencies
 */
import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * External dependencies
 */
import { useQueryClient } from '@tanstack/react-query';
/**
 * Internal dependencies
 */
import { useAgents, AGENTS_KEY } from '@shared/queries/agents';
import { useAgentStore } from '@shared/stores/agentStore';

/**
 * Agent switcher dropdown.
 *
 * Shows "All Agents" + each available agent. Hidden when only one agent exists
 * (single-agent installs don't need a switcher).
 *
 * @return {React.ReactElement|null} Switcher or null.
 */
export default function AgentSwitcher() {
	const { data: agents = [], isLoading } = useAgents();
	const { selectedAgentId, setSelectedAgentId } = useAgentStore();
	const queryClient = useQueryClient();

	// Don't render until loaded, or if 0-1 agents (nothing to switch).
	if ( isLoading || agents.length <= 1 ) {
		return null;
	}

	const options = [
		{
			label: __( 'All Agents', 'data-machine' ),
			value: '',
		},
		...agents.map( ( agent ) => ( {
			label:
				agent.agent_name ||
				agent.agent_slug ||
				__( 'Unnamed Agent', 'data-machine' ),
			value: String( agent.agent_id ),
		} ) ),
	];

	/**
	 * Handle agent change — update store and invalidate all cached data
	 * except the agents list itself (that doesn't change per agent).
	 *
	 * @param {string} value Selected agent ID or empty string for all.
	 */
	const handleChange = ( value ) => {
		const newId = value === '' ? null : Number( value );
		const currentId = selectedAgentId;

		if ( newId === currentId ) {
			return;
		}

		setSelectedAgentId( newId );

		// Invalidate everything except the agents list itself.
		queryClient.invalidateQueries( {
			predicate: ( query ) => {
				const key = query.queryKey;
				// Keep agents list cache — it doesn't depend on selected agent.
				if (
					key.length === AGENTS_KEY.length &&
					key[ 0 ] === AGENTS_KEY[ 0 ]
				) {
					return false;
				}
				return true;
			},
		} );
	};

	return (
		<div className="datamachine-agent-switcher">
			<SelectControl
				label={ __( 'Agent', 'data-machine' ) }
				hideLabelFromVision
				value={
					selectedAgentId !== null
						? String( selectedAgentId )
						: ''
				}
				options={ options }
				onChange={ handleChange }
				__nextHasNoMarginBottom
			/>
		</div>
	);
}
