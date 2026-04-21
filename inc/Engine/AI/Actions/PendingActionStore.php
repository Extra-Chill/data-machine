<?php
/**
 * PendingActionStore — server-side storage for tool invocations awaiting user resolution.
 *
 * When a tool runs in preview mode (see ActionPolicyResolver), the pending
 * invocation is stored here instead of being applied immediately. The
 * datamachine/resolve-pending-action ability later retrieves the stored
 * payload and either replays it (`accepted`) or discards it (`rejected`).
 *
 * This is the generic successor to PendingDiffStore. PendingDiffStore was
 * scoped to post-content diffs (`edit_post_blocks`, `replace_post_blocks`,
 * `insert_content`); PendingActionStore is kind-agnostic and supports any
 * tool that opts into the preview/approve workflow (socials publishes,
 * destructive ops, account mutations, etc.).
 *
 * Payload shape:
 *
 *   array(
 *       'kind'          => 'socials_publish_instagram',  // handler dispatch key
 *       'summary'       => 'Post to Instagram: "NEW EP 🎸"',
 *       'preview_data'  => array( ... ),  // UI-oriented preview payload
 *       'apply_input'   => array( ... ),  // replayable handler input
 *       'created_by'    => 123,            // user_id (or 0 if anonymous)
 *       'agent_id'      => 7,              // acting agent, if any
 *       'context'       => array( ... ),   // free-form (session_id, bridge_app, etc.)
 *   )
 *
 * Uses WordPress transients with a 1-hour TTL. Stale pending actions
 * auto-expire if never resolved.
 *
 * @package DataMachine\Engine\AI\Actions
 * @since   0.72.0
 */

namespace DataMachine\Engine\AI\Actions;

defined( 'ABSPATH' ) || exit;

class PendingActionStore {

	/**
	 * Transient TTL in seconds (1 hour).
	 */
	private const TTL = HOUR_IN_SECONDS;

	/**
	 * Transient key prefix. Short to keep option_name under DB limits.
	 */
	private const PREFIX = 'dm_pa_';

	/**
	 * Store a pending action.
	 *
	 * The caller is responsible for building a well-formed payload. The
	 * store stamps a created_at timestamp and the action_id before persisting.
	 *
	 * @param string $action_id Unique action identifier.
	 * @param array  $payload   Pending action payload (see class docblock).
	 * @return bool Whether the transient was written.
	 */
	public static function store( string $action_id, array $payload ): bool {
		$payload['created_at'] = time();
		$payload['action_id']  = $action_id;

		return set_transient( self::PREFIX . $action_id, $payload, self::TTL );
	}

	/**
	 * Retrieve a pending action payload.
	 *
	 * @param string $action_id Action identifier.
	 * @return array|null The payload, or null if not found / expired.
	 */
	public static function get( string $action_id ): ?array {
		$data = get_transient( self::PREFIX . $action_id );

		if ( false === $data || ! is_array( $data ) ) {
			return null;
		}

		return $data;
	}

	/**
	 * Delete a pending action (called after resolution).
	 *
	 * @param string $action_id Action identifier.
	 * @return bool Whether the transient was deleted.
	 */
	public static function delete( string $action_id ): bool {
		return delete_transient( self::PREFIX . $action_id );
	}

	/**
	 * Generate a unique action identifier.
	 *
	 * @return string A namespaced UUID.
	 */
	public static function generate_id(): string {
		return 'act_' . wp_generate_uuid4();
	}
}
