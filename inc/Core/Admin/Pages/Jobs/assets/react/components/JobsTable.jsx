/**
 * JobsTable Component
 *
 * Displays the jobs list with batch parent/child hierarchy.
 * Parent jobs show a batch progress badge and expand to reveal child jobs.
 * Child jobs are hidden from the top-level list and lazy-loaded on expand.
 *
 * @since 0.44.2
 */

/**
 * WordPress dependencies
 */
import { useState, useCallback } from '@wordpress/element';
import { Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useChildJobs } from '../queries/jobs';

const getStatusClass = ( status ) => {
	if ( ! status ) {
		return 'datamachine-status--neutral';
	}
	const baseStatus = status.split( ' - ' )[ 0 ];
	if ( baseStatus === 'failed' ) {
		return 'datamachine-status--error';
	}
	if ( baseStatus === 'completed' ) {
		return 'datamachine-status--success';
	}
	return 'datamachine-status--neutral';
};

const formatStatus = ( status ) => {
	if ( ! status ) {
		return __( 'Unknown', 'data-machine' );
	}
	return (
		status.charAt( 0 ).toUpperCase() +
		status.slice( 1 ).replace( /_/g, ' ' )
	);
};

/**
 * Determine if a job has child jobs (batch parent or any parent).
 * Uses child_count from the DB query, falls back to engine_data batch flag.
 */
const hasChildren = ( job ) => {
	// Prefer the child_count from the SQL subquery (most reliable).
	if ( job.child_count !== undefined && parseInt( job.child_count, 10 ) > 0 ) {
		return true;
	}
	// Fallback: check engine_data for batch flag.
	if ( ! job.engine_data ) {
		return false;
	}
	const ed =
		typeof job.engine_data === 'string'
			? JSON.parse( job.engine_data )
			: job.engine_data;
	return !! ed.batch;
};

/**
 * Extract batch results from a parent job's engine_data.
 */
const getBatchResults = ( job ) => {
	if ( ! job.engine_data ) {
		return null;
	}
	const ed =
		typeof job.engine_data === 'string'
			? JSON.parse( job.engine_data )
			: job.engine_data;
	return ed.batch_results || null;
};

/**
 * Extract batch total (scheduled count) from engine_data.
 */
const getBatchTotal = ( job ) => {
	if ( ! job.engine_data ) {
		return 0;
	}
	const ed =
		typeof job.engine_data === 'string'
			? JSON.parse( job.engine_data )
			: job.engine_data;
	return ed.batch_total || ed.batch_scheduled || 0;
};

/**
 * Batch progress badge for parent jobs.
 */
const BatchBadge = ( { job } ) => {
	const results = getBatchResults( job );
	const total = getBatchTotal( job );

	if ( ! total ) {
		return (
			<span className="datamachine-batch-badge">
				{ __( 'Batch', 'data-machine' ) }
			</span>
		);
	}

	if ( results ) {
		const parts = [];
		if ( results.completed > 0 ) {
			parts.push(
				`${ results.completed }/${ results.total } ${ __(
					'completed',
					'data-machine'
				) }`
			);
		}
		if ( results.failed > 0 ) {
			parts.push(
				`${ results.failed } ${ __( 'failed', 'data-machine' ) }`
			);
		}
		if ( results.skipped > 0 ) {
			parts.push(
				`${ results.skipped } ${ __( 'skipped', 'data-machine' ) }`
			);
		}

		const hasFailures = results.failed > 0;

		return (
			<span
				className={ `datamachine-batch-badge ${
					hasFailures
						? 'datamachine-batch-badge--warning'
						: 'datamachine-batch-badge--complete'
				}` }
			>
				{ parts.join( ', ' ) }
			</span>
		);
	}

	// Batch in progress — no results yet.
	return (
		<span className="datamachine-batch-badge datamachine-batch-badge--progress">
			{ `${ __( 'Batch:', 'data-machine' ) } ${ total } ${ __(
				'items',
				'data-machine'
			) }` }
		</span>
	);
};

/**
 * Expandable child rows for a batch parent.
 */
