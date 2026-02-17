/**
 * Chat Turn Hook
 *
 * Manages turn-by-turn chat execution for async responses.
 * Polls /chat/continue endpoint until conversation completes.
 * Updates TanStack Query cache directly instead of using callbacks.
 *
 * @since 0.12.0
 */

/**
 * WordPress dependencies
 */
import { useState, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { useChatQueryInvalidation } from './useChatQueryInvalidation';

/**
 * Update chat session cache with new messages
 *
 * @param {Object} queryClient TanStack Query client
 * @param {string} sessionId   Session ID
 * @param {Array}  newMessages New messages to append
 */
function appendMessagesToCache( queryClient, sessionId, newMessages ) {
	queryClient.setQueryData( [ 'chat-session', sessionId ], ( old ) => {
		if ( ! old ) {
			return old;
		}
		return {
			...old,
			conversation: [ ...( old.conversation || [] ), ...newMessages ],
		};
	} );
}

/**
 * Hook for managing turn-by-turn chat execution
 *
 * @return {Object} Turn management functions and state
 */
export function useChatTurn() {
	const [ isProcessing, setIsProcessing ] = useState( false );
	const [ processingSessionId, setProcessingSessionId ] = useState( null );
	const [ turnCount, setTurnCount ] = useState( 0 );
	const { invalidateFromToolCalls } = useChatQueryInvalidation();

	/**
	 * Execute a single continuation turn
	 *
	 * @param {string} sessionId          Session ID to continue
	 * @param {Object} queryClient        TanStack Query client
	 * @param {number} selectedPipelineId Pipeline ID for context
	 * @return {Object} API response
	 */
	const continueTurn = useCallback(
		async ( sessionId, queryClient, selectedPipelineId ) => {
			const response = await apiFetch( {
				path: '/datamachine/v1/chat/continue',
				method: 'POST',
				data: { session_id: sessionId },
			} );

			if ( ! response.success ) {
				throw new Error(
					response.message || 'Continue request failed'
				);
			}

			const data = response.data;

			if ( data.new_messages?.length ) {
				appendMessagesToCache( queryClient, sessionId, data.new_messages );
				invalidateFromToolCalls( data.tool_calls, selectedPipelineId );
			}

			return data;
		},
		[ invalidateFromToolCalls ]
	);

	/**
	 * Process turns until completion or max turns reached
	 *
	 * @param {string} sessionId          Session ID to continue
	 * @param {Object} queryClient        TanStack Query client
	 * @param {number} maxTurns           Maximum turns from server response
	 * @param {number} selectedPipelineId Pipeline ID for context
	 * @return {Object} Result with completed status and turn count
	 */
	const processToCompletion = useCallback(
		async ( sessionId, queryClient, maxTurns, selectedPipelineId ) => {
			setProcessingSessionId( sessionId );
			setIsProcessing( true );
			setTurnCount( 0 );

			try {
				let completed = false;
				let turns = 0;
				const effectiveMaxTurns = maxTurns || 12;

				while ( ! completed && turns < effectiveMaxTurns ) {
					const response = await continueTurn(
						sessionId,
						queryClient,
						selectedPipelineId
					);

					completed = response.completed;
					turns++;
					setTurnCount( turns );

					if ( response.max_turns_reached ) {
						break;
					}
				}

				return { completed, turns };
			} finally {
				setIsProcessing( false );
				setProcessingSessionId( null );
			}
		},
		[ continueTurn ]
	);

	return {
		continueTurn,
		processToCompletion,
		isProcessing,
		processingSessionId,
		turnCount,
	};
}
