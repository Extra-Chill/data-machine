/**
 * REST API Wrapper for Data Machine Pipelines
 *
 * Centralized REST API calls with error handling and standardized responses.
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
	const config = window.dataMachineConfig || {};
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
			...response,
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
const client = {
	get: ( path, params = {} ) => request( path, 'GET', undefined, params ),
	post: ( path, data ) => request( path, 'POST', data ),
	put: ( path, data ) => request( path, 'PUT', data ),
	patch: ( path, data ) => request( path, 'PATCH', data ),
	delete: ( path ) => request( path, 'DELETE' ),
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

/**
 * Pipeline Operations
 */

/**
 * Fetch all pipelines or a specific pipeline
 *
 * @param {number|null} pipelineId - Optional pipeline ID
 * @return {Promise<Object>} Pipeline data
 */
export const fetchPipelines = async ( pipelineId = null ) => {
	return await client.get(
		'/pipelines',
		pipelineId ? { pipeline_id: pipelineId } : {}
	);
};

/**
 * Create a new pipeline
 *
 * @param {string} name - Pipeline name
 * @return {Promise<Object>} Created pipeline data
 */
export const createPipeline = async ( name ) => {
	return await client.post( '/pipelines', { pipeline_name: name } );
};

/**
 * Update pipeline title
 *
 * @param {number} pipelineId - Pipeline ID
 * @param {string} name       - New pipeline name
 * @return {Promise<Object>} Updated pipeline data
 */
export const updatePipelineTitle = async ( pipelineId, name ) => {
	return await client.patch( `/pipelines/${ pipelineId }`, {
		pipeline_name: name,
	} );
};

/**
 * Delete a pipeline
 *
 * @param {number} pipelineId - Pipeline ID
 * @return {Promise<Object>} Deletion confirmation
 */
export const deletePipeline = async ( pipelineId ) => {
	return await client.delete( `/pipelines/${ pipelineId }` );
};

/**
 * Add a step to a pipeline
 *
 * @param {number} pipelineId     - Pipeline ID
 * @param {string} stepType       - Step type (fetch, ai, publish, update)
 * @param {number} executionOrder - Step position
 * @return {Promise<Object>} Created step data
 */
export const addPipelineStep = async (
	pipelineId,
	stepType,
	executionOrder
) => {
	return await client.post( `/pipelines/${ pipelineId }/steps`, {
		step_type: stepType,
		execution_order: executionOrder,
		label: `${
			stepType.charAt( 0 ).toUpperCase() + stepType.slice( 1 )
		} Step`,
	} );
};

/**
 * Delete a pipeline step
 *
 * @param {number} pipelineId - Pipeline ID
 * @param {string} stepId     - Pipeline step ID
 * @return {Promise<Object>} Deletion confirmation
 */
export const deletePipelineStep = async ( pipelineId, stepId ) => {
	return await client.delete(
		`/pipelines/${ pipelineId }/steps/${ stepId }`
	);
};

/**
 * Reorder pipeline steps
 *
 * @param {number}        pipelineId - Pipeline ID
 * @param {Array<Object>} steps      - Reordered steps array
 * @return {Promise<Object>} Updated pipeline data
 */
export const reorderPipelineSteps = async ( pipelineId, steps ) => {
	const stepOrder = steps.map( ( step, index ) => ( {
		pipeline_step_id: step.pipeline_step_id,
		execution_order: index,
	} ) );

	return await client.put( `/pipelines/${ pipelineId }/steps/reorder`, {
		step_order: stepOrder,
	} );
};

/**
 * Update AI step configuration
 *
 * @param {string}        stepId       - Pipeline step ID
 * @param {string}        prompt       - System prompt content
 * @param {string}        provider     - AI provider
 * @param {string}        model        - AI model
 * @param {Array<string>} enabledTools - Enabled AI tools
 * @param {string}        stepType     - Step type (currently only 'ai' supported)
 * @param {number}        pipelineId   - Pipeline ID for context
 * @return {Promise<Object>} Updated step data
 */
export const updateSystemPrompt = async (
	stepId,
	prompt,
	provider,
	model,
	enabledTools = [],
	stepType = 'ai',
	pipelineId = null
) => {
	const payload = {
		step_type: stepType,
		pipeline_id: pipelineId,
		provider,
		model,
		system_prompt: prompt,
		enabled_tools: enabledTools,
	};

	return await client.put( `/pipelines/steps/${ stepId }/config`, payload );
};

/**
 * Flow Operations
 */

/**
 * Fetch flows for a pipeline with pagination
 *
 * @param {number} pipelineId      - Pipeline ID
 * @param {Object} options         - Pagination options
 * @param {number} options.page    - Current page (1-indexed)
 * @param {number} options.perPage - Items per page
 * @return {Promise<Object>} Paginated flows response
 */
export const fetchFlows = async (
	pipelineId,
	{ page = 1, perPage = 20 } = {}
) => {
	const offset = ( page - 1 ) * perPage;
	return await client.get( '/flows', {
		pipeline_id: pipelineId,
		per_page: perPage,
		offset,
	} );
};

