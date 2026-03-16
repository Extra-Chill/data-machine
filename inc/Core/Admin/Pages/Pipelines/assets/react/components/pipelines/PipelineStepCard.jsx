/**
 * Pipeline Step Card Component
 *
 * Display individual pipeline step with configuration.
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
import { useStepTypes, useTools } from '../../queries/config';

/**
 * Pipeline Step Card Component
 *
 * @param {Object}   props                - Component props
 * @param {Object}   props.step           - Step data
 * @param {number}   props.pipelineId     - Pipeline ID
 * @param {Object}   props.pipelineConfig - AI configuration keyed by pipeline_step_id
 * @param {Function} props.onDelete       - Delete handler
 * @param {Function} props.onConfigure    - Configure handler
 * @return {React.ReactElement} Pipeline step card
 */
export default function PipelineStepCard( {
	step,
	pipelineId,
	pipelineConfig,
	onDelete,
	onConfigure,
} ) {
	// Use TanStack Query for data
	const { data: stepTypes = {} } = useStepTypes();
	const { data: toolsData = {} } = useTools();
	const stepTypeInfo = stepTypes?.[ step.step_type ] || {};
	const canConfigure = stepTypeInfo.has_pipeline_config === true;

	const isAiStep = step.step_type === 'ai';

	const stepConfig = pipelineConfig[ step.pipeline_step_id ] || null;

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
					stepConfig.provider,
					stepConfig.model,
					[], // disabledTools - empty means no tools disabled (use all global)
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
						{ stepTypes[ step.step_type ]?.label || step.step_type }
					</strong>
				</div>

				{ /* AI Configuration Display */ }
				{ isAiStep && stepConfig && (
					<div className="datamachine-ai-config-display datamachine-step-card-ai-config">
						<div className="datamachine-step-card-ai-label">
							<strong>
								{ __( 'AI Provider:', 'data-machine' ) }
							</strong>{ ' ' }
							{ stepConfig.provider || 'Not configured' }
							{ ' | ' }
							<strong>
								{ __( 'Model:', 'data-machine' ) }
							</strong>{ ' ' }
							{ stepConfig.model || 'Not configured' }
						</div>
						<div className="datamachine-step-card-tools-label">
							<strong>{ __( 'Tools:', 'data-machine' ) }</strong>{ ' ' }
							{ ( () => {
								// Get all globally-enabled tools
								const globallyEnabledTools = Object.entries(
									toolsData
								)
									.filter(
										( [ , tool ] ) => tool.globally_enabled
									)
									.map( ( [ id ] ) => id );

								// disabled_tools is exclusion list
								const disabledTools = Array.isArray(
									stepConfig.disabled_tools
								)
									? stepConfig.disabled_tools
									: [];

								// Effective tools = globally enabled minus disabled
								const effectiveTools =
									globallyEnabledTools.filter(
										( toolId ) =>
											! disabledTools.includes( toolId )
									);

								if ( effectiveTools.length === 0 ) {
									return globallyEnabledTools.length === 0
										? __(
												'None configured',
												'data-machine'
										  )
										: __(
												'None (all disabled)',
												'data-machine'
										  );
								}

								return effectiveTools
									.map(
										( toolId ) =>
											toolsData[ toolId ]?.label || toolId
									)
									.join( ', ' );
							} )() }
						</div>

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
					</div>
				) }

				{ /* Action Buttons */ }
				<div className="datamachine-step-card-actions">
					{ canConfigure && (
						<Button
							variant="secondary"
							size="small"
							onClick={ () => onConfigure && onConfigure( step ) }
						>
							{ __( 'Configure', 'data-machine' ) }
						</Button>
					) }

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
