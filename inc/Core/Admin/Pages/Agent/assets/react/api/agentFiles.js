/**
 * Agent Files API
 *
 * Ability-backed memory file operations, executed through WordPress
 * core's ability runner (see #3456).
 */

/**
 * External dependencies
 */
import { executeAbility } from '@shared/utils/api';
import { useAgentStore } from '@shared/stores/agentStore';

/**
 * Get agent_id ability input for the selected agent, if any.
 *
 * @return {Object} Object with agent_id if one is selected, empty otherwise.
 */
const getAgentParams = () => {
	const { selectedAgentId } = useAgentStore.getState();
	return selectedAgentId !== null ? { agent_id: selectedAgentId } : {};
};

export const listAgentFiles = async () => {
	const result = await executeAbility(
		'list-agent-files',
		getAgentParams()
	);

	if ( ! result.success ) {
		return result;
	}

	return {
		...result,
		data: result.files ?? [],
	};
};

export const getAgentFile = async ( filename ) => {
	const result = await executeAbility( 'get-agent-file', {
		filename,
		...getAgentParams(),
	} );

	if ( ! result.success ) {
		return result;
	}

	return {
		...result,
		data: result.file ?? {},
	};
};

export const putAgentFile = async ( filename, content ) => {
	return executeAbility( 'write-agent-file', {
		filename,
		content,
		...getAgentParams(),
	} );
};

export const deleteAgentFile = async ( filename ) => {
	return executeAbility( 'delete-agent-file', {
		filename,
		...getAgentParams(),
	} );
};

// Daily memory file operations.

export const listDailyFiles = async () => {
	const result = await executeAbility(
		'daily-memory-list',
		getAgentParams()
	);

	if ( ! result.success ) {
		return result;
	}

	return {
		...result,
		data: result.months ?? {},
	};
};

export const getDailyFile = async ( year, month, day ) => {
	const result = await executeAbility( 'daily-memory-read', {
		date: `${ year }-${ month }-${ day }`,
		...getAgentParams(),
	} );

	if ( ! result.success ) {
		return result;
	}

	return {
		...result,
		data: { date: result.date, content: result.content },
	};
};

export const putDailyFile = async ( year, month, day, content ) => {
	return executeAbility( 'daily-memory-write', {
		date: `${ year }-${ month }-${ day }`,
		content,
		mode: 'write',
		...getAgentParams(),
	} );
};

export const deleteDailyFile = async ( year, month, day ) => {
	return executeAbility( 'daily-memory-delete', {
		date: `${ year }-${ month }-${ day }`,
		...getAgentParams(),
	} );
};
