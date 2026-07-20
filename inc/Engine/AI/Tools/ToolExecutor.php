<?php
/**
 * Universal AI tool execution infrastructure.
 *
 * Shared tool execution logic used by both Chat and Pipeline agents.
 * Handles tool discovery, validation, execution, and parameter building.
 *
 * @package DataMachine\Engine\AI\Tools
 * @since   0.2.0
 */

namespace DataMachine\Engine\AI\Tools;

use AgentsAPI\AI\Tools\WP_Agent_Action_Policy;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Execution_Core;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Workspace\WordPressWorkspaceScope;
use DataMachine\Core\WordPress\PostTracking;
use DataMachine\Engine\AI\Actions\ActionPolicyResolver;
use DataMachine\Engine\AI\Actions\PendingActionHelper;
use DataMachine\Engine\AI\Tools\Execution\ToolExecutionCore;

defined( 'ABSPATH' ) || exit;

class ToolExecutor {


	/**
	 * Execute tool with parameter preparation and comprehensive error handling.
	 * Runtime context values only satisfy parameters through explicit tool bindings.
	 *
	 * Before invoking the tool handler, consults ActionPolicyResolver to
	 * decide whether the invocation should execute directly, be staged for
	 * user approval (preview), or be refused (forbidden). Tools opt into
	 * preview/forbidden via metadata; unopted tools resolve to 'direct' and
	 * behave identically to pre-WP_Agent_Action_Policy releases.
	 *
	 * The `$mode` / `$agent_id` / `$client_context` parameters were added in
	 * 0.72.0 so the resolver has enough context to apply per-agent and
	 * per-mode policy. Callers that pre-date the feature (pipeline code that
	 * goes through `getAvailableTools()`) continue to work — mode defaults
	 * to MODE_CHAT which matches the historical assumption, and missing
	 * agent_id simply skips per-agent policy.
	 *
	 * @param  string $tool_name       Tool name to execute.
	 * @param  array  $tool_parameters Parameters from AI.
	 * @param  array  $available_tools Available tools array.
	 * @param  array  $payload         Step payload (job_id, flow_step_id, data, flow_step_config).
	 * @param  string $mode            Agent mode (chat/pipeline/system). Default: chat.
	 * @param  int    $agent_id        Acting agent ID (0 = no per-agent policy).
	 * @param  array  $client_context  Optional client-supplied context.
	 * @return array Tool execution result.
	 */
	public static function executeTool(
		string $tool_name,
		array $tool_parameters,
		array $available_tools,
		array $payload,
		string $mode = ActionPolicyResolver::MODE_CHAT,
		int $agent_id = 0,
		array $client_context = array()
	): array {
		$core           = new WP_Agent_Tool_Execution_Core();
		$execution      = new ToolExecutionCore();
		$tool_context                   = array_merge( $client_context, $payload );
		$tool_context['client_context'] = $client_context;
		$tool_context['caller_context'] = $payload;
		$prepared       = $core->prepareWP_Agent_Tool_Call( $tool_name, $tool_parameters, $available_tools, $tool_context );
		if ( empty( $prepared['ready'] ) ) {
			unset( $prepared['ready'] );
			return $prepared;
		}

		$tool_def            = $prepared['tool_def'];
		$tool_call           = $prepared['tool_call'];
		$complete_parameters = is_array( $tool_call['parameters'] ?? null ) ? $tool_call['parameters'] : array();
		$audit_context       = self::buildToolAuditContext( $tool_name, $complete_parameters, $tool_def, $payload, $agent_id, $client_context );

		if ( 'client' === (string) ( $tool_def['executor'] ?? '' ) || ! empty( $tool_def['external_executor'] ) ) {
			return array(
				'success'   => false,
				'error'     => sprintf( 'Tool "%s" is declared for client-side execution and cannot be executed by the Data Machine PHP tool executor.', $tool_name ),
				'tool_name' => $tool_name,
				'executor'  => 'client',
			);
		}

		// Resolve the action policy for this invocation. Tools without
		// action_policy metadata resolve to 'direct' and behave exactly
		// as before this feature landed.
		$resolver = new ActionPolicyResolver();
		$policy   = $resolver->resolveForTool(
			array_merge(
				self::buildActionPolicyContext( $payload, $agent_id, $client_context ),
				array(
					'tool_name' => $tool_name,
					'tool_def'  => $tool_def,
					'mode'      => $mode,
					'input'     => $complete_parameters,
				)
			)
		);

		if ( WP_Agent_Action_Policy::refusesExecution( $policy ) ) {
			self::dispatchToolAudit( array_merge( $audit_context, array( 'result_status' => 'forbidden' ) ) );

			return array(
				'success'        => false,
				'error'          => sprintf( 'Tool "%s" is not permitted in the current context (action_policy=forbidden).', $tool_name ),
				'tool_name'      => $tool_name,
				'action_policy'  => $policy,
				'audit_context'  => array_merge( $audit_context, array( 'result_status' => 'forbidden' ) ),
			);
		}

		if ( WP_Agent_Action_Policy::stagesApproval( $policy ) ) {
			// Tool must declare the pending-action kind. Preview is an approval
			// contract, not metadata we can synthesize safely at execution time.
			if ( empty( $tool_def['action_kind'] ) ) {
				do_action(
					'datamachine_log',
					'error',
					'WP_Agent_Action_Policy: tool resolved to preview but is missing action_kind metadata; refusing execution.',
					array(
						'tool_name' => $tool_name,
						'mode'      => $mode,
						'agent_id'  => $agent_id,
					)
				);

				return array(
					'success'       => false,
					'error'         => sprintf( 'Tool "%s" resolved to staged approval but is missing required pending-action metadata: action_kind.', $tool_name ),
					'tool_name'     => $tool_name,
					'action_policy' => $policy,
					'metadata'      => array(
						'error_type'       => 'missing_pending_action_metadata',
						'missing_metadata' => array( 'action_kind' ),
					),
				);
			} else {
				$staged = PendingActionHelper::stage(
					array(
						'kind'         => (string) $tool_def['action_kind'],
						'summary'      => self::buildActionSummary( $tool_name, $complete_parameters, $tool_def ),
						'apply_input'  => $complete_parameters,
						'preview_data' => self::buildActionPreviewData( $complete_parameters, $tool_def ),
						'agent_id'     => $agent_id,
						'user_id'      => self::resolveActingUserId( $payload, $client_context ),
						'context'      => array_filter(
							array(
								'mode'           => $mode,
								'tool_name'      => $tool_name,
								'job_id'         => $payload['job_id'] ?? null,
								'session_id'     => $client_context['session_id'] ?? null,
								'bridge_app'     => $client_context['bridge_app'] ?? null,
							),
							fn( $v ) => null !== $v && '' !== $v
						),
						'metadata'     => array(
							'datamachine' => array(
								'audit_context' => array_merge( $audit_context, array( 'result_status' => 'staged' ) ),
							),
						),
					)
				);
				self::dispatchToolAudit( array_merge( $audit_context, array( 'result_status' => ! empty( $staged['staged'] ) ? 'staged' : 'stage_failed' ) ) );

				return array_merge(
					array(
						'success'       => true,
						'tool_name'     => $tool_name,
						'action_policy' => $policy,
					),
					$staged
				);
			}
		}

		// Policy is 'direct' — execute the tool normally.
		$tool_result = $core->executePreparedTool( $tool_call, $tool_def, $execution, $tool_context );
		$tool_result = self::attachToolAuditContext( $tool_result, array_merge( $audit_context, array( 'result_status' => ! empty( $tool_result['success'] ) ? 'success' : 'error' ) ) );
		self::dispatchToolAudit( $tool_result['metadata']['datamachine']['audit_context'] ?? array_merge( $audit_context, array( 'result_status' => ! empty( $tool_result['success'] ) ? 'success' : 'error' ) ) );

		// Automatic post origin tracking — applies to every tool whose result
		// contains an extractable post_id. This covers both handler tools
		// (Publish/Update base classes) and ability tools that create or
		// modify posts (PublishWordPressAbility, InsertContentAbility, wiki
		// create/update, third-party abilities, etc.). update_post_meta() is
		// idempotent, so callers that already stamped tracking themselves
		// (e.g. legacy handler base classes before this was centralized) are
		// safe to run through this path without double-writes.
		if ( ! empty( $tool_result['success'] ) ) {
			$post_id = PostTracking::extractPostId( $tool_result );
			if ( $post_id > 0 ) {
				$job_id = (int) ( $payload['job_id'] ?? 0 );
				PostTracking::store( $post_id, $tool_def, $job_id );
			}
		}

		return $tool_result;
	}

