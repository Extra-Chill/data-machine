/**
 * REST API Client for Data Machine Admin Pages
 *
 * Centralized REST API wrapper with error handling and standardized responses.
 * Uses wp.apiFetch from @wordpress/api-fetch.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

/**
 * Get REST API configuration from WordPress globals
 */
const getConfig = () => {
	const config =
		window.dataMachineConfig ||
		window.dataMachineLogsConfig ||
		window.dataMachineSettingsConfig ||
		window.dataMachineAgentConfig ||
		{};
	return {
		restNamespace: config.restNamespace || 'datamachine/v1',
		restNonce: config.restNonce || '',
	};
};

/**
 * Core API Request Handler
 *
 * @param {string} path         - Endpoint path (relative to namespace)
 * @param {string} method       - HTTP method
 * @param {Object} data         - Request body data (for JSON)
 * @param {Object} params       - Query parameters
 * @param {Object} extraOptions - Additional fetch options (headers, body, etc.)
 */
const request = async (
	path,
	method = 'GET',
	data = undefined,
	params = {},
	extraOptions = {}
) => {
	const config = getConfig();
	const endpoint = addQueryArgs(
		`/${ config.restNamespace }${ path }`,
		params
	);

	try {
		const response = await apiFetch( {
			path: endpoint,
			method,
			data,
			headers: {
				'X-WP-Nonce': config.restNonce,
				...extraOptions.headers,
			},
			...extraOptions,
		} );

		return {
			success: response.success,
			data: response.data,
			message: response.message || '',
			...response, // Include any additional fields from response
		};
	} catch ( error ) {
		console.error( `API Request Error [${ method } ${ path }]:`, error );

		return {
			success: false,
			data: null,
			message: error.message || 'An error occurred',
		};
	}
};

/**
 * API Client Methods
 */
export const client = {
	get: ( path, params = {} ) => request( path, 'GET', undefined, params ),
	post: ( path, data ) => request( path, 'POST', data ),
	put: ( path, data ) => request( path, 'PUT', data ),
	patch: ( path, data ) => request( path, 'PATCH', data ),
	delete: ( path, params = {} ) =>
		request( path, 'DELETE', undefined, params ),
	upload: async ( path, file, additionalData = {} ) => {
		const formData = new FormData();
		formData.append( 'file', file );
		Object.keys( additionalData ).forEach( ( key ) =>
			formData.append( key, additionalData[ key ] )
		);

		return request(
			path,
			'POST',
			undefined,
			{},
			{
				body: formData,
			}
		);
	},
};

export default client;
