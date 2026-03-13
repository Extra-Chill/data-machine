/**
 * AgentSettings Component
 *
 * Agent configuration settings: provider/model, site context, turns, webhook.
 * Transplanted from the former Settings → Agent tab.
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { useSettings, useUpdateSettings } from '@shared/queries/settings';
import { client } from '@shared/utils/api';
import { useFormState } from '@shared/hooks/useFormState';
import SettingsSaveBar, {
	useSaveStatus,
} from '@shared/components/SettingsSaveBar';
import ProviderModelSelector from '@shared/components/ai/ProviderModelSelector';
import { useProviders } from '@shared/queries/providers';

const EMPTY_FORM = {
	default_provider: '',
	default_model: '',
	context_models: {},
	site_context_enabled: false,
	max_turns: 0,
};

const AgentSettings = () => {
	const { data, isLoading, error } = useSettings();
	const { data: providersData } = useProviders();
	const updateMutation = useUpdateSettings();
	const maxTurnsDefault = data?.defaults?.max_turns ?? 1;
	const [ pingSecret, setPingSecret ] = useState( '' );
	const [ pingSecretVisible, setPingSecretVisible ] = useState( false );
	const [ pingCopied, setPingCopied ] = useState( false );
	const [ pingGenerating, setPingGenerating ] = useState( false );

	const form = useFormState( {
		initialData: EMPTY_FORM,
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
				default_provider: data.settings.default_provider || '',
				default_model: data.settings.default_model || '',
				context_models:
					data.settings.context_models ||
					data.settings.agent_models ||
					{},
				site_context_enabled:
					data.settings.site_context_enabled ?? false,
				max_turns: data.settings.max_turns ?? maxTurnsDefault,
			} );
			save.setHasChanges( false );
		}
	}, [ data, maxTurnsDefault ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const updateField = ( field, value ) => {
		form.updateField( field, value );
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

	return (
		<div className="datamachine-agent-settings">
			<h2 className="datamachine-agent-settings-title">Configuration</h2>
			<table className="form-table">
				<tbody>
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
									providerLabel="Default Provider"
									modelLabel="Default Model"
								/>
							</div>
							<p className="description">
								The default provider and model used for all
								contexts unless overridden below.
							</p>
						</td>
					</tr>

					{ ( providersData?.contexts || [] ).length > 0 && (
						<tr>
							<th scope="row">
								Per-Context Model Overrides
							</th>
							<td>
								<p
									className="description"
									style={ {
										marginTop: 0,
										marginBottom: '16px',
									} }
								>
									The same agent runs in different contexts
									with different toolkits. Override the default
									model for specific contexts — leave empty to
									use the default above.
								</p>
								{ ( providersData?.contexts || [] ).map(
									( contextItem ) => {
										const contextConfig =
											form.data.context_models?.[
												contextItem.id
											] || {};
										return (
											<div
												key={ contextItem.id }
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
													{ contextItem.label }
												</h4>
												<p
													className="description"
													style={ {
														marginTop: 0,
														marginBottom: '8px',
													} }
												>
														{ contextItem.description }
													</p>
													<ProviderModelSelector
														provider={
															contextConfig.provider ||
															''
														}
														model={
															contextConfig.model ||
															''
														}
													onProviderChange={ (
														provider
													) => {
															form.updateData( {
																context_models: {
																	...form.data
																		.context_models,
																	[ contextItem.id ]:
																		{
																			...contextConfig,
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
																context_models: {
																	...form.data
																		.context_models,
																	[ contextItem.id ]:
																		{
																			...contextConfig,
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
												) || maxTurnsDefault
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
