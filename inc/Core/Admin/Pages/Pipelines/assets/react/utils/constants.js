/**
 * Constants for Data Machine React Application
 *
 * Centralized constant definitions for intervals and other shared values.
 */

/**
 * Modal Types
 */
export const MODAL_TYPES = {
	STEP_SELECTION: 'step-selection',
	HANDLER_SELECTION: 'handler-selection',
	CONFIGURE_STEP: 'configure-step',
	HANDLER_SETTINGS: 'handler-settings',
	FLOW_SCHEDULE: 'flow-schedule',
	FLOW_QUEUE: 'flow-queue',
	IMPORT_EXPORT: 'import-export',
	OAUTH: 'oauth',
	CONTEXT_FILES: 'context-files',
	MEMORY_FILES: 'memory-files',
	FLOW_MEMORY_FILES: 'flow-memory-files',
};

/**
 * Auto-save Debounce Delay (milliseconds)
 */
export const AUTO_SAVE_DELAY = 500;

/**
 * API Request Timeout (milliseconds)
 */
export const API_TIMEOUT = 30000;

/**
 * Validation Constants
 */
export const VALIDATION = {
	MIN_PIPELINE_NAME_LENGTH: 1,
	MAX_PIPELINE_NAME_LENGTH: 255,
	MIN_FLOW_NAME_LENGTH: 1,
	MAX_FLOW_NAME_LENGTH: 255,
	MAX_PROMPT_LENGTH: 10000,
	MAX_USER_MESSAGE_LENGTH: 5000,
};