	/**
	 * Build safe audit metadata for a tool invocation.
	 *
	 * @param string $tool_name       Tool name.
	 * @param array  $parameters      Complete tool parameters.
	 * @param array  $tool_def        Tool definition.
	 * @param array  $payload         Data Machine loop payload.
	 * @param int    $agent_id        Acting agent ID.
	 * @param array  $client_context  Client context.
	 * @return array<string,mixed>
	 */
	private static function buildToolAuditContext( string $tool_name, array $parameters, array $tool_def, array $payload, int $agent_id, array $client_context ): array {
		return array_filter(
			array(
				'tool_name'           => $tool_name,
				'tool_source'         => self::toolSourceSummary( $tool_def ),
				'principal_context'   => self::buildSafePrincipalContext( $payload, $agent_id, $client_context ),
				'parameters_redacted' => self::redactForAudit( $parameters ),
				'executed_at'         => gmdate( 'c' ),
			),
			static fn( $value ): bool => null !== $value && array() !== $value && '' !== $value
		);
	}

	/**
	 * Attach audit metadata to a tool result without replacing existing metadata.
	 *
	 * @param array $tool_result   Tool result.
	 * @param array $audit_context Safe audit context.
	 * @return array<string,mixed>
	 */
	private static function attachToolAuditContext( array $tool_result, array $audit_context ): array {
		$metadata                        = is_array( $tool_result['metadata'] ?? null ) ? $tool_result['metadata'] : array();
		$datamachine                     = is_array( $metadata['datamachine'] ?? null ) ? $metadata['datamachine'] : array();
		$datamachine['audit_context']    = $audit_context;
		$metadata['datamachine']         = $datamachine;
		$tool_result['metadata']         = $metadata;
		$tool_result['audit_context']    = $audit_context;

		return $tool_result;
	}

