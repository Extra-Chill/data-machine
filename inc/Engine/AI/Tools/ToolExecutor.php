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

use DataMachine\Core\WordPress\PostTracking;
use DataMachine\Engine\AI\Actions\ActionPolicyResolver;
use DataMachine\Engine\AI\Actions\PendingActionHelper;

defined( 'ABSPATH' ) || exit;

class ToolExecutor {


	/**
	 * Execute tool with parameter merging and comprehensive error handling.
	 * Builds complete parameters by combining AI parameters with step payload.
	 *
	 * Before invoking the tool handler, consults ActionPolicyResolver to
	 * decide whether the invocation should execute directly, be staged for
	 * user approval (preview), or be refused (forbidden). Tools opt into
	 * preview/forbidden via metadata; unopted tools resolve to 'direct' and
	 * behave identically to pre-ActionPolicy releases.
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
		$tool_def = $available_tools[ $tool_name ] ?? null;
		if ( ! $tool_def ) {
			return array(
				'success'   => false,
				'error'     => "Tool '{$tool_name}' not found",
				'tool_name' => $tool_name,
			);
		}

		$validation = self::validateRequiredParameters( $tool_parameters, $tool_def );
		if ( ! $validation['valid'] ) {
			return array(
				'success'   => false,
				'error'     => sprintf(
					'%s requires the following parameters: %s. Please provide these parameters and try again.',
					ucwords( str_replace( '_', ' ', $tool_name ) ),
					implode( ', ', $validation['missing'] )
				),
				'tool_name' => $tool_name,
			);
		}

		$complete_parameters = ToolParameters::buildParameters(
			$tool_parameters,
			$payload,
			$tool_def
		);

		// Resolve the action policy for this invocation. Tools without
		// action_policy metadata resolve to 'direct' and behave exactly
		// as before this feature landed.
		$resolver = new ActionPolicyResolver();
		$policy   = $resolver->resolveForTool(
			array(
				'tool_name'      => $tool_name,
				'tool_def'       => $tool_def,
				'mode'           => $mode,
				'agent_id'       => $agent_id,
				'client_context' => $client_context,
			)
		);

		if ( ActionPolicyResolver::POLICY_FORBIDDEN === $policy ) {
			return array(
				'success'        => false,
				'error'          => sprintf( 'Tool "%s" is not permitted in the current context (action_policy=forbidden).', $tool_name ),
				'tool_name'      => $tool_name,
				'action_policy'  => $policy,
			);
		}

		if ( ActionPolicyResolver::POLICY_PREVIEW === $policy ) {
			// Tool must declare how to build an action kind and summary.
			// If it hasn't opted into the preview pipeline properly, fall
			// back to 'direct' and log a warning — preview is a contract,
			// not something we can synthesize for tools that don't cooperate.
			if ( empty( $tool_def['action_kind'] ) ) {
				do_action(
					'datamachine_log',
					'warning',
					'ActionPolicy: tool resolved to preview but is missing action_kind metadata; falling back to direct.',
					array(
						'tool_name' => $tool_name,
						'mode'      => $mode,
						'agent_id'  => $agent_id,
					)
				);
			} else {
				$staged = PendingActionHelper::stage(
					array(
						'kind'         => (string) $tool_def['action_kind'],
						'summary'      => self::buildActionSummary( $tool_name, $complete_parameters, $tool_def ),
						'apply_input'  => $complete_parameters,
						'preview_data' => self::buildActionPreviewData( $complete_parameters, $tool_def ),
						'agent_id'     => $agent_id,
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
					)
				);

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

		// Policy is 'direct' (or 'preview' fell back) — execute the tool normally.
		if ( self::isAbilityOnlyTool( $tool_def ) ) {
			$tool_result = self::executeAbilityTool( $tool_name, $complete_parameters, $tool_def );
		} else {
			$tool_result = self::executeLegacyTool( $tool_name, $complete_parameters, $tool_def );
		}

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
	 * Whether a tool should execute directly through a linked WordPress Ability.
	 *
	 * Legacy class/method definitions stay authoritative during the migration.
	 * This branch only handles the new ability-native shape for a single linked
	 * ability; composed multi-ability tools remain on their existing adapters.
	 *
	 * @param array $tool_def Tool definition.
	 * @return bool
	 */
	private static function isAbilityOnlyTool( array $tool_def ): bool {
		return ! empty( $tool_def['ability'] )
			&& empty( $tool_def['class'] )
			&& empty( $tool_def['method'] );
	}

