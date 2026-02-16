/**
 * Queueable Prompt Field Component.
 *
 * Shared prompt field with queue integration for any step type
 * that supports prompt queues (AI steps, Agent Ping, etc.).
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { Button, TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useUpdateQueueItem, useAddToQueue } from '../../queries/queue';
import { AUTO_SAVE_DELAY } from '../../utils/constants';

/**
 * QueueablePromptField Component.
 *
 * @param {Object}   props                - Component props.
 * @param {number}   props.flowId         - Flow ID.
 * @param {string}   props.flowStepId     - Flow step ID.
 * @param {string}   props.prompt         - Current prompt value (from handler_config or user_message).
 * @param {Array}    props.promptQueue    - Prompt queue array.
 * @param {boolean}  props.queueEnabled   - Whether queue is enabled.
 * @param {string}   props.placeholder    - Placeholder text.
 * @param {string}   props.label          - Field label override.
 * @param {Function} props.onSave         - Save callback for non-queue saves (receives prompt string).
 * @param {Function} props.onQueueClick   - Queue management button handler.
 * @param {Function} props.onError        - Error callback.
 * @return {JSX.Element} Prompt field with queue integration.
 */
export default function QueueablePromptField( {
	flowId,
	flowStepId,
	prompt = '',
	promptQueue = [],
	queueEnabled = false,
	placeholder,
	label,
	onSave,
	onQueueClick,
	onError,
} ) {
	const queueCount = promptQueue.length;
	const queueHasItems = queueCount > 0;
	const firstQueuePrompt = queueHasItems ? promptQueue[ 0 ].prompt : '';
	const shouldUseQueue = queueEnabled || queueHasItems;

	const [ localValue, setLocalValue ] = useState(
		firstQueuePrompt || prompt || ''
	);
	const [ isSaving, setIsSaving ] = useState( false );
	const saveTimeout = useRef( null );

	const updateQueueItemMutation = useUpdateQueueItem();
	const addToQueueMutation = useAddToQueue();

	// Sync with external changes.
	useEffect( () => {
		setLocalValue( firstQueuePrompt || prompt || '' );
	}, [ firstQueuePrompt, prompt ] );

	// Cleanup timeout on unmount.
	useEffect( () => {
		return () => {
			if ( saveTimeout.current ) {
				clearTimeout( saveTimeout.current );
			}
		};
	}, [] );

	/**
	 * Save to queue (add or update index 0).
	 */
	const saveToQueue = useCallback(
		async ( message ) => {
			if ( ! shouldUseQueue || ! message.trim() ) {
				return;
			}

			const currentMessage = firstQueuePrompt || '';
			if ( message === currentMessage ) {
				return;
			}

			setIsSaving( true );

			try {
				let response;

				if ( queueHasItems ) {
					response = await updateQueueItemMutation.mutateAsync( {
						flowId,
						flowStepId,
						index: 0,
						prompt: message,
					} );
				} else {
					response = await addToQueueMutation.mutateAsync( {
						flowId,
						flowStepId,
						prompt: message,
					} );
				}

				if ( ! response?.success ) {
					const errorMsg =
						response?.message ||
						__( 'Failed to save prompt', 'data-machine' );
					if ( onError ) {
						onError( errorMsg );
					}
					setLocalValue( currentMessage );
				}
			} catch ( err ) {
				// eslint-disable-next-line no-console
				console.error( 'Queue save error:', err );
				if ( onError ) {
					onError(
						err.message ||
							__( 'An error occurred', 'data-machine' )
					);
				}
				setLocalValue( currentMessage );
			} finally {
				setIsSaving( false );
			}
		},
		[
			flowId,
			flowStepId,
			firstQueuePrompt,
			queueHasItems,
			shouldUseQueue,
			updateQueueItemMutation,
			addToQueueMutation,
			onError,
		]
	);

	/**
	 * Handle value change with debounced save.
	 */
	const handleChange = useCallback(
		( value ) => {
			setLocalValue( value );

			if ( saveTimeout.current ) {
				clearTimeout( saveTimeout.current );
			}

			saveTimeout.current = setTimeout( () => {
				if ( shouldUseQueue ) {
					saveToQueue( value );
				} else if ( onSave ) {
					onSave( value );
				}
			}, AUTO_SAVE_DELAY );
		},
		[ shouldUseQueue, saveToQueue, onSave ]
	);

	/**
	 * Build the label with queue indicator.
	 */
	const getFieldLabel = () => {
		const baseLabel = label || __( 'User Message', 'data-machine' );
		if ( queueHasItems ) {
			return (
				<span className="datamachine-user-message-label">
					{ baseLabel }
					<span className="datamachine-queue-indicator">
						{ ' ' }
						<span className="datamachine-queue-badge">
							{ __( 'Next in queue', 'data-machine' ) }
						</span>
					</span>
				</span>
			);
		}
		return baseLabel;
	};

	/**
	 * Build help text.
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
		<div className="datamachine-queueable-prompt">
			<TextareaControl
				label={ getFieldLabel() }
				value={ localValue }
				onChange={ handleChange }
				placeholder={
					placeholder ||
					__( 'Enter prompt…', 'data-machine' )
				}
				rows={ 4 }
				help={ getHelpText() }
				className={ queueHasItems ? 'datamachine-queue-linked' : '' }
			/>

			<div className="datamachine-queue-actions">
				<Button
					variant="secondary"
					size="small"
					onClick={ onQueueClick }
				>
					{ __( 'Manage Queue', 'data-machine' ) }{ ' ' }
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
	);
}