	/**
	 * Emit a generic audit hook for integrations that persist external logs.
	 *
	 * @param array $audit_context Safe audit context.
	 */
	private static function dispatchToolAudit( array $audit_context ): void {
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}

		do_action( 'datamachine_tool_execution_audit', $audit_context );
	}

	/**
	 * Build a non-secret principal summary for audit and review surfaces.
	 *
	 * @param array $payload        Loop payload.
	 * @param int   $agent_id       Acting agent ID.
	 * @param array $client_context Client context.
	 * @return array<string,mixed>
	 */
	private static function buildSafePrincipalContext( array $payload, int $agent_id, array $client_context ): array {
		$principal = class_exists( PermissionHelper::class ) ? PermissionHelper::get_execution_principal() : null;
		$context   = array();

		if ( null !== $principal ) {
			$owner = $principal->conversation_owner();
			$context = array_filter(
				array(
					'principal_class'    => $principal->auth_source,
					'auth_source'        => $principal->auth_source,
					'request_context'    => $principal->request_context,
					'effective_agent_id' => $principal->effective_agent_id,
					'acting_user_id'     => $principal->acting_user_id > 0 ? $principal->acting_user_id : null,
					'token_id'           => $principal->token_id,
					'workspace_id'       => $principal->workspace_id,
					'client_id'          => $principal->client_id,
					'owner_type'         => is_array( $owner ) ? ( $owner['type'] ?? null ) : null,
				),
				static fn( $value ): bool => null !== $value && '' !== $value
			);
		}

		$user_id = self::resolveActingUserId( $payload, $client_context );
		if ( $user_id > 0 && empty( $context['acting_user_id'] ) ) {
			$context['acting_user_id'] = $user_id;
		}

		if ( $agent_id > 0 && empty( $context['effective_agent_id'] ) ) {
			$context['effective_agent_id'] = 'agent:' . $agent_id;
		}

		if ( empty( $context['principal_class'] ) ) {
			$context['principal_class'] = class_exists( PermissionHelper::class ) && PermissionHelper::in_agent_context() ? 'agent_token' : ( $user_id > 0 ? 'user' : 'system' );
		}
		$context['credential_scope'] = self::credentialScopeForPrincipalClass( (string) $context['principal_class'] );

		foreach ( array( 'session_id', 'transcript_session_id', 'request_id' ) as $key ) {
			$value = self::firstNonEmptyString( $payload, $client_context, $key );
			if ( '' !== $value ) {
				$context[ $key ] = $value;
			}
		}

		return $context;
	}

	/**
	 * Map principal classes to generic credential scopes.
	 *
	 * @param string $principal_class Principal/auth source.
	 * @return string Generic credential scope.
	 */
	private static function credentialScopeForPrincipalClass( string $principal_class ): string {
		return match ( $principal_class ) {
			'agent_token' => 'agent',
			'user', 'application_password' => 'user',
			'runtime', 'audience' => 'runtime',
			default => 'site',
		};
	}

	/**
	 * Return a stable source/provider summary for a tool definition.
	 *
	 * @param array $tool_def Tool definition.
	 * @return array<string,string>|null
	 */
	private static function toolSourceSummary( array $tool_def ): ?array {
		$source = array();
		foreach ( array( 'source', 'provider', 'category', 'class', 'method', 'ability', 'handler' ) as $key ) {
			if ( isset( $tool_def[ $key ] ) && is_scalar( $tool_def[ $key ] ) && '' !== trim( (string) $tool_def[ $key ] ) ) {
				$source[ $key ] = (string) $tool_def[ $key ];
			}
		}

		return empty( $source ) ? null : $source;
	}

	/**
	 * Redact secrets and oversized values from audit metadata.
	 *
	 * @param mixed $value Value to redact.
	 * @param string $key Current key.
	 * @return mixed Redacted value.
	 */
	private static function redactForAudit( $value, string $key = '' ) {
		if ( '' !== $key && preg_match( '/(token|secret|password|pass|cookie|authorization|auth|key|credential|nonce|session)/i', $key ) ) {
			return '[redacted]';
		}

		if ( is_array( $value ) ) {
			$redacted = array();
			foreach ( $value as $child_key => $child_value ) {
				$redacted[ $child_key ] = self::redactForAudit( $child_value, is_scalar( $child_key ) ? (string) $child_key : '' );
			}
			return $redacted;
		}

		if ( is_string( $value ) && strlen( $value ) > 500 ) {
			return substr( $value, 0, 500 ) . '... [truncated]';
		}

		return is_scalar( $value ) || null === $value ? $value : '[' . gettype( $value ) . ']';
	}

	/**
	 * Resolve the acting user ID from explicit context or PermissionHelper.
	 *
	 * @param array $payload        Loop payload.
	 * @param array $client_context Client context.
	 * @return int Acting user ID, or 0.
	 */
	private static function resolveActingUserId( array $payload, array $client_context ): int {
		$user_id = self::firstPositiveInt( $payload, $client_context, 'acting_user_id', 'calling_user_id', 'user_id' );
		if ( $user_id > 0 ) {
			return $user_id;
		}

		if ( class_exists( PermissionHelper::class ) ) {
			return PermissionHelper::acting_user_id();
		}

		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}

	/**
	 * Build canonical identity context for action-policy resolution.
	 *
	 * @param array $payload        Step payload / invocation context.
	 * @param int   $agent_id       Acting Data Machine agent ID.
	 * @param array $client_context Client-supplied context.
	 * @return array<string,mixed> First-class Agents API context plus namespaced Data Machine metadata.
	 */
	private static function buildActionPolicyContext( array $payload, int $agent_id, array $client_context ): array {
		$context = array(
			'client_context' => $client_context,
			'datamachine'    => array_filter(
				array(
					'job_id'               => $payload['job_id'] ?? null,
					'flow_step_id'         => $payload['flow_step_id'] ?? null,
					'selected_pipeline_id' => $payload['selected_pipeline_id'] ?? null,
				),
				static fn( $value ): bool => null !== $value && '' !== $value
			),
		);

		$workspace = self::resolveActionPolicyWorkspace( $payload );
		if ( null !== $workspace ) {
			$context['workspace'] = $workspace;
		}

		$user_id = self::firstPositiveInt( $payload, $client_context, 'user_id' );
		if ( $user_id > 0 ) {
			$context['user_id'] = $user_id;
		}

		$acting_user_id = self::firstPositiveInt( $payload, $client_context, 'acting_user_id', 'calling_user_id' );
		if ( $acting_user_id > 0 ) {
			$context['acting_user_id'] = $acting_user_id;
		}

		if ( $agent_id > 0 ) {
			$context['agent_id'] = $agent_id;
		}

		$agent_slug = self::firstNonEmptyString( $payload, $client_context, 'agent_slug' );
		if ( '' !== $agent_slug ) {
			$context['agent_slug'] = sanitize_title( $agent_slug );
		}

		foreach ( array( 'session_id', 'transcript_session_id', 'request_id' ) as $identifier ) {
			$value = self::firstNonEmptyString( $payload, $client_context, $identifier );
			if ( '' !== $value ) {
				$context[ $identifier ] = $value;
			}
		}

		if ( empty( $context['datamachine'] ) ) {
			unset( $context['datamachine'] );
		}

		return $context;
	}

	/**
	 * Resolve the canonical workspace for action-policy resolution.
	 *
	 * @param array $payload Step payload / invocation context.
	 * @return WP_Agent_Workspace_Scope|null Workspace scope.
	 */
	private static function resolveActionPolicyWorkspace( array $payload ): ?WP_Agent_Workspace_Scope {
		$workspace = $payload['workspace'] ?? null;
		if ( $workspace instanceof WP_Agent_Workspace_Scope ) {
			return $workspace;
		}

		if ( is_array( $workspace ) ) {
			try {
				return WP_Agent_Workspace_Scope::from_array( $workspace );
			} catch ( \InvalidArgumentException $e ) {
				return null;
			}
		}

		return WordPressWorkspaceScope::current();
	}

	/**
	 * Return the first positive integer from payload or client context.
	 *
	 * @param array  $payload        Step payload / invocation context.
	 * @param array  $client_context Client-supplied context.
	 * @param string ...$keys        Candidate keys in priority order.
	 * @return int Positive integer or 0.
	 */
	private static function firstPositiveInt( array $payload, array $client_context, string ...$keys ): int {
		foreach ( $keys as $key ) {
			foreach ( array( $payload, $client_context ) as $source ) {
				$value = isset( $source[ $key ] ) ? (int) $source[ $key ] : 0;
				if ( $value > 0 ) {
					return $value;
				}
			}
		}

		return 0;
	}

	/**
	 * Return the first non-empty string from payload or client context.
	 *
	 * @param array  $payload        Step payload / invocation context.
	 * @param array  $client_context Client-supplied context.
	 * @param string ...$keys        Candidate keys in priority order.
	 * @return string Non-empty string or empty string.
	 */
	private static function firstNonEmptyString( array $payload, array $client_context, string ...$keys ): string {
		foreach ( $keys as $key ) {
			foreach ( array( $payload, $client_context ) as $source ) {
				$value = $source[ $key ] ?? null;
				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
					return trim( (string) $value );
				}
			}
		}

		return '';
	}

	/**
	 * Build a one-line human summary for a staged invocation.
	 *
	 * Tools can customize via a `build_action_summary` callable in their
	 * definition. Fallback: `"<Tool Name>: <first-required-param-value truncated>"`.
	 *
	 * @param string $tool_name   Tool name.
	 * @param array  $parameters  Complete parameters (post parameter-merge).
	 * @param array  $tool_def    Tool definition.
	 * @return string
	 */
	private static function buildActionSummary( string $tool_name, array $parameters, array $tool_def ): string {
		if ( ! empty( $tool_def['build_action_summary'] ) && is_callable( $tool_def['build_action_summary'] ) ) {
			$custom = call_user_func( $tool_def['build_action_summary'], $parameters, $tool_def );
			if ( is_string( $custom ) && '' !== trim( $custom ) ) {
				return wp_strip_all_tags( trim( $custom ) );
			}
		}

		$label = ucwords( str_replace( '_', ' ', $tool_name ) );

		// First non-empty scalar param, truncated.
		foreach ( $tool_def['parameters'] ?? array() as $param_name => $param_config ) {
			if ( ! isset( $parameters[ $param_name ] ) ) {
				continue;
			}
			$value = $parameters[ $param_name ];
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$snippet = wp_trim_words( wp_strip_all_tags( $value ), 12, '…' );
				return $label . ': ' . $snippet;
			}
		}

		return $label;
	}

	/**
	 * Build a preview payload for a staged invocation.
	 *
	 * Tools can customize via a `build_action_preview` callable. Fallback:
	 * pass the complete parameters through (minus anything under the tool's
	 * `action_preview_redact` allowlist). This keeps the UI informative for
	 * opted-in tools without requiring every tool to implement its own
	 * preview renderer.
	 *
	 * @param array $parameters Complete parameters.
	 * @param array $tool_def   Tool definition.
	 * @return array
	 */
	private static function buildActionPreviewData( array $parameters, array $tool_def ): array {
		if ( ! empty( $tool_def['build_action_preview'] ) && is_callable( $tool_def['build_action_preview'] ) ) {
			$custom = call_user_func( $tool_def['build_action_preview'], $parameters, $tool_def );
			if ( is_array( $custom ) ) {
				return $custom;
			}
		}

		$redact = array();
		if ( ! empty( $tool_def['action_preview_redact'] ) && is_array( $tool_def['action_preview_redact'] ) ) {
			$redact = array_flip( array_map( 'strval', $tool_def['action_preview_redact'] ) );
		}

		if ( empty( $redact ) ) {
			return $parameters;
		}

		return array_diff_key( $parameters, $redact );
	}

}
