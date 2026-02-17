/**
 * AgentTab Component
 *
 * AI agent settings including tools, system prompt, provider/model, and conversation limits.
 * Uses useFormState for form management and SettingsSaveBar for save UI.
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import { Button } from '@wordpress/components';

/**
 * Internal dependencies
 */
import {
	useSettings,
	useUpdateSettings,
} from '../../queries/settings';
import { client } from '@shared/utils/api';
import ToolConfigModal from '../ToolConfigModal';
import { useFormState } from '@shared/hooks/useFormState';
import SettingsSaveBar, {
	useSaveStatus,
} from '@shared/components/SettingsSaveBar';

/**
 * External dependencies
 */
import ProviderModelSelector from '@shared/components/ai/ProviderModelSelector';

const SOUL_SECTIONS = [
	{
		key: 'identity',
		label: 'Identity',
		description:
			'Who is this agent? Name, role, personality. This is the core identity that shapes every response.',
		placeholder:
			'e.g. You are a helpful content assistant for our WordPress site…',
		rows: 4,
	},
	{
		key: 'voice',
		label: 'Voice & Tone',
		description:
			'How should the agent communicate? Formal, casual, technical, friendly?',
		placeholder:
			'e.g. Write in a warm, conversational tone. Avoid jargon…',
		rows: 3,
	},
	{
		key: 'rules',
		label: 'Rules',
		description:
			'Hard constraints the agent must follow. Safety guardrails, content policies, formatting requirements.',
		placeholder:
			'e.g. Never generate content about competitors. Always cite sources…',
		rows: 4,
	},
	{
		key: 'context',
		label: 'Context',
		description:
			'Background knowledge the agent should always have. Brand info, audience details, domain expertise.',
		placeholder:
			'e.g. Our target audience is small business owners aged 25-45…',
		rows: 4,
	},
];

const DEFAULTS = {
	disabled_tools: {},
	agent_soul: {
		identity: '',
		voice: '',
		rules: '',
		context: '',
	},
	global_system_prompt: '',
	default_provider: '',
	default_model: '',
	site_context_enabled: false,
	max_turns: 12,
};

