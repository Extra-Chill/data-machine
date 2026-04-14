/**
 * Agent Files API
 *
 * REST client functions for agent memory file operations.
 * Uses the shared API client for automatic agent interceptor support.
 */

/**
 * Internal dependencies
 */
import { client } from '@shared/utils/api';
import { useAgentStore } from '@shared/stores/agentStore';

/**
 * Get agent_id params for mutation requests (PUT/DELETE).
 * The shared client interceptor only injects into GET requests,
 * so mutations must include agent_id explicitly.
 *
 * @return {Object} Object with agent_id if one is selected, empty otherwise.
 */
const getAgentParams = () => {
	const { selectedAgentId } = useAgentStore.getState();
	return selectedAgentId !== null ? { agent_id: selectedAgentId } : {};
};

export const listAgentFiles = async () => {
	return client.get( '/files/agent' );
};

export const getAgentFile = async ( filename ) => {
	return client.get( `/files/agent/${ filename }` );
};

export const putAgentFile = async ( filename, content ) => {
	return client.put( `/files/agent/${ filename }`, {
		content,
		...getAgentParams(),
	} );
};

export const deleteAgentFile = async ( filename ) => {
	return client.delete( `/files/agent/${ filename }`, getAgentParams() );
};

// Daily memory file operations.

export const listDailyFiles = async () => {
	return client.get( '/files/agent/daily' );
};

export const getDailyFile = async ( year, month, day ) => {
	return client.get( `/files/agent/daily/${ year }/${ month }/${ day }` );
};

export const putDailyFile = async ( year, month, day, content ) => {
	return client.put( `/files/agent/daily/${ year }/${ month }/${ day }`, {
		content,
		...getAgentParams(),
	} );
};

export const deleteDailyFile = async ( year, month, day ) => {
	return client.delete(
		`/files/agent/daily/${ year }/${ month }/${ day }`,
		getAgentParams()
	);
};

// Context memory file operations.

export const getContextFile = async ( slug ) => {
	return client.get( `/files/agent/contexts/${ slug }` );
};

export const putContextFile = async ( slug, content ) => {
	return client.put( `/files/agent/contexts/${ slug }`, {
		content,
		...getAgentParams(),
	} );
};