/**
 * Fetch a specific flow
 *
 * @param {number} flowId - Flow ID
 * @return {Promise<Object>} Flow data
 */
export const fetchFlow = async ( flowId ) => {
	return await client.get( `/flows/${ flowId }` );
};

/**
 * Create a new flow
 *
 * @param {number} pipelineId - Pipeline ID
 * @param {string} flowName   - Flow name
 * @return {Promise<Object>} Created flow data
 */
export const createFlow = async ( pipelineId, flowName ) => {
	return await client.post( '/flows', {
		pipeline_id: pipelineId,
		flow_name: flowName,
	} );
};

/**
 * Update flow title
 *
 * @param {number} flowId - Flow ID
 * @param {string} name   - New flow name
 * @return {Promise<Object>} Updated flow data
 */
export const updateFlowTitle = async ( flowId, name ) => {
	return await client.patch( `/flows/${ flowId }`, { flow_name: name } );
};

/**
 * Delete a flow
 *
 * @param {number} flowId - Flow ID
 * @return {Promise<Object>} Deletion confirmation
 */
export const deleteFlow = async ( flowId ) => {
	return await client.delete( `/flows/${ flowId }` );
};

/**
 * Duplicate a flow
 *
 * @param {number} flowId - Flow ID
 * @return {Promise<Object>} Duplicated flow data
 */
export const duplicateFlow = async ( flowId ) => {
	return await client.post( `/flows/${ flowId }/duplicate` );
};

/**
 * Run a flow immediately
 *
 * @param {number} flowId - Flow ID
 * @return {Promise<Object>} Execution confirmation
 */
export const runFlow = async ( flowId ) => {
	return await client.post( '/execute', { flow_id: flowId } );
};

/**
 * Update flow handler for a specific step
 *
 * @param {string} flowStepId         - Flow step ID
 * @param {string} handlerSlug        - Handler slug
 * @param {Object} settings           - Handler settings
 * @param {number} pipelineId         - Pipeline ID
 * @param {string} stepType           - Step type
 * @param          flowConfig
 * @param          pipelineStepConfig
 * @return {Promise<Object>} Updated flow step data
 */
export const updateFlowHandler = async (
	flowStepId,
	handlerSlug,
	settings = {},
	pipelineId,
	stepType,
	flowConfig = {},
	pipelineStepConfig = {}
) => {
	return await client.put( `/flows/steps/${ flowStepId }/handler`, {
		handler_slug: handlerSlug,
		pipeline_id: pipelineId,
		step_type: stepType,
		flow_config: flowConfig,
		pipeline_step: pipelineStepConfig,
		settings,
	} );
};

/**
 * Update flow step configuration
 *
 * @param {string} flowStepId - Flow step ID
 * @param {Object} config     - Partial step configuration
 * @return {Promise<Object>} Updated flow step data
 */
export const updateFlowStepConfig = async ( flowStepId, config ) => {
	return await client.patch( `/flows/steps/${ flowStepId }/config`, config );
};

/**
 * Update user message for AI step in flow
 *
 * @param {string} flowStepId - Flow step ID
 * @param {string} message    - User message content
 * @return {Promise<Object>} Updated flow step data
 */
export const updateUserMessage = async ( flowStepId, message ) => {
	return await client.patch( `/flows/steps/${ flowStepId }/user-message`, {
		user_message: message,
	} );
};

/**
 * Get available scheduling intervals
 *
 * @return {Promise<Object>} Array of scheduling intervals
 */
export const getSchedulingIntervals = async () => {
	return await client.get( '/settings/scheduling-intervals' );
};

/**
 * Update flow scheduling configuration
 *
 * @param {number} flowId                    - Flow ID
 * @param {Object} schedulingConfig          - Scheduling configuration
 * @param {string} schedulingConfig.interval - Interval (hourly, daily, weekly, etc.)
 * @return {Promise<Object>} Updated flow data
 */
export const updateFlowSchedule = async ( flowId, schedulingConfig ) => {
	return await client.patch( `/flows/${ flowId }`, {
		scheduling_config: schedulingConfig,
	} );
};

/**
 * Import/Export Operations
 */

/**
 * Export pipelines to CSV
 *
 * @param {Array<number>} pipelineIds - Array of pipeline IDs to export
 * @return {Promise<Object>} Export data with CSV content
 */
export const exportPipelines = async ( pipelineIds ) => {
	return await client.get( '/pipelines', {
		format: 'csv',
		ids: pipelineIds.join( ',' ),
	} );
};

/**
 * Import pipelines from CSV
 *
 * @param {string} csvContent - CSV file content
 * @return {Promise<Object>} Import result with created pipeline IDs
 */
export const importPipelines = async ( csvContent ) => {
	return await client.post( '/pipelines', {
		batch_import: true,
		format: 'csv',
		data: csvContent,
	} );
};

/**
 * Context Files Operations
 */

/**
 * Fetch context files for a pipeline
 *
 * @param {number} pipelineId - Pipeline ID
 * @return {Promise<Object>} Array of context files
 */
