<?php
/**
 * PendingActionHelper — convenience wrapper for tool handlers that need to
 * stage an invocation for user approval.
 *
 * Tools that opt into WP_Agent_Action_Policy should never hand-roll the store/envelope
 * shape. Call PendingActionHelper::stage() from the 'preview' branch of the
 * tool handler and return its result directly. The AI sees the Agents API
 * approval-required envelope: read pending_action.action_id and call
 * `resolve_pending_action` with `accepted` or `rejected` once the user
 * decides.
 *
 * @package DataMachine\Engine\AI\Actions
 * @since   0.72.0
 */

namespace DataMachine\Engine\AI\Actions;

use AgentsAPI\AI\WP_Agent_Message;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action;

defined( 'ABSPATH' ) || exit;

class PendingActionHelper {

	/**
	 * Stage a tool invocation for user approval.
	 *
	 * The returned value is an Agents API approval_required envelope. The
	 * envelope payload carries a `pending_action` value object plus the
	 * `resolve_with` / `resolve_params` directives the AI uses to confirm
	 * or reject the invocation.
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
	 *     @type array    $resolver_grants Optional. Explicit non-human resolver grants.
	 * }
	 * @return array Agents API approval_required envelope, or a `staged=>false`
	 *               error array when staging fails.
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
		$grants       = isset( $args['resolver_grants'] ) && is_array( $args['resolver_grants'] ) ? $args['resolver_grants'] : array();
		$metadata     = isset( $args['metadata'] ) && is_array( $args['metadata'] ) ? $args['metadata'] : array();

		if ( '' === $kind ) {
			return array(
				'staged'     => false,
				'error'      => 'PendingActionHelper::stage() requires a non-empty kind.',
				'error_code' => 'invalid_kind',
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

		$grants = apply_filters( 'datamachine_pending_action_resolver_grants', $grants, $args );
		$grants = is_array( $grants ) ? array_values( array_filter( $grants, 'is_array' ) ) : array();

		$datamachine_metadata    = isset( $metadata['datamachine'] ) && is_array( $metadata['datamachine'] ) ? $metadata['datamachine'] : array();
		$metadata['datamachine'] = array_merge(
			$datamachine_metadata,
			array(
				'agent_id'        => $agent_id,
				'created_by'      => $user_id,
				'context'         => $context,
				'resolver_grants' => $grants,
				'resolve_with'    => 'resolve_pending_action',
			)
		);

		$payload = array(
			'kind'            => $kind,
			'summary'         => wp_strip_all_tags( $summary ),
			'apply_input'     => $apply_input,
			'preview_data'    => $preview_data,
			'resolver_grants' => $grants,
			'agent_id'        => $agent_id,
			'agent'           => $agent_id > 0 ? 'agent:' . $agent_id : null,
			'created_by'      => $user_id,
			'creator'         => $user_id > 0 ? 'user:' . $user_id : null,
			'context'         => $context,
			'metadata'        => $metadata,
		);
		if ( isset( $args['ttl'] ) ) {
			$payload['ttl'] = (int) $args['ttl'];
		}
		if ( isset( $args['expires_at'] ) ) {
			$payload['expires_at'] = $args['expires_at'];
		}

		$stored = PendingActionStore::store( $action_id, $payload );
		if ( ! $stored ) {
			return array(
				'staged'     => false,
				'error'      => 'Failed to persist pending action.',
				'error_code' => 'store_failed',
			);
		}

		$stored_payload = PendingActionStore::get( $action_id ) ?? $payload;

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

		$expires_at = isset( $stored_payload['expires_at'] ) ? (int) $stored_payload['expires_at'] : null;
		$created_at = isset( $stored_payload['created_at'] ) ? (int) $stored_payload['created_at'] : time();

		$pending_action = array(
			'action_id'       => $action_id,
			'kind'            => $kind,
			'summary'         => $payload['summary'],
			'preview'         => $preview_data,
			'apply_input'     => $apply_input,
			'resolver_grants' => $grants,
			'creator'         => $payload['creator'],
			'agent'           => $payload['agent'],
			'metadata'        => $payload['metadata'],
			'created_at'      => gmdate( 'c', $created_at ),
			'expires_at'      => null !== $expires_at ? gmdate( 'c', $expires_at ) : null,
		);

		if ( class_exists( WP_Agent_Pending_Action::class ) ) {
			$pending_action = WP_Agent_Pending_Action::from_array( $pending_action )->to_array();
		}

		$envelope_payload = array(
			'pending_action' => $pending_action,
			'resolve_with'   => 'resolve_pending_action',
			'resolve_params' => array(
				'action_id' => $action_id,
				'decision'  => '<accepted|rejected>',
			),
			'instruction'    => 'Show this preview to the user and wait for their confirmation. Do not call resolve_pending_action until they explicitly approve or reject. If the user asks to modify, call the original tool again with updated parameters instead of resolving this action.',
		);

		$envelope_metadata = array(
			'adapter'     => 'data-machine',
			'datamachine' => array(
				'kind'         => $kind,
				'resolve_with' => 'resolve_pending_action',
			),
		);

		$envelope = method_exists( WP_Agent_Message::class, 'approvalRequired' )
			? WP_Agent_Message::approvalRequired( $payload['summary'], $envelope_payload, $envelope_metadata )
			: array(
				'schema'   => WP_Agent_Message::SCHEMA,
				'version'  => WP_Agent_Message::VERSION,
				'type'     => WP_Agent_Message::TYPE_APPROVAL_REQUIRED,
				'role'     => 'tool',
				'content'  => $payload['summary'],
				'payload'  => $envelope_payload,
				'metadata' => $envelope_metadata,
			);

		// Surface staged + action_id at the top level so internal callers
		// (content abilities, ToolExecutor, bundle/memory pending actions)
		// can detect success and reference the new pending action without
		// digging into the envelope payload. Everything else — kind,
		// summary, preview, resolve_with, instruction, timestamps — lives
		// inside the Agents API envelope payload (envelope.payload.pending_action).
		return array_merge(
			$envelope,
			array(
				'staged'    => true,
				'action_id' => $action_id,
			)
		);
	}
}
