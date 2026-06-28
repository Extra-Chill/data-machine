/**
 * REST API Client for Data Machine Admin Pages
 *
 * Centralized REST API wrapper with error handling and standardized responses.
 * Uses wp.apiFetch from @wordpress/api-fetch.
 *
 * Supports param interceptors — functions that inject query params into every
 * GET request. Register interceptors at app boot via client.addParamInterceptor().
 * The client itself has no knowledge of what's being injected (agents, user
 * preferences, etc.) — it just calls the registered functions and merges results.
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
 * Registered param interceptors.
 * Each is a function () => Object that returns params to merge into GET requests.
 *
 * @type {Array<Function>}
 */
const paramInterceptors = [];

/**
 * Collect params from all registered interceptors.
 *
 * @return {Object} Merged params from all interceptors.
 */
const getInterceptedParams = () => {
	let merged = {};
	for ( const interceptor of paramInterceptors ) {
		merged = { ...merged, ...interceptor() };
	}
	return merged;
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
	/**
	 * GET request with automatic param interceptor injection.
	 * Interceptor params are applied first, then caller params override.
	 */
	get: ( path, params = {} ) =>
		request( path, 'GET', undefined, {
			...getInterceptedParams(),
			...params,
		} ),
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

	/**
	 * Register a param interceptor.
	 * The function is called on every GET request and should return an Object
	 * of query params to inject (or {} to inject nothing).
	 *
	 * @param {Function} interceptor - () => Object
	 */
	addParamInterceptor: ( interceptor ) => {
		if ( typeof interceptor === 'function' ) {
			paramInterceptors.push( interceptor );
		}
	},
};

export default client;
