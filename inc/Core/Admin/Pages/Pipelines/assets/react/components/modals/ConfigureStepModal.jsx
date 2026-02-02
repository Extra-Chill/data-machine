/**
 * Configure Step Modal Component
 *
 * Modal for configuring AI step settings: provider, model, tools, system prompt.
 * Agent Ping configuration is handled at flow level via handler config.
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect, useMemo } from '@wordpress/element';
import { Modal, Button, TextareaControl } from '@wordpress/components';
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
 * Configure Step Modal Component
 *
 * @param {Object}   props                - Component props
 * @param {Function} props.onClose        - Close handler
 * @param {number}   props.pipelineId     - Pipeline ID
 * @param {string}   props.pipelineStepId - Pipeline step ID
 * @param {string}   props.stepType       - Step type (currently only 'ai' supported)
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
			} ),
		[
			currentConfig?.provider,
			currentConfig?.model,
			currentConfig?.system_prompt,
			currentConfig?.enabled_tools,
		]
	);

	// Build initial data for AI step
	const initialData = useMemo( () => {
		return {
			provider: currentConfig?.provider || '',
			model: currentConfig?.model || '',
			systemPrompt: currentConfig?.system_prompt || '',
		};
	}, [ currentConfig ] );

	const formState = useFormState( {
		initialData,
		validate: ( data ) => {
			if ( ! data.provider ) {
				return __( 'Please select an AI provider', 'data-machine' );
			}
			if ( ! data.model ) {
				return __( 'Please select an AI model', 'data-machine' );
			}
			return null;
		},
		onSubmit: async ( data ) => {
			const response = await updateMutation.mutateAsync( {
				stepId: pipelineStepId,
				prompt: data.systemPrompt,
				provider: data.provider,
				model: data.model,
				enabledTools: selectedTools,
				stepType,
				pipelineId,
			} );

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

	// Use TanStack Query for tools data
	const { data: tools, isLoading: isLoadingTools } = useTools();

	// Pre-populate tools when data loads
	useEffect( () => {
		if ( isLoadingTools ) {
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
	}, [ configKey, tools, isLoadingTools ] );

	// Determine if defaults should be applied (only for new/unconfigured steps)
	const shouldApplyDefaults = ! currentConfig?.provider;

	// Determine if save button should be disabled
	const isSaveDisabled =
		formState.isSubmitting ||
		! formState.data.provider ||
		! formState.data.model;

	return (
		<Modal
			title={ __( 'Configure AI Step', 'data-machine' ) }
			onRequestClose={ onClose }
			className="datamachine-configure-step-modal"
		>
			<div className="datamachine-modal-content">
				{ formState.error && (
					<div className="datamachine-modal-error notice notice-error">
						<p>{ formState.error }</p>
					</div>
				) }

				<AIStepConfig
					formState={ formState }
					selectedTools={ selectedTools }
					setSelectedTools={ setSelectedTools }
					isLoadingTools={ isLoadingTools }
					shouldApplyDefaults={ shouldApplyDefaults }
				/>

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
