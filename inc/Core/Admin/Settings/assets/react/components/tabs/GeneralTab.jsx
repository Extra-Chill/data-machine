/**
 * GeneralTab Component
 *
 * General settings including enabled admin pages, cleanup options, and file retention.
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect } from '@wordpress/element';
/**
 * Internal dependencies
 */
import { useSettings, useUpdateSettings } from '../../queries/settings';

const GeneralTab = () => {
	const { data, isLoading, error } = useSettings();
	const updateMutation = useUpdateSettings();

	const [ formState, setFormState ] = useState( {
		cleanup_job_data_on_failure: true,
		file_retention_days: 7,
		chat_retention_days: 90,
		chat_ai_titles_enabled: true,
		alt_text_auto_generate_enabled: true,
		flows_per_page: 20,
		jobs_per_page: 50,
		queue_tuning: {
			concurrent_batches: 3,
			batch_size: 25,
			time_limit: 60,
		},
	} );
	const [ hasChanges, setHasChanges ] = useState( false );
	const [ saveStatus, setSaveStatus ] = useState( null );

	useEffect( () => {
		if ( data?.settings ) {
			setFormState( {
				cleanup_job_data_on_failure:
					data.settings.cleanup_job_data_on_failure ?? true,
				file_retention_days: data.settings.file_retention_days ?? 7,
				chat_retention_days: data.settings.chat_retention_days ?? 90,
				chat_ai_titles_enabled:
					data.settings.chat_ai_titles_enabled ?? true,
				alt_text_auto_generate_enabled:
					data.settings.alt_text_auto_generate_enabled ?? true,
				flows_per_page: data.settings.flows_per_page ?? 20,
				jobs_per_page: data.settings.jobs_per_page ?? 50,
				queue_tuning: data.settings.queue_tuning ?? {
					concurrent_batches: 3,
					batch_size: 25,
					time_limit: 60,
				},
			} );
			setHasChanges( false );
		}
	}, [ data ] );

	const handleCleanupToggle = ( enabled ) => {
		setFormState( ( prev ) => ( {
			...prev,
			cleanup_job_data_on_failure: enabled,
		} ) );
		setHasChanges( true );
	};

	const handleRetentionChange = ( days ) => {
		const value = Math.max( 1, Math.min( 90, parseInt( days, 10 ) || 1 ) );
		setFormState( ( prev ) => ( {
			...prev,
			file_retention_days: value,
		} ) );
		setHasChanges( true );
	};

	const handleChatRetentionChange = ( days ) => {
		const value = Math.max(
			1,
			Math.min( 365, parseInt( days, 10 ) || 90 )
		);
		setFormState( ( prev ) => ( {
			...prev,
			chat_retention_days: value,
		} ) );
		setHasChanges( true );
	};

	const handleChatAiTitlesToggle = ( enabled ) => {
		setFormState( ( prev ) => ( {
			...prev,
			chat_ai_titles_enabled: enabled,
		} ) );
		setHasChanges( true );
	};

	const handleAltTextAutoGenerateToggle = ( enabled ) => {
		setFormState( ( prev ) => ( {
			...prev,
			alt_text_auto_generate_enabled: enabled,
		} ) );
		setHasChanges( true );
	};

	const handleFlowsPerPageChange = ( count ) => {
		const value = Math.max(
			5,
			Math.min( 100, parseInt( count, 10 ) || 20 )
		);
		setFormState( ( prev ) => ( {
			...prev,
			flows_per_page: value,
		} ) );
		setHasChanges( true );
	};

	const handleJobsPerPageChange = ( count ) => {
		const value = Math.max(
			5,
			Math.min( 100, parseInt( count, 10 ) || 50 )
		);
		setFormState( ( prev ) => ( {
			...prev,
			jobs_per_page: value,
		} ) );
		setHasChanges( true );
	};

	const handleQueueTuningChange = ( key, rawValue ) => {
		const limits = {
			concurrent_batches: { min: 1, max: 10, default: 3 },
			batch_size: { min: 10, max: 200, default: 25 },
			time_limit: { min: 15, max: 300, default: 60 },
		};
		const { min, max, default: defaultVal } = limits[ key ];
		const value = Math.max(
			min,
			Math.min( max, parseInt( rawValue, 10 ) || defaultVal )
		);
		setFormState( ( prev ) => ( {
			...prev,
			queue_tuning: {
				...prev.queue_tuning,
				[ key ]: value,
			},
		} ) );
		setHasChanges( true );
	};

	const handleSave = async () => {
		setSaveStatus( 'saving' );
		try {
			await updateMutation.mutateAsync( formState );
			setSaveStatus( 'saved' );
			setHasChanges( false );
			setTimeout( () => setSaveStatus( null ), 2000 );
		} catch ( err ) {
			setSaveStatus( 'error' );
			setTimeout( () => setSaveStatus( null ), 3000 );
		}
	};

	if ( isLoading ) {
		return (
			<div className="datamachine-general-tab-loading">
				<span className="spinner is-active"></span>
				<span>Loading settings...</span>
			</div>
		);
	}

	if ( error ) {
		return (
			<div className="notice notice-error">
				<p>Error loading settings: { error.message }</p>
			</div>
		);
	}

	return (
		<div className="datamachine-general-tab">
			<table className="form-table">
				<tbody>
					<tr>
						<th scope="row">Clean up job data on failure</th>
						<td>
							<fieldset>
								<label htmlFor="cleanup_job_data_on_failure">
									<input
										type="checkbox"
										id="cleanup_job_data_on_failure"
										checked={
											formState.cleanup_job_data_on_failure
										}
										onChange={ ( e ) =>
											handleCleanupToggle(
												e.target.checked
											)
										}
									/>
									Remove job data files when jobs fail
								</label>
								<p className="description">
									Disable to preserve failed job data files
									for debugging purposes. Processed items in
									database are always cleaned up to allow
									retry.
								</p>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row">File retention (days)</th>
						<td>
							<fieldset>
								<input
									type="number"
									id="file_retention_days"
									value={ formState.file_retention_days }
									onChange={ ( e ) =>
										handleRetentionChange( e.target.value )
									}
									min="1"
									max="90"
									className="small-text"
								/>
								<p className="description">
									Automatically delete repository files older
									than this many days. Includes Reddit images,
									Files handler uploads, and other temporary
									workflow files.
								</p>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row">Chat session retention (days)</th>
						<td>
							<fieldset>
								<input
									type="number"
									id="chat_retention_days"
									value={ formState.chat_retention_days }
									onChange={ ( e ) =>
										handleChatRetentionChange(
											e.target.value
										)
									}
									min="1"
									max="365"
									className="small-text"
								/>
								<p className="description">
									Automatically delete chat sessions with no
									activity older than this many days.
								</p>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row">AI-generated chat titles</th>
						<td>
							<fieldset>
								<label htmlFor="chat_ai_titles_enabled">
									<input
										type="checkbox"
										id="chat_ai_titles_enabled"
										checked={
											formState.chat_ai_titles_enabled
										}
										onChange={ ( e ) =>
											handleChatAiTitlesToggle(
												e.target.checked
											)
										}
									/>
									Use AI to generate descriptive titles for
									chat sessions
								</label>
								<p className="description">
									Disable to reduce API costs. Titles will use
									the first message instead.
								</p>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row">Auto-generate image alt text</th>
						<td>
							<fieldset>
								<label htmlFor="alt_text_auto_generate_enabled">
									<input
										type="checkbox"
										id="alt_text_auto_generate_enabled"
										checked={
											formState.alt_text_auto_generate_enabled
										}
										onChange={ ( e ) =>
											handleAltTextAutoGenerateToggle(
												e.target.checked
											)
										}
									/>
									Automatically generate AI-powered alt text when
									images are uploaded. Disable to reduce API costs
									or for manual control.
								</label>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row">Flows per page</th>
						<td>
							<fieldset>
								<input
									type="number"
									id="flows_per_page"
									value={ formState.flows_per_page }
									onChange={ ( e ) =>
										handleFlowsPerPageChange(
											e.target.value
										)
									}
									min="5"
									max="100"
									className="small-text"
								/>
								<p className="description">
									Number of flows to display per page in the
									Pipeline Builder.
								</p>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row">Jobs per page</th>
						<td>
							<fieldset>
								<input
									type="number"
									id="jobs_per_page"
									value={ formState.jobs_per_page }
									onChange={ ( e ) =>
										handleJobsPerPageChange(
											e.target.value
										)
									}
									min="5"
									max="100"
									className="small-text"
								/>
								<p className="description">
									Number of jobs to display per page in the
									Jobs admin.
								</p>
							</fieldset>
						</td>
					</tr>
				</tbody>
			</table>

			<h3>Queue Performance</h3>
			<p className="description datamachine-section-description">
				Tune Action Scheduler for faster parallel execution. Higher values = more throughput but higher server load.
			</p>
			<table className="form-table">
				<tbody>
					<tr>
						<th scope="row">Concurrent batches</th>
						<td>
							<fieldset>
								<input
									type="number"
									id="concurrent_batches"
									value={ formState.queue_tuning?.concurrent_batches ?? 3 }
									onChange={ ( e ) =>
										handleQueueTuningChange(
											'concurrent_batches',
											e.target.value
										)
									}
									min="1"
									max="10"
									className="small-text"
								/>
								<p className="description">
									Number of action batches that can run simultaneously.
									Higher = faster processing, but more server load. (1-10, default: 3)
								</p>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row">Batch size</th>
						<td>
							<fieldset>
								<input
									type="number"
									id="batch_size"
									value={ formState.queue_tuning?.batch_size ?? 25 }
									onChange={ ( e ) =>
										handleQueueTuningChange(
											'batch_size',
											e.target.value
										)
									}
									min="10"
									max="200"
									className="small-text"
								/>
								<p className="description">
									Number of actions claimed per batch.
									For AI-heavy workloads, smaller batches with more concurrency often works better. (10-200, default: 25)
								</p>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row">Time limit (seconds)</th>
						<td>
							<fieldset>
								<input
									type="number"
									id="time_limit"
									value={ formState.queue_tuning?.time_limit ?? 60 }
									onChange={ ( e ) =>
										handleQueueTuningChange(
											'time_limit',
											e.target.value
										)
									}
									min="15"
									max="300"
									className="small-text"
								/>
								<p className="description">
									Maximum seconds per batch execution.
									AI steps with external API calls may need longer limits. (15-300, default: 60)
								</p>
							</fieldset>
						</td>
					</tr>
				</tbody>
			</table>

			<div className="datamachine-settings-submit">
				<button
					type="button"
					className="button button-primary"
					onClick={ handleSave }
					disabled={ ! hasChanges || saveStatus === 'saving' }
				>
					{ saveStatus === 'saving' ? 'Saving...' : 'Save Changes' }
				</button>

				{ hasChanges && saveStatus !== 'saving' && (
					<span className="datamachine-unsaved-indicator">
						Unsaved changes
					</span>
				) }

				{ saveStatus === 'saved' && (
					<span className="datamachine-saved-indicator">
						Settings saved!
					</span>
				) }

				{ saveStatus === 'error' && (
					<span className="datamachine-error-indicator">
						Error saving settings
					</span>
				) }
			</div>
		</div>
	);
};

export default GeneralTab;
