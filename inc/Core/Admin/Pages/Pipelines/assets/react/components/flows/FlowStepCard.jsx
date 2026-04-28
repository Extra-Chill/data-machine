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
 * @param {Object}   props.pipelineConfig - Pipeline AI configuration (currently unused; retained
 *                                          for API stability while callers still pass it).
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
	const usesHandler = stepTypeInfo.uses_handler === true;
	const isMultiHandlerStep = stepTypeInfo.multi_handler === true;
	const handlerSlugs = isMultiHandlerStep
		? ( flowStepConfig?.handler_slugs || [] )
		: ( flowStepConfig?.handler_slug ? [ flowStepConfig.handler_slug ] : [] );
	const primarySlug = handlerSlugs[0] || '';
	const primaryConfig = isMultiHandlerStep
		? ( primarySlug && flowStepConfig?.handler_configs?.[primarySlug] )
		: ( flowStepConfig?.handler_config || {} );

	const promptQueue = flowStepConfig.prompt_queue || [];
	const rawQueueMode = flowStepConfig.queue_mode;
	const queueMode = [ 'drain', 'loop', 'static' ].includes( rawQueueMode )
		? rawQueueMode
		: 'static';
	const queueHasItems = promptQueue.length > 0;
	// Static is the no-op mode; only drain/loop actively mutate the queue
	// per tick. Show the queue surface when the mode is active OR when items
	// are staged (so the user can manage a dormant stockpile).
	const shouldShowQueue = queueMode !== 'static' || queueHasItems;

	const [ error, setError ] = useState( null );

	// Determine the current prompt value based on step type.
	const currentPrompt = useMemo( () => {
		if ( isAiStep ) {
			return promptQueue[ 0 ]?.prompt || '';
		}
		// Support both top-level prompt (legacy) and nested params.prompt (system_task).
		return primaryConfig?.prompt || primaryConfig?.params?.prompt || '';
	}, [ isAiStep, promptQueue, primaryConfig ] );

	// Determine if this step type shows a prompt field.
	// AI steps always get it. Other steps get it if they have a prompt in handler_config
	// or if queue is enabled/has items.
	const hasPromptConfig = primaryConfig && (
		primaryConfig.prompt !== undefined ||
		primaryConfig.params?.prompt !== undefined
	);
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
				let config;
				if ( isAiStep ) {
					// The ability accepts the public `user_message` input and stores it as
					// a one-item static prompt_queue entry server-side.
					config = { user_message: value };
				} else if ( primaryConfig?.params?.prompt !== undefined ) {
					// system_task with nested params (e.g. agent_ping).
					config = {
						handler_config: {
							...( primaryConfig || {} ),
							params: {
								...( primaryConfig.params || {} ),
								prompt: value,
							},
						},
					};
				} else {
					config = {
						handler_config: {
							...( primaryConfig || {} ),
							prompt: value,
						},
					};
				}

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
		[ flowStepId, isAiStep, primaryConfig ]
	);

	const effectiveHandlerSlug = usesHandler ? primarySlug : pipelineStep.step_type;

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

					{ /* AI provider/model are not shown on the step card:
					   they are resolved server-side via the mode system
					   (PluginSettings::resolveModelForAgentMode), not from
					   per-step config. See data-machine#1180. */ }

					{ /* Inline Config Fields — flow step settings for non-handler step types only.
					   Handler-based steps show their settings in the configuration modal,
					   not inline on the card. Non-handler step types (Agent Ping, Webhook Gate)
					   render their fields here via the handler details API fallback. */ }
					{ ! usesHandler && effectiveHandlerSlug && (
					<InlineStepConfig
						flowStepId={ flowStepId }
						handlerConfig={ flowStepConfig?.handler_config || {} }
						handlerSlug={ effectiveHandlerSlug }
							excludeFields={ excludeFields }
							onError={ setError }
							pipelineId={ pipelineId }
							flowId={ flowId }
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
							queueMode={ queueMode }
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
						handlerSlug={ primarySlug || null }
							handlerSlugs={ handlerSlugs.length ? handlerSlugs : null }
							settingsDisplay={ flowStepConfig.settings_display || [] }
							handlerSettingsDisplays={ flowStepConfig.handler_settings_displays || null }
							onConfigure={ ( slug ) =>
								onConfigure && onConfigure( flowStepId, slug )
							}
							onAddHandler={ isMultiHandlerStep ? () =>
								onConfigure && onConfigure( flowStepId, null, true )
							: undefined }
							showConfigureButton
							showBadge
						/>
					) }
				</div>
			</CardBody>
		</Card>
	);
}
