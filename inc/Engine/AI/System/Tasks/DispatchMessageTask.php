<?php
/**
 * Dispatch Message System Task.
 *
 * Thin wrapper around the canonical `agents/dispatch-message` ability
 * (agents-api v0.107.0+). Lets Data Machine flows hand an outbound
 * message off to whichever channel transport is registered via the
 * `wp_agent_dispatch_message_handler` filter — without DM needing any
 * knowledge of the underlying transport runtime.
 *
 * This task is to `agents/dispatch-message` what `AgentCallTask` is to
 * `datamachine/agent-call`: it forwards `handler_config.params` straight
 * to the ability and surfaces the canonical output (or WP_Error) in the
 * job result envelope.
 *
 * Expected handler_config shape:
 *
 *     {
 *       "task": "dispatch_message",
 *       "params": {
 *         "channel": "<channel-id>",
 *         "recipient": "<transport-specific recipient id>",
 *         "message": "<text body>",
 *         "conversation_id": null,
 *         "attachments": [],
 *         "client_context": {},
 *         "metadata": {}
 *       }
 *     }
 *
 * Required input: `channel`, `recipient`, `message`. Everything else is
 * optional and passes through to the ability untouched.
 *
 * @package DataMachine\Engine\AI\System\Tasks
 */

namespace DataMachine\Engine\AI\System\Tasks;

defined( 'ABSPATH' ) || exit;

class DispatchMessageTask extends SystemTask {

	/**
	 * Canonical ability slug forwarded to by this task.
	 */
	private const ABILITY_SLUG = 'agents/dispatch-message';

	/**
	 * Get the task type identifier.
	 *
	 * @return string
	 */
	public function getTaskType(): string {
		return 'dispatch_message';
	}

	/**
	 * Get task metadata for admin UI and TaskRegistry.
	 *
	 * @return array
	 */
	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Dispatch Message',
			'description'     => 'Send one outbound message through a registered channel transport via the agents/dispatch-message ability.',
			'setting_key'     => null,
			'default_enabled' => true,
			'trigger'         => 'On-demand via CLI, REST, or pipeline step',
			'trigger_type'    => 'manual',
			'supports_run'    => true,
		);
	}

	/**
	 * Execute dispatch message task.
	 *
	 * Resolves the canonical `agents/dispatch-message` ability and forwards
	 * the configured `params`. Fails the job cleanly when the ability is not
	 * registered or when the ability returns a WP_Error. On success, stores
	 * the canonical output
	 * (`sent`, `channel`, `recipient`, `message_id`, `metadata`) in the
	 * job result envelope alongside a `completed_at` timestamp — mirroring
	 * AgentCallTask's success shape.
	 *
	 * @param int   $jobId  DM Job ID.
	 * @param array $params Task params with `channel`, `recipient`, `message` and optional passthrough fields.
	 */
	public function executeTask( int $jobId, array $params ): void {
		$ability = wp_get_ability( self::ABILITY_SLUG );
		if ( ! $ability ) {
			$this->failJob(
				$jobId,
				sprintf( 'Ability %s not registered.', self::ABILITY_SLUG )
			);
			return;
		}

		$input = $this->extractAbilityInput( $params );

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			$code    = $result->get_error_code();
			$message = $result->get_error_message();
			$detail  = is_string( $code ) && '' !== $code
				? sprintf( '[%s] %s', $code, $message )
				: $message;
			$this->failJob( $jobId, $detail );
			return;
		}

		if ( ! is_array( $result ) ) {
			$this->failJob(
				$jobId,
				sprintf( 'Ability %s returned an unexpected non-array result.', self::ABILITY_SLUG )
			);
			return;
		}

		$this->completeJob(
			$jobId,
			array_merge(
				$result,
				array( 'completed_at' => current_time( 'mysql' ) )
			)
		);
	}

	/**
	 * Pull the canonical ability input out of the task params.
	 *
	 * Accepts either the wrapped shape used by SystemTaskStep
	 * (`{ task, params: { channel, recipient, message, ... } }`) or a
	 * flat shape where the canonical fields are already on `$params`.
	 *
	 * Unknown keys on `$params['params']` pass through untouched so
	 * future ability schema additions do not require a code change here.
	 *
	 * @param array $params Task params from engine_data.
	 * @return array Canonical input map for the ability.
	 */
	private function extractAbilityInput( array $params ): array {
		$inner = isset( $params['params'] ) && is_array( $params['params'] )
			? $params['params']
			: $params;

		// Drop SystemTask scaffolding keys that may leak in when the
		// flat shape is used. The ability's input_schema accepts only
		// the canonical message fields plus optional passthrough.
		unset( $inner['task'], $inner['task_type'] );

		return $inner;
	}
}
