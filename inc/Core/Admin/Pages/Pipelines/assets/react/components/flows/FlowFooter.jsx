/**
 * Flow Footer Component
 *
 * Display scheduling metadata for a flow.
 * Uses pre-formatted display strings from backend (no client-side date parsing).
 */

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

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
 * @param {Object}   props                             - Component props
 * @param {number}   props.flowId                      - Flow ID
 * @param {Object}   props.scheduling                  - Scheduling display data
 * @param {string}   props.scheduling.interval         - Schedule interval
 * @param {string}   props.scheduling.last_run_display - Pre-formatted last run display
 * @param {string}   props.scheduling.last_run_status  - Job status from last run
 * @param {boolean}  props.scheduling.is_running       - Whether a job is currently running
 * @param {string}   props.scheduling.next_run_display - Pre-formatted next run display
 * @param {number}   props.queueCount                  - Number of prompts in queue
 * @param {Function} props.onQueueClick                - Handler for queue button click
 * @return {React.ReactElement} Flow footer
 */
export default function FlowFooter( {
	flowId,
	scheduling,
	queueCount = 0,
	onQueueClick,
} ) {
	const {
		interval,
		last_run_display,
		last_run_status,
		is_running,
		next_run_display,
	} = scheduling || {};

	const scheduleDisplay =
		interval && interval !== 'manual'
			? interval
			: __( 'Manual', 'data-machine' );

	// When running, show "Running" status; otherwise format the job status
	const displayStatus = is_running
		? __( 'Running', 'data-machine' )
		: formatStatus( last_run_status );

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
				{ last_run_display || __( 'Never', 'data-machine' ) }
				{ displayStatus && (
					<span
						className={ getStatusClass(
							last_run_status,
							is_running
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
					{ next_run_display || __( 'Never', 'data-machine' ) }
				</div>
			) }

			{ onQueueClick && (
				<div className="datamachine-flow-meta-item datamachine-flow-meta-item--queue">
					<Button
						variant="link"
						onClick={ onQueueClick }
						className="datamachine-queue-badge"
					>
						{ __( 'Queue', 'data-machine' ) }{ ' ' }
						<span
							className={ `datamachine-queue-count ${
								queueCount > 0
									? 'datamachine-queue-count--active'
									: ''
							}` }
						>
							({ queueCount })
						</span>
					</Button>
				</div>
			) }
		</div>
	);
}
