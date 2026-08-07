/**
 * ChatSessionList Component
 *
 * Full session list view that replaces the chat messages area.
 * Shows all sessions with delete capability.
 */

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { arrowLeft, trash } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import { useChatSessions, useDeleteChatSession } from '../../queries/chat';
import { formatRelativeTime, getSessionTitle } from '../../utils/formatters';

export default function ChatSessionList( {
	currentSessionId,
	onSelectSession,
	onBack,
	onSessionDeleted,
} ) {
	const [ deletingId, setDeletingId ] = useState( null );
	const { data: sessionsData, isLoading, refetch } = useChatSessions( 100 );
	const deleteMutation = useDeleteChatSession();

	const sessions = sessionsData?.sessions || [];

	const handleDelete = async ( e, sessionId ) => {
		e.stopPropagation();

		if ( deletingId ) {
			return;
		}

		setDeletingId( sessionId );
		try {
			await deleteMutation.mutateAsync( sessionId );

			// If we deleted the current session, notify parent
			if ( sessionId === currentSessionId ) {
				onSessionDeleted();
			}

			refetch();
		} catch ( error ) {
			// Error handled by mutation
		} finally {
			setDeletingId( null );
		}
	};

	const handleSessionClick = ( sessionId ) => {
		onSelectSession( sessionId );
	};

	return (
		<div className="datamachine-chat-session-list">
			<div className="datamachine-chat-session-list__header">
				<Button
					icon={ arrowLeft }
					onClick={ onBack }
					label={ __( 'Back to chat', 'data-machine' ) }
					className="datamachine-chat-session-list__back"
				>
					{ __( 'Back to chat', 'data-machine' ) }
				</Button>
			</div>

			<div className="datamachine-chat-session-list__content">
				{ isLoading ? (
					<div className="datamachine-chat-session-list__loading">
						<span className="spinner is-active"></span>
						<span>
							{ __( 'Loading conversations…', 'data-machine' ) }
						</span>
					</div>
				) : sessions.length === 0 ? (
					<div className="datamachine-chat-session-list__empty">
						<p>{ __( 'No conversations yet.', 'data-machine' ) }</p>
						<p>
							{ __(
								'Start a new conversation to begin.',
								'data-machine'
							) }
						</p>
					</div>
				) : (
					<ul className="datamachine-chat-session-list__items">
						{ sessions.map( ( session ) => (
							<li
								key={ session.session_id }
								className={ `datamachine-chat-session-list__item ${
									session.session_id === currentSessionId
										? 'is-active'
										: ''
								}` }
							>
								<button
									type="button"
									className="datamachine-chat-session-list__item-content"
									onClick={ () =>
										handleSessionClick( session.session_id )
									}
								>
									<span className="datamachine-chat-session-list__item-title">
										{ getSessionTitle( session ) }
									</span>
									<span className="datamachine-chat-session-list__item-meta">
										{ formatRelativeTime(
											session.updated_at
										) }
										{ session.message_count > 0 && (
											<>
												{ ' ' }
												&middot;{ ' ' }
												{ session.message_count }{ ' ' }
												{ session.message_count === 1
													? __(
															'message',
															'data-machine'
													  )
													: __(
															'messages',
															'data-machine'
													  ) }
											</>
										) }
									</span>
								</button>
								<Button
									icon={ trash }
									onClick={ ( e ) =>
										handleDelete( e, session.session_id )
									}
									label={ __(
										'Delete conversation',
										'data-machine'
									) }
									className="datamachine-chat-session-list__item-delete"
									disabled={
										deletingId === session.session_id
									}
									isDestructive
								/>
							</li>
						) ) }
					</ul>
				) }
			</div>
		</div>
	);
}
