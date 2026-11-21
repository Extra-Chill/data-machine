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
 * `message`, `recipient` and `conversation_id` may contain `{{...}}`
 * placeholders resolved against the most recent upstream DataPacket, so a
 * fetch step (e.g. `webhook_payload`) can feed request data into the
 * outbound message:
 *
 *     {{title}}               packet data.title
 *     {{content}} / {{body}}  packet data.body
 *     {{metadata.<dot.path>}} packet metadata value
 *
 * Unresolved placeholders render as an empty string. With no upstream
 * packet the configured values are forwarded unchanged.
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
	 * Ability input fields that support `{{...}}` placeholders.
	 */
	private const TEMPLATED_FIELDS = array( 'message', 'recipient', 'conversation_id' );

	/**
	 * Execution scaffolding SystemTaskStep merges into the flat task params.
	 * These describe the pipeline run, not the outbound message, and must
	 * never reach the ability input.
	 */
	private const SCAFFOLDING_KEYS = array(
		'task',
		'task_type',
		'pipeline_job_id',
		'pipeline_step_id',
		'scheduled_at',
		'agent_id',
		'agent_slug',
		'user_id',
		'job',
		'flow_id',
		'flow_step_id',
		'data_packets',
		'engine_data',
		'job_id',
		'pipeline_id',
		'post_id',
	);

	/**
	 * Get the task type identifier.
	 *
	 * @return string
	 */
	public function getTaskType(): string {
		return 'dispatch_message';
	}

	/**
	 * Receive upstream DataPackets so placeholders can be resolved.
	 *
	 * @return bool
	 */
	public function needsPipelineContext(): bool {
		return true;
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

		$input = $this->resolvePlaceholders(
			$this->extractAbilityInput( $params ),
			$this->latestPacket( $params )
		);

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

		// Drop SystemTask scaffolding keys that leak in when the flat
		// shape is used. The ability's input_schema accepts only the
		// canonical message fields plus optional passthrough.
		foreach ( self::SCAFFOLDING_KEYS as $key ) {
			unset( $inner[ $key ] );
		}

		return $inner;
	}

	/**
	 * Most recent upstream DataPacket (packets are newest-first).
	 *
	 * @param array $params Task params from engine_data.
	 * @return array|null Packet array, or null when none was handed over.
	 */
	private function latestPacket( array $params ): ?array {
		$packets = $params['data_packets'] ?? null;
		if ( ! is_array( $packets ) || array() === $packets ) {
			return null;
		}

		$packet = reset( $packets );
		return is_array( $packet ) ? $packet : null;
	}

	/**
	 * Resolve `{{...}}` placeholders in the templated fields.
	 *
	 * @param array      $input  Canonical ability input.
	 * @param array|null $packet Upstream packet, or null.
	 * @return array Input with placeholders resolved.
	 */
	private function resolvePlaceholders( array $input, ?array $packet ): array {
		if ( null === $packet ) {
			return $input;
		}

		$data    = is_array( $packet['data'] ?? null ) ? $packet['data'] : array();
		$context = array(
			'title'    => $data['title'] ?? '',
			'content'  => $data['body'] ?? '',
			'body'     => $data['body'] ?? '',
			'metadata' => is_array( $packet['metadata'] ?? null ) ? $packet['metadata'] : array(),
		);

		foreach ( self::TEMPLATED_FIELDS as $field ) {
			if ( ! isset( $input[ $field ] ) || ! is_string( $input[ $field ] ) || false === strpos( $input[ $field ], '{{' ) ) {
				continue;
			}

			$input[ $field ] = (string) preg_replace_callback(
				'/\{\{\s*([A-Za-z0-9_.-]+)\s*\}\}/',
				static function ( array $matches ) use ( $context ): string {
					return self::stringifyValue( self::getPath( $context, $matches[1] ) );
				},
				$input[ $field ]
			);
		}

		return $input;
	}

	/**
	 * Walk a dot path through nested arrays.
	 *
	 * @param array  $source Source array.
	 * @param string $path   Dot path.
	 * @return mixed Value, or null when any segment is missing.
	 */
	private static function getPath( array $source, string $path ) {
		$value = $source;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( '' === $segment || ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return null;
			}
			$value = $value[ $segment ];
		}

		return $value;
	}

	/**
	 * Render a resolved value as message text.
	 *
	 * @param mixed $value Resolved value.
	 * @return string Text; '' for null.
	 */
	private static function stringifyValue( $value ): string {
		if ( null === $value ) {
			return '';
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $encoded ) ? $encoded : '';
	}
}
