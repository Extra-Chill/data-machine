/**
 * Chat Message Normalizer
 *
 * Converts Data Machine's native message format to the
 * @extrachill/chat normalized message model.
 *
 * DM format:
 *   { role, content, tool_calls?, metadata?: { type, tool_name, timestamp, success } }
 *
 * @extrachill/chat format:
 *   { id, role, content, timestamp, toolCalls?, toolResult? }
 */

let idCounter = 0;

function generateId() {
	return `dm_${ Date.now() }_${ ++idCounter }`;
}

/**
 * Normalize a single DM message to @extrachill/chat format.
 *
 * @param {Object} msg    Raw DM message
 * @param {number} index  Position in the array (fallback for key generation)
 * @return {Object} Normalized ChatMessage
 */
function normalizeMessage( msg, index ) {
	const metaType = msg.metadata?.type;
	const timestamp = msg.metadata?.timestamp || new Date().toISOString();
	const id = `dm_${ index }_${ timestamp }`;

	// Tool call messages
	if ( metaType === 'tool_call' ) {
		return {
			id,
			role: 'tool_call',
			content: msg.content || '',
			timestamp,
			toolCalls: [ {
				id: generateId(),
				name: msg.metadata?.tool_name || 'unknown',
				parameters: msg.tool_calls?.[ 0 ]?.parameters || {},
			} ],
		};
	}

	// Tool result messages
	if ( metaType === 'tool_result' ) {
		return {
			id,
			role: 'tool_result',
			content: msg.content || '',
			timestamp,
			toolResult: {
				toolName: msg.metadata?.tool_name || 'unknown',
				success: msg.metadata?.success ?? true,
			},
		};
	}

	// Regular user/assistant messages
	const normalized = {
		id,
		role: msg.role,
		content: msg.content || '',
		timestamp,
	};

	// Assistant messages may carry tool_calls array
	if ( msg.role === 'assistant' && msg.tool_calls?.length ) {
		normalized.toolCalls = msg.tool_calls.map( ( tc ) => ( {
			id: generateId(),
			name: tc.function?.name || tc.name || 'unknown',
			parameters: tc.parameters || {},
		} ) );
	}

	return normalized;
}

/**
 * Normalize an array of DM messages.
 *
 * @param {Array} messages Raw DM message array
 * @return {Array} Normalized ChatMessage array
 */
export function normalizeMessages( messages ) {
	if ( ! Array.isArray( messages ) ) {
		return [];
	}
	return messages.map( normalizeMessage );
}
