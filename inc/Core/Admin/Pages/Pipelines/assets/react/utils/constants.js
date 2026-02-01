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
	CONFIRM_DELETE: 'confirm-delete',
	CONTEXT_FILES: 'context-files',
};

/**
 * Handler Types (matches step types)
 */
export const HANDLER_TYPES = {
	FETCH: 'fetch',
	PUBLISH: 'publish',
	UPDATE: 'update',
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
 * Default Pipeline/Flow Names
 */
export const DEFAULT_PIPELINE_NAME = 'New Pipeline';
export const DEFAULT_FLOW_NAME = 'New Flow';

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

/**
 * CSS Class Prefixes
 */
export const CLASS_PREFIX = 'datamachine-pipelines';

/**
 * Status Colors
 */
export const STATUS_COLORS = {
	SUCCESS: '#46b450',
	ERROR: '#dc3232',
	WARNING: '#f0b849',
	INFO: '#0073aa',
};
