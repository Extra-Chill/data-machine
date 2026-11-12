/**
 * ChatToggle Component
 *
 * Toggle button to open/close the chat sidebar.
 * Displayed in the Pipelines page header.
 */

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { comment } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import { useUIStore } from '../../stores/uiStore';

export default function ChatToggle() {
	const { isChatOpen, toggleChat } = useUIStore();

	return (
		<Button
			icon={ comment }
			onClick={ toggleChat }
			label={
				isChatOpen
					? __( 'Close chat', 'data-machine' )
					: __( 'Open chat', 'data-machine' )
			}
			className={ `datamachine-chat-toggle ${
				isChatOpen ? 'is-active' : ''
			}` }
			isPressed={ isChatOpen }
		/>
	);
}
