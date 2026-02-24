/**
 * Agent Files API
 *
 * REST client functions for agent memory file operations.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

const getConfig = () => {
	const config = window.dataMachineAgentConfig || {};
	return { restNamespace: config.restNamespace || 'datamachine/v1' };
};

export const listAgentFiles = async () => {
	const config = getConfig();
	return apiFetch( { path: `/${ config.restNamespace }/files/agent` } );
};

export const getAgentFile = async ( filename ) => {
	const config = getConfig();
	return apiFetch( {
		path: `/${ config.restNamespace }/files/agent/${ filename }`,
	} );
};

export const putAgentFile = async ( filename, content ) => {
	const config = getConfig();
	return apiFetch( {
		path: `/${ config.restNamespace }/files/agent/${ filename }`,
		method: 'PUT',
		body: content,
		headers: { 'Content-Type': 'text/plain' },
	} );
};

export const deleteAgentFile = async ( filename ) => {
	const config = getConfig();
	return apiFetch( {
		path: `/${ config.restNamespace }/files/agent/${ filename }`,
		method: 'DELETE',
	} );
};

// Daily memory file operations.

export const listDailyFiles = async () => {
	const config = getConfig();
	return apiFetch( {
		path: `/${ config.restNamespace }/files/agent/daily`,
	} );
};

export const getDailyFile = async ( year, month, day ) => {
	const config = getConfig();
	return apiFetch( {
		path: `/${ config.restNamespace }/files/agent/daily/${ year }/${ month }/${ day }`,
	} );
};

export const putDailyFile = async ( year, month, day, content ) => {
	const config = getConfig();
	return apiFetch( {
		path: `/${ config.restNamespace }/files/agent/daily/${ year }/${ month }/${ day }`,
		method: 'PUT',
		body: content,
		headers: { 'Content-Type': 'text/plain' },
	} );
};

export const deleteDailyFile = async ( year, month, day ) => {
	const config = getConfig();
	return apiFetch( {
		path: `/${ config.restNamespace }/files/agent/daily/${ year }/${ month }/${ day }`,
		method: 'DELETE',
	} );
};
