/**
 * Flow step handler shape helpers.
 *
 * The server stores and returns handler-backed flow steps in one canonical
 * shape for both single- and multi-handler step types:
 * `handler_slugs` (array) + `handler_configs` (keyed by slug). Non-handler
 * steps (AI, system tasks) carry a flat `handler_config`. The legacy
 * `handler_slug` key is read only as a fallback.
 */

/**
 * Get the handler slugs configured on a flow step.
 *
 * @param {Object}  flowStepConfig Flow step configuration.
 * @param {boolean} isMultiHandler Whether the step type allows multiple handlers.
 * @return {string[]} Handler slugs (at most one for single-handler steps).
 */
export const getFlowStepHandlerSlugs = ( flowStepConfig, isMultiHandler ) => {
	let slugs = [];
	if (
		Array.isArray( flowStepConfig?.handler_slugs ) &&
		flowStepConfig.handler_slugs.length
	) {
		slugs = flowStepConfig.handler_slugs.filter( Boolean );
	} else if ( flowStepConfig?.handler_slug ) {
		slugs = [ flowStepConfig.handler_slug ];
	}

	return isMultiHandler ? slugs : slugs.slice( 0, 1 );
};

/**
 * Get the saved settings for one handler on a flow step.
 *
 * @param {Object} flowStepConfig Flow step configuration.
 * @param {string} handlerSlug    Handler slug.
 * @return {Object} Handler settings.
 */
export const getFlowStepHandlerConfig = ( flowStepConfig, handlerSlug ) =>
	( handlerSlug && flowStepConfig?.handler_configs?.[ handlerSlug ] ) ||
	flowStepConfig?.handler_config ||
	{};

export default { getFlowStepHandlerSlugs, getFlowStepHandlerConfig };
