/**
 * GeneralTab Component
 *
 * General settings including enabled admin pages, cleanup options, and file retention.
 * Uses useFormState for form management and SettingsSaveBar for save UI.
 */

/**
 * WordPress dependencies
 */
import { useEffect } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { useSettings, useUpdateSettings } from '@shared/queries/settings';
import { useFormState } from '@shared/hooks/useFormState';
import SettingsSaveBar, {
	useSaveStatus,
} from '@shared/components/SettingsSaveBar';

const DEFAULTS = {
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
};

/**
 * Clamp a numeric value within bounds.
 *
 * @param {string|number} raw          Raw input value
 * @param {number}        min          Minimum allowed
 * @param {number}        max          Maximum allowed
 * @param {number}        defaultValue Fallback if NaN
 * @return {number} Clamped integer
 */
const clamp = ( raw, min, max, defaultValue ) =>
	Math.max( min, Math.min( max, parseInt( raw, 10 ) || defaultValue ) );

const QUEUE_LIMITS = {
	concurrent_batches: { min: 1, max: 10, default: 3 },
	batch_size: { min: 10, max: 200, default: 25 },
	time_limit: { min: 15, max: 300, default: 60 },
};

const GeneralTab = () => {
	const { data, isLoading, error } = useSettings();
	const updateMutation = useUpdateSettings();

	const form = useFormState( {
		initialData: DEFAULTS,
		onSubmit: ( formData ) => updateMutation.mutateAsync( formData ),
	} );

	const save = useSaveStatus( {
		onSave: () => form.submit(),
	} );

	// Sync server data → form state
	useEffect( () => {
		if ( data?.settings ) {
			form.reset( {
				cleanup_job_data_on_failure:
					data.settings.cleanup_job_data_on_failure ?? DEFAULTS.cleanup_job_data_on_failure,
				file_retention_days:
					data.settings.file_retention_days ?? DEFAULTS.file_retention_days,
				chat_retention_days:
					data.settings.chat_retention_days ?? DEFAULTS.chat_retention_days,
				chat_ai_titles_enabled:
					data.settings.chat_ai_titles_enabled ?? DEFAULTS.chat_ai_titles_enabled,
				alt_text_auto_generate_enabled:
					data.settings.alt_text_auto_generate_enabled ?? DEFAULTS.alt_text_auto_generate_enabled,
				flows_per_page:
					data.settings.flows_per_page ?? DEFAULTS.flows_per_page,
				jobs_per_page:
					data.settings.jobs_per_page ?? DEFAULTS.jobs_per_page,
				queue_tuning:
					data.settings.queue_tuning ?? DEFAULTS.queue_tuning,
			} );
			save.setHasChanges( false );
		}
	}, [ data ] ); // eslint-disable-line react-hooks/exhaustive-deps

	/**
	 * Update a field and mark the form as changed.
	 *
	 * @param {string} field Field key
	 * @param {*}      value New value
	 */
	const updateField = ( field, value ) => {
		form.updateField( field, value );
		save.markChanged();
	};

	const updateQueueTuning = ( key, rawValue ) => {
		const { min, max, default: defaultVal } = QUEUE_LIMITS[ key ];
		const value = clamp( rawValue, min, max, defaultVal );
		form.updateData( {
			queue_tuning: {
				...form.data.queue_tuning,
				[ key ]: value,
			},
		} );
		save.markChanged();
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
											form.data
												.cleanup_job_data_on_failure
										}
										onChange={ ( e ) =>
											updateField(
												'cleanup_job_data_on_failure',
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
									value={ form.data.file_retention_days }
									onChange={ ( e ) =>
										updateField(
											'file_retention_days',
											clamp( e.target.value, 1, 90, 7 )
										)
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
									value={ form.data.chat_retention_days }
									onChange={ ( e ) =>
										updateField(
											'chat_retention_days',
											clamp(
												e.target.value,
												1,
												365,
												90
											)
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
											form.data.chat_ai_titles_enabled
										}
										onChange={ ( e ) =>
											updateField(
												'chat_ai_titles_enabled',
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
											form.data
												.alt_text_auto_generate_enabled
										}
										onChange={ ( e ) =>
											updateField(
												'alt_text_auto_generate_enabled',
												e.target.checked
											)
										}
									/>
									Automatically generate AI-powered alt text
									when images are uploaded. Disable to reduce
									API costs or for manual control.
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
									value={ form.data.flows_per_page }
									onChange={ ( e ) =>
										updateField(
											'flows_per_page',
											clamp(
												e.target.value,
												5,
												100,
												20
											)
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
									value={ form.data.jobs_per_page }
									onChange={ ( e ) =>
										updateField(
											'jobs_per_page',
											clamp(
												e.target.value,
												5,
												100,
												50
											)
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
				Tune Action Scheduler for faster parallel execution. Higher
				values = more throughput but higher server load.
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
									value={
										form.data.queue_tuning
											?.concurrent_batches ?? 3
									}
									onChange={ ( e ) =>
										updateQueueTuning(
											'concurrent_batches',
											e.target.value
										)
									}
									min="1"
									max="10"
									className="small-text"
								/>
								<p className="description">
									Number of action batches that can run
									simultaneously. Higher = faster processing,
									but more server load. (1-10, default: 3)
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
									value={
										form.data.queue_tuning?.batch_size ?? 25
									}
									onChange={ ( e ) =>
										updateQueueTuning(
											'batch_size',
											e.target.value
										)
									}
									min="10"
									max="200"
									className="small-text"
								/>
								<p className="description">
									Number of actions claimed per batch. For
									AI-heavy workloads, smaller batches with
									more concurrency often works better. (10-200,
									default: 25)
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
									value={
										form.data.queue_tuning?.time_limit ?? 60
									}
									onChange={ ( e ) =>
										updateQueueTuning(
											'time_limit',
											e.target.value
										)
									}
									min="15"
									max="300"
									className="small-text"
								/>
								<p className="description">
									Maximum seconds per batch execution. AI
									steps with external API calls may need
									longer limits. (15-300, default: 60)
								</p>
							</fieldset>
						</td>
					</tr>
				</tbody>
			</table>

			<SettingsSaveBar
				hasChanges={ save.hasChanges }
				saveStatus={ save.saveStatus }
				onSave={ save.handleSave }
			/>
		</div>
	);
};

export default GeneralTab;
