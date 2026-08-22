/**
 * Flow Footer Component
 *
 * Display scheduling metadata for a flow.
 * Uses pre-formatted display strings from backend (no client-side date parsing).
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Get CSS class for job status.
 *
 * @param {string|null} status    - Job status string (may be compound like "agent_skipped - reason")
 * @param {boolean}     isRunning - Whether the job is currently running
 * @return {string} CSS class name
 */
const getStatusClass = ( status, isRunning = false ) => {
	if ( isRunning ) {
		return 'datamachine-status--running';
	}
	if ( ! status ) {
		return '';
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

/**
 * Format status for display.
 *
 * @param {string|null} status - Job status string
 * @return {string|null} Formatted status or null
 */
const formatStatus = ( status ) => {
	if ( ! status ) {
		return null;
	}
	return (
		status.charAt( 0 ).toUpperCase() +
		status.slice( 1 ).replace( /_/g, ' ' )
	);
};

/**
 * Flow Footer Component
 *
 * @param {Object} props            - Component props
 * @param {number} props.flowId     - Flow ID
 * @param {Object} props.scheduling - Scheduling display data
 * @return {React.ReactElement} Flow footer
 */
export default function FlowFooter( { flowId, scheduling } ) {
	const interval = scheduling?.interval;
	const scheduledTime = scheduling?.scheduled_time;
	const lastRunDisplay = scheduling?.last_run_display;
	const lastRunStatus = scheduling?.last_run_status;
	const isRunning = scheduling?.is_running;
	const nextRunDisplay = scheduling?.next_run_display;

	let scheduleDisplay;
	if ( interval === 'one_time' && scheduledTime ) {
		scheduleDisplay = sprintf(
			/* translators: %s: Scheduled date and time. */
			__( 'One Time: %s', 'data-machine' ),
			new Date( scheduledTime ).toLocaleString()
		);
	} else if ( interval && interval !== 'manual' ) {
		scheduleDisplay = interval;
	} else {
		scheduleDisplay = __( 'Manual', 'data-machine' );
	}

	// When running, show "Running" status; otherwise format the job status
	const displayStatus = isRunning
		? __( 'Running', 'data-machine' )
		: formatStatus( lastRunStatus );

	return (
		<div className="datamachine-flow-footer">
			<div className="datamachine-flow-meta-item datamachine-flow-meta-item--id">
				<strong>{ __( 'Flow ID:', 'data-machine' ) }</strong> #
				{ flowId }
			</div>

			<div className="datamachine-flow-meta-item">
				<strong>{ __( 'Schedule:', 'data-machine' ) }</strong>{ ' ' }
				{ scheduleDisplay }
			</div>

			<div className="datamachine-flow-meta-item">
				<strong>{ __( 'Last Run:', 'data-machine' ) }</strong>{ ' ' }
				{ lastRunDisplay || __( 'Never', 'data-machine' ) }
				{ displayStatus && (
					<span
						className={ getStatusClass(
							lastRunStatus,
							isRunning
						) }
					>
						{ ' ' }
						({ displayStatus })
					</span>
				) }
			</div>

			{ interval && interval !== 'manual' && (
				<div className="datamachine-flow-meta-item">
					<strong>{ __( 'Next Run:', 'data-machine' ) }</strong>{ ' ' }
					{ nextRunDisplay || __( 'Never', 'data-machine' ) }
				</div>
			) }
		</div>
	);
}
