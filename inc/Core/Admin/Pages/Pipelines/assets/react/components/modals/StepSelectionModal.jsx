/**
 * Step Selection Modal Component
 *
 * Modal for selecting step type to add to pipeline.
 */

/**
 * WordPress dependencies
 */
import { Modal, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import { useStepTypes } from '../../queries/config';
import { useHandlers } from '../../queries/handlers';
import { useAddPipelineStep } from '../../queries/pipelines';
import { useAsyncOperation } from '../../hooks/useFormState';

/**
 * Step Selection Modal Component
 *
 * @param {Object}   props                    - Component props
 * @param {Function} props.onClose            - Close handler
 * @param {number}   props.pipelineId         - Pipeline ID
 * @param {number}   props.nextExecutionOrder - Next execution order
 * @param {Function} props.onSuccess          - Success callback
 * @return {React.ReactElement|null} Step selection modal
 */
export default function StepSelectionModal( {
	onClose,
	pipelineId,
	nextExecutionOrder,
	onSuccess,
} ) {
	// Use TanStack Query for data
	const { data: stepTypes = {} } = useStepTypes();
	const { data: handlers = {} } = useHandlers();

	// Use mutations
	const addStepMutation = useAddPipelineStep();

	const addStepOperation = useAsyncOperation();

	/**
	 * Count handlers for each step type
	 * @param stepType
	 */
	const getHandlerCount = ( stepType ) => {
		return Object.values( handlers ).filter(
			( handler ) => handler.type === stepType
		).length;
	};

	/**
	 * Handle step type selection
	 * @param stepType
	 */
	const handleSelectStep = ( stepType ) => {
		addStepOperation.execute( async () => {
			await addStepMutation.mutateAsync( {
				pipelineId,
				stepType,
				executionOrder: nextExecutionOrder,
			} );

			if ( onSuccess ) {
				onSuccess();
			}
			onClose();
		} );
	};

	return (
		<Modal
			title={ __( 'Add Pipeline Step', 'data-machine' ) }
			onRequestClose={ onClose }
			className="datamachine-step-selection-modal"
		>
			<div className="datamachine-modal-content">
				{ addStepOperation.error && (
					<div className="datamachine-modal-error notice notice-error">
						<p>{ addStepOperation.error }</p>
					</div>
				) }

				<p className="datamachine-modal-header-text">
					{ __(
						'Select the type of step you want to add to your pipeline:',
						'data-machine'
					) }
				</p>

				<div className="datamachine-modal-grid-2col">
					{ Object.entries( stepTypes ).map(
						( [ stepType, config ] ) => {
							const handlerCount = getHandlerCount( stepType );

							return (
								<button
									key={ stepType }
									type="button"
									className="datamachine-modal-card"
									onClick={ () =>
										handleSelectStep( stepType )
									}
									disabled={ addStepOperation.isLoading }
								>
									<strong>
										{ stepTypes[ stepType ]?.label ||
											stepType }
									</strong>

									<p>{ config.description || '' }</p>

									{ stepType !== 'ai' && handlerCount > 0 && (
										<span className="datamachine-modal-card-meta">
											{ handlerCount }{ ' ' }
											{ handlerCount === 1
												? __(
														'handler',
														'data-machine'
												  )
												: __(
														'handlers',
														'data-machine'
												  ) }{ ' ' }
											{ __(
												'available',
												'data-machine'
											) }
										</span>
									) }
								</button>
							);
						}
					) }
				</div>

				<div className="datamachine-modal-info-box">
					<p>
						<strong>{ __( 'Tip:', 'data-machine' ) }</strong>{ ' ' }
						{ __(
							'Steps execute in order. You can configure each step after adding it.',
							'data-machine'
						) }
					</p>
				</div>

				<div className="datamachine-modal-actions">
					<Button
						variant="secondary"
						onClick={ onClose }
						disabled={ addStepOperation.isLoading }
					>
						{ __( 'Cancel', 'data-machine' ) }
					</Button>
				</div>
			</div>
		</Modal>
	);
}
