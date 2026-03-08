/**
 * Agent Param Interceptor
 *
 * Registers a param interceptor on the shared API client that injects agent_id
 * into every GET request when an agent is selected in the AgentSwitcher.
 *
 * Import this module once at app boot (in index.jsx) — the side effect runs
 * on import. No need to call anything explicitly.
 */

/**
 * Internal dependencies
 */
import { client } from '@shared/utils/api';
import { useAgentStore } from '@shared/stores/agentStore';

client.addParamInterceptor( () => {
	const { selectedAgentId } = useAgentStore.getState();
	return selectedAgentId !== null ? { agent_id: selectedAgentId } : {};
} );
