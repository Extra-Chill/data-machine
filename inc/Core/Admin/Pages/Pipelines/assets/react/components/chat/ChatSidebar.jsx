/**
 * ChatSidebar Component
 *
 * Collapsible right sidebar for chat interface.
 * Uses @extrachill/chat's useChat hook for all conversation state,
 * continuation loops, and API communication.
 *
 * DM-specific concerns (pipeline context, TanStack Query cache
 * invalidation, UI store) are wired via hook callbacks.
 */

/**
 * WordPress dependencies
 */
import { useState, useCallback, lazy, Suspense } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { close, copy } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
/**
 * External dependencies
 */
import {
	useChat,
	ChatMessages,
	ChatInput,
	TypingIndicator,
	ErrorBoundary,
} from '@extrachill/chat';
/**
 * Internal dependencies
 */
import { useUIStore } from '../../stores/uiStore';
import { useChatQueryInvalidation } from '../../hooks/useChatQueryInvalidation';
import ChatSessionSwitcher from './ChatSessionSwitcher';
import ChatSessionList from './ChatSessionList';
import { formatChatAsMarkdown } from '../../utils/formatters';

const ReactMarkdown = lazy( () => import( 'react-markdown' ) );

/**
 * Custom markdown renderer for @extrachill/chat messages.
 *
 * @param {string} content - Message content (markdown)
 * @return {JSX.Element} Rendered content
 */
function renderMarkdown( content ) {
	return (
		<Suspense fallback={ <div>{ content }</div> }>
			<ReactMarkdown>{ content }</ReactMarkdown>
		</Suspense>
	);
}

export default function ChatSidebar() {
	const {
		toggleChat,
		chatSessionId,
		setChatSessionId,
		clearChatSession,
		selectedPipelineId,
	} = useUIStore();
	const [ isCopied, setIsCopied ] = useState( false );
	const [ view, setView ] = useState( 'chat' );
	const { invalidateFromToolCalls } = useChatQueryInvalidation();

	const handleToolCalls = useCallback(
		( toolCalls ) => {
			invalidateFromToolCalls( toolCalls, selectedPipelineId );
		},
		[ invalidateFromToolCalls, selectedPipelineId ]
	);

	const chat = useChat( {
		basePath: '/datamachine/v1/chat',
		fetchFn: apiFetch,
		metadata: {
			selected_pipeline_id: selectedPipelineId || undefined,
		},
		sessionContext: 'chat',
		initialSessionId: chatSessionId || undefined,
		onToolCalls: handleToolCalls,
		onError: ( error ) => {
			if ( error.message?.includes( 'not found' ) ) {
				clearChatSession();
			}
		},
	} );

	// Sync session ID changes back to UI store.
	if ( chat.sessionId && chat.sessionId !== chatSessionId ) {
		setChatSessionId( chat.sessionId );
	}

	const handleSend = useCallback(
		( message ) => {
			chat.sendMessage( message );
		},
		[ chat ]
	);

	const handleNewConversation = useCallback( () => {
		clearChatSession();
		chat.newSession();
		setView( 'chat' );
	}, [ clearChatSession, chat ] );

	const handleSelectSession = useCallback(
		( sessionId ) => {
			setChatSessionId( sessionId );
			chat.switchSession( sessionId );
			setView( 'chat' );
		},
		[ setChatSessionId, chat ]
	);

	const handleShowMore = useCallback( () => {
		setView( 'sessions' );
	}, [] );

	const handleBackToChat = useCallback( () => {
		setView( 'chat' );
	}, [] );

	const handleSessionDeleted = useCallback( () => {
		clearChatSession();
		chat.newSession();
	}, [ clearChatSession, chat ] );

	const handleCopyChat = useCallback( () => {
		const markdown = formatChatAsMarkdown( chat.messages );
		navigator.clipboard.writeText( markdown );
		setIsCopied( true );
		setTimeout( () => setIsCopied( false ), 2000 );
	}, [ chat.messages ] );

	// Session-aware loading — only show for the session that initiated the request.
	const isLoading =
		chat.isLoading &&
		( ! chat.processingSessionId ||
			chat.processingSessionId === chatSessionId );

	return (
		<aside className="datamachine-chat-sidebar">
			<header className="datamachine-chat-sidebar__header">
				<h2 className="datamachine-chat-sidebar__title">
					{ __( 'Chat', 'data-machine' ) }
				</h2>
				<Button
					icon={ close }
					onClick={ toggleChat }
					label={ __( 'Close chat', 'data-machine' ) }
					className="datamachine-chat-sidebar__close"
				/>
			</header>

			<ErrorBoundary>
			{ view === 'chat' ? (
				<>
					<div className="datamachine-chat-sidebar__actions">
						<ChatSessionSwitcher
							currentSessionId={ chatSessionId }
							onSelectSession={ handleSelectSession }
							onNewConversation={ handleNewConversation }
							onShowMore={ handleShowMore }
						/>
						<Button
							variant="tertiary"
							onClick={ handleCopyChat }
							className="datamachine-chat-sidebar__copy"
							disabled={ chat.messages.length === 0 }
							icon={ copy }
						>
							{ isCopied
								? __( 'Copied!', 'data-machine' )
								: __( 'Copy', 'data-machine' ) }
						</Button>
					</div>

					<ChatMessages
						messages={ chat.messages }
						showTools={ true }
						contentFormat="markdown"
						renderContent={ renderMarkdown }
						emptyState={
							! isLoading
								? __( 'Ask me to create a pipeline, configure a flow, or help with your automations.', 'data-machine' )
								: null
						}
						className="datamachine-chat-messages"
					/>

					<TypingIndicator
						visible={ isLoading }
						label={
							chat.turnCount > 0
								? `${ __( 'Processing turn', 'data-machine' ) } ${ chat.turnCount }...`
								: undefined
						}
						className="datamachine-chat-typing"
					/>

					<ChatInput
						onSend={ handleSend }
						disabled={ isLoading }
						placeholder={ __( 'Ask me to build something…', 'data-machine' ) }
						className="datamachine-chat-input"
					/>
				</>
			) : (
				<ChatSessionList
					currentSessionId={ chatSessionId }
					onSelectSession={ handleSelectSession }
					onBack={ handleBackToChat }
					onSessionDeleted={ handleSessionDeleted }
				/>
			) }
			</ErrorBoundary>
		</aside>
	);
}
