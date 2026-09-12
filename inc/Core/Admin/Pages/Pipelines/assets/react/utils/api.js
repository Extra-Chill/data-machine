/**
 * REST API Wrapper for Data Machine Pipelines
 *
 * Domain-specific API functions for pipelines, flows, steps, and queues.
 * Uses the shared REST client from @shared/utils/api.
 */

/**
 * External dependencies
 */
import { client, executeAbility } from '@shared/utils/api';
import { useAgentStore } from '@shared/stores/agentStore';

/**
 * Get agent_id payload for mutations.
 * Returns an object with agent_id if one is selected, or empty object.
 *
 * @return {Object} { agent_id: number } or {}.
 */
const getAgentPayload = () => {
	const { selectedAgentId } = useAgentStore.getState();
	return selectedAgentId ? { agent_id: selectedAgentId } : {};
};

/**
 * Pipeline Operations
 */

/**
 * Fetch pipelines list or a specific pipeline.
 *
 * In list mode, requests explicit lightweight responses so the
 * admin UI can render hundreds of pipelines without the server embedding every
 * flow. Each pipeline still exposes flow_count for display purposes, and the
 * selected pipeline's flows are fetched separately via /flows.
 *
 * @param {number|null} pipelineId             - Optional pipeline ID (single-pipeline mode ignores list params)
 * @param {Object}      [options]              - List-mode options
 * @param {number}      [options.perPage]      - Items per page (default 100)
 * @param {number}      [options.offset]       - Pagination offset (default 0)
 * @param {boolean}     [options.includeFlows] - Embed flows per pipeline (default false)
 * @param {string}      [options.outputMode]   - Pipeline output mode (default list)
 * @param {string|null} [options.search]       - Filter by pipeline name (substring)
 * @return {Promise<Object>} Pipeline data
 */
export const fetchPipelines = async (
	pipelineId = null,
	{
		perPage = 100,
		offset = 0,
		includeFlows = false,
		outputMode = 'list',
		search = null,
	} = {}
) => {
	if ( pipelineId ) {
		const result = await executeAbility( 'get-pipelines', {
			pipeline_id: pipelineId,
			output_mode: outputMode,
			include_flows: includeFlows,
		} );

		if ( ! result.success ) {
			return result;
		}

		const record = result.pipelines?.[ 0 ] ?? null;
		if ( ! record ) {
			return {
				success: false,
				data: null,
				message: 'Pipeline not found.',
			};
		}

		const { flows = [], ...pipeline } = record;
		return {
			...result,
			data: { pipeline, flows },
		};
	}

	const input = {
		per_page: perPage,
		offset,
		output_mode: outputMode,
		include_flows: includeFlows,
	};

	if ( search ) {
		input.search = search;
	}

	const result = await executeAbility( 'get-pipelines', {
		...input,
		...getAgentPayload(),
	} );

	if ( ! result.success ) {
		return result;
	}

	return {
		...result,
		data: { pipelines: result.pipelines ?? [] },
	};
};

/**
 * Create a new pipeline
 *
 * @param {string} name - Pipeline name
 * @return {Promise<Object>} Created pipeline data
 */
export const createPipeline = async ( name ) => {
	return await executeAbility( 'create-pipeline', {
		pipeline_name: name,
		...getAgentPayload(),
	} );
};

/**
 * Update pipeline title
 *
 * @param {number} pipelineId - Pipeline ID
 * @param {string} name       - New pipeline name
 * @return {Promise<Object>} Updated pipeline data
 */