export const fetchContextFiles = async ( pipelineId ) => {
	return await client.get( '/files', { pipeline_id: pipelineId } );
};

/**
 * Upload context file for a pipeline
 *
 * @param {number} pipelineId - Pipeline ID
 * @param {File}   file       - File object to upload
 * @return {Promise<Object>} Upload confirmation
 */
export const uploadContextFile = async ( pipelineId, file ) => {
	return await client.upload( '/files', file, { pipeline_id: pipelineId } );
};

/**
 * Delete context file
 *
 * @param {string} filename - Filename to delete
 * @return {Promise<Object>} Deletion confirmation
 */
export const deleteContextFile = async ( filename ) => {
	return await client.delete( `/files/${ filename }` );
};

/**
 * Fetch complete handler details
 *
 * @param {string} handlerSlug - Handler slug (e.g., 'twitter', 'wordpress_publish')
 * @return {Promise<Object>} Handler details including basic info, settings schema, and AI tool definition
 */
export const fetchHandlerDetails = async ( handlerSlug ) => {
	return await client.get( `/handlers/${ handlerSlug }` );
};

/**
 * Get available step types
 *
 * @return {Promise<Object>} Step types configuration
 */
export const getStepTypes = async () => {
	return await client.get( '/step-types' );
};

/**
 * Get available providers
 *
 * @return {Promise<Object>} Providers configuration
 */
export const getProviders = async () => {
	return await client.get( '/providers' );
};

/**
 * Get available tools
 *
 * @return {Promise<Object>} Tools configuration
 */
export const getTools = async () => {
	return await client.get( '/tools' );
};

/**
 * Get all handlers
 *
 * @param {string} stepType - Optional step type filter
 * @return {Promise<Object>} Handlers configuration
 */
export const getHandlers = async ( stepType = null ) => {
	const params = stepType ? { step_type: stepType } : {};
	return await client.get( '/handlers', params );
};

/**
 * Queue Operations
 */

/**
 * Fetch queue for a flow
 *
 * @param {number} flowId     - Flow ID
 * @param {string} flowStepId - Flow step ID
 * @return {Promise<Object>} Queue data with items and count
 */
export const fetchFlowQueue = async ( flowId, flowStepId ) => {
	return await client.get( `/flows/${ flowId }/queue`, {
		flow_step_id: flowStepId,
	} );
};

/**
 * Add prompt(s) to flow queue
 *
 * @param {number}               flowId     - Flow ID
 * @param {string}               flowStepId - Flow step ID
 * @param {string|Array<string>} prompts    - Single prompt string or array of prompts
 * @return {Promise<Object>} Result with added count and queue length
 */
export const addToFlowQueue = async ( flowId, flowStepId, prompts ) => {
	const payload = Array.isArray( prompts )
		? { prompts }
		: { prompt: prompts };
	return await client.post( `/flows/${ flowId }/queue`, {
		...payload,
		flow_step_id: flowStepId,
	} );
};

/**
 * Clear all prompts from flow queue
 *
 * @param {number} flowId     - Flow ID
 * @param {string} flowStepId - Flow step ID
 * @return {Promise<Object>} Result with cleared count
 */
export const clearFlowQueue = async ( flowId, flowStepId ) => {
	return await client.delete(
		`/flows/${ flowId }/queue?flow_step_id=${ encodeURIComponent( flowStepId ) }`
	);
};

/**
 * Remove a specific prompt from flow queue by index
 *
 * @param {number} flowId     - Flow ID
 * @param {string} flowStepId - Flow step ID
 * @param {number} index      - Queue index (0-based)
 * @return {Promise<Object>} Result with removed prompt and new queue length
 */
export const removeFromFlowQueue = async ( flowId, flowStepId, index ) => {
	return await client.delete(
		`/flows/${ flowId }/queue/${ index }?flow_step_id=${ encodeURIComponent( flowStepId ) }`
	);
};

/**
 * Update a specific prompt in flow queue by index
 *
 * @param {number} flowId     - Flow ID
 * @param {string} flowStepId - Flow step ID
 * @param {number} index      - Queue index (0-based)
 * @param {string} prompt     - New prompt text
 * @return {Promise<Object>} Result with updated queue info
 */
export const updateFlowQueueItem = async (
	flowId,
	flowStepId,
	index,
	prompt
) => {
	return await client.put( `/flows/${ flowId }/queue/${ index }`, {
		flow_step_id: flowStepId,
		prompt,
	} );
};

/**
 * Update queue settings for a flow step
 *
 * @param {number}  flowId       - Flow ID
 * @param {string}  flowStepId   - Flow step ID
 * @param {boolean} queueEnabled - Whether queue pop is enabled
 * @return {Promise<Object>} Result
 */
export const updateFlowQueueSettings = async (
	flowId,
	flowStepId,
	queueEnabled
) => {
	return await client.put( `/flows/${ flowId }/queue/settings`, {
		flow_step_id: flowStepId,
		queue_enabled: queueEnabled,
	} );
};
