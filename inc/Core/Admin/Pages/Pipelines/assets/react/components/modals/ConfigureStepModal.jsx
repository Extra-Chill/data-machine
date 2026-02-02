/**
 * Configure Step Modal Component
 *
 * Modal for configuring step settings. Supports:
 * - AI steps: provider, model, tools, system prompt
 * - Agent Ping steps: webhook URL, prompt/instructions
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect, useMemo } from '@wordpress/element';
import { Modal, Button, TextareaControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import { useTools } from '../../queries/config';
import { useUpdateSystemPrompt } from '../../queries/pipelines';
import { useFormState } from '../../hooks/useFormState';
/**
 * External dependencies
 */
import ProviderModelSelector from '@shared/components/ai/ProviderModelSelector';
import AIToolsSelector from './configure-step/AIToolsSelector';

/**
 * AI Step Configuration Content
 */
function AIStepConfig( {
	formState,
	selectedTools,
	setSelectedTools,
	isLoadingTools,
	shouldApplyDefaults,
} ) {
	return (
		<>
			<ProviderModelSelector
				provider={ formState.data.provider }
				model={ formState.data.model }
				onProviderChange={ ( value ) =>
					formState.updateField( 'provider', value )
				}
				onModelChange={ ( value ) =>
					formState.updateField( 'model', value )
				}
				disabled={ isLoadingTools }
				applyDefaults={ shouldApplyDefaults }
				providerHelp={ __(
					'Choose the AI provider for this step.',
					'data-machine'
				) }
				modelHelp={ __(
					'Choose the AI model to use.',
					'data-machine'
				) }
			/>

			<AIToolsSelector
				selectedTools={ selectedTools }
				onSelectionChange={ setSelectedTools }
			/>

			<div className="datamachine-form-field-wrapper">
				<TextareaControl
					label={ __( 'System Prompt', 'data-machine' ) }
					value={ formState.data.systemPrompt }
					onChange={ ( value ) =>
						formState.updateField( 'systemPrompt', value )
					}
					placeholder={ __(
						'Enter system prompt for AI processing…',
						'data-machine'
					) }
					rows={ 8 }
					help={ __(
						'Optional: Provide instructions for the AI to follow during processing.',
						'data-machine'
					) }
				/>
			</div>

			<div className="datamachine-modal-info-box datamachine-modal-info-box--note">
				<p>
					<strong>{ __( 'Note:', 'data-machine' ) }</strong>{ ' ' }
					{ __(
						'The system prompt is shared across all flows using this pipeline. To add flow-specific instructions, use the user message field in the flow step card.',
						'data-machine'
					) }
				</p>
			</div>
		</>
	);
}

/**
 * Agent Ping Step Configuration Content
 */
function AgentPingConfig( { formState } ) {
	/**
	 * Validate URL format
	 */
	const isValidUrl = ( url ) => {
		if ( ! url || ! url.trim() ) {
			return true;
		}
		try {
			const parsed = new URL( url );
			return [ 'http:', 'https:' ].includes( parsed.protocol );
		} catch {
			return false;
		}
	};

	const urlError =
		formState.data.webhookUrl &&
		! isValidUrl( formState.data.webhookUrl )
			? __( 'Please enter a valid URL', 'data-machine' )
			: null;

	return (
		<>
			<div className="datamachine-form-field-wrapper">
				<TextControl
					label={ __( 'Webhook URL', 'data-machine' ) }
					value={ formState.data.webhookUrl }
					onChange={ ( value ) =>
						formState.updateField( 'webhookUrl', value )
					}
					placeholder={ __(
						'https://discord.com/api/webhooks/...',
						'data-machine'
					) }
					help={
						urlError ||
						__(
							'URL to POST data to (Discord, Slack, custom endpoint)',
							'data-machine'
						)
					}
					type="url"
					className={ urlError ? 'has-error' : '' }
				/>
			</div>

			<div className="datamachine-form-field-wrapper">
				<TextareaControl
					label={ __( 'Instructions', 'data-machine' ) }
					value={ formState.data.prompt }
					onChange={ ( value ) =>
						formState.updateField( 'prompt', value )
					}
					placeholder={ __(
						'Enter instructions for the receiving agent…',
						'data-machine'
					) }
					rows={ 6 }
					help={ __(
						'Optional: Provide instructions or context to send with the webhook payload.',
						'data-machine'
					) }
				/>
			</div>

			<div className="datamachine-modal-info-box datamachine-modal-info-box--note">
				<p>
					<strong>{ __( 'Note:', 'data-machine' ) }</strong>{ ' ' }
					{ __(
						'This configuration is shared across all flows using this pipeline. The webhook will receive pipeline context including all data packets from previous steps.',
						'data-machine'
					) }
				</p>
			</div>
		</>
	);
}

