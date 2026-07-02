/**
 * Agents CRUD API
 *
 * REST client functions for agent identity management.
 * Uses the shared API client for automatic agent interceptor support.
 */

/**
 * Internal dependencies
 */
import { client } from '@shared/utils/api';

/**
 * Fetch all agents (management list — includes timestamps).
 *
 * @return {Promise<Object>} API response with agents array.
 */
export const fetchAgents = () => client.get( '/agents' );

/**
 * Fetch a single agent by ID (includes config, access, directory info).
 *
 * @param {number} agentId Agent ID.
 * @return {Promise<Object>} API response with agent data.
 */
export const fetchAgent = ( agentId ) =>
	client.get( `/agents/${ agentId }` );

/**
 * Create a new agent.
 *
 * @param {Object} data Agent data: { agent_slug, agent_name?, config? }.
 * @return {Promise<Object>} API response with created agent.
 */
export const createAgent = ( data ) => client.post( '/agents', data );

/**
 * Update an agent's mutable fields.
 *
 * @param {number} agentId Agent ID.
 * @param {Object} data    Fields to update: { agent_name?, agent_config?, status? }.
 * @return {Promise<Object>} API response with updated agent.
 */
export const updateAgent = ( agentId, data ) =>
	client.put( `/agents/${ agentId }`, data );

/**
 * Delete an agent.
 *
 * @param {number}  agentId     Agent ID.
 * @param {boolean} deleteFiles Also delete filesystem directory.
 * @return {Promise<Object>} API response.
 */
export const deleteAgent = ( agentId, deleteFiles = false ) =>
	client.delete( `/agents/${ agentId }`, { delete_files: deleteFiles } );

/**
 * Fetch access grants for an agent.
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
