/**
 * Flow Queue Modal Component
 *
 * Modal for managing the prompt queue for a flow.
 * Allows viewing, adding, and removing queued prompts.
 */

/**
 * WordPress dependencies
 */
import { useState, useCallback } from '@wordpress/element';
import { Modal, Button, TextareaControl, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import {
	useFlowQueue,
	useAddToQueue,
	useClearQueue,
	useRemoveFromQueue,
} from '../../queries/queue';

/**
 * Flow Queue Modal Component
 *
 * @param {Object}   props          - Component props
 * @param {Function} props.onClose  - Close handler
 * @param {number}   props.flowId   - Flow ID
 * @param {string}   props.flowName - Flow name
 * @return {JSX.Element} Flow queue modal
 */
export default function FlowQueueModal( { onClose, flowId, flowName } ) {
	const [ newPrompt, setNewPrompt ] = useState( '' );
	const [ confirmClear, setConfirmClear ] = useState( false );

	// Query hooks
	const { data, isLoading, error } = useFlowQueue( flowId );
	const addMutation = useAddToQueue();
	const clearMutation = useClearQueue();
	const removeMutation = useRemoveFromQueue();

	const queue = data?.queue || [];
	const isOperating =
		addMutation.isPending ||
		clearMutation.isPending ||
		removeMutation.isPending;

	/**
	 * Handle adding a new prompt
	 */
	const handleAdd = useCallback( () => {
		const trimmed = newPrompt.trim();
		if ( ! trimmed ) {
			return;
		}

		addMutation.mutate(
			{ flowId, prompts: trimmed },
			{
				onSuccess: () => {
					setNewPrompt( '' );
				},
			}
		);
	}, [ flowId, newPrompt, addMutation ] );

	/**
	 * Handle removing a prompt
	 */
	const handleRemove = useCallback(
		( index ) => {
			removeMutation.mutate( { flowId, index } );
		},
		[ flowId, removeMutation ]
	);

	/**
	 * Handle clearing all prompts
	 */
	const handleClear = useCallback( () => {
		if ( ! confirmClear ) {
			setConfirmClear( true );
			return;
		}

		clearMutation.mutate(
			{ flowId },
			{
				onSuccess: () => {
					setConfirmClear( false );
				},
			}
		);
	}, [ flowId, confirmClear, clearMutation ] );

	/**
	 * Cancel clear confirmation
	 */
	const handleCancelClear = useCallback( () => {
		setConfirmClear( false );
	}, [] );

	/**
	 * Handle Enter key in textarea
	 */
	const handleKeyDown = useCallback(
		( event ) => {
			if ( event.key === 'Enter' && event.ctrlKey ) {
				event.preventDefault();
				handleAdd();
			}
		},
		[ handleAdd ]
	);

	return (
		<Modal
			title={ __( 'Prompt Queue', 'data-machine' ) }
			onRequestClose={ onClose }
			className="datamachine-flow-queue-modal"
		>
			<div className="datamachine-modal-content">
				{ error && (
					<div className="datamachine-modal-error notice notice-error">
						<p>{ error.message }</p>
					</div>
				) }

				<div className="datamachine-modal-spacing--mb-20">
					<strong>{ __( 'Flow:', 'data-machine' ) }</strong>{ ' ' }
					{ flowName }
				</div>

				{ /* Queue List */ }
				<div className="datamachine-queue-list">
					<h4>
						{ __( 'Queued Prompts', 'data-machine' ) }{ ' ' }
						<span className="datamachine-queue-count">
							({ queue.length })
						</span>
					</h4>

					{ isLoading && (
						<div className="datamachine-queue-loading">
							<Spinner />
						</div>
					) }

					{ ! isLoading && queue.length === 0 && (
						<div className="datamachine-queue-empty">
							<p>
								{ __(
									'No prompts in queue. Add one below.',
									'data-machine'
								) }
							</p>
						</div>
					) }

					{ ! isLoading && queue.length > 0 && (
						<ul className="datamachine-queue-items">
							{ queue.map( ( item, index ) => (
								<li
									key={ index }
									className="datamachine-queue-item"
								>
									<div className="datamachine-queue-item-content">
										<span className="datamachine-queue-item-index">
											{ index + 1 }.
										</span>
										<span className="datamachine-queue-item-text">
											{ item.prompt }
										</span>
										{ item.added_at && (
											<span className="datamachine-queue-item-time">
												{ new Date(
													item.added_at
												).toLocaleString() }
											</span>
										) }
									</div>
									<Button
										variant="tertiary"
										isDestructive
										onClick={ () => handleRemove( index ) }
										disabled={ isOperating }
										className="datamachine-queue-item-remove"
									>
										{ __( 'Remove', 'data-machine' ) }
									</Button>
								</li>
							) ) }
						</ul>
					) }
				</div>

				{ /* Add New Prompt */ }
				<div className="datamachine-queue-add">
					<TextareaControl
						label={ __( 'Add New Prompt', 'data-machine' ) }
						value={ newPrompt }
						onChange={ setNewPrompt }
						onKeyDown={ handleKeyDown }
						placeholder={ __(
							'Enter a prompt to add to the queue…',
							'data-machine'
						) }
						help={ __(
							'Press Ctrl+Enter to add quickly.',
							'data-machine'
						) }
						rows={ 3 }
						disabled={ isOperating }
					/>
					<Button
						variant="primary"
						onClick={ handleAdd }
						disabled={ ! newPrompt.trim() || isOperating }
						isBusy={ addMutation.isPending }
					>
						{ addMutation.isPending
							? __( 'Adding…', 'data-machine' )
							: __( 'Add to Queue', 'data-machine' ) }
					</Button>
				</div>

				{ /* Modal Actions */ }
				<div className="datamachine-modal-actions">
					<Button
						variant="secondary"
						onClick={ onClose }
						disabled={ isOperating }
					>
						{ __( 'Close', 'data-machine' ) }
					</Button>

					{ queue.length > 0 && (
						<>
							{ confirmClear ? (
								<>
									<Button
										variant="secondary"
										onClick={ handleCancelClear }
										disabled={ isOperating }
									>
										{ __( 'Cancel', 'data-machine' ) }
									</Button>
									<Button
										variant="primary"
										isDestructive
										onClick={ handleClear }
										disabled={ isOperating }
										isBusy={ clearMutation.isPending }
									>
										{ clearMutation.isPending
											? __( 'Clearing…', 'data-machine' )
											: __(
													'Confirm Clear All',
													'data-machine'
											  ) }
									</Button>
								</>
							) : (
								<Button
									variant="secondary"
									isDestructive
									onClick={ handleClear }
									disabled={ isOperating }
								>
									{ __( 'Clear All', 'data-machine' ) }
								</Button>
							) }
						</>
					) }
				</div>

				{ /* Info Box */ }
				<div className="datamachine-modal-info-box datamachine-modal-info-box--note datamachine-modal-spacing--mt-20">
					<p>
						<strong>
							{ __( 'How it works:', 'data-machine' ) }
						</strong>{ ' ' }
						{ __(
							'Prompts are processed in order (FIFO). Each time the flow runs, the first prompt is removed and used as input for the AI step.',
							'data-machine'
						) }
					</p>
				</div>
			</div>
		</Modal>
	);
}
