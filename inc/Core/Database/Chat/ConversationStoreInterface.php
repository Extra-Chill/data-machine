<?php
/**
 * Data Machine aggregate conversation store contract.
 *
 * Compatibility seam between Data Machine chat-product operations and the
 * underlying persistence backend. Default implementation ({@see Chat})
 * preserves today's MySQL-table behavior. Consumers can swap in an alternate
 * aggregate store via the `datamachine_conversation_store` filter.
 *
 * This aggregate is intentionally broader than the generic Agents API storage
 * candidate. {@see ConversationTranscriptStoreInterface} is the narrow generic
 * transcript CRUD seam. The other composed interfaces are Data Machine chat
 * product surfaces today: session switcher indexes, read state, retention
 * cleanup, and reporting/metrics. They may become optional Agents API
 * contracts later, but they are not required for a transcript-only backend.
 * Existing chat UI, REST, CLI, and retention callers still request the
 * aggregate via {@see ConversationStoreFactory::get()}, so this split is a
 * contract clarification with no behavior change.
 *
 * Implementations are responsible for:
 * - normalizing messages on read to Data Machine's canonical AI message envelope
 *   (see docs/development/hooks/core-filters.md#message-shape-contract);
 * - preserving per-session identity via UUIDv4 session IDs;
 * - honoring the `(user_id, agent_id, context)` triple when listing.
 *
 * Session-scope policy (ownership checks, token resolution, agent adoption,
 * listing visibility, title generation, retention schedules) stays in the
 * higher-level Data Machine callers
 * ({@see \DataMachine\Api\Chat\ChatOrchestrator},
 * {@see \DataMachine\Abilities\Chat\ChatSessionHelpers}). The store
 * is the dumb persistence layer underneath those product policies.
 *
 * @package DataMachine\Core\Database\Chat
 * @since   next
 */

namespace DataMachine\Core\Database\Chat;

use AgentsAPI\Core\Database\Chat\ConversationTranscriptStoreInterface;

defined( 'ABSPATH' ) || exit;

interface ConversationStoreInterface extends
	ConversationTranscriptStoreInterface,
	ConversationSessionIndexInterface,
	ConversationReadStateInterface,
	ConversationRetentionInterface,
	ConversationReportingInterface {
}
