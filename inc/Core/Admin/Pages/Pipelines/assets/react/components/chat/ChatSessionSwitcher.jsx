/**
 * ChatSessionSwitcher Component
 *
 * Dropdown component for switching between chat sessions.
 * Shows 5 most recent sessions with "Show more" option.
 * Uses @wordpress/components Dropdown for accessibility.
 */

/**
 * WordPress dependencies
 */
import { Button, Dropdown } from '@wordpress/components';
import { chevronDown, plus } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import { useChatSessions } from '../../queries/chat';
import { formatRelativeTime, getSessionTitle } from '../../utils/formatters';

export default function ChatSessionSwitcher( {
	currentSessionId,
	onSelectSession,
	onNewConversation,
	onShowMore,
} ) {
	const { data: sessionsData, isLoading } = useChatSessions( 5 );

	const sessions = sessionsData?.sessions || [];
	const total = sessionsData?.total || 0;
	const hasMore = total > 5;

	const currentSession = sessions.find(
		( s ) => s.session_id === currentSessionId
	);
	const currentTitle = currentSession
		? getSessionTitle( currentSession )
		: __( 'New conversation', 'data-machine' );

	return (
		<div className="datamachine-chat-session-switcher">
			<Dropdown
				className="datamachine-chat-session-switcher__dropdown-wrapper"
				popoverProps={ { placement: 'bottom-start' } }
				renderToggle={ ( { isOpen, onToggle } ) => (
					<Button
						className="datamachine-chat-session-switcher__trigger"
						onClick={ onToggle }
						aria-expanded={ isOpen }
					>
						<span className="datamachine-chat-session-switcher__title">
							{ currentTitle }
						</span>
						<span
							className={ `datamachine-chat-session-switcher__icon ${
								isOpen ? 'is-open' : ''
							}` }
						>
							{ chevronDown }
						</span>
					</Button>
				) }
				renderContent={ ( { onClose } ) => (
					<div className="datamachine-chat-session-switcher__dropdown">
						{ isLoading ? (
							<div className="datamachine-chat-session-switcher__loading">
								<span className="spinner is-active"></span>
							</div>
						) : (
							<>
								{ sessions.length === 0 ? (
									<div className="datamachine-chat-session-switcher__empty">
										{ __(
											'No conversations yet',
											'data-machine'
										) }
									</div>
								) : (
									<ul className="datamachine-chat-session-switcher__list">
										{ sessions.map( ( session ) => (
											<li key={ session.session_id }>
												<Button
													className={ `datamachine-chat-session-switcher__item ${
														session.session_id ===
														currentSessionId
															? 'is-active'
															: ''
													}` }
													onClick={ () => {
														onSelectSession(
															session.session_id
														);
														onClose();
													} }
												>
													<span className="datamachine-chat-session-switcher__item-title">
														{ getSessionTitle(
															session
														) }
													</span>
													<span className="datamachine-chat-session-switcher__item-meta">
														{ formatRelativeTime(
															session.updated_at
														) }
													</span>
												</Button>
											</li>
										) ) }
									</ul>
								) }

								{ hasMore && (
									<Button
										className="datamachine-chat-session-switcher__show-more"
										onClick={ () => {
											onShowMore();
											onClose();
										} }
									>
										{ __(
											'Show all conversations',
											'data-machine'
										) }
									</Button>
								) }
							</>
						) }
					</div>
				) }
			/>

			<Button
				icon={ plus }
				onClick={ onNewConversation }
				label={ __( 'New conversation', 'data-machine' ) }
				className="datamachine-chat-session-switcher__new-btn"
			/>
		</div>
	);
}