const AgentTab = () => {
	const { data, isLoading, error } = useSettings();
	const updateMutation = useUpdateSettings();
	const [ openToolId, setOpenToolId ] = useState( null );
	const [ pingSecret, setPingSecret ] = useState( '' );
	const [ pingSecretVisible, setPingSecretVisible ] = useState( false );
	const [ pingCopied, setPingCopied ] = useState( false );
	const [ pingGenerating, setPingGenerating ] = useState( false );

	const form = useFormState( {
		initialData: DEFAULTS,
		onSubmit: ( formData ) => updateMutation.mutateAsync( formData ),
	} );

	const save = useSaveStatus( {
		onSave: () => form.submit(),
	} );

	// Sync ping secret from server data.
	useEffect( () => {
		if ( data?.settings?.chat_ping_secret ) {
			setPingSecret( data.settings.chat_ping_secret );
		}
	}, [ data ] );

	const handleGeneratePingSecret = useCallback( async () => {
		const confirmed = pingSecret
			? window.confirm(
					'Regenerating will invalidate the current token. Any services using it will lose access. Continue?'
			  )
			: true;

		if ( ! confirmed ) {
			return;
		}

		setPingGenerating( true );
		try {
			const response = await client.post(
				'/settings/generate-ping-secret'
			);
			if ( response.success && response.secret ) {
				setPingSecret( response.secret );
				setPingSecretVisible( true );
			}
		} catch ( err ) {
			// eslint-disable-next-line no-console
			console.error( 'Failed to generate ping secret:', err );
		} finally {
			setPingGenerating( false );
		}
	}, [ pingSecret ] );

	const handleCopyPingSecret = useCallback( () => {
		navigator.clipboard.writeText( pingSecret ).then( () => {
			setPingCopied( true );
			setTimeout( () => setPingCopied( false ), 2000 );
		} );
	}, [ pingSecret ] );

	// Sync server data → form state
	useEffect( () => {
		if ( data?.settings ) {
			const serverSoul = data.settings.agent_soul || {};
			form.reset( {
				disabled_tools: data.settings.disabled_tools || {},
				agent_soul: {
					identity: serverSoul.identity || '',
					voice: serverSoul.voice || '',
					rules: serverSoul.rules || '',
					context: serverSoul.context || '',
				},
				global_system_prompt:
					data.settings.global_system_prompt || '',
				default_provider: data.settings.default_provider || '',
				default_model: data.settings.default_model || '',
				site_context_enabled:
					data.settings.site_context_enabled ?? false,
				max_turns: data.settings.max_turns ?? 12,
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

	const handleToolToggle = ( toolName, enabled ) => {
		const newTools = { ...form.data.disabled_tools };
		if ( enabled ) {
			delete newTools[ toolName ];
		} else {
			newTools[ toolName ] = true;
		}
		form.updateField( 'disabled_tools', newTools );
		save.markChanged();
	};

	const handleProviderChange = ( provider ) => {
		form.updateData( {
			default_provider: provider,
			default_model: '',
		} );
		save.markChanged();
	};

	if ( isLoading ) {
		return (
			<div className="datamachine-agent-tab-loading">
				<span className="spinner is-active"></span>
				<span>Loading agent settings...</span>
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

	const globalTools = data?.global_tools || {};

	return (
		<div className="datamachine-agent-tab">
			{ openToolId && (
				<ToolConfigModal
					toolId={ openToolId }
					isOpen={ Boolean( openToolId ) }
					onRequestClose={ () => setOpenToolId( null ) }
				/>
			) }
			<table className="form-table">
				<tbody>
					<tr>
						<th scope="row">Tool Configuration</th>
						<td>
							{ Object.keys( globalTools ).length > 0 ? (
								<div className="datamachine-tool-config-grid">
									{ Object.entries( globalTools ).map(
										( [ toolName, toolConfig ] ) => {
											const isConfigured =
												toolConfig.is_configured;
											const isEnabled =
												! ( form.data.disabled_tools?.[
													toolName
												] ?? false );
											const toolLabel =
												toolConfig.label ||
												toolName.replace( /_/g, ' ' );

											return (
												<div
													key={ toolName }
													className="datamachine-tool-config-item"
												>
													<h4>{ toolLabel }</h4>
													{ toolConfig.description && (
														<p className="description">
															{
																toolConfig.description
															}
														</p>
													) }
													<div className="datamachine-tool-controls">
														<span
															className={ `datamachine-config-status ${
																isConfigured
																	? 'configured'
																	: 'not-configured'
															}` }
														>
															{ isConfigured
																? 'Configured'
																: 'Not Configured' }
														</span>

														{ toolConfig.requires_configuration && (
															<Button
																variant="secondary"
																onClick={ () =>
																	setOpenToolId(
																		toolName
																	)
																}
															>
																Configure
															</Button>
														) }

														{ isConfigured ? (
															<label className="datamachine-tool-enabled-toggle">
																<input
																	type="checkbox"
																	checked={
																		isEnabled
																	}
																	onChange={ (
																		e
																	) =>
																		handleToolToggle(
																			toolName,
																			e
																				.target
																				.checked
																		)
																	}
																/>
																Enable for
																agents
															</label>
														) : (
															<label className="datamachine-tool-enabled-toggle datamachine-tool-disabled">
																<input
																	type="checkbox"
																	disabled
																/>
																<span className="description">
																	Configure to
																	enable
																</span>
															</label>
														) }
													</div>
												</div>
											);
										}
									) }
								</div>
							) : (
								<p>No global tools are currently available.</p>
							) }
						</td>
					</tr>

					<tr>
						<th scope="row">Agent Soul</th>
						<td>
							<p
								className="description"
								style={ {
									marginTop: 0,
									marginBottom: '16px',
								} }
							>
								Define who this agent is across four tiers.
								Each section is injected as a system directive
								for every AI interaction — pipelines and chat.
							</p>
							{ SOUL_SECTIONS.map( ( section ) => (
								<div
									key={ section.key }
									className="datamachine-soul-section"
									style={ { marginBottom: '16px' } }
								>
									<label
										htmlFor={ `agent_soul_${ section.key }` }
										style={ {
											fontWeight: 600,
											display: 'block',
											marginBottom: '4px',
										} }
									>
										{ section.label }
									</label>
									<textarea
										id={ `agent_soul_${ section.key }` }
										rows={ section.rows }
										cols="70"
										className="large-text code"
										placeholder={ section.placeholder }
										value={
											form.data.agent_soul?.[
												section.key
											] || ''
										}
										onChange={ ( e ) => {
											form.updateData( {
												agent_soul: {
													...form.data.agent_soul,
													[ section.key ]:
														e.target.value,
												},
											} );
											save.markChanged();
										} }
									/>
									<p className="description">
										{ section.description }
									</p>
								</div>
							) ) }

							{ form.data.global_system_prompt && (
								<div
									className="notice notice-warning inline"
									style={ {
										marginTop: '12px',
										padding: '8px 12px',
									} }
								>
									<p>
										<strong>
											Legacy prompt detected.
										</strong>{ ' ' }
										You have content in the old Global
										System Prompt field. Move it into the
										sections above for structured agent
										behavior.
									</p>
									<details style={ { marginTop: '8px' } }>
										<summary>
											View legacy prompt
										</summary>
										<textarea
											rows="4"
											cols="70"
											className="large-text code"
											value={
												form.data
													.global_system_prompt || ''
											}
											onChange={ ( e ) =>
												updateField(
													'global_system_prompt',
													e.target.value
												)
											}
											style={ { marginTop: '8px' } }
										/>
									</details>
								</div>
							) }
						</td>
					</tr>

					<tr>
						<th scope="row">Default AI Provider &amp; Model</th>
						<td>
							<div className="datamachine-ai-provider-model-settings">
								<ProviderModelSelector
									provider={ form.data.default_provider }
									model={ form.data.default_model }
									onProviderChange={ handleProviderChange }
									onModelChange={ ( model ) =>
										updateField( 'default_model', model )
									}
									applyDefaults={ false }
									providerLabel="Default AI Provider"
									modelLabel="Default AI Model"
								/>
							</div>
							<p className="description">
								Set the default AI provider and model for new AI
								steps and chat requests. These can be overridden
								on a per-step or per-request basis.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">Provide site context to agents</th>
						<td>
							<fieldset>
								<label htmlFor="site_context_enabled">
									<input
										type="checkbox"
										id="site_context_enabled"
										checked={
											form.data.site_context_enabled
										}
										onChange={ ( e ) =>
											updateField(
												'site_context_enabled',
												e.target.checked
											)
										}
									/>
									Include WordPress site context in AI
									requests
								</label>
								<p className="description">
									Automatically provides site information
									(post types, taxonomies, user stats) to AI
									agents for better context awareness.
								</p>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label htmlFor="max_turns">
								Maximum conversation turns
							</label>
						</th>
						<td>
							<input
								type="number"
								id="max_turns"
								value={ form.data.max_turns }
								onChange={ ( e ) =>
									updateField(
										'max_turns',
										Math.max(
											1,
											Math.min(
												50,
												parseInt(
													e.target.value,
													10
												) || 1
											)
										)
									)
								}
								min="1"
								max="50"
								className="small-text"
							/>
							<p className="description">
								Maximum number of conversation turns allowed for
								AI agents (1-50). Applies to both pipeline and
								chat conversations.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">Chat Agent Webhook</th>
						<td>
							<div className="datamachine-ping-secret-section">
								<p className="description" style={ { marginTop: 0 } }>
									Allow external services to send messages to
									your chat agent via webhook. Use this
									endpoint URL and secret token in Agent Ping
									step configurations.
								</p>

								<div style={ { marginBottom: '12px' } }>
									<strong>Endpoint URL:</strong>{ ' ' }
									<code>
										{ window.location.origin }
										/wp-json/datamachine/v1/chat/ping
									</code>
								</div>

								{ pingSecret ? (
									<div
										style={ {
											display: 'flex',
											alignItems: 'center',
											gap: '8px',
											marginBottom: '12px',
										} }
									>
										<input
											type={
												pingSecretVisible
													? 'text'
													: 'password'
											}
											value={ pingSecret }
											readOnly
											className="regular-text"
										/>
										<Button
											variant="secondary"
											onClick={ () =>
												setPingSecretVisible(
													! pingSecretVisible
												)
											}
										>
											{ pingSecretVisible
												? 'Hide'
												: 'Show' }
										</Button>
										<Button
											variant="secondary"
											onClick={ handleCopyPingSecret }
										>
											{ pingCopied
												? 'Copied!'
												: 'Copy' }
										</Button>
									</div>
								) : (
									<p>
										<em>
											No secret configured. Generate one
											to enable the webhook endpoint.
										</em>
									</p>
								) }

								<Button
									variant={
										pingSecret ? 'secondary' : 'primary'
									}
									onClick={ handleGeneratePingSecret }
									isBusy={ pingGenerating }
									disabled={ pingGenerating }
								>
									{ pingSecret
										? 'Regenerate Secret'
										: 'Generate Secret' }
								</Button>

								{ pingSecret && (
									<p
										className="description"
										style={ { marginTop: '8px' } }
									>
										Send requests with header:{ ' ' }
										<code>
											Authorization: Bearer &lt;secret&gt;
										</code>
									</p>
								) }
							</div>
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

export default AgentTab;
