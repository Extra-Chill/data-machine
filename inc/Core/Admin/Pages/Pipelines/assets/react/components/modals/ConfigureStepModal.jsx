/**
 * Configure Step Modal Component
 *
 * DEPRECATED: AI step configuration (model, provider, tools) is now managed
 * by the context system. System prompt is editable inline on the step card.
 * This modal is retained as a no-op for backward compatibility with ModalSwitch routing.
 */

/**
 * WordPress dependencies
 */
import { useEffect } from '@wordpress/element';

export default function ConfigureStepModal( { onClose } ) {
	// Immediately close — this modal is no longer needed.
	useEffect( () => {
		onClose();
	}, [ onClose ] );

	return null;
}