const ChildRows = ( { parentJobId } ) => {
	const { data: children, isLoading, isError } = useChildJobs( parentJobId );

	if ( isLoading ) {
		return (
			<tr className="datamachine-child-row datamachine-child-row--loading">
				<td colSpan="5">
					<div className="datamachine-child-loading">
						<Spinner />
						<span>
							{ __( 'Loading child jobs\u2026', 'data-machine' ) }
						</span>
					</div>
				</td>
			</tr>
		);
	}

	if ( isError || ! children || children.length === 0 ) {
		return (
			<tr className="datamachine-child-row">
				<td colSpan="5" className="datamachine-child-empty">
					{ __( 'No child jobs found.', 'data-machine' ) }
				</td>
			</tr>
		);
	}

	return children.map( ( child ) => (
		<tr key={ child.job_id } className="datamachine-child-row">
			<td className="datamachine-child-id">
				<span className="datamachine-child-indicator">
					{ '\u21b3' }
				</span>
				{ child.job_id }
			</td>
			<td>
				{ child.display_label ||
					child.label ||
					__( 'Child job', 'data-machine' ) }
			</td>
			<td>
				<span className={ getStatusClass( child.status ) }>
					{ formatStatus( child.status ) }
				</span>
			</td>
			<td>{ child.created_at_display || '' }</td>
			<td>{ child.completed_at_display || '' }</td>
		</tr>
	) );
};

/**
 * Single job row — handles expand/collapse for batch parents.
 */
const JobRow = ( { job, isExpanded, onToggle } ) => {
	const isBatch = hasChildren( job );

	return (
		<>
			<tr
				className={ `${
					isBatch ? 'datamachine-batch-parent' : ''
				} ${ isExpanded ? 'is-expanded' : '' }` }
				onClick={ isBatch ? onToggle : undefined }
				style={ isBatch ? { cursor: 'pointer' } : undefined }
			>
				<td>
					<strong>
						{ isBatch && (
							<span
								className="datamachine-batch-arrow"
								aria-hidden="true"
							>
								{ isExpanded ? '\u25bc' : '\u25b6' }
							</span>
						) }
						{ job.job_id }
					</strong>
				</td>
				<td>
					{ job.display_label ||
						job.label ||
						( job.pipeline_name && job.flow_name
							? `${ job.pipeline_name } \u2192 ${ job.flow_name }`
							: __( 'Unknown', 'data-machine' ) ) }
					{ isBatch && <BatchBadge job={ job } /> }
				</td>
				<td>
					<span className={ getStatusClass( job.status ) }>
						{ formatStatus( job.status ) }
					</span>
				</td>
				<td>{ job.created_at_display || '' }</td>
				<td>{ job.completed_at_display || '' }</td>
			</tr>
			{ isExpanded && <ChildRows parentJobId={ job.job_id } /> }
		</>
	);
};

const JobsTable = ( { jobs, isLoading, isError, error } ) => {
	const [ expandedJobs, setExpandedJobs ] = useState( {} );

	const toggleExpand = useCallback( ( jobId ) => {
		setExpandedJobs( ( prev ) => ( {
			...prev,
			[ jobId ]: ! prev[ jobId ],
		} ) );
	}, [] );

	if ( isLoading ) {
		return (
			<div className="datamachine-jobs-loading">
				<Spinner />
				<span>{ __( 'Loading jobs\u2026', 'data-machine' ) }</span>
			</div>
		);
	}

	if ( isError ) {
		return (
			<div className="datamachine-jobs-error">
				{ error?.message ||
					__( 'Failed to load jobs.', 'data-machine' ) }
			</div>
		);
	}

	if ( ! jobs || jobs.length === 0 ) {
		return (
			<div className="datamachine-jobs-empty-state">
				<p className="datamachine-jobs-empty-message">
					{ __(
						'No jobs found. Jobs will appear here when Data Machine processes data.',
						'data-machine'
					) }
				</p>
			</div>
		);
	}

	return (
		<div className="datamachine-jobs-table-container">
			<table className="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th className="datamachine-col-job-id">
							{ __( 'Job ID', 'data-machine' ) }
						</th>
						<th>{ __( 'Source', 'data-machine' ) }</th>
						<th className="datamachine-col-status">
							{ __( 'Status', 'data-machine' ) }
						</th>
						<th className="datamachine-col-created">
							{ __( 'Created At', 'data-machine' ) }
						</th>
						<th className="datamachine-col-completed">
							{ __( 'Completed At', 'data-machine' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ jobs.map( ( job ) => (
						<JobRow
							key={ job.job_id }
							job={ job }
							isExpanded={ !! expandedJobs[ job.job_id ] }
							onToggle={ () => toggleExpand( job.job_id ) }
						/>
					) ) }
				</tbody>
			</table>
		</div>
	);
};

export default JobsTable;
