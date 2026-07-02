/**
 * SystemTasksTab Component
 *
 * Card-based display of registered system tasks with trigger info,
 * run history, enable/disable toggles, Run Now, and editable AI prompts.
 *
 * @since 0.42.0
 */

import { useState, useMemo } from '@wordpress/element';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { client } from '@shared/utils/api';
import { useUpdateSettings } from '@shared/queries/settings';
import PromptField from '@shared/components/PromptField';
import {
	useSystemTaskPrompts,
	useSavePrompt,
	useResetPrompt,
} from '../queries/systemTaskPrompts';

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



/**
 * PromptEditor — expandable section for a single prompt definition.
 */
const PromptEditor = ( { prompt, onSave, onReset } ) => {
	const [ isResetting, setIsResetting ] = useState( false );
	const effectiveValue = prompt.has_override
		? prompt.override
		: prompt.default;

	const handleSave = async ( newValue ) => {
		return await onSave( prompt.task_type, prompt.prompt_key, newValue );
	};

	const handleReset = async () => {
		setIsResetting( true );
		try {
			await onReset( prompt.task_type, prompt.prompt_key );
		} finally {
			setIsResetting( false );
		}
	};

	const variableKeys = prompt.variables
		? Object.keys( prompt.variables )
		: [];

	return (
		<div className="datamachine-prompt-editor">
			<PromptField
				value={ effectiveValue }
				onSave={ handleSave }
				label={ prompt.label }
				placeholder={ prompt.default }
				rows={ 8 }
				help={ prompt.description }
			/>

			{ variableKeys.length > 0 && (
				<div className="datamachine-prompt-variables">
					<span className="datamachine-prompt-variables-label">
						Variables:
					</span>
					{ variableKeys.map( ( key ) => (
						<code
							key={ key }
							className="datamachine-prompt-variable"
							title={ prompt.variables[ key ] }
						>
							{ `{{${ key }}}` }
						</code>
					) ) }
				</div>
			) }

			{ prompt.has_override && (
				<button
					className="button button-link datamachine-prompt-reset"
					onClick={ handleReset }
					disabled={ isResetting }
				>
					{ isResetting ? 'Resetting...' : 'Reset to default' }
				</button>
			) }
		</div>
	);
};

const TaskCard = ( {
	task,
	prompts,
	onToggle,
	onRun,
	onSavePrompt,
	onResetPrompt,
	isToggling,
	isRunning,
} ) => {
	const [ showPrompts, setShowPrompts ] = useState( false );
	const status = formatStatus( task.last_status );
	const hasToggle = Boolean( task.setting_key );
	const lastRunDate = formatDate( task.last_run_at );
	const hasPrompts = prompts.length > 0;

	return (
		<div
			className={ `datamachine-task-card ${
				hasToggle && ! task.enabled ? 'is-disabled' : ''
			}` }
		>
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
						{ task.trigger }
					</span>
				</div>

				<div className="datamachine-task-card-meta-item">
					<span className="datamachine-task-card-meta-label">
						Last run
					</span>
					<span className="datamachine-task-card-meta-value">
						{ lastRunDate ? (
							<>
								{ lastRunDate }{ ' ' }
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

			<div className="datamachine-task-card-actions">
				{ task.supports_run && (
					<button
						className="button button-secondary button-small"
						onClick={ () => onRun( task.task_type ) }
						disabled={
							isRunning || ( hasToggle && ! task.enabled )
						}
					>
						{ isRunning ? 'Scheduling...' : 'Run Now' }
					</button>
				) }

				{ hasPrompts && (
					<button
						className="button button-secondary button-small"
						onClick={ () => setShowPrompts( ! showPrompts ) }
					>
						{ showPrompts ? 'Hide Prompt' : 'Edit Prompt' }
					</button>
				) }
			</div>

			{ showPrompts && hasPrompts && (
				<div className="datamachine-task-prompts">
					{ prompts.map( ( prompt ) => (
						<PromptEditor
							key={ `${ prompt.task_type }-${ prompt.prompt_key }` }
							prompt={ prompt }
							onSave={ onSavePrompt }
							onReset={ onResetPrompt }
						/>
					) ) }
				</div>
			) }
		</div>
	);
};

const SystemTasksTab = () => {
	const { data: tasks, isLoading, error } = useSystemTasks();
	const {
		data: allPrompts,
		isLoading: promptsLoading,
	} = useSystemTaskPrompts();
	const updateMutation = useUpdateSettings();
	const runMutation = useRunTask();
	const saveMutation = useSavePrompt();
	const resetMutation = useResetPrompt();
	const queryClient = useQueryClient();
	const [ runningTask, setRunningTask ] = useState( null );

	// Group prompts by task_type for efficient lookup.
	const promptsByTask = useMemo( () => {
		if ( ! allPrompts ) {
			return {};
		}
		const grouped = {};
		for ( const prompt of allPrompts ) {
			if ( ! grouped[ prompt.task_type ] ) {
				grouped[ prompt.task_type ] = [];
			}
			grouped[ prompt.task_type ].push( prompt );
		}
		return grouped;
	}, [ allPrompts ] );

	const handleToggle = async ( settingKey, currentValue ) => {
		try {
			await updateMutation.mutateAsync( {
				[ settingKey ]: ! currentValue,
			} );
			queryClient.invalidateQueries( { queryKey: SYSTEM_TASKS_KEY } );
		} catch ( err ) {
			// eslint-disable-next-line no-console
			console.error( 'Failed to toggle system task:', err );
		}
	};

	const handleRun = async ( taskType ) => {
		setRunningTask( taskType );
		try {
			await runMutation.mutateAsync( taskType );
		} catch ( err ) {
			// eslint-disable-next-line no-console
			console.error( 'Failed to run task:', err );
		} finally {
			setRunningTask( null );
		}
	};

	const handleSavePrompt = async ( taskType, promptKey, prompt ) => {
		try {
			await saveMutation.mutateAsync( {
				taskType,
				promptKey,
				prompt,
			} );
			return { success: true };
		} catch ( err ) {
			return { success: false, message: err.message };
		}
	};

	const handleResetPrompt = async ( taskType, promptKey ) => {
		await resetMutation.mutateAsync( { taskType, promptKey } );
	};

	if ( isLoading || promptsLoading ) {
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
				Registered task definitions. Each task can run via Action
				Scheduler or as a step in a pipeline flow. Tasks with AI
				prompts can be customized below.
			</p>
			<div className="datamachine-task-cards">
				{ tasks.map( ( task ) => (
					<TaskCard
						key={ task.task_type }
						task={ task }
						prompts={ promptsByTask[ task.task_type ] || [] }
						onToggle={ handleToggle }
						onRun={ handleRun }
						onSavePrompt={ handleSavePrompt }
						onResetPrompt={ handleResetPrompt }
						isToggling={ updateMutation.isPending }
						isRunning={ runningTask === task.task_type }
					/>
				) ) }
			</div>
		</div>
	);
};

export default SystemTasksTab;
