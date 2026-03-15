/**
 * SystemTasksTab Component
 *
 * Card-based display of registered system tasks with trigger info,
 * run history, enable/disable toggles, and Run Now functionality.
 *
 * @since 0.42.0
 */

import { useState } from '@wordpress/element';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
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

const useRunTask = () => {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: async ( taskType ) => {
			const result = await client.post(
				`/system/tasks/${ taskType }/run`
			);
			if ( ! result.success ) {
				throw new Error( result.message || 'Failed to run task' );
			}
			return result.data;
		},
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: SYSTEM_TASKS_KEY } );
		},
	} );
};

const formatStatus = ( status ) => {
	if ( ! status ) {
		return { label: '\u2014', className: '' };
	}
	if ( status === 'completed' ) {
		return { label: 'Completed', className: 'datamachine-status--success' };
	}
	if ( status.startsWith( 'failed' ) ) {
		const reason = status.replace( /^failed\s*-?\s*/, '' );
		return {
			label: reason ? `Failed: ${ reason }` : 'Failed',
			className: 'datamachine-status--error',
		};
	}
	if ( status === 'completed_no_items' ) {
		return { label: 'No items', className: 'datamachine-status--muted' };
	}
	if ( status === 'processing' ) {
		return { label: 'Running...', className: 'datamachine-status--info' };
	}
	return { label: status, className: '' };
};

const formatDate = ( datetime ) => {
	if ( ! datetime ) {
		return null;
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

const TRIGGER_ICONS = {
	event: '\u26A1',
	cron: '\u23F0',
	manual: '\u270B',
	tool: '\uD83E\uDD16',
};

const TaskCard = ( { task, onToggle, onRun, isToggling, isRunning } ) => {
	const status = formatStatus( task.last_status );
	const hasToggle = Boolean( task.setting_key );
	const lastRunDate = formatDate( task.last_run_at );
	const triggerIcon = TRIGGER_ICONS[ task.trigger_type ] || '';

	return (
		<div className={ `datamachine-task-card ${
			hasToggle && ! task.enabled ? 'is-disabled' : ''
		}` }>
			<div className="datamachine-task-card-header">
				<div className="datamachine-task-card-title">
					<strong>{ task.label }</strong>
					{ hasToggle && (
						<label className="datamachine-task-toggle">
							<input
								type="checkbox"
								checked={ task.enabled }
								onChange={ () =>
									onToggle( task.setting_key, task.enabled )
								}
								disabled={ isToggling }
							/>
							<span>
								{ task.enabled ? 'Enabled' : 'Disabled' }
							</span>
						</label>
					) }
				</div>
				<p className="datamachine-task-card-description">
					{ task.description }
				</p>
			</div>

			<div className="datamachine-task-card-meta">
				<div className="datamachine-task-card-meta-item">
					<span className="datamachine-task-card-meta-label">
						Trigger
					</span>
					<span className="datamachine-task-card-meta-value">
						{ triggerIcon } { task.trigger }
					</span>
				</div>

				<div className="datamachine-task-card-meta-item">
					<span className="datamachine-task-card-meta-label">
						Last run
					</span>
					<span className="datamachine-task-card-meta-value">
						{ lastRunDate ? (
							<>
								{ lastRunDate }
								{ ' ' }
								<span className={ status.className }>
									{ status.label }
								</span>
							</>
						) : (
							<span className="datamachine-status--muted">
								Never
							</span>
						) }
					</span>
				</div>

				<div className="datamachine-task-card-meta-item">
					<span className="datamachine-task-card-meta-label">
						Total runs
					</span>
					<span className="datamachine-task-card-meta-value">
						{ task.run_count || 0 }
					</span>
				</div>
			</div>

			{ task.supports_run && (
				<div className="datamachine-task-card-actions">
					<button
						className="button button-secondary button-small"
						onClick={ () => onRun( task.task_type ) }
						disabled={
							isRunning ||
							( hasToggle && ! task.enabled )
						}
					>
						{ isRunning ? 'Scheduling...' : 'Run Now' }
					</button>
				</div>
			) }
		</div>
	);
};

const SystemTasksTab = () => {
	const { data: tasks, isLoading, error } = useSystemTasks();
	const updateMutation = useUpdateSettings();
	const runMutation = useRunTask();
	const queryClient = useQueryClient();
	const [ runningTask, setRunningTask ] = useState( null );

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

	const handleRun = async ( taskType ) => {
		setRunningTask( taskType );
		try {
			await runMutation.mutateAsync( taskType );
		} catch ( err ) {
			console.error( 'Failed to run task:', err );
		} finally {
			setRunningTask( null );
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
			<p
				className="description"
				style={ { marginTop: '16px', marginBottom: '16px' } }
			>
				Registered task definitions. Each task can run standalone via
				Action Scheduler or as a step in a pipeline flow.
			</p>
			<div className="datamachine-task-cards">
				{ tasks.map( ( task ) => (
					<TaskCard
						key={ task.task_type }
						task={ task }
						onToggle={ handleToggle }
						onRun={ handleRun }
						isToggling={ updateMutation.isPending }
						isRunning={ runningTask === task.task_type }
					/>
				) ) }
			</div>
		</div>
	);
};

export default SystemTasksTab;