export const updatePipelineTitle = async ( pipelineId, name ) => {
	return await executeAbility( 'update-pipeline', {
		pipeline_id: pipelineId,
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
	return await executeAbility( 'delete-pipeline', {
		pipeline_id: pipelineId,
	} );
};

/**
 * Add a step to a pipeline
 *
 * Execution order and label are derived by the ability (appended last,
 * labelled from the step-type registry).
 *
 * @param {number} pipelineId - Pipeline ID
 * @param {string} stepType   - Step type (fetch, ai, publish, upsert)
 * @return {Promise<Object>} Created step data
 */
export const addPipelineStep = async ( pipelineId, stepType ) => {
	const result = await executeAbility( 'add-pipeline-step', {
		pipeline_id: pipelineId,
		step_type: stepType,
	} );

	if ( ! result.success ) {
		return result;
	}

	const pipelineStepId = result.pipeline_step_id;
	const stepsResult = await executeAbility( 'get-pipeline-steps', {
		pipeline_step_id: pipelineStepId,
	} );
	const stepData = stepsResult.success
		? ( stepsResult.data?.steps ?? stepsResult.steps ?? [] ).find(
				( step ) => step.pipeline_step_id === pipelineStepId
		  )
		: null;

	return {
		...result,
		data: {
			step_type: stepType,
			pipeline_id: pipelineId,
			pipeline_step_id: pipelineStepId,
			step_data: stepData ?? null,
			created_type: 'step',
		},
	};
};

/**
 * Delete a pipeline step
 *
 * @param {number} pipelineId - Pipeline ID
 * @param {string} stepId     - Pipeline step ID
 * @return {Promise<Object>} Deletion confirmation
 */
export const deletePipelineStep = async ( pipelineId, stepId ) => {
	return await executeAbility( 'delete-pipeline-step', {
		pipeline_id: pipelineId,
		pipeline_step_id: stepId,
	} );
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

	return await executeAbility( 'reorder-pipeline-steps', {
		pipeline_id: pipelineId,
		step_order: stepOrder,
	} );
};

/**
 * Update AI step configuration
 *
 * @param {string} stepId - Pipeline step ID
 * @param {string} prompt - System prompt content
 * @return {Promise<Object>} Updated step data
 */
export const updateSystemPrompt = async ( stepId, prompt ) => {
	// Model/provider/tools are managed via context system, not per-pipeline.
	return await executeAbility( 'update-pipeline-step', {
		pipeline_step_id: stepId,
		system_prompt: prompt,
	} );
};

/**
 * Flow Operations
 */

/**
 * Fetch flows for a pipeline with pagination
 *
 * @param {number} pipelineId         - Pipeline ID
 * @param {Object} options            - Pagination options
 * @param {number} options.page       - Current page (1-indexed)
 * @param {number} options.perPage    - Items per page
 * @param {string} options.outputMode - Flow output mode (default list)
 * @return {Promise<Object>} Paginated flows response
 */
export const fetchFlows = async (
	pipelineId,
	{ page = 1, perPage = 20, outputMode = 'list' } = {}
) => {
	const offset = ( page - 1 ) * perPage;
	const result = await executeAbility( 'get-flows', {
		pipeline_id: pipelineId,
		per_page: perPage,
		offset,
		output_mode: outputMode,
		...getAgentPayload(),
	} );

	if ( ! result.success ) {
		return result;
	}

	return {
		...result,
		data: { pipeline_id: pipelineId, flows: result.flows ?? [] },
	};
};

/**
 * Fetch a specific flow
 *
 * @param {number} flowId - Flow ID
 * @return {Promise<Object>} Flow data
 */
export const fetchFlow = async ( flowId ) => {
	const result = await executeAbility( 'get-flows', { flow_id: flowId } );

	if ( ! result.success ) {
		return result;
	}

	const flow = result.flows?.[ 0 ] ?? null;
	if ( ! flow ) {
		return {
			success: false,
			data: null,
			message: 'Flow not found.',
		};
	}

	return {
		...result,
		data: flow,
	};
};

/**
 * Create a new flow
 *
 * @param {number} pipelineId - Pipeline ID
 * @param {string} flowName   - Flow name
 * @return {Promise<Object>} Created flow data
 */
export const createFlow = async ( pipelineId, flowName ) => {
	return await executeAbility( 'create-flow', {
		pipeline_id: pipelineId,
		flow_name: flowName,
		...getAgentPayload(),
	} );
};

/**
 * Update a flow record (title and/or scheduling), then re-fetch the full
 * flow record so callers can cache it.
 *
 * @param {number} flowId - Flow ID
 * @param {Object} patch  - Fields to update (flow_name, scheduling_config)
 * @return {Promise<Object>} Updated flow data
 */
const updateFlowRecord = async ( flowId, patch ) => {
	const result = await executeAbility( 'update-flow', {
		flow_id: flowId,
		...patch,
	} );

	if ( ! result.success ) {
		return result;
	}

	const flowResult = await executeAbility( 'get-flows', { flow_id: flowId } );
	const flow = flowResult.success ? ( flowResult.flows?.[ 0 ] ?? null ) : null;

	if ( ! flow ) {
		return { ...result, data: result.flow_data ?? { flow_id: flowId } };
	}

	return {
		...result,
		data: flow,
	};
};

/**
 * Update flow title
 *
 * @param {number} flowId - Flow ID
 * @param {string} name   - New flow name
 * @return {Promise<Object>} Updated flow data
 */
export const updateFlowTitle = async ( flowId, name ) => {
	return await updateFlowRecord( flowId, { flow_name: name } );
};

/**
 * Delete a flow
 *
 * @param {number} flowId - Flow ID
 * @return {Promise<Object>} Deletion confirmation
 */
export const deleteFlow = async ( flowId ) => {
	return await executeAbility( 'delete-flow', { flow_id: flowId } );
};

/**
 * Duplicate a flow
 *
 * @param {number} flowId - Flow ID
 * @return {Promise<Object>} Duplicated flow data
 */
export const duplicateFlow = async ( flowId ) => {
	return await executeAbility( 'duplicate-flow', {
		source_flow_id: flowId,
	} );
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
 * The update-flow-step ability derives flow, pipeline, and step-type context
 * from the flow step ID; only the handler selection and settings are sent.
 * The refreshed step config (including settings display data) is fetched so
 * callers can patch their caches.
 *
 * @param {string} flowStepId  - Flow step ID
 * @param {string} handlerSlug - Handler slug
 * @param {Object} settings    - Handler settings
 * @return {Promise<Object>} Updated flow step data
 */
export const updateFlowHandler = async (
	flowStepId,
	handlerSlug,
	settings = {}
) => {
	const result = await executeAbility( 'update-flow-step', {
		flow_step_id: flowStepId,
		handler_slug: handlerSlug,
		handler_config: settings,
	} );

	if ( ! result.success ) {
		return result;
	}

	const stepsResult = await executeAbility( 'get-flow-steps', {
		flow_step_id: flowStepId,
	} );
	const step = stepsResult.success
		? ( stepsResult.steps?.[ 0 ] ?? null )
		: null;

	return {
		...result,
		data: {
			step_type: step?.step_type ?? null,
			flow_step_id: flowStepId,
			flow_id: step?.flow_id ?? null,
			step_config: step ?? {},
			handler_settings_display: step?.settings_display ?? null,
			handler_settings_displays: step?.handler_settings_displays ?? null,
		},
	};
};

/**
 * Update flow step configuration
 *
 * @param {string} flowStepId - Flow step ID
 * @param {Object} config     - Partial step config (handler_slug, handler_config, user_message)
 * @return {Promise<Object>} Updated flow step data
 */
export const updateFlowStepConfig = async ( flowStepId, config ) => {
	return await executeAbility( 'update-flow-step', {
		flow_step_id: flowStepId,
		...config,
	} );
};

/**
 * Add a handler to a flow step (multi-handler mode).
 *
 * Uses the wp-abilities API to invoke the datamachine/update-flow-step ability.
 *
 * @param {string} flowStepId  - Flow step ID
 * @param {string} handlerSlug - Handler slug to add
 * @param {Object} settings    - Initial handler settings
 * @return {Promise<Object>} Ability execution result
 */
export const addFlowHandler = async ( flowStepId, handlerSlug, settings = {} ) => {
	return await executeAbility( 'update-flow-step', {
		flow_step_id: flowStepId,
		add_handler: handlerSlug,
		add_handler_config: settings,
	} );
};

/**
 * Remove a handler from a flow step (multi-handler mode).
 *
 * Uses the wp-abilities API to invoke the datamachine/update-flow-step ability.
 *
 * @param {string} flowStepId  - Flow step ID
 * @param {string} handlerSlug - Handler slug to remove
 * @return {Promise<Object>} Ability execution result
 */
export const removeFlowHandler = async ( flowStepId, handlerSlug ) => {
	return await executeAbility( 'update-flow-step', {
		flow_step_id: flowStepId,
		remove_handler: handlerSlug,
	} );
};

/**
 * Get available scheduling intervals
 *
 * @return {Promise<Object>} Array of scheduling intervals
 */
export const getSchedulingIntervals = async () => {
	const result = await executeAbility( 'get-scheduling-intervals' );

	if ( ! result.success ) {
		return result;
	}

	return {
		...result,
		data: result.intervals ?? [],
	};
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
	return await updateFlowRecord( flowId, {
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
 * @return {Promise<Object>} Export result with `data` holding the CSV content
 */
export const exportPipelines = async ( pipelineIds ) => {
	return await executeAbility( 'export-pipelines', {
		pipeline_ids: pipelineIds,
	} );
};

/**
 * Import pipelines from CSV
 *
 * @param {string} csvContent - CSV file content
 * @return {Promise<Object>} Import result with created pipeline IDs
 */
export const importPipelines = async ( csvContent ) => {
	return await executeAbility( 'import-pipelines', {
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
 * Files are flow-step scoped on the ability (`list-flow-files` requires a
 * `flow_step_id`); the pipeline-scoped section cannot supply one, so this
 * surface keeps failing the way the wrapper route did. See the #3456
 * parity note before wiring this to a real step.
 *
 * @param {number} pipelineId - Pipeline ID
 * @return {Promise<Object>} Array of context files
 */
export const fetchContextFiles = async ( pipelineId ) => {
	return await executeAbility( 'list-flow-files', {
		pipeline_id: pipelineId,
	} );
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
 * Mirrors fetchContextFiles: deletion is flow-step scoped on the ability
 * (`delete-flow-file` requires a `flow_step_id`), which this caller cannot
 * supply, so the call fails the way the wrapper route did.
 *
 * @param {string} filename - Filename to delete
 * @return {Promise<Object>} Deletion confirmation
 */
export const deleteContextFile = async ( filename ) => {
	return await executeAbility( 'delete-flow-file', { filename } );
};

/**
 * Memory Files Operations
 */

/**
 * Fetch memory files for a pipeline
 *
 * @param {number} pipelineId - Pipeline ID
 * @return {Promise<Object>} Array of memory filenames
 */
export const fetchPipelineMemoryFiles = async ( pipelineId ) => {
	const result = await executeAbility( 'get-pipeline-memory-files', {
		pipeline_id: pipelineId,
	} );

	if ( ! result.success ) {
		return result;
	}

	return {
		...result,
		data: result.memory_files ?? [],
	};
};

/**
 * Update memory files for a pipeline
 *
 * @param {number}        pipelineId  - Pipeline ID
 * @param {Array<string>} memoryFiles - Array of filenames
 * @return {Promise<Object>} Update confirmation
 */
export const updatePipelineMemoryFiles = async ( pipelineId, memoryFiles ) => {
	return await executeAbility( 'update-pipeline-memory-files', {
		pipeline_id: pipelineId,
		memory_files: memoryFiles,
	} );
};

/**
 * Fetch memory files for a flow
 *
 * @param {number} flowId - Flow ID
 * @return {Promise<Object>} Object with memory_files array
 */
export const fetchFlowMemoryFiles = async ( flowId ) => {
	const result = await executeAbility( 'get-flow-memory-files', {
		flow_id: flowId,
	} );

	if ( ! result.success ) {
		return result;
	}

	return {
		...result,
		data: { memory_files: result.memory_files ?? [] },
	};
};

/**
 * Update memory files for a flow
 *
 * @param {number}        flowId      - Flow ID
 * @param {Array<string>} memoryFiles - Array of filenames
 * @return {Promise<Object>} Update confirmation
 */
export const updateFlowMemoryFiles = async ( flowId, memoryFiles ) => {
	return await executeAbility( 'update-flow-memory-files', {
		flow_id: flowId,
		memory_files: memoryFiles,
	} );
};

/**
 * Fetch available agent files
 *
 * @return {Promise<Object>} Array of agent files
 */
export const fetchAgentFiles = async () => {
	const { selectedAgentId } = useAgentStore.getState();
	const result = await executeAbility( 'list-agent-files', {
		...( selectedAgentId ? { agent_id: selectedAgentId } : {} ),
	} );

	if ( ! result.success ) {
		return result;
	}

	return {
		...result,
		data: result.files ?? [],
	};
};

/**
 * Fetch complete handler details
 *
 * @param {string} handlerSlug - Handler slug (e.g., 'twitter', 'wordpress_publish')
 * @return {Promise<Object>} Handler details including basic info, settings schema, and AI tool definition
 */
export const fetchHandlerDetails = async ( handlerSlug ) => {
	return await executeAbility( 'get-handler-detail', {
		handler_slug: handlerSlug,
	} );
};

/**
 * Get available step types
 *
 * @return {Promise<Object>} Step types configuration
 */
export const getStepTypes = async () => {
	const result = await executeAbility( 'get-step-types' );

	if ( ! result.success ) {
		return result;
	}

	return {
		...result,
		data: result.step_types ?? {},
	};
};

/**
 * Get available tools
 *
 * @param {string|null} context - Optional context filter ('pipeline', 'chat', 'system')
 * @return {Promise<Object>} Tools configuration
 */
export const getTools = async ( context = null ) => {
	const params = context ? { context } : {};
	return await client.get( '/tools', params );
};

/**
 * Get all handlers
 *
 * @param {string} stepType - Optional step type filter
 * @return {Promise<Object>} Handlers configuration
 */
export const getHandlers = async ( stepType = null ) => {
	const result = await executeAbility( 'get-handlers', stepType ? { step_type: stepType } : {} );

	if ( ! result.success ) {
		return result;
	}

	return {
		...result,
		data: result.handlers ?? {},
	};
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
	return await executeAbility( 'queue-list', {
		flow_id: flowId,
		flow_step_id: flowStepId,
	} );
};

/**
 * Add prompt(s) to flow queue
 *
 * The queue-add ability takes a single prompt; multiple prompts are added
 * sequentially, mirroring the retired wrapper route.
 *
 * @param {number}               flowId     - Flow ID
 * @param {string}               flowStepId - Flow step ID
 * @param {string|Array<string>} prompts    - Single prompt string or array of prompts
 * @return {Promise<Object>} Result with added count and queue length
 */
export const addToFlowQueue = async ( flowId, flowStepId, prompts ) => {
	const list = Array.isArray( prompts ) ? prompts : [ prompts ];
	let addedCount = 0;
	let queueLength = 0;
	let lastResult = null;

	for ( const prompt of list ) {
		if ( ! prompt || ! String( prompt ).trim() ) {
			continue;
		}

		const result = await executeAbility( 'queue-add', {
			flow_id: flowId,
			flow_step_id: flowStepId,
			prompt,
		} );
		lastResult = result;

		if ( ! result.success ) {
			if ( 0 === addedCount ) {
				return result;
			}
			continue;
		}

		addedCount += 1;
		queueLength = result.queue_length ?? queueLength;
	}

	if ( 0 === addedCount ) {
		return (
			lastResult ?? {
				success: false,
				data: null,
				message: 'No prompts provided.',
			}
		);
	}

	return {
		success: true,
		data: {
			flow_id: flowId,
			flow_step_id: flowStepId,
			added_count: addedCount,
			queue_length: queueLength,
		},
		message: lastResult?.message || '',
	};
};

/**
 * Clear all prompts from flow queue
 *
 * @param {number} flowId     - Flow ID
 * @param {string} flowStepId - Flow step ID
 * @return {Promise<Object>} Result with cleared count
 */
export const clearFlowQueue = async ( flowId, flowStepId ) => {
	return await executeAbility( 'queue-clear', {
		flow_id: flowId,
		flow_step_id: flowStepId,
	} );
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
	return await executeAbility( 'queue-remove', {
		flow_id: flowId,
		flow_step_id: flowStepId,
		index,
	} );
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
	return await executeAbility( 'queue-update', {
		flow_id: flowId,
		flow_step_id: flowStepId,
		index,
		prompt,
	} );
};

/**
 * Update queue mode for a flow step
 *
 * @param {number} flowId     - Flow ID
 * @param {string} flowStepId - Flow step ID
 * @param {string} mode       - Queue access mode: "drain" | "loop" | "static"
 * @return {Promise<Object>} Result
 */
export const updateFlowQueueMode = async ( flowId, flowStepId, mode ) => {
	return await executeAbility( 'queue-mode', {
		flow_id: flowId,
		flow_step_id: flowStepId,
		mode,
	} );
};
