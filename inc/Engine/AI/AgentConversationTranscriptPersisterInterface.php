<?php
/**
 * Runtime transcript persistence contract.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Persists a completed or failed conversation transcript when requested.
 */
interface AgentConversationTranscriptPersisterInterface {

	/**
	 * Persist a runtime transcript.
	 *
	 * @param array  $messages Final conversation messages.
	 * @param string $provider Provider identifier.
	 * @param string $model    Model identifier.
	 * @param array  $payload  Adapter payload.
	 * @param array  $result   Loop result so far.
	 * @return string Session ID on success, empty string when not persisted.
	 */
	public function persist( array $messages, string $provider, string $model, array $payload, array $result ): string;
}
