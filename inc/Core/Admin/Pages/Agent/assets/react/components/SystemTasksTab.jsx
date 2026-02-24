/**
 * SystemTasksTab Component
 *
 * Displays registered system agent tasks in a table with
 * enable/disable toggles, last run info, and result status.
 *
 * @since 0.32.0
 * @see https://github.com/Extra-Chill/data-machine/issues/357
 */

import { useQuery, useQueryClient } from '@tanstack/react-query';
import { client } from '@shared/utils/api';
import { useUpdateSettings } from '@shared/queries/settings';

const SYSTEM_TASKS_KEY = [ 'system-tasks' ];

const useSystemTasks = () =>
	useQuery( {
		queryKey: SYSTEM_TASKS_KEY,
		queryFn: async () => {
			const result = await client.get( '/system/tasks' );
			if ( ! result.success ) {
				throw new Error(
					result.message || 'Failed to fetch system tasks'
				);
			}
			return result.data;
		},
		staleTime: 30 * 1000,
	} );

/**
 * Format a status string for display.
 *
 * @param {string|null} status Raw status from jobs table.
 * @return {Object} { label, className }
 */
const formatStatus = ( status ) => {
	if ( ! status ) {
		return { label: '\u2014', className: '' };
	}

	if ( status === 'completed' ) {
		return { label: 'Completed', className: 'datamachine-status-success' };
	}

	if ( status.startsWith( 'failed' ) ) {
		const reason = status.replace( /^failed\s*-?\s*/, '' );
		return {
			label: reason ? `Failed: ${ reason }` : 'Failed',
			className: 'datamachine-status-error',
		};
	}

	if ( status === 'completed_no_items' ) {
		return {
			label: 'No items',
			className: 'datamachine-status-muted',
		};
	}

	return { label: status, className: '' };
};

/**
 * Format a datetime string for display.
 *
 * @param {string|null} datetime MySQL datetime string.
 * @return {string}
 */
const formatDate = ( datetime ) => {
	if ( ! datetime ) {
		return '\u2014';
	}

	try {
		const date = new Date( datetime + 'Z' );
		return date.toLocaleString( undefined, {
			month: 'short',
			day: 'numeric',
			hour: '2-digit',
			minute: '2-digit',
		} );
	} catch {
		return datetime;
	}
};

const SystemTasksTab = () => {
	const { data: tasks, isLoading, error } = useSystemTasks();
	const updateMutation = useUpdateSettings();
	const queryClient = useQueryClient();

	const handleToggle = async ( settingKey, currentValue ) => {
		try {
			await updateMutation.mutateAsync( {
				[ settingKey ]: ! currentValue,
			} );
			queryClient.invalidateQueries( { queryKey: SYSTEM_TASKS_KEY } );
		} catch ( err ) {
			console.error( 'Failed to toggle system task:', err );
		}
	};

	if ( isLoading ) {
		return (
			<div className="datamachine-system-tasks-loading">
				<span className="spinner is-active"></span>
				<span>Loading system tasks...</span>
			</div>
		);
	}

	if ( error ) {
		return (
			<div className="notice notice-error">
				<p>Error loading system tasks: { error.message }</p>
			</div>
		);
	}

	if ( ! tasks || tasks.length === 0 ) {
		return (
			<div className="datamachine-system-tasks-empty">
				<p>No system tasks registered.</p>
			</div>
		);
	}

	return (
		<div className="datamachine-system-tasks">
			<h2 className="datamachine-system-tasks-title">System Tasks</h2>
			<p className="description" style={ { marginTop: 0, marginBottom: '16px' } }>
				Built-in background tasks that run automatically. Tasks with a toggle
				can be enabled or disabled. Others run on demand via abilities or tools.
			</p>
			<table className="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col" style={ { width: '20%' } }>Task</th>
						<th scope="col" style={ { width: '35%' } }>Description</th>
						<th scope="col" style={ { width: '12%' } }>Status</th>
						<th scope="col" style={ { width: '18%' } }>Last Run</th>
						<th scope="col" style={ { width: '15%' } }>Result</th>
					</tr>
				</thead>
				<tbody>
					{ tasks.map( ( task ) => {
						const status = formatStatus( task.last_status );
						const hasToggle = Boolean( task.setting_key );

						return (
							<tr key={ task.task_type }>
								<td>
									<strong>{ task.label }</strong>
								</td>
								<td>
									<span className="description">
										{ task.description }
									</span>
								</td>
								<td>
									{ hasToggle ? (
										<label className="datamachine-system-task-toggle">
											<input
												type="checkbox"
												checked={ task.enabled }
												onChange={ () =>
													handleToggle(
														task.setting_key,
														task.enabled
													)
												}
												disabled={
													updateMutation.isPending
												}
											/>
											{ task.enabled
												? 'Enabled'
												: 'Disabled' }
										</label>
									) : (
										<span className="datamachine-status-muted">
											Always on
										</span>
									) }
								</td>
								<td>
									{ formatDate( task.last_run_at ) }
								</td>
								<td>
									<span className={ status.className }>
										{ status.label }
									</span>
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>
		</div>
	);
};

export default SystemTasksTab;
