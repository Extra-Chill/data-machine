/**
 * Logs API Operations
 *
 * REST API calls for database-backed log operations.
 */

/* eslint-disable jsdoc/check-line-alignment */

/**
 * External dependencies
 */
import { client } from '@shared/utils/api';

/**
 * Fetch log entries with filters and pagination.
 * @param {Object} params Filter parameters (agent_id, level, since, before, job_id, flow_id, pipeline_id, search, per_page, page).
 * @return {Promise<Object>} Paginated log entries.
 */
export const fetchLogs = ( params = {} ) => client.get( '/logs', params );

/**
 * Fetch log metadata (counts, time range, level distribution).
 * @param {Object} params Optional { agent_id }.
 * @return {Promise<Object>} Log metadata.
 */
export const fetchLogMetadata = ( params = {} ) =>
	client.get( '/logs/metadata', params );

/**
 * Clear log entries.
 * @param {Object} params Optional { agent_id }.
 * @return {Promise<Object>} Clear operation result.
 */
export const clearLogs = ( params = {} ) => client.delete( '/logs', params );
