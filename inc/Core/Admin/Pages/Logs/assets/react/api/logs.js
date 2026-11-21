/**
 * Logs API Operations
 *
 * Ability-backed log operations, executed through WordPress core's
 * ability runner (see #3456).
 */

/**
 * External dependencies
 */
import { executeAbility } from '@shared/utils/api';

/**
 * Fetch log entries with filters and pagination.
 * @param {Object} params Filter parameters (agent_id, level, since, before, job_id, flow_id, pipeline_id, search, per_page, page).
 * @return {Promise<Object>} Paginated log entries.
 */
export const fetchLogs = ( params = {} ) =>
	executeAbility( 'read-logs', params );

/**
 * Fetch log metadata (counts, time range, level distribution).
 * @param {Object} params Optional { agent_id }.
 * @return {Promise<Object>} Log metadata.
 */
export const fetchLogMetadata = ( params = {} ) =>
	executeAbility( 'get-log-metadata', params );

/**
 * Clear log entries.
 * @param {Object} params Optional { agent_id }.
 * @return {Promise<Object>} Clear operation result.
 */
export const clearLogs = ( params = {} ) =>
	executeAbility( 'clear-logs', params );
