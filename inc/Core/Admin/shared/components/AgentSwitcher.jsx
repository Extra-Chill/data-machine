/**
 * Agent Switcher Component
 *
 * Global dropdown for switching the active agent across all DM admin pages.
 * Persists selection via Zustand (localStorage). Invalidates all TanStack Query
 * caches when agent changes so data refetches with the new scope.
 *
 * Always visible when agents exist (even a single agent — the user needs to see
 * what agent is active). Includes a "+ Create Agent" action and a create button
 * when no agents exist yet.
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect } from '@wordpress/element';
import { SelectControl, Button } from '@wordpress/components';
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
import CreateAgentModal from './CreateAgentModal';

/**
 * Special value for the "+ Create Agent" option in the dropdown.
 */
const CREATE_AGENT_VALUE = '__create__';

/**
 * Agent switcher dropdown.
 *
 * Shows current agent + all available agents + "Create Agent" action.
 * When no agents exist, renders a create button instead of a dropdown.
 *
 * @return {React.ReactElement|null} Switcher, create button, or null while loading.
 */
export default function AgentSwitcher() {
	const { data: agents = [], isLoading } = useAgents();
	const { selectedAgentId, setSelectedAgentId } = useAgentStore();
	const queryClient = useQueryClient();
	const [ showCreateModal, setShowCreateModal ] = useState( false );

	// Auto-select the first agent when none is selected or the selected one
	// no longer exists. There is no "All Agents" option — the switcher always
	// points at a concrete agent.
	useEffect( () => {
		if ( agents.length === 0 ) {
			return;
		}

		const selectedExists = agents.some(
			( a ) => a.agent_id === selectedAgentId
		);

		if ( selectedAgentId === null || ! selectedExists ) {
			setSelectedAgentId( agents[ 0 ].agent_id );
		}
	}, [ agents, selectedAgentId, setSelectedAgentId ] );

	// Don't render until the agents list has loaded.
	if ( isLoading ) {
		return null;
	}

	// No agents at all — show a create button.
	if ( agents.length === 0 ) {
		return (
			<div className="datamachine-agent-switcher datamachine-agent-switcher--empty">
				<Button
					variant="primary"
					size="compact"
					onClick={ () => setShowCreateModal( true ) }
				>
					{ __( 'Create Agent', 'data-machine' ) }
				</Button>
				{ showCreateModal && (
					<CreateAgentModal
						onClose={ () => setShowCreateModal( false ) }
						onCreated={ ( agent ) => {
							if ( agent?.agent_id ) {
								setSelectedAgentId( agent.agent_id );
							}
						} }
					/>
				) }
			</div>
		);
	}

	// Build dropdown options: agents + create action.
	const options = [
		...agents.map( ( agent ) => ( {
			label:
				agent.agent_name ||
				agent.agent_slug ||
				__( 'Unnamed Agent', 'data-machine' ),
			value: String( agent.agent_id ),
		} ) ),
		{
			label: __( '+ Create Agent', 'data-machine' ),
			value: CREATE_AGENT_VALUE,
		},
	];

	/**
	 * Handle agent change — update store and invalidate all cached data
	 * except the agents list itself (that doesn't change per agent).
	 *
	 * @param {string} value Selected agent ID or create action.
	 */
	const handleChange = ( value ) => {
		// Intercept the create action — open modal, don't change selection.
		if ( value === CREATE_AGENT_VALUE ) {
			setShowCreateModal( true );
			return;
		}

		const newId = Number( value );
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
			{ showCreateModal && (
				<CreateAgentModal
					onClose={ () => setShowCreateModal( false ) }
					onCreated={ ( agent ) => {
						if ( agent?.agent_id ) {
							setSelectedAgentId( agent.agent_id );
						}
					} }
				/>
			) }
		</div>
	);
}
