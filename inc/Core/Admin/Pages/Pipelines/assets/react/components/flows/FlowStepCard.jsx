/**
 * Flow Step Card Component.
 *
 * Schema-driven display for individual flow steps. All step types render
 * through the same path — no hardcoded step type branching.
 */

/**
 * WordPress dependencies
 */
import { useState, useMemo, useCallback } from '@wordpress/element';
import { Card, CardBody, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import FlowStepHandler from './FlowStepHandler';
import QueueablePromptField from './QueueablePromptField';
import InlineStepConfig from './InlineStepConfig';
import { updateFlowStepConfig } from '../../utils/api';
import { useStepTypes } from '../../queries/config';

/**
 * Flow Step Card Component.
 *
 * @param {Object}   props                - Component props.
 * @param {number}   props.flowId         - Flow ID.
 * @param {number}   props.pipelineId     - Pipeline ID.
 * @param {string}   props.flowStepId     - Flow step ID.
 * @param {Object}   props.flowStepConfig - Flow step configuration.
 * @param {Object}   props.pipelineStep   - Pipeline step data.
 * @param {Object}   props.pipelineConfig - Pipeline AI configuration.
 * @param {Function} props.onConfigure    - Configure handler callback.
 * @param {Function} props.onQueueClick   - Queue button click handler (opens modal).
 * @return {JSX.Element} Flow step card.
 */
export default function FlowStepCard( {
	flowId,
	pipelineId,
	flowStepId,
	flowStepConfig,
	pipelineStep,
	pipelineConfig,
	onConfigure,
	onQueueClick,
} ) {
	const { data: stepTypes = {} } = useStepTypes();
	const stepTypeInfo = stepTypes[ pipelineStep.step_type ] || {};

	const isAiStep = pipelineStep.step_type === 'ai';
	const aiConfig = isAiStep
		? pipelineConfig[ pipelineStep.pipeline_step_id ]
		: null;

	const promptQueue = flowStepConfig.prompt_queue || [];
	const queueEnabled = !! flowStepConfig.queue_enabled;
	const queueHasItems = promptQueue.length > 0;
	const shouldShowQueue = queueEnabled || queueHasItems;

	const [ error, setError ] = useState( null );

	// Determine the current prompt value based on step type.
	const currentPrompt = useMemo( () => {
		if ( isAiStep ) {
			return flowStepConfig.user_message || '';
		}
		return flowStepConfig?.handler_config?.prompt || '';
	}, [ isAiStep, flowStepConfig.user_message, flowStepConfig?.handler_config?.prompt ] );

	// Determine if this step type shows a prompt field.
	// AI steps always get it. Other steps get it if they have a prompt in handler_config
	// or if queue is enabled/has items.
	const hasPromptConfig = flowStepConfig?.handler_config?.prompt !== undefined;
	const showPromptField = isAiStep || shouldShowQueue || hasPromptConfig;

	// Fields to exclude from inline config (handled by QueueablePromptField).
	const excludeFields = useMemo( () => {
		const excluded = [ 'prompt' ]; // Always exclude prompt — handled by QueueablePromptField.
		return excluded;
	}, [] );

	/**
	 * Save prompt for non-queue mode.
	 */
	const handlePromptSave = useCallback(
		async ( value ) => {
			try {
				const config = isAiStep
					? { user_message: value }
					: { handler_config: { ...( flowStepConfig?.handler_config || {} ), prompt: value } };

				const response = await updateFlowStepConfig( flowStepId, config );
				if ( ! response?.success ) {
					setError( response?.message || __( 'Failed to save', 'data-machine' ) );
				}
			} catch ( err ) {
				// eslint-disable-next-line no-console
				console.error( 'Prompt save error:', err );
				setError( err.message || __( 'An error occurred', 'data-machine' ) );
			}
		},
		[ flowStepId, isAiStep, flowStepConfig?.handler_config ]
	);

	// Resolve handler info for settings display.
	const usesHandler = stepTypeInfo.uses_handler !== '' && stepTypeInfo.uses_handler !== false;
	const effectiveHandlerSlug = usesHandler ? flowStepConfig.handler_slug : pipelineStep.step_type;

	return (
		<Card
			className={ `datamachine-flow-step-card datamachine-step-type--${ pipelineStep.step_type }` }
			size="small"
		>
			<CardBody>
				{ error && (
					<Notice
						status="error"
						isDismissible
						onRemove={ () => setError( null ) }
					>
						{ error }
					</Notice>
				) }

				<div className="datamachine-step-content">
					<div className="datamachine-step-header-row">
						<strong>
							{ stepTypeInfo.label || pipelineStep.step_type }
						</strong>
					</div>

					{ /* AI Provider/Model Display */ }
					{ isAiStep && aiConfig && (
						<div className="datamachine-ai-config-display">
							<div className="datamachine-ai-provider-info">
								<strong>
									{ __( 'AI Provider:', 'data-machine' ) }
								</strong>{ ' ' }
								{ aiConfig.provider || 'Not configured' }
								{ ' | ' }
								<strong>
									{ __( 'Model:', 'data-machine' ) }
								</strong>{ ' ' }
								{ aiConfig.model || 'Not configured' }
							</div>
						</div>
					) }

					{ /* Inline Config Fields (schema-driven from handler details API).
					   Renders for any step type with registered fields — the component
					   self-determines whether to render based on API response (returns
					   null when no fields exist). Not gated by show_settings_display,
					   which controls the PHP-side read-only summary, not the React editor. */ }
					{ effectiveHandlerSlug && (
						<InlineStepConfig
							flowStepId={ flowStepId }
							handlerConfig={ flowStepConfig?.handler_config || {} }
							handlerSlug={ effectiveHandlerSlug }
							excludeFields={ excludeFields }
							onError={ setError }
						/>
					) }

					{ /* Prompt Field with Queue Integration */ }
					{ showPromptField && (
						<QueueablePromptField
							flowId={ flowId }
							flowStepId={ flowStepId }
							pipelineId={ pipelineId }
							prompt={ currentPrompt }
							promptQueue={ promptQueue }
							queueEnabled={ queueEnabled }
							placeholder={
								isAiStep
									? __( 'Enter user message for AI processing…', 'data-machine' )
									: __( 'Enter instructions…', 'data-machine' )
							}
							label={ __( 'User Message', 'data-machine' ) }
							onSave={ handlePromptSave }
							onQueueClick={ onQueueClick }
							onError={ setError }
						/>
					) }

					{ /* Handler Configuration (handler-based steps only) */ }
					{ usesHandler && (
						<FlowStepHandler
							handlerSlug={ flowStepConfig.handler_slug || null }
							settingsDisplay={ flowStepConfig.settings_display || [] }
							onConfigure={ () =>
								onConfigure && onConfigure( flowStepId )
							}
							showConfigureButton
							showBadge
						/>
					) }
				</div>
			</CardBody>
		</Card>
	);
}
