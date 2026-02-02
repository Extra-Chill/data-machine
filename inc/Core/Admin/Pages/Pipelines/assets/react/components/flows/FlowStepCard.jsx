/**
 * Flow Step Card Component.
 *
 * Display individual flow step with handler configuration.
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { Card, CardBody, TextareaControl, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import FlowStepHandler from './FlowStepHandler';
import { useUpdateUserMessage } from '../../queries/flows';
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
} ) {
	// Global config: Use stepTypes hook directly (TanStack Query handles caching)
	const { data: stepTypes = {} } = useStepTypes();
	const stepTypeInfo = stepTypes[ pipelineStep.step_type ] || {};
	const isAiStep = pipelineStep.step_type === 'ai';
	const aiConfig = isAiStep
		? pipelineConfig[ pipelineStep.pipeline_step_id ]
		: null;

	const [ localUserMessage, setLocalUserMessage ] = useState(
		flowStepConfig.user_message || ''
	);
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const saveTimeout = useRef( null );
	const updateUserMessageMutation = useUpdateUserMessage();

	/**
	 * Sync local user message with config changes
	 */
	useEffect( () => {
		setLocalUserMessage( flowStepConfig.user_message || '' );
	}, [ flowStepConfig.user_message ] );

	/**
	 * Save user message to API
	 */
	const saveUserMessage = useCallback(
		async ( message ) => {
			if ( ! isAiStep ) {
				return;
			}

			const currentMessage = flowStepConfig.user_message || '';
			if ( message === currentMessage ) {
				return;
			}

			setIsSaving( true );
			setError( null );

			try {
				const response = await updateUserMessageMutation.mutateAsync( {
					pipelineId,
					flowId,
					flowStepId,
					message,
				} );

				if ( ! response?.success ) {
					setError(
						response?.message ||
							__( 'Failed to update user message', 'data-machine' )
					);
					setLocalUserMessage( currentMessage );
				}
			} catch ( err ) {
				// eslint-disable-next-line no-console
				console.error( 'User message update error:', err );
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
			pipelineId,
			flowStepId,
			flowStepConfig.user_message,
			isAiStep,
			updateUserMessageMutation,
		]
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

			// Set new timeout for debounced save
			saveTimeout.current = setTimeout( () => {
				saveUserMessage( value );
			}, AUTO_SAVE_DELAY );
		},
		[ saveUserMessage ]
	);

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

							<TextareaControl
								label={ __( 'User Message', 'data-machine' ) }
								value={ localUserMessage }
								onChange={ handleUserMessageChange }
								placeholder={ __(
									'Enter user message for AI processing…',
									'data-machine'
								) }
								rows={ 4 }
								help={
									isSaving
										? __( 'Saving…', 'data-machine' )
										: null
								}
							/>
						</div>
					) }

					{ /* Handler Configuration */ }
					{ ( () => {
						const handlerStepTypeInfo =
							stepTypes[ pipelineStep.step_type ] || {};
						const usesHandler =
							handlerStepTypeInfo.uses_handler !== false; // Default true for safety

						// For steps that don't use handlers (e.g., agent_ping),
						// use the step_type as the effective handler slug for config display
						const effectiveHandlerSlug = usesHandler
							? flowStepConfig.handler_slug
							: pipelineStep.step_type;

						// Only skip rendering if this is a handler-based step with no handler configured
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

						return (
							<FlowStepHandler
								handlerSlug={ effectiveHandlerSlug }
								settingsDisplay={
									flowStepConfig.settings_display || []
								}
								onConfigure={ () =>
									onConfigure && onConfigure( flowStepId )
								}
							/>
						);
					} )() }
				</div>
			</CardBody>
		</Card>
	);
}
