/**
 * Agents CRUD API
 *
 * Ability-backed agent identity management, executed through WordPress
 * core's ability runner (see #3456).
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import { executeAbility } from '@shared/utils/api';

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
 * Fetch access grants for an agent, enriched with WP user display fields.
 *
 * Access is owned by the Agents API substrate (`agents/list-agent-users`);
 * the registry keys agents by slug. User display enrichment is presentation
 * and stays here.
 *
 * @param {string} agentSlug Agent slug.
 * @return {Promise<Object>} Result with the enriched grants array in `data`.
 */
export const fetchAgentAccess = async ( agentSlug ) => {
	const result = await executeAbility(
		'agents/list-agent-users',
		{ agent: agentSlug },
		{ method: 'GET' }
	);
	if ( ! result.success ) {
		return result;
	}
	const grants = result.users ?? [];
	const userIds = grants.map( ( g ) => g.user_id ).filter( Boolean );
	let usersById = {};
	if ( userIds.length ) {
		try {
			const users = await apiFetch( {
				path: addQueryArgs( '/wp/v2/users', {
					include: userIds.join( ',' ),
					per_page: 100,
					context: 'edit',
				} ),
			} );
			usersById = Object.fromEntries(
				users.map( ( u ) => [ u.id, u ] )
			);
		} catch {
			// Enrichment is best-effort; grants still render by user_id.
		}
	}
	return {
		...result,
		data: grants.map( ( g ) => ( {
			...g,
			display_name: usersById[ g.user_id ]?.name ?? `#${ g.user_id }`,
			user_email: usersById[ g.user_id ]?.email ?? '',
		} ) ),
	};
};

/**
 * Grant a user access to an agent.
 *
 * @param {string} agentSlug Agent slug.
 * @param {number} userId    WordPress user ID.
 * @param {string} role      Access role (admin, operator, viewer).
 * @return {Promise<Object>} Ability result.
 */
export const grantAccess = ( agentSlug, userId, role = 'viewer' ) =>
	executeAbility( 'agents/grant-agent-access', {
		agent: agentSlug,
		user_id: userId,
		role,
	} );

/**
 * Revoke a user's access to an agent.
 *
 * @param {string} agentSlug Agent slug.
 * @param {number} userId    WordPress user ID.
 * @return {Promise<Object>} Ability result.
 */
export const revokeAccess = ( agentSlug, userId ) =>
	executeAbility( 'agents/revoke-agent-access', {
		agent: agentSlug,
		user_id: userId,
	} );
