<?php
/**
 * ResolvePendingAction chat tool.
 *
 * Thin chat wrapper around datamachine/resolve-pending-action. Exposes the
 * accept/reject decision to the AI so it can finalize user approval of a
 * staged tool invocation.
 *
 * This tool is always 'direct' — resolving a pending action is itself the
 * confirmation step and must not be staged for further approval (that would
 * loop forever).
 *
 * @package DataMachine\Engine\AI\Actions
 * @since   0.72.0
 */

namespace DataMachine\Engine\AI\Actions;

use DataMachine\Engine\AI\Tools\BaseTool;

defined( 'ABSPATH' ) || exit;

class ResolvePendingAction extends BaseTool {

	public function __construct() {
		$this->registerTool(
			'resolve_pending_action',
			array( $this, 'getToolDefinition' ),
			array( 'chat' ),
			array( 'ability' => 'datamachine/resolve-pending-action' )
		);
	}

	/**
	 * Tool definition surfaced to the AI.
	 *
	 * @return array
	 */
	public function getToolDefinition(): array {
		return array(
			'class'         => self::class,
			'method'        => 'handle_tool_call',
			'description'   => 'Resolve a pending action (staged by a publish/write tool with action_policy=preview). Call with decision=accepted to apply, decision=rejected to discard. Only call this after the user has explicitly approved or rejected the preview. Do not guess the user\'s intent — if the user wants changes, call the original tool again with new parameters instead of accepting.',
			'parameters'    => array(
				'action_id' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'The action_id returned by the preview envelope.',
				),
				'decision'  => array(
					'type'        => 'string',
					'required'    => true,
					'enum'        => array( 'accepted', 'rejected' ),
					'description' => 'accepted to apply the staged action, rejected to discard it.',
				),
			),
			// Resolving is always direct — this IS the confirmation step.
			'action_policy' => ActionPolicyResolver::POLICY_DIRECT,
		);
	}

	/**
	 * Handle chat tool call.
	 *
	 * @param array $parameters Tool parameters from AI agent.
	 * @param array $tool_def   Tool definition context.
	 * @return array Result for AI agent.
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$action_id = isset( $parameters['action_id'] ) ? sanitize_text_field( $parameters['action_id'] ) : '';
		$decision  = isset( $parameters['decision'] ) ? sanitize_text_field( $parameters['decision'] ) : '';

		if ( '' === $action_id || '' === $decision ) {
			return $this->buildErrorResponse( 'action_id and decision are required.', 'resolve_pending_action' );
		}

		$result = ResolvePendingActionAbility::execute(
			array(
				'action_id' => $action_id,
				'decision'  => $decision,
			)
		);

		return $result;
	}
}
