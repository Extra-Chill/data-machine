<?php
/**
 * Aggregate conversation store contract.
 *
 * Single seam between chat session operations and the underlying
 * persistence backend. Default implementation ({@see Chat}) preserves
 * today's MySQL-table behavior. Consumers can swap in an alternate
 * aggregate store via the `datamachine_conversation_store` filter.
 *
 * The narrower contracts this interface composes are the reusable seams
 * for future adapters that only own part of the conversation surface:
 * transcript/session CRUD, session indexes, read state, retention cleanup,
 * and reporting/metrics. Existing callers still request the aggregate via
 * {@see ConversationStoreFactory::get()}, so this split is a contract
 * clarification with no behavior change.
 *
 * Implementations are responsible for:
 * - normalizing messages on read to Data Machine message shape
 *   (see docs/development/hooks/core-filters.md#message-shape-contract);
 * - preserving per-session identity via UUIDv4 session IDs;
 * - honoring the `(user_id, agent_id, context)` triple when listing.
 *
 * Session-scope policy (ownership checks, token resolution, agent
 * adoption, title generation) stays in the higher-level callers
 * ({@see \DataMachine\Api\Chat\ChatOrchestrator},
 * {@see \DataMachine\Abilities\Chat\ChatSessionHelpers}). The store
 * is the dumb persistence layer underneath.
 *
 * @package DataMachine\Core\Database\Chat
 * @since   next
 */

namespace DataMachine\Core\Database\Chat;

defined( 'ABSPATH' ) || exit;

interface ConversationStoreInterface extends
	ConversationTranscriptStoreInterface,
	ConversationSessionIndexInterface,
	ConversationReadStateInterface,
	ConversationRetentionInterface,
	ConversationReportingInterface {
}
