<?php
/**
 * PendingActionHelper — convenience wrapper for tool handlers that need to
 * stage an invocation for user approval.
 *
 * Tools that opt into ActionPolicy should never hand-roll the store/envelope
 * shape. Call PendingActionHelper::stage() from the 'preview' branch of the
 * tool handler and return its result directly. The AI sees a standardized
 * envelope that tells it to show the preview to the user and wait for
 * confirmation.
 *
 * Envelope shape returned to the AI:
 *
 *   array(
 *       'staged'         => true,
 *       'action_id'      => 'act_abc123',
 *       'kind'           => 'socials_publish_instagram',
 *       'summary'        => 'Post to Instagram: "NEW EP 🎸"',
 *       'preview'        => array( ... preview_data ... ),
 *       'resolve_with'   => 'resolve_pending_action',
 *       'resolve_params' => array( 'action_id' => 'act_abc123', 'decision' => 'accepted'|'rejected' ),
 *       'instruction'    => 'Show the preview to the user and wait for confirmation. Do not auto-accept.',
 *       'expires_at'     => <unix ts>,
 *   )
 *
 * @package DataMachine\Engine\AI\Actions
 * @since   0.72.0
 */

namespace DataMachine\Engine\AI\Actions;

defined( 'ABSPATH' ) || exit;

class PendingActionHelper {

	/**
	 * Stage a tool invocation for user approval.
	 *
	 * @param array $args {
	 *     Staging arguments.
	 *
	 *     @type string   $kind         Required. Handler dispatch key (e.g. 'socials_publish_instagram').
	 *                                  Registered via the `datamachine_pending_action_handlers` filter.
	 *     @type string   $summary      Required. Human-readable one-liner describing what will happen.
	 *                                  Used by the AI to narrate the preview to the user.
	 *     @type array    $apply_input  Required. Input that will replay through the kind's apply callback
	 *                                  when the user accepts. Must be fully self-contained — it's re-sanitized
	 *                                  and re-executed as if the tool were called fresh.
	 *     @type array    $preview_data Optional. Renderable preview payload (copy, images, counts, etc.).
	 *                                  Surfaced in the envelope so the AI can summarize to the user.
	 *     @type string   $action_id    Optional. Pre-generated action identifier. Useful when the caller
	 *                                  has to embed the id in the preview payload (e.g. Gutenberg diff
	 *                                  block attributes) before staging. Must be produced by
	 *                                  PendingActionStore::generate_id(); anything else is replaced.
	 *     @type int|null $agent_id     Optional. Acting agent ID (recorded for audit + can_resolve checks).
	 *     @type int|null $user_id      Optional. Acting user ID (defaults to current user).
	 *     @type array    $context      Optional. Free-form context (session_id, bridge_app, etc.).
	 * }
	 * @return array Envelope for the AI (see class docblock).
	 */
	public static function stage( array $args ): array {
		$kind         = isset( $args['kind'] ) ? sanitize_key( $args['kind'] ) : '';
		$summary      = isset( $args['summary'] ) ? (string) $args['summary'] : '';
		$apply_input  = isset( $args['apply_input'] ) && is_array( $args['apply_input'] ) ? $args['apply_input'] : array();
		$preview_data = isset( $args['preview_data'] ) && is_array( $args['preview_data'] ) ? $args['preview_data'] : array();
		$agent_id     = isset( $args['agent_id'] ) ? (int) $args['agent_id'] : 0;
		$user_id      = isset( $args['user_id'] ) ? (int) $args['user_id'] : get_current_user_id();
		$context      = isset( $args['context'] ) && is_array( $args['context'] ) ? $args['context'] : array();
		$action_id    = isset( $args['action_id'] ) ? (string) $args['action_id'] : '';

		if ( '' === $kind ) {
			return array(
				'staged'      => false,
				'error'       => 'PendingActionHelper::stage() requires a non-empty kind.',
				'error_code'  => 'invalid_kind',
			);
		}

		if ( empty( $apply_input ) ) {
			return array(
				'staged'     => false,
				'error'      => 'PendingActionHelper::stage() requires apply_input.',
				'error_code' => 'missing_apply_input',
			);
		}

		// Accept a caller-supplied action_id only if it matches the generated
		// shape; otherwise produce a fresh one.
		if ( '' === $action_id || ! preg_match( '/^act_[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $action_id ) ) {
			$action_id = PendingActionStore::generate_id();
		}

		$payload = array(
			'kind'         => $kind,
			'summary'      => wp_strip_all_tags( $summary ),
			'apply_input'  => $apply_input,
			'preview_data' => $preview_data,
			'agent_id'     => $agent_id,
			'created_by'   => $user_id,
			'context'      => $context,
		);

		$stored = PendingActionStore::store( $action_id, $payload );
		if ( ! $stored ) {
			return array(
				'staged'     => false,
				'error'      => 'Failed to persist pending action (transient write failed).',
				'error_code' => 'store_failed',
			);
		}

		/**
		 * Fires when a pending action has been staged and is awaiting resolution.
		 *
		 * Hook this to notify users (email, chat ping, etc.), log audit trails,
		 * or mirror the pending action into a visible queue.
		 *
		 * @since 0.72.0
		 *
		 * @param string $action_id Action identifier.
		 * @param array  $payload   Full payload as stored.
		 */
		do_action( 'datamachine_pending_action_staged', $action_id, $payload );

		return array(
			'staged'         => true,
			'action_id'      => $action_id,
			'kind'           => $kind,
			'summary'        => $payload['summary'],
			'preview'        => $preview_data,
			'resolve_with'   => 'resolve_pending_action',
			'resolve_params' => array(
				'action_id' => $action_id,
				'decision'  => '<accepted|rejected>',
			),
			'instruction'    => 'Show this preview to the user and wait for their confirmation. Do not call resolve_pending_action until they explicitly approve or reject. If the user asks to modify, call the original tool again with updated parameters instead of resolving this action.',
			'expires_at'     => time() + HOUR_IN_SECONDS,
		);
	}
}