/**
 * Configure Step Modal Component
 *
 * @param {Object}   props                - Component props
 * @param {Function} props.onClose        - Close handler
 * @param {number}   props.pipelineId     - Pipeline ID
 * @param {string}   props.pipelineStepId - Pipeline step ID
 * @param {string}   props.stepType       - Step type ('ai' or 'agent_ping')
 * @param {Object}   props.currentConfig  - Current configuration
 * @param {Function} props.onSuccess      - Success callback
 * @return {React.ReactElement|null} Configure step modal
 */
export default function ConfigureStepModal( {
	onClose,
	pipelineId,
	pipelineStepId,
	stepType,
	currentConfig,
	onSuccess,
} ) {
	const isAgentPing = stepType === 'agent_ping';

	const [ selectedTools, setSelectedTools ] = useState(
		currentConfig?.enabled_tools || []
	);

	const updateMutation = useUpdateSystemPrompt();

	const configKey = useMemo(
		() =>
			JSON.stringify( {
				provider: currentConfig?.provider,
				model: currentConfig?.model,
				system_prompt: currentConfig?.system_prompt,
				enabled_tools: currentConfig?.enabled_tools,
				webhook_url: currentConfig?.webhook_url,
				prompt: currentConfig?.prompt,
			} ),
		[
			currentConfig?.provider,
			currentConfig?.model,
			currentConfig?.system_prompt,
			currentConfig?.enabled_tools,
			currentConfig?.webhook_url,
			currentConfig?.prompt,
		]
	);

	// Build initial data based on step type
	const initialData = useMemo( () => {
		if ( isAgentPing ) {
			return {
				webhookUrl: currentConfig?.webhook_url || '',
				prompt: currentConfig?.prompt || '',
			};
		}
		return {
			provider: currentConfig?.provider || '',
			model: currentConfig?.model || '',
			systemPrompt: currentConfig?.system_prompt || '',
		};
	}, [ isAgentPing, currentConfig ] );

	const formState = useFormState( {
		initialData,
		validate: ( data ) => {
			if ( isAgentPing ) {
				// Webhook URL is required for Agent Ping
				if ( ! data.webhookUrl || ! data.webhookUrl.trim() ) {
					return __( 'Webhook URL is required', 'data-machine' );
				}
				// Validate URL format
				try {
					const parsed = new URL( data.webhookUrl );
					if ( ! [ 'http:', 'https:' ].includes( parsed.protocol ) ) {
						return __( 'Please enter a valid HTTP/HTTPS URL', 'data-machine' );
					}
				} catch {
					return __( 'Please enter a valid URL', 'data-machine' );
				}
				return null;
			}

			// AI step validation
			if ( ! data.provider ) {
				return __( 'Please select an AI provider', 'data-machine' );
			}
			if ( ! data.model ) {
				return __( 'Please select an AI model', 'data-machine' );
			}
			return null;
		},
		onSubmit: async ( data ) => {
			let response;

			if ( isAgentPing ) {
				// Agent Ping configuration
				response = await updateMutation.mutateAsync( {
					stepId: pipelineStepId,
					webhookUrl: data.webhookUrl,
					prompt: data.prompt,
					stepType: 'agent_ping',
					pipelineId,
				} );
			} else {
				// AI step configuration
				response = await updateMutation.mutateAsync( {
					stepId: pipelineStepId,
					prompt: data.systemPrompt,
					provider: data.provider,
					model: data.model,
					enabledTools: selectedTools,
					stepType,
					pipelineId,
				} );
			}

			if ( response.success ) {
				onClose();
			} else {
				throw new Error(
					response.message ||
						__( 'Failed to update configuration', 'data-machine' )
				);
			}
		},
	} );

	// Use TanStack Query for tools data (AI steps only)
	const { data: tools, isLoading: isLoadingTools } = useTools();

	// Pre-populate tools when data loads (AI steps only)
	useEffect( () => {
		if ( isAgentPing || isLoadingTools ) {
			return;
		}

		/*
		 * Tools selection logic:
		 * - Global settings = source of truth for NEW steps (defaults)
		 * - Steps can DISABLE globally-enabled tools (override)
		 * - Steps CANNOT enable globally-disabled tools
		 *
		 * Detection:
		 * - enabled_tools is Array → explicitly configured (use as-is, even if empty)
		 * - enabled_tools is undefined → never configured → pre-fill with global defaults
		 */
		const isExplicitlyConfigured = Array.isArray(
			currentConfig?.enabled_tools
		);

		if ( isExplicitlyConfigured ) {
			// Use explicitly configured tools (even if empty array)
			setSelectedTools( currentConfig.enabled_tools );
		} else if ( tools ) {
			// Never configured - pre-fill with globally enabled tools
			const globalDefaults = Object.entries( tools )
				.filter( ( [ , tool ] ) => tool.globally_enabled )
				.map( ( [ id ] ) => id );
			setSelectedTools( globalDefaults );
		}
	}, [ configKey, tools, isLoadingTools, isAgentPing ] );

	// Determine if defaults should be applied (only for new/unconfigured AI steps)
	const shouldApplyDefaults = ! isAgentPing && ! currentConfig?.provider;

	// Determine modal title
	const modalTitle = isAgentPing
		? __( 'Configure Agent Ping', 'data-machine' )
		: __( 'Configure AI Step', 'data-machine' );

	// Determine if save button should be disabled
	const isSaveDisabled = isAgentPing
		? formState.isSubmitting || ! formState.data.webhookUrl?.trim()
		: formState.isSubmitting ||
		  ! formState.data.provider ||
		  ! formState.data.model;

	return (
		<Modal
			title={ modalTitle }
			onRequestClose={ onClose }
			className="datamachine-configure-step-modal"
		>
			<div className="datamachine-modal-content">
				{ formState.error && (
					<div className="datamachine-modal-error notice notice-error">
						<p>{ formState.error }</p>
					</div>
				) }

				{ isAgentPing ? (
					<AgentPingConfig formState={ formState } />
				) : (
					<AIStepConfig
						formState={ formState }
						selectedTools={ selectedTools }
						setSelectedTools={ setSelectedTools }
						isLoadingTools={ isLoadingTools }
						shouldApplyDefaults={ shouldApplyDefaults }
					/>
				) }

				<div className="datamachine-modal-actions">
					<Button
						variant="secondary"
						onClick={ onClose }
						disabled={ formState.isSubmitting }
					>
						{ __( 'Cancel', 'data-machine' ) }
					</Button>

					<Button
						variant="primary"
						onClick={ formState.submit }
						disabled={ isSaveDisabled }
						isBusy={ formState.isSubmitting }
					>
						{ formState.isSubmitting
							? __( 'Saving…', 'data-machine' )
							: __( 'Save Configuration', 'data-machine' ) }
					</Button>
				</div>
			</div>
		</Modal>
	);
}
