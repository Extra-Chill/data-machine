/**
 * Flow Step Card Component.
 *
 * Display individual flow step with handler configuration.
 */

/**
 * WordPress dependencies
 */
import { Button, Card, CardBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import FlowStepHandler from './FlowStepHandler';
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
 * @param {Array}    props.promptQueue    - Flow-level prompt queue.
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
	promptQueue = [],
	onConfigure,
	onQueueClick,
} ) {
	// Global config: Use stepTypes hook directly (TanStack Query handles caching)
	const { data: stepTypes = {} } = useStepTypes();
	const stepTypeInfo = stepTypes[ pipelineStep.step_type ] || {};
	const isAiStep = pipelineStep.step_type === 'ai';
	const aiConfig = isAiStep
		? pipelineConfig[ pipelineStep.pipeline_step_id ]
		: null;

	const queueCount = promptQueue.length;

	return (
		<Card
			className={ `datamachine-flow-step-card datamachine-step-type--${ pipelineStep.step_type }` }
			size="small"
		>
			<CardBody>
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
