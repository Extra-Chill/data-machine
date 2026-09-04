/**
 * WordPress dependencies
 */
import { Button, Modal } from '@wordpress/components';
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Stable confirmation dialog built from public WordPress components.
 *
 * @param {Object}          props                   Component props.
 * @param {React.ReactNode} props.children          Confirmation message.
 * @param {Function}        props.onConfirm         Confirm callback.
 * @param {Function}        props.onCancel          Cancel callback.
 * @param {boolean}         props.isBusy            Whether confirmation is running.
 * @param {string}          props.confirmButtonText Confirm button label.
 * @param {string}          props.cancelButtonText  Cancel button label.
 * @param {string}          props.title             Accessible modal title.
 * @return {React.ReactElement} Confirmation modal.
 */
export default function ConfirmationModal( {
	children,
	onConfirm,
	onCancel,
	isBusy = false,
	confirmButtonText = __( 'OK', 'data-machine' ),
	cancelButtonText = __( 'Cancel', 'data-machine' ),
	title = __( 'Confirm action', 'data-machine' ),
} ) {
	const cancelButtonRef = useRef( null );
	const confirmButtonRef = useRef( null );

	useEffect( () => {
		cancelButtonRef.current?.focus();
	}, [] );

	const handleKeyDown = ( event ) => {
		if (
			! isBusy &&
			event.key === 'Enter' &&
			event.target !== cancelButtonRef.current &&
			event.target !== confirmButtonRef.current
		) {
			onConfirm( event );
		}
	};

	return (
		<Modal
			title={ title }
			onRequestClose={ onCancel }
			onKeyDown={ handleKeyDown }
			focusOnMount={ false }
			isDismissible={ ! isBusy }
		>
			<div>{ children }</div>
			<div className="datamachine-modal-actions">
				<Button
					ref={ cancelButtonRef }
					variant="tertiary"
					onClick={ onCancel }
					disabled={ isBusy }
				>
					{ cancelButtonText }
				</Button>
				<Button
					ref={ confirmButtonRef }
					variant="primary"
					onClick={ onConfirm }
					disabled={ isBusy }
					isBusy={ isBusy }
				>
					{ confirmButtonText }
				</Button>
			</div>
		</Modal>
	);
}
