/**
 * Flow Step Card Component.
 *
 * Display individual flow step with handler configuration.
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { Button, Card, CardBody, TextareaControl, TextControl, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import FlowStepHandler from './FlowStepHandler';
import { useUpdateQueueItem, useAddToQueue } from '../../queries/queue';
import { updateFlowStepConfig } from '../../utils/api';
import { AUTO_SAVE_DELAY } from '../../utils/constants';
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
	// Global config: Use stepTypes hook directly (TanStack Query handles caching)
	const { data: stepTypes = {} } = useStepTypes();
	const stepTypeInfo = stepTypes[ pipelineStep.step_type ] || {};
	const isAiStep = pipelineStep.step_type === 'ai';
	const isAgentPing = pipelineStep.step_type === 'agent_ping';
	const aiConfig = isAiStep
		? pipelineConfig[ pipelineStep.pipeline_step_id ]
		: null;

	const promptQueue = flowStepConfig.prompt_queue || [];
	const queueEnabled = !! flowStepConfig.queue_enabled;
	const queueCount = promptQueue.length;
	const queueHasItems = queueCount > 0;
	const firstQueuePrompt = queueHasItems ? promptQueue[ 0 ].prompt : '';
	const shouldUseQueue = queueEnabled || queueHasItems;

	const [ localUserMessage, setLocalUserMessage ] = useState(
		firstQueuePrompt || flowStepConfig.user_message || ''
	);
	const [ localAgentPingPrompt, setLocalAgentPingPrompt ] = useState(
		firstQueuePrompt || flowStepConfig?.handler_config?.prompt || ''
	);
	const [ localWebhookUrl, setLocalWebhookUrl ] = useState(
		flowStepConfig?.handler_config?.webhook_url || ''
	);
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const saveTimeout = useRef( null );
	const updateQueueItemMutation = useUpdateQueueItem();
	const addToQueueMutation = useAddToQueue();

	/**
	 * Sync local user message with queue/config changes
	 */
	useEffect( () => {
		// Priority: queue[0] > user_message
		const newValue = firstQueuePrompt || flowStepConfig.user_message || '';
		setLocalUserMessage( newValue );
	}, [ firstQueuePrompt, flowStepConfig.user_message ] );

	useEffect( () => {
		const newValue =
			firstQueuePrompt || flowStepConfig?.handler_config?.prompt || '';
		setLocalAgentPingPrompt( newValue );
	}, [ firstQueuePrompt, flowStepConfig?.handler_config?.prompt ] );

	useEffect( () => {
		setLocalWebhookUrl( flowStepConfig?.handler_config?.webhook_url || '' );
	}, [ flowStepConfig?.handler_config?.webhook_url ] );

	/**
	 * Save user message to queue (add if empty, update index 0 if exists)
	 */
	const saveToQueue = useCallback(
		async ( message ) => {
			if ( ! isAiStep && ! isAgentPing ) {
				return;
			}

			if ( ! shouldUseQueue ) {
				return;
			}

			// Skip if message is empty (don't add empty items)
			if ( ! message.trim() ) {
				return;
			}

			// Compare to current queue value
			const currentMessage = firstQueuePrompt || '';
			if ( message === currentMessage ) {
				return;
			}

			setIsSaving( true );
			setError( null );

			try {
				let response;

				if ( queueHasItems ) {
					// Update existing queue[0]
					response = await updateQueueItemMutation.mutateAsync( {
						flowId,
						flowStepId,
						index: 0,
						prompt: message,
					} );
				} else {
					// Add new item when queue is empty
					response = await addToQueueMutation.mutateAsync( {
						flowId,
						flowStepId,
						prompt: message,
					} );
				}

				if ( ! response?.success ) {
					setError(
						response?.message ||
							__( 'Failed to save prompt', 'data-machine' )
					);
					setLocalUserMessage( currentMessage );
				}
			} catch ( err ) {
				// eslint-disable-next-line no-console
				console.error( 'Queue save error:', err );
				setError(
					err.message || __( 'An error occurred', 'data-machine' )
				);
				setLocalUserMessage( currentMessage );
			} finally {
				setIsSaving( false );
			}
		},
		[
			flowId,
			flowStepId,
			firstQueuePrompt,
			queueHasItems,
			isAiStep,
			isAgentPing,
			shouldUseQueue,
			updateQueueItemMutation,
			addToQueueMutation,
		]
	);

	const saveStepConfig = useCallback(
		async ( config, onErrorRevert ) => {
			setIsSaving( true );
			setError( null );

			try {
				const response = await updateFlowStepConfig( flowStepId, config );
				if ( ! response?.success ) {
					setError(
						response?.message ||
							__( 'Failed to save settings', 'data-machine' )
					);
					if ( onErrorRevert ) {
						onErrorRevert();
					}
				}
			} catch ( err ) {
				// eslint-disable-next-line no-console
				console.error( 'Flow step save error:', err );
				setError(
					err.message || __( 'An error occurred', 'data-machine' )
				);
				if ( onErrorRevert ) {
					onErrorRevert();
				}
			} finally {
				setIsSaving( false );
			}
		},
		[ flowStepId ]
	);

	const handleAgentPingPromptChange = useCallback(
		( value ) => {
			setLocalAgentPingPrompt( value );

			if ( saveTimeout.current ) {
				clearTimeout( saveTimeout.current );
			}

			saveTimeout.current = setTimeout( () => {
				if ( shouldUseQueue ) {
					saveToQueue( value );
					return;
				}

				saveStepConfig(
					{
						handler_config: {
							webhook_url: localWebhookUrl,
							prompt: value,
						},
					},
					() => setLocalAgentPingPrompt( localAgentPingPrompt )
				);
			}, AUTO_SAVE_DELAY );
		},
		[
			shouldUseQueue,
			saveToQueue,
			saveStepConfig,
			localWebhookUrl,
			localAgentPingPrompt,
		]
	);

	const handleWebhookUrlChange = useCallback(
		( value ) => {
			setLocalWebhookUrl( value );

			if ( saveTimeout.current ) {
				clearTimeout( saveTimeout.current );
			}

			saveTimeout.current = setTimeout( () => {
				saveStepConfig(
					{
						handler_config: {
							webhook_url: value,
							prompt: localAgentPingPrompt,
						},
					},
					() => setLocalWebhookUrl( localWebhookUrl )
				);
			}, AUTO_SAVE_DELAY );
		},
		[ saveStepConfig, localAgentPingPrompt, localWebhookUrl ]
	);

	/**
	 * Handle user message change with debouncing
	 */
	const handleUserMessageChange = useCallback(
		( value ) => {
			setLocalUserMessage( value );

			// Clear existing timeout
			if ( saveTimeout.current ) {
				clearTimeout( saveTimeout.current );
			}

			saveTimeout.current = setTimeout( () => {
				if ( shouldUseQueue ) {
					saveToQueue( value );
					return;
				}

				saveStepConfig(
					{ user_message: value },
					() => setLocalUserMessage( localUserMessage )
				);
			}, AUTO_SAVE_DELAY );
		},
		[ shouldUseQueue, saveToQueue, saveStepConfig, localUserMessage ]
	);

	const getPromptValue = () => {
		if ( shouldUseQueue ) {
			return queueHasItems ? firstQueuePrompt : '';
		}
		if ( isAiStep ) {
			return localUserMessage;
		}
		return localAgentPingPrompt;
	};

	/**
	 * Cleanup timeout on unmount
	 */
	useEffect( () => {
		return () => {
			if ( saveTimeout.current ) {
				clearTimeout( saveTimeout.current );
			}
		};
	}, [] );

	/**
	 * Build the label with queue indicator
	 */
	const getFieldLabel = () => {
		if ( queueHasItems ) {
			return (
				<span className="datamachine-user-message-label">
					{ __( 'User Message', 'data-machine' ) }
					<span className="datamachine-queue-indicator">
						{ ' ' }
						<span className="datamachine-queue-badge">
							{ __( 'Next in queue', 'data-machine' ) }
						</span>
					</span>
				</span>
			);
		}
		return __( 'User Message', 'data-machine' );
	};

	/**
	 * Build help text
	 */
	const getHelpText = () => {
		if ( isSaving ) {
			return __( 'Saving…', 'data-machine' );
		}
		if ( shouldUseQueue ) {
			if ( queueHasItems ) {
				return __(
					'Editing updates the first item in the prompt queue.',
					'data-machine'
				);
			}
			return __(
				'Queue enabled. Type a prompt to add it to the queue. Use Manage Queue for multiple prompts.',
				'data-machine'
			);
		}
		return __(
			'Type a prompt to save it directly. Enable the queue to pop prompts in order.',
			'data-machine'
		);
	};

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

				{ /* AI Configuration Display */ }
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

						{ /* Prompt Field - shows/edits queue[0] */ }
						<TextareaControl
							label={ getFieldLabel() }
							value={ getPromptValue() }
							onChange={ handleUserMessageChange }
							placeholder={ __(
								'Enter user message for AI processing…',
								'data-machine'
							) }
							rows={ 4 }
							help={ getHelpText() }
							className={
								queueHasItems
									? 'datamachine-queue-linked'
									: ''
							}
						/>

						{ /* Queue Management Button */ }
						<div className="datamachine-queue-actions">
							<Button
								variant="secondary"
								size="small"
								onClick={ onQueueClick }
							>
								{ __( 'Manage Queue', 'data-machine' ) }
								{ ' ' }
								<span
									className={ `datamachine-queue-count ${
										queueCount > 0
											? 'datamachine-queue-count--active'
											: ''
									}` }
								>
									({ queueCount })
								</span>
							</Button>
						</div>
					</div>
				) }

				{ /* Agent Ping Configuration */ }
				{ isAgentPing && (
					<div className="datamachine-agent-ping-config">
						<TextControl
							label={ __( 'Webhook URL', 'data-machine' ) }
							value={ localWebhookUrl }
							onChange={ handleWebhookUrlChange }
							placeholder={ __(
								'Enter webhook URL…',
								'data-machine'
							) }
							help={ __(
								'URL to POST data to (Discord, Slack, custom endpoint).',
								'data-machine'
							) }
							className="datamachine-agent-ping-webhook"
						/>

						<TextareaControl
							label={ getFieldLabel() }
							value={ getPromptValue() }
							onChange={ handleAgentPingPromptChange }
							placeholder={ __(
								'Enter instructions for the agent…',
								'data-machine'
							) }
							rows={ 4 }
							help={ getHelpText() }
							className={
								queueHasItems
									? 'datamachine-queue-linked'
									: ''
							}
						/>

						<div className="datamachine-queue-actions">
							<Button
								variant="secondary"
								size="small"
								onClick={ onQueueClick }
							>
								{ __( 'Manage Queue', 'data-machine' ) }
								{ ' ' }
								<span
									className={ `datamachine-queue-count ${
										queueCount > 0
											? 'datamachine-queue-count--active'
											: ''
									}` }
								>
									({ queueCount })
								</span>
							</Button>
						</div>
					</div>
				) }

				{ /* Handler Configuration */ }
					{ ( () => {
						const handlerStepTypeInfo =
							stepTypes[ pipelineStep.step_type ] || {};
						// Falsy check: PHP false becomes "" in JSON, but undefined means still loading
						const usesHandler =
							handlerStepTypeInfo.uses_handler !== '' &&
							handlerStepTypeInfo.uses_handler !== false;

						// For steps that don't use handlers (e.g., agent_ping),
						// use the step_type as the effective handler slug for settings display
						const effectiveHandlerSlug = usesHandler
							? flowStepConfig.handler_slug
							: pipelineStep.step_type;

						// Handler-based step with no handler configured - show configure button
						if ( usesHandler && ! flowStepConfig.handler_slug ) {
							return (
								<FlowStepHandler
									handlerSlug={ null }
									settingsDisplay={ [] }
									onConfigure={ () =>
										onConfigure && onConfigure( flowStepId )
									}
								/>
							);
						}

						// Show settings display (works for both handler steps and non-handler steps like agent_ping)
						// Non-handler steps don't need Configure button or badge (configured at pipeline level)
						return (
							<FlowStepHandler
								handlerSlug={ effectiveHandlerSlug }
								settingsDisplay={
									flowStepConfig.settings_display || []
								}
								onConfigure={ () =>
									onConfigure && onConfigure( flowStepId )
								}
								showConfigureButton={ usesHandler }
								showBadge={ usesHandler }
							/>
						);
					} )() }
				</div>
			</CardBody>
		</Card>
	);
}
