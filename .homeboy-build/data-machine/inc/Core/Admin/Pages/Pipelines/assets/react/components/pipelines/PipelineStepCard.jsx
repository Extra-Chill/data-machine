/**
 * Pipeline Step Card Component
 *
 * Display individual pipeline step with configuration.
 * AI step system prompt is editable inline — no modal needed.
 */

/**
 * WordPress dependencies
 */
import { useCallback } from '@wordpress/element';
import { Card, CardBody, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import PromptField from '@shared/components/PromptField';
import { updateSystemPrompt } from '../../utils/api';
import { useStepTypes } from '../../queries/config';

/**
 * Normalize a tool policy field into displayable labels.
 *
 * @param {Array|string|Object} value Tool policy field value.
 * @return {Array<string>} Display labels.
 */
const normalizeToolPolicyList = ( value ) => {
	if ( Array.isArray( value ) ) {
		return value.filter( Boolean ).map( String );
	}

	if ( typeof value === 'string' && value ) {
		return [ value ];
	}

	if ( value && typeof value === 'object' ) {
		return Object.entries( value )
			.filter( ( [ , enabled ] ) => Boolean( enabled ) )
			.map( ( [ key ] ) => key );
	}

	return [];
};

/**
 * Render a read-only tool policy row.
 *
 * @param {string}        label  Policy label.
 * @param {Array<string>} values Policy values.
 * @return {React.ReactElement|null} Policy row.
 */
const renderToolPolicyRow = ( label, values ) => {
	if ( ! values.length ) {
		return null;
	}

	return (
		<div className="datamachine-ai-tool-policy-row">
			<strong>{ label }</strong>
			<div className="datamachine-ai-tool-policy-values">
				{ values.map( ( value ) => (
					<code key={ value }>{ value }</code>
				) ) }
			</div>
		</div>
	);
};

/**
 * Pipeline Step Card Component
 *
 * @param {Object}   props                - Component props
 * @param {Object}   props.step           - Step data
 * @param {number}   props.pipelineId     - Pipeline ID
 * @param {Object}   props.pipelineConfig - AI configuration keyed by pipeline_step_id
 * @param {Function} props.onDelete       - Delete handler
 * @return {React.ReactElement} Pipeline step card
 */
export default function PipelineStepCard( {
	step,
	pipelineId,
	pipelineConfig,
	onDelete,
} ) {
	// Use TanStack Query for data
	const { data: stepTypes = {} } = useStepTypes();
	const isAiStep = step.step_type === 'ai';
	const isSystemTask = step.step_type === 'system_task';

	const stepConfig = pipelineConfig[ step.pipeline_step_id ] || null;
	const enabledTools = normalizeToolPolicyList( stepConfig?.enabled_tools );
	const disabledTools = normalizeToolPolicyList( stepConfig?.disabled_tools );
	const toolCategories = normalizeToolPolicyList( stepConfig?.tool_categories );
	const hasToolPolicy = Boolean(
		enabledTools.length || disabledTools.length || toolCategories.length
	);

	// Resolve display label: step type registry, then legacy fallback for agent_ping.
	const displayLabel = stepTypes[ step.step_type ]?.label
		|| ( step.step_type === 'agent_ping' ? __( 'Agent Ping', 'data-machine' ) : step.step_type );

	// For system_task steps, show the task name badge (e.g. "Agent Ping").
	const systemTaskName = isSystemTask
		? ( stepConfig?.handler_config?.task || step.handler_config?.task || '' )
		: '';

	/**
	 * Save system prompt to API (AI steps)
	 */
	const handleSavePrompt = useCallback(
		async ( prompt ) => {
			if ( ! stepConfig ) {
				return { success: false, message: 'No configuration found' };
			}

			const currentPrompt = stepConfig.system_prompt || '';
			if ( prompt === currentPrompt ) {
				return { success: true };
			}

			try {
				const response = await updateSystemPrompt(
					step.pipeline_step_id,
					prompt,
					step.step_type,
					pipelineId
				);

				if ( ! response.success ) {
					return {
						success: false,
						message:
							response.message ||
							__( 'Failed to update prompt', 'data-machine' ),
					};
				}

				return { success: true };
			} catch ( err ) {
				console.error( 'Prompt update error:', err );
				return {
					success: false,
					message:
						err.message ||
						__( 'An error occurred', 'data-machine' ),
				};
			}
		},
		[ pipelineId, step.pipeline_step_id, step.step_type, stepConfig ]
	);

	/**
	 * Handle step deletion
	 */
	const handleDelete = useCallback( () => {
		const confirmed = window.confirm(
			__( 'Are you sure you want to remove this step?', 'data-machine' )
		);

		if ( confirmed && onDelete ) {
			onDelete( step.pipeline_step_id );
		}
	}, [ step.pipeline_step_id, onDelete ] );

	return (
		<Card
			className={ `datamachine-pipeline-step-card datamachine-step-type--${ step.step_type }` }
			size="small"
		>
			<CardBody>
				<div className="datamachine-step-card-header">
					<strong>
						{ displayLabel }
					</strong>
					{ systemTaskName && (
						<span className="datamachine-step-card-task-badge">
							{ systemTaskName }
						</span>
					) }
				</div>

				{ /* AI Step: inline system prompt editor */ }
				{ isAiStep && stepConfig && (
					<div className="datamachine-ai-config-display datamachine-step-card-ai-config">
						<PromptField
							label={ __( 'System Prompt', 'data-machine' ) }
							value={ stepConfig.system_prompt || '' }
							onSave={ handleSavePrompt }
							placeholder={ __(
								'Enter system prompt for AI processing…',
								'data-machine'
							) }
							rows={ 6 }
						/>
						{ hasToolPolicy && (
							<div className="datamachine-ai-tool-policy-summary">
								<h4>
									{ __( 'Tool Policy', 'data-machine' ) }
								</h4>
								<p>
									{ __(
										'Read-only summary of the pipeline AI step policy. Handler tools required by adjacent steps are resolved separately at runtime.',
										'data-machine'
									) }
								</p>
								{ renderToolPolicyRow(
									__( 'Allowlist', 'data-machine' ),
									enabledTools
								) }
								{ renderToolPolicyRow(
									__( 'Denylist', 'data-machine' ),
									disabledTools
								) }
								{ renderToolPolicyRow(
									__( 'Categories', 'data-machine' ),
									toolCategories
								) }
							</div>
						) }
					</div>
				) }

				{ /* Action Buttons */ }
				<div className="datamachine-step-card-actions">
					<Button
						variant="secondary"
						size="small"
						isDestructive
						onClick={ handleDelete }
					>
						{ __( 'Delete', 'data-machine' ) }
					</Button>
				</div>
			</CardBody>
		</Card>
	);
}
