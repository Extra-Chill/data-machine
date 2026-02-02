/**
 * Pipeline Step Card Component
 *
 * Display individual pipeline step with configuration.
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import {
	Card,
	CardBody,
	Button,
	TextareaControl,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import { updateSystemPrompt } from '../../utils/api';
import { AUTO_SAVE_DELAY } from '../../utils/constants';
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
	const aiConfig =
		step.step_type === 'ai'
			? pipelineConfig[ step.pipeline_step_id ]
			: null;

	const [ localPrompt, setLocalPrompt ] = useState(
		aiConfig?.system_prompt || ''
	);
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const saveTimeout = useRef( null );

	/**
	 * Sync local prompt with config changes
	 */
	useEffect( () => {
		if ( aiConfig ) {
			setLocalPrompt( aiConfig.system_prompt || '' );
		}
	}, [ aiConfig ] );

	/**
	 * Save system prompt to API
	 */
	const savePrompt = useCallback(
		async ( prompt ) => {
			if ( ! aiConfig ) {return;}

			const currentPrompt = aiConfig.system_prompt || '';
			if ( prompt === currentPrompt ) {return;}

			setIsSaving( true );
			setError( null );

			try {
				const response = await updateSystemPrompt(
					step.pipeline_step_id,
					prompt,
					aiConfig.provider,
					aiConfig.model,
					[], // enabledTools - not available in inline editing
					step.step_type,
					pipelineId
				);

				if ( ! response.success ) {
					setError(
						response.message ||
							__( 'Failed to update prompt', 'data-machine' )
					);
					setLocalPrompt( currentPrompt ); // Revert on error
				}
			} catch ( err ) {
				console.error( 'Prompt update error:', err );
				setError(
					err.message || __( 'An error occurred', 'data-machine' )
				);
				setLocalPrompt( currentPrompt ); // Revert on error
			} finally {
				setIsSaving( false );
			}
		},
		[ pipelineId, step.pipeline_step_id, step.step_type, aiConfig ]
	);

	/**
	 * Handle prompt change with debouncing
	 */
	const handlePromptChange = useCallback(
		( value ) => {
			setLocalPrompt( value );

			// Clear existing timeout
			if ( saveTimeout.current ) {
				clearTimeout( saveTimeout.current );
			}

			// Set new timeout for debounced save
			saveTimeout.current = setTimeout( () => {
				savePrompt( value );
			}, AUTO_SAVE_DELAY );
		},
		[ savePrompt ]
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
			className={ `datamachine-pipeline-step-card datamachine-step-type--${ step.step_type }` }
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

				<div className="datamachine-step-card-header">
					<strong>
						{ stepTypes[ step.step_type ]?.label || step.step_type }
					</strong>
				</div>

				{ /* AI Configuration Display */ }
				{ aiConfig && (
					<div className="datamachine-ai-config-display datamachine-step-card-ai-config">
						<div className="datamachine-step-card-ai-label">
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
						<div className="datamachine-step-card-tools-label">
							<strong>{ __( 'Tools:', 'data-machine' ) }</strong>{ ' ' }
							{ ( () => {
								/*
								 * Tools display logic:
								 * - Global settings = source of truth for NEW steps (defaults)
								 * - Steps can DISABLE globally-enabled tools (override)
								 * - Steps CANNOT enable globally-disabled tools
								 *
								 * Detection:
								 * - enabled_tools is Array → explicitly configured (use as-is, even if empty)
								 * - enabled_tools is undefined → never configured → show global defaults
								 */
								const isExplicitlyConfigured = Array.isArray(
									aiConfig.enabled_tools
								);
								const effectiveTools = isExplicitlyConfigured
									? aiConfig.enabled_tools
									: Object.entries( toolsData )
											.filter(
												( [ , tool ] ) =>
													tool.globally_enabled
											)
											.map( ( [ id ] ) => id );

								if ( effectiveTools.length === 0 ) {
									return isExplicitlyConfigured
										? __( 'None (disabled)', 'data-machine' )
										: __( 'None configured', 'data-machine' );
								}

								return effectiveTools
									.map(
										( toolId ) =>
											toolsData[ toolId ]?.label || toolId
									)
									.join( ', ' );
							} )() }
						</div>

						<TextareaControl
							label={ __( 'System Prompt', 'data-machine' ) }
							value={ localPrompt }
							onChange={ handlePromptChange }
							placeholder={ __(
								'Enter system prompt for AI processing…',
								'data-machine'
							) }
							rows={ 6 }
							help={
								isSaving
									? __( 'Saving…', 'data-machine' )
									: null
							}
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
