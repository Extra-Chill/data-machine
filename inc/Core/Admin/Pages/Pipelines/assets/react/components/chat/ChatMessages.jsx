/**
 * ChatMessages Component
 *
 * Scrollable container for chat message history.
 * Auto-scrolls to bottom on new messages.
 * Groups tool messages by exchange into collapsible elements.
 */

/**
 * WordPress dependencies
 */
import { useRef, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import ChatMessage from './ChatMessage';
import ToolMessage from './ToolMessage';

/**
 * Pair consecutive tool_call and tool_result messages by tool name.
 * Returns array of { toolCall, toolResult } pairs.
 * @param toolMessages
 */
function pairToolMessages( toolMessages ) {
	const pairs = [];
	const callsByName = {};

	toolMessages.forEach( ( msg ) => {
		const type = msg.metadata?.type;
		const toolName = msg.metadata?.tool_name;

		if ( type === 'tool_call' ) {
			callsByName[ toolName ] = { toolCall: msg, toolResult: null };
		} else if ( type === 'tool_result' && callsByName[ toolName ] ) {
			callsByName[ toolName ].toolResult = msg;
			pairs.push( callsByName[ toolName ] );
			delete callsByName[ toolName ];
		}
	} );

	// Handle any orphaned calls (shouldn't happen but be safe)
	Object.values( callsByName ).forEach( ( pair ) => pairs.push( pair ) );

	return pairs;
}

/**
 * Build display items array with regular messages and tool groups in order.
 * Uses position-based grouping to keep tools within their exchange.
 * @param messages
 */
function buildDisplayItems( messages ) {
	const items = [];
	let toolBuffer = [];

	messages.forEach( ( msg ) => {
		const type = msg.metadata?.type;
		const isToolMessage = type === 'tool_call' || type === 'tool_result';

		if ( isToolMessage ) {
			toolBuffer.push( msg );
		} else {
			// Flush any accumulated tools before this message
			if ( toolBuffer.length > 0 ) {
				items.push( {
					type: 'tool_group',
					data: { tools: pairToolMessages( toolBuffer ) },
				} );
				toolBuffer = [];
			}

			// Add the regular message
			if ( msg.role === 'user' || msg.role === 'assistant' ) {
				items.push( { type: 'message', data: msg } );
			}
		}
	} );

	// Flush any remaining tools at end
	if ( toolBuffer.length > 0 ) {
		items.push( {
			type: 'tool_group',
			data: { tools: pairToolMessages( toolBuffer ) },
		} );
	}

	return items;
}

export default function ChatMessages( { messages, isLoading } ) {
	const containerRef = useRef( null );

	useEffect( () => {
		if ( containerRef.current ) {
			containerRef.current.scrollTop = containerRef.current.scrollHeight;
		}
	}, [ messages, isLoading ] );

	const displayItems = buildDisplayItems( messages );

	return (
		<div className="datamachine-chat-messages" ref={ containerRef }>
			{ displayItems.length === 0 && ! isLoading && (
				<div className="datamachine-chat-messages__empty">
					{ __(
						'Ask me to create a pipeline, configure a flow, or help with your automations.',
						'data-machine'
					) }
				</div>
			) }

			{ displayItems.map( ( item, index ) => {
				if ( item.type === 'tool_group' ) {
					const toolKey = item.data.tools
						.map( ( t ) => t.toolCall?.metadata?.tool_name || 'tool' )
						.join( '-' );
					return (
						<ToolMessage
							key={ `tool-group-${ toolKey }-${ index }` }
							tools={ item.data.tools }
						/>
					);
				}
				const msg = item.data;
				const msgKey = `${ msg.role }-${ msg.metadata?.timestamp || index }`;
				return <ChatMessage key={ msgKey } message={ msg } />;
			} ) }

			{ isLoading && (
				<div className="datamachine-chat-messages__typing">
					<span className="datamachine-typing-dot"></span>
					<span className="datamachine-typing-dot"></span>
					<span className="datamachine-typing-dot"></span>
				</div>
			) }
		</div>
	);
}
