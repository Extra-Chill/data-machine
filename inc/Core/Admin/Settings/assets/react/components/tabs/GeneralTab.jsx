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
 * External dependencies
 */
import { useSettings, useUpdateSettings } from '@shared/queries/settings';
import { useFormState } from '@shared/hooks/useFormState';
import SettingsSaveBar, {
	useSaveStatus,
} from '@shared/components/SettingsSaveBar';

const EMPTY_FORM = {
	cleanup_job_data_on_failure: true,
	file_retention_days: 7,
	chat_retention_days: 90,
	chat_ai_titles_enabled: true,
	wp_ai_client_connect_timeout: 15,
	wp_ai_client_request_timeout: 300,
	pipeline_ai_concurrency_limit: 3,
	pipeline_ai_provider_concurrency_limits: {},
	pipeline_ai_throttle_delay: 10,
	flows_per_page: 20,
	jobs_per_page: 50,
	queue_tuning: {
		concurrent_batches: 0,
		batch_size: 0,
		time_limit: 0,
		chunk_size: 0,
		chunk_delay: 0,
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
	concurrent_batches: { min: 1, max: 50, default: 3 },
	batch_size: { min: 10, max: 500, default: 25 },
	time_limit: { min: 15, max: 300, default: 60 },
	chunk_size: { min: 1, max: 500, default: 10 },
	chunk_delay: { min: 0, max: 300, default: 30 },
};

const AI_CONCURRENCY_LIMITS = {
	pipeline_ai_concurrency_limit: { min: 1, max: 50, default: 3 },
	pipeline_ai_throttle_delay: { min: 1, max: 300, default: 10 },
};

const GeneralTab = () => {
	const { data, isLoading, error } = useSettings();
	const updateMutation = useUpdateSettings();
	const queueDefaults = data?.defaults?.queue_tuning ?? {
		concurrent_batches: 3,
		batch_size: 25,
		time_limit: 60,
		chunk_size: 10,
		chunk_delay: 30,
	};
	const transportDefaults = {
		connectTimeout: data?.defaults?.wp_ai_client_connect_timeout ?? 15,
		requestTimeout: data?.defaults?.wp_ai_client_request_timeout ?? 300,
	};
	const aiConcurrencyDefaults = {
		limit: data?.defaults?.pipeline_ai_concurrency_limit ?? 3,
		providerLimits:
			data?.defaults?.pipeline_ai_provider_concurrency_limits ?? {},
		throttleDelay: data?.defaults?.pipeline_ai_throttle_delay ?? 10,
	};

	const form = useFormState( {
		initialData: EMPTY_FORM,
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
					data.settings.cleanup_job_data_on_failure ?? EMPTY_FORM.cleanup_job_data_on_failure,
				file_retention_days:
					data.settings.file_retention_days ?? EMPTY_FORM.file_retention_days,
				chat_retention_days:
					data.settings.chat_retention_days ?? EMPTY_FORM.chat_retention_days,
				chat_ai_titles_enabled:
					data.settings.chat_ai_titles_enabled ?? EMPTY_FORM.chat_ai_titles_enabled,
				wp_ai_client_connect_timeout:
					data.settings.wp_ai_client_connect_timeout ?? transportDefaults.connectTimeout,
				wp_ai_client_request_timeout:
					data.settings.wp_ai_client_request_timeout ?? transportDefaults.requestTimeout,
				pipeline_ai_concurrency_limit:
					data.settings.pipeline_ai_concurrency_limit ?? aiConcurrencyDefaults.limit,
				pipeline_ai_provider_concurrency_limits:
					data.settings.pipeline_ai_provider_concurrency_limits ?? aiConcurrencyDefaults.providerLimits,
				pipeline_ai_throttle_delay:
					data.settings.pipeline_ai_throttle_delay ?? aiConcurrencyDefaults.throttleDelay,
				flows_per_page:
					data.settings.flows_per_page ?? EMPTY_FORM.flows_per_page,
				jobs_per_page:
					data.settings.jobs_per_page ?? EMPTY_FORM.jobs_per_page,
				queue_tuning:
					data.settings.queue_tuning ?? queueDefaults,
			} );
			save.setHasChanges( false );
		}
	}, [ data, queueDefaults ] ); // eslint-disable-line react-hooks/exhaustive-deps

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
		const value = clamp( rawValue, min, max, queueDefaults[ key ] ?? defaultVal );
		form.updateData( {
			queue_tuning: {
				...form.data.queue_tuning,
				[ key ]: value,
			},
		} );
		save.markChanged();
	};

	const updateAIConcurrency = ( key, rawValue ) => {
		const { min, max, default: defaultVal } = AI_CONCURRENCY_LIMITS[ key ];
		const fallback =
			key === 'pipeline_ai_concurrency_limit'
				? aiConcurrencyDefaults.limit
				: aiConcurrencyDefaults.throttleDelay;
		const value = clamp( rawValue, min, max, fallback ?? defaultVal );
		form.updateField( key, value );
		save.markChanged();
	};

	const updateProviderLimit = ( provider, rawValue ) => {
		const value = clamp( rawValue, 0, 50, 0 );
		const providerLimits = {
			...( form.data.pipeline_ai_provider_concurrency_limits ?? {} ),
		};

		if ( value > 0 ) {
			providerLimits[ provider ] = value;
		} else {
			delete providerLimits[ provider ];
		}

		form.updateField( 'pipeline_ai_provider_concurrency_limits', providerLimits );
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
						<th scope="row">AI connect timeout</th>
						<td>
							<fieldset>
								<input
									type="number"
									id="wp_ai_client_connect_timeout"
									value={ form.data.wp_ai_client_connect_timeout }
									onChange={ ( e ) =>
										updateField(
											'wp_ai_client_connect_timeout',
											clamp(
												e.target.value,
												0,
												300,
												transportDefaults.connectTimeout
											)
										)
									}
									min="0"
									max="300"
									className="small-text"
								/>
								<p className="description">
									Seconds allowed to establish the provider
									connection. Default:{ ' ' }
									{ transportDefaults.connectTimeout }.
								</p>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row">AI request timeout</th>
						<td>
							<fieldset>
								<input
									type="number"
									id="wp_ai_client_request_timeout"
									value={ form.data.wp_ai_client_request_timeout }
									onChange={ ( e ) =>
										updateField(
											'wp_ai_client_request_timeout',
											clamp(
												e.target.value,
												0,
												900,
												transportDefaults.requestTimeout
											)
										)
									}
									min="0"
									max="900"
									className="small-text"
								/>
								<p className="description">
									Seconds allowed for the full non-streaming AI
									response. Default:{ ' ' }
									{ transportDefaults.requestTimeout }.
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

			<h3>Pipeline AI Concurrency</h3>
			<p className="description datamachine-section-description">
				Limit concurrent provider calls from pipeline AI steps. These
				limits are separate from Action Scheduler queue throughput: queue
				settings decide how many actions run, while these settings decide
				how many pipeline AI calls may be in flight at once.
			</p>
			<table className="form-table">
				<tbody>
					<tr>
						<th scope="row">Site-wide AI calls</th>
						<td>
							<fieldset>
								<input
									type="number"
									id="pipeline_ai_concurrency_limit"
									value={ form.data.pipeline_ai_concurrency_limit }
									onChange={ ( e ) =>
										updateAIConcurrency(
											'pipeline_ai_concurrency_limit',
											e.target.value
										)
									}
									min="1"
									max="50"
									className="small-text"
								/>
								<p className="description">
									Maximum concurrent pipeline AI provider calls across
									the site. (1-50, default:{ ' ' }
									{ aiConcurrencyDefaults.limit })
								</p>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row">OpenAI AI calls</th>
						<td>
							<fieldset>
								<input
									type="number"
									id="pipeline_ai_provider_openai_limit"
									value={
										form.data.pipeline_ai_provider_concurrency_limits
											?.openai ?? 0
									}
									onChange={ ( e ) =>
										updateProviderLimit( 'openai', e.target.value )
									}
									min="0"
									max="50"
									className="small-text"
								/>
								<p className="description">
									Optional OpenAI-specific cap. Use 0 to rely only on
									the site-wide AI call limit.
								</p>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row">AI throttle retry delay</th>
						<td>
							<fieldset>
								<input
									type="number"
									id="pipeline_ai_throttle_delay"
									value={ form.data.pipeline_ai_throttle_delay }
									onChange={ ( e ) =>
										updateAIConcurrency(
											'pipeline_ai_throttle_delay',
											e.target.value
										)
									}
									min="1"
									max="300"
									className="small-text"
								/>
								<p className="description">
									Seconds before a pipeline AI job retries when the
									AI concurrency lane is full. (1-300, default:{ ' ' }
									{ aiConcurrencyDefaults.throttleDelay })
								</p>
							</fieldset>
						</td>
					</tr>
				</tbody>
			</table>

			<h3>Queue Performance</h3>
			<p className="description datamachine-section-description">
				Tune how Data Machine feeds the queue (chunking) and how
				Action Scheduler drains it (concurrency). Higher values =
				more throughput but higher server load. The two layers are
				complementary — bumping concurrency without bumping chunk
				size leaves the queue runner idle waiting for work.
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
											?.concurrent_batches ?? queueDefaults.concurrent_batches
									}
									onChange={ ( e ) =>
										updateQueueTuning(
											'concurrent_batches',
											e.target.value
										)
									}
									min="1"
									max="50"
									className="small-text"
								/>
								<p className="description">
									Number of action batches that can run
									simultaneously. Higher = faster processing,
									but more server load. (1-50, default: { queueDefaults.concurrent_batches })
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
										form.data.queue_tuning?.batch_size ?? queueDefaults.batch_size
									}
									onChange={ ( e ) =>
										updateQueueTuning(
											'batch_size',
											e.target.value
										)
									}
									min="10"
									max="500"
									className="small-text"
								/>
								<p className="description">
									Number of actions claimed per batch. For
									AI-heavy workloads, smaller batches with
									more concurrency often works better. (10-500,
									default: { queueDefaults.batch_size })
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
										form.data.queue_tuning?.time_limit ?? queueDefaults.time_limit
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
									longer limits. (15-300, default: { queueDefaults.time_limit })
								</p>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row">Chunk size</th>
						<td>
							<fieldset>
								<input
									type="number"
									id="chunk_size"
									value={
										form.data.queue_tuning?.chunk_size ?? queueDefaults.chunk_size
									}
									onChange={ ( e ) =>
										updateQueueTuning(
											'chunk_size',
											e.target.value
										)
									}
									min="1"
									max="500"
									className="small-text"
								/>
								<p className="description">
									Number of child jobs Data Machine creates
									per scheduling cycle when fanning out a
									batch. Lower = gentler on the queue;
									higher = faster fan-out. (1-500,
									default: { queueDefaults.chunk_size })
								</p>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row">Chunk delay (seconds)</th>
						<td>
							<fieldset>
								<input
									type="number"
									id="chunk_delay"
									value={
										form.data.queue_tuning?.chunk_delay ?? queueDefaults.chunk_delay
									}
									onChange={ ( e ) =>
										updateQueueTuning(
											'chunk_delay',
											e.target.value
										)
									}
									min="0"
									max="300"
									className="small-text"
								/>
								<p className="description">
									Seconds to wait between chunks while
									creating a batch. Higher = more
									breathing room for other tasks; 0 =
									schedule chunks back-to-back. (0-300,
									default: { queueDefaults.chunk_delay })
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
