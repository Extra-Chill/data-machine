/**
 * AgentSettings Component
 *
 * Agent configuration settings: tools, provider/model, site context, turns, webhook.
 * Transplanted from the former Settings → Agent tab.
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { useSettings, useUpdateSettings } from '@shared/queries/settings';
import { client } from '@shared/utils/api';
import ToolConfigModal from '@shared/components/ToolConfigModal';
import { useFormState } from '@shared/hooks/useFormState';
import SettingsSaveBar, {
	useSaveStatus,
} from '@shared/components/SettingsSaveBar';
import ProviderModelSelector from '@shared/components/ai/ProviderModelSelector';
import { useProviders } from '@shared/queries/providers';

const DEFAULTS = {
	disabled_tools: {},
	default_provider: '',
	default_model: '',
	agent_models: {},
	site_context_enabled: false,
	max_turns: 12,
};

const AgentSettings = () => {
	const { data, isLoading, error } = useSettings();
	const { data: providersData } = useProviders();
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

	useEffect( () => {
		if ( data?.settings ) {
			form.reset( {
				disabled_tools: data.settings.disabled_tools || {},
				default_provider: data.settings.default_provider || '',
				default_model: data.settings.default_model || '',
				agent_models: data.settings.agent_models || {},
				site_context_enabled:
					data.settings.site_context_enabled ?? false,
				max_turns: data.settings.max_turns ?? 12,
			} );
			save.setHasChanges( false );
		}
	}, [ data ] ); // eslint-disable-line react-hooks/exhaustive-deps

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
			<div className="datamachine-agent-settings-loading">
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
		<div className="datamachine-agent-settings">
			<h2 className="datamachine-agent-settings-title">Configuration</h2>

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
									providerLabel="Global Default Provider"
									modelLabel="Global Default Model"
								/>
							</div>
							<p className="description">
								Fallback provider and model used when no
								agent-specific override is set below.
							</p>
						</td>
					</tr>

					{ ( providersData?.agent_types || [] ).length > 0 && (
						<tr>
							<th scope="row">
								Per-Agent Model Overrides
							</th>
							<td>
								<p
									className="description"
									style={ {
										marginTop: 0,
										marginBottom: '16px',
									} }
								>
									Assign different providers and models to
									each agent type. Leave empty to use the
									global default above.
								</p>
								{ ( providersData?.agent_types || [] ).map(
									( agentType ) => {
										const agentConfig =
											form.data.agent_models?.[
												agentType.id
											] || {};
										return (
											<div
												key={ agentType.id }
												className="datamachine-agent-model-override"
												style={ {
													marginBottom: '20px',
													paddingBottom: '16px',
													borderBottom:
														'1px solid #e0e0e0',
												} }
											>
												<h4
													style={ {
														margin: '0 0 4px',
													} }
												>
													{ agentType.label }
												</h4>
												<p
													className="description"
													style={ {
														marginTop: 0,
														marginBottom: '8px',
													} }
												>
													{
														agentType.description
													}
												</p>
												<ProviderModelSelector
													provider={
														agentConfig.provider ||
														''
													}
													model={
														agentConfig.model ||
														''
													}
													onProviderChange={ (
														provider
													) => {
														form.updateData( {
															agent_models: {
																...form.data
																	.agent_models,
																[ agentType.id ]:
																	{
																		...agentConfig,
																		provider,
																		model: '',
																	},
															},
														} );
														save.markChanged();
													} }
													onModelChange={ (
														model
													) => {
														form.updateData( {
															agent_models: {
																...form.data
																	.agent_models,
																[ agentType.id ]:
																	{
																		...agentConfig,
																		model,
																	},
															},
														} );
														save.markChanged();
													} }
													applyDefaults={ false }
													providerLabel="Provider"
													modelLabel="Model"
												/>
											</div>
										);
									}
								) }
							</td>
						</tr>
					) }

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

export default AgentSettings;
