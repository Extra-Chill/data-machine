<?php
/**
 * Audit Logger
 *
 * Thin static helper for recording agent audit trail events.
 * Resolves agent_id and user_id from the current execution context,
 * then delegates to the AgentLog repository.
 *
 * Usage:
 *
 *     AuditLogger::record( 'flow.run', 'allowed', [
 *         'resource_type' => 'flow',
 *         'resource_id'   => 42,
 *         'metadata'      => [ 'pipeline_id' => 7 ],
 *     ] );
 *
 * @package DataMachine\Engine
 * @since 0.42.0
 */

namespace DataMachine\Engine;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Database\Agents\AgentLog;
use DataMachine\Core\Database\Agents\Agents;

defined( 'ABSPATH' ) || exit;

class AuditLogger {

	/**
	 * Record an audit event for the current agent context.
	 *
	 * Resolves agent_id automatically from:
	 * 1. Explicit agent_id in $options
	 * 2. PermissionHelper context (request param, pre-authenticated context)
	 * 3. Owner lookup from acting user
	 *
	 * If no agent can be resolved, the event is silently dropped
	 * (single-agent installs with no agent row).
	 *
	 * @param string $action  Action identifier (e.g. 'flow.run', 'pipeline.create').
	 * @param string $result  Result: 'allowed', 'denied', or 'error'. Default 'allowed'.
	 * @param array  $options {
	 *     Optional parameters.
	 *
	 *     @type int    $agent_id       Explicit agent ID (skips auto-resolution).
	 *     @type int    $user_id        Acting user ID (defaults to PermissionHelper::acting_user_id()).
	 *     @type string $resource_type  Resource type (e.g. 'flow', 'pipeline', 'job').
	 *     @type int    $resource_id    Resource ID.
	 *     @type array  $metadata       Additional context (stored as JSON).
	 * }
	 * @return void
	 */
	public static function record( string $action, string $result = 'allowed', array $options = array() ): void {
		$agent_id = self::resolve_agent_id( $options );

		if ( 0 === $agent_id ) {
			// No agent context — silently skip (single-agent mode without agent row).
			return;
		}

		$user_id = (int) ( $options['user_id'] ?? PermissionHelper::acting_user_id() );

		$log_options = array(
			'user_id' => $user_id,
		);

		if ( ! empty( $options['resource_type'] ) ) {
			$log_options['resource_type'] = $options['resource_type'];
		}

		if ( ! empty( $options['resource_id'] ) ) {
			$log_options['resource_id'] = (int) $options['resource_id'];
		}

		if ( ! empty( $options['metadata'] ) && is_array( $options['metadata'] ) ) {
			$log_options['metadata'] = $options['metadata'];
		}

		$repo = new AgentLog();
		$repo->log( $agent_id, $action, $result, $log_options );

		/**
		 * Fires after an audit event is recorded.
		 *
		 * @since 0.42.0
		 *
		 * @param array $event {
		 *     @type int    $agent_id      Agent ID.
		 *     @type int    $user_id       Acting user ID.
		 *     @type string $action        Action identifier.
		 *     @type string $result        Result (allowed, denied, error).
		 *     @type string $resource_type Resource type.
		 *     @type int    $resource_id   Resource ID.
		 *     @type array  $metadata      Additional context.
		 * }
		 */
		do_action(
			'datamachine_audit_event',
			array_merge(
				array(
					'agent_id' => $agent_id,
					'user_id'  => $user_id,
					'action'   => $action,
					'result'   => $result,
				),
				$log_options
			)
		);
	}

	/**
	 * Resolve agent_id from options or current context.
	 *
	 * @param array $options Options that may contain agent_id.
	 * @return int Agent ID, or 0 if none could be resolved.
	 */
	private static function resolve_agent_id( array $options ): int {
		// 1. Explicit agent_id.
		if ( ! empty( $options['agent_id'] ) ) {
			return (int) $options['agent_id'];
		}

		// 2. Look up from acting user's owned agent.
		$user_id = PermissionHelper::acting_user_id();
		if ( $user_id > 0 ) {
			$agents_repo = new Agents();
			$agent       = $agents_repo->get_by_owner_id( $user_id );

			if ( $agent ) {
				return (int) $agent['agent_id'];
			}
		}

		return 0;
	}
}
