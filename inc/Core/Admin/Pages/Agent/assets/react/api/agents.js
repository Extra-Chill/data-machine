/**
 * Agents CRUD API
 *
 * Ability-backed agent identity management, executed through WordPress
 * core's ability runner (see #3456).
 */

/**
 * External dependencies
 */
import { client, executeAbility } from '@shared/utils/api';

/**
 * Fetch all agents accessible to the current user.
 *
 * @return {Promise<Object>} Result with the agents array in `data`.
 */
export const fetchAgents = async () => {
	const result = await executeAbility( 'list-agents' );

	if ( ! result.success ) {
		return result;
	}

	return {
		...result,
		data: result.agents ?? [],
	};
};

/**
 * Fetch a single agent by ID (includes config, access, directory info).
 *
 * @param {number} agentId Agent ID.
 * @return {Promise<Object>} Result with the agent data in `data`.
 */
export const fetchAgent = async ( agentId ) => {
	// get-agent is annotated readonly, so the core runner requires GET.
	const result = await executeAbility(
		'get-agent',
		{ agent_id: agentId },
		{ method: 'GET' }
	);

	if ( ! result.success ) {
		return result;
	}

	return {
		...result,
		data: result.agent ?? {},
	};
};

/**
 * Create a new agent owned by the current user.
 *
 * @param {Object} data Agent data: { agent_slug, agent_name? }.
 * @return {Promise<Object>} Ability result with the created agent fields.
 */
export const createAgent = ( data ) => executeAbility( 'create-agent', data );

/**
 * Update an agent's mutable fields.
 *
 * @param {number} agentId Agent ID.
 * @param {Object} data    Fields to update: { agent_name?, agent_config? }.
 * @return {Promise<Object>} Result with the updated agent in `data`.
 */
export const updateAgent = async ( agentId, data ) => {
	const result = await executeAbility( 'update-agent', {
		agent_id: agentId,
		...data,
	} );

	if ( ! result.success ) {
		return result;
	}

	return {
		...result,
		data: result.agent ?? {},
	};
};

/**
 * Delete an agent.
 *
 * @param {number}  agentId     Agent ID.
 * @param {boolean} deleteFiles Also delete filesystem directory.
 * @return {Promise<Object>} Ability result.
 */
export const deleteAgent = ( agentId, deleteFiles = false ) =>
	executeAbility( 'delete-agent', {
		agent_id: agentId,
		delete_files: deleteFiles,
	} );

/**
 * Fetch access grants for an agent.
 *
 * Access routes are retained on datamachine/v1 until Agents API ships
 * grant/revoke/list-users abilities (Automattic/agents-api#537).
 *
 * @param {number} agentId Agent ID.
 * @return {Promise<Object>} API response with access grants array.
 */
export const fetchAgentAccess = ( agentId ) =>
	client.get( `/agents/${ agentId }/access` );

/**
 * Grant a user access to an agent.
 *
 * @param {number} agentId Agent ID.
 * @param {number} userId  WordPress user ID.
 * @param {string} role    Access role (admin, operator, viewer).
 * @return {Promise<Object>} API response.
 */
export const grantAccess = ( agentId, userId, role = 'viewer' ) =>
	client.post( `/agents/${ agentId }/access`, {
		user_id: userId,
		role,
	} );

/**
 * Revoke a user's access to an agent.
 *
 * @param {number} agentId Agent ID.
 * @param {number} userId  WordPress user ID.
 * @return {Promise<Object>} API response.
 */
export const revokeAccess = ( agentId, userId ) =>
	client.delete( `/agents/${ agentId }/access/${ userId }` );