	/**
	 * Execute a tool through its linked WordPress Ability.
	 *
	 * @param string $tool_name  Tool name.
	 * @param array  $parameters Complete tool parameters.
	 * @param array  $tool_def   Tool definition.
	 * @return array Tool execution result.
	 */
	private static function executeAbilityTool( string $tool_name, array $parameters, array $tool_def ): array {
		$ability_slug = (string) $tool_def['ability'];
		if ( ! class_exists( '\\WP_Abilities_Registry' ) ) {
			return array(
				'success'   => false,
				'error'     => sprintf( "Tool '%s' references ability '%s', but the WordPress Abilities API is not available.", $tool_name, $ability_slug ),
				'tool_name' => $tool_name,
				'ability'   => $ability_slug,
			);
		}

		$registry     = \WP_Abilities_Registry::get_instance();
		$ability      = $registry ? $registry->get_registered( $ability_slug ) : null;

		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => sprintf( "Tool '%s' references missing ability '%s'.", $tool_name, $ability_slug ),
				'tool_name' => $tool_name,
				'ability'   => $ability_slug,
			);
		}

		$permission = $ability->check_permissions( $parameters );
		if ( is_wp_error( $permission ) ) {
			return array(
				'success'   => false,
				'error'     => $permission->get_error_message(),
				'tool_name' => $tool_name,
				'ability'   => $ability_slug,
			);
		}

		if ( true !== $permission ) {
			return array(
				'success'   => false,
				'error'     => sprintf( "Tool '%s' is not permitted by ability '%s'.", $tool_name, $ability_slug ),
				'tool_name' => $tool_name,
				'ability'   => $ability_slug,
			);
		}

		$result = $ability->execute( $parameters );
		if ( is_wp_error( $result ) ) {
			return array(
				'success'   => false,
				'error'     => $result->get_error_message(),
				'tool_name' => $tool_name,
				'ability'   => $ability_slug,
			);
		}

		if ( is_array( $result ) ) {
			return $result;
		}

		return array(
			'success'   => true,
			'tool_name' => $tool_name,
			'ability'   => $ability_slug,
			'result'    => $result,
		);
	}

	/**
	 * Execute a legacy class/method tool definition.
	 *
	 * @param string $tool_name  Tool name.
	 * @param array  $parameters Complete tool parameters.
	 * @param array  $tool_def   Tool definition.
	 * @return array Tool execution result.
	 */
	private static function executeLegacyTool( string $tool_name, array $parameters, array $tool_def ): array {
		// Ensure tool definition has required 'class' key.
		if ( ! isset( $tool_def['class'] ) || empty( $tool_def['class'] ) ) {
			return array(
				'success'   => false,
				'error'     => "Tool '{$tool_name}' is missing required 'class' definition. This may indicate the tool was not properly resolved from a callable.",
				'tool_name' => $tool_name,
			);
		}

		$class_name = $tool_def['class'];
		if ( ! class_exists( $class_name ) ) {
			return array(
				'success'   => false,
				'error'     => "Tool class '{$class_name}' not found",
				'tool_name' => $tool_name,
			);
		}

		$method = $tool_def['method'] ?? null;
		if ( ! $method || ! method_exists( $class_name, $method ) ) {
			return array(
				'success'   => false,
				'error'     => sprintf(
					"Tool '%s' definition is missing required 'method' key or method '%s' does not exist on class '%s'.",
					$tool_name,
					$method ?? '(none)',
					$class_name
				),
				'tool_name' => $tool_name,
			);
		}

		$tool_handler = new $class_name();
		return $tool_handler->$method( $parameters, $tool_def );
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

	/**
	 * Validate that all required parameters are present.
	 *
	 * @param  array $tool_parameters Parameters from AI
	 * @param  array $tool_def        Tool definition with parameter specs
	 * @return array Validation result with 'valid', 'required', and 'missing' keys
	 */
	private static function validateRequiredParameters( array $tool_parameters, array $tool_def ): array {
		$required = array();
		$missing  = array();

		$param_defs = $tool_def['parameters'] ?? array();

		foreach ( $param_defs as $param_name => $param_config ) {
			if ( ! is_array( $param_config ) ) {
				continue;
			}

			if ( ! empty( $param_config['required'] ) ) {
				$required[] = $param_name;

				if ( ! isset( $tool_parameters[ $param_name ] ) || '' === $tool_parameters[ $param_name ] ) {
					$missing[] = $param_name;
				}
			}
		}

		return array(
			'valid'    => empty( $missing ),
			'required' => $required,
			'missing'  => $missing,
		);
	}
}
