/**
 * ClearProcessedForm Component
 *
 * Form for clearing processed items by pipeline or flow.
 */

/**
 * WordPress dependencies
 */
import { useState, useCallback } from '@wordpress/element';
import { Button, SelectControl, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import {
	usePipelinesForDropdown,
	useFlowsForDropdown,
	useClearProcessedItems,
} from '../../queries/jobs';

const ClearProcessedForm = () => {
	const [ clearType, setClearType ] = useState( '' );
	const [ pipelineId, setPipelineId ] = useState( '' );
	const [ flowId, setFlowId ] = useState( '' );
	const [ notice, setNotice ] = useState( null );

	const { data: pipelines = [], isLoading: pipelinesLoading } =
		usePipelinesForDropdown();
	const { data: flows = [], isLoading: flowsLoading } =
		useFlowsForDropdown( pipelineId );
	const clearMutation = useClearProcessedItems();

	const handleClearTypeChange = useCallback( ( value ) => {
		setClearType( value );
		setFlowId( '' );
		setNotice( null );
	}, [] );

	const handlePipelineChange = useCallback( ( value ) => {
		setPipelineId( value );
		setFlowId( '' );
		setNotice( null );
	}, [] );

	const handleFlowChange = useCallback( ( value ) => {
		setFlowId( value );
		setNotice( null );
	}, [] );

	const handleSubmit = useCallback(
		( e ) => {
			e.preventDefault();
			setNotice( null );

			if ( ! clearType ) {
				setNotice( {
					type: 'warning',
					message: __( 'Please select a clear type', 'data-machine' ),
				} );
				return;
			}

			let targetId = '';
			let confirmMessage = '';

			if ( clearType === 'pipeline' ) {
				targetId = pipelineId;
				if ( ! targetId ) {
					setNotice( {
						type: 'warning',
						message: __(
							'Please select a pipeline',
							'data-machine'
						),
					} );
					return;
				}
				confirmMessage = __(
					'Are you sure you want to clear all processed items for ALL flows in this pipeline? This will allow all items to be reprocessed.',
					'data-machine'
				);
			} else if ( clearType === 'flow' ) {
				targetId = flowId;
				if ( ! targetId ) {
					setNotice( {
						type: 'warning',
						message: __( 'Please select a flow', 'data-machine' ),
					} );
					return;
				}
				confirmMessage = __(
					'Are you sure you want to clear all processed items for this flow? This will allow all items to be reprocessed.',
					'data-machine'
				);
			}

			if (
				// eslint-disable-line no-alert
				! window.confirm( confirmMessage )
			) {
				return;
			}

			clearMutation.mutate(
				{ clearType, targetId: parseInt( targetId, 10 ) },
				{
					onSuccess: ( response ) => {
						setNotice( {
							type: 'success',
							message: response.message,
						} );
						setClearType( '' );
						setPipelineId( '' );
						setFlowId( '' );
					},
					onError: ( error ) => {
						setNotice( {
							type: 'error',
							message:
								error.message ||
								__( 'An error occurred', 'data-machine' ),
						} );
					},
				}
			);
		},
		[ clearType, pipelineId, flowId, clearMutation ]
	);

	const pipelineOptions = [
		{ label: __( '— Select a Pipeline —', 'data-machine' ), value: '' },
		...pipelines.map( ( p ) => ( {
			label: p.pipeline_name,
			value: String( p.pipeline_id ),
		} ) ),
	];

	const flowOptions = [
		{
			label: pipelineId
				? __( '— Select a Flow —', 'data-machine' )
				: __( '— Select a Pipeline First —', 'data-machine' ),
			value: '',
		},
		...flows.map( ( f ) => ( {
			label: f.flow_name,
			value: String( f.flow_id ),
		} ) ),
	];

	const clearTypeOptions = [
		{ label: __( '— Select Type —', 'data-machine' ), value: '' },
		{
			label: __( 'Entire Pipeline (all flows)', 'data-machine' ),
			value: 'pipeline',
		},
		{ label: __( 'Specific Flow', 'data-machine' ), value: 'flow' },
	];

	return (
		<div className="datamachine-admin-section">
			<h3>{ __( 'Clear Processed Items', 'data-machine' ) }</h3>
			<p className="description">
				{ __(
					'Clear processed item records to allow reprocessing during testing and development. This is useful when iteratively refining prompts and configurations.',
					'data-machine'
				) }
			</p>

			<form onSubmit={ handleSubmit } className="datamachine-admin-form">
				<div className="datamachine-form-field">
					<SelectControl
						label={ __( 'Clear By', 'data-machine' ) }
						value={ clearType }
						options={ clearTypeOptions }
						onChange={ handleClearTypeChange }
						__nextHasNoMarginBottom
					/>
				</div>

				{ ( clearType === 'pipeline' || clearType === 'flow' ) && (
					<div className="datamachine-form-field">
						<SelectControl
							label={ __( 'Select Pipeline', 'data-machine' ) }
							value={ pipelineId }
							options={ pipelineOptions }
							onChange={ handlePipelineChange }
							disabled={ pipelinesLoading }
							__nextHasNoMarginBottom
						/>
						{ clearType === 'pipeline' && (
							<p className="description">
								{ __(
									'All processed items for ALL flows in this pipeline will be cleared.',
									'data-machine'
								) }
							</p>
						) }
					</div>
				) }

				{ clearType === 'flow' && (
					<div className="datamachine-form-field">
						<SelectControl
							label={ __( 'Select Flow', 'data-machine' ) }
							value={ flowId }
							options={ flowOptions }
							onChange={ handleFlowChange }
							disabled={ ! pipelineId || flowsLoading }
							__nextHasNoMarginBottom
						/>
						<p className="description">
							{ __(
								'All processed items for this specific flow will be cleared.',
								'data-machine'
							) }
						</p>
					</div>
				) }

				<div className="datamachine-form-actions">
					<Button
						variant="primary"
						type="submit"
						isBusy={ clearMutation.isPending }
						disabled={ clearMutation.isPending }
					>
						{ __( 'Clear Processed Items', 'data-machine' ) }
					</Button>
				</div>

				{ notice && (
					<Notice
						status={ notice.type }
						isDismissible={ false }
						className="datamachine-form-notice"
					>
						{ notice.message }
					</Notice>
				) }
			</form>
		</div>
	);
};

export default ClearProcessedForm;
