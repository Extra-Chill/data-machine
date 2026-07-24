<?php
/**
 * Tool Policy Resolver
 *
 * Single entry point for determining which tools are available for any
 * execution context. Composes tools from registered sources, filters by
 * context (pipeline/chat/system), then applies per-agent tool policies.
 *
 * Resolution precedence (highest to lowest):
 * 1. Explicit deny list (always wins)
 * 2. Per-agent tool policy (deny/allow mode from agent_config, supports categories)
 * 3. Ability category filter (narrows tools by their linked ability's category)
 * 4. Context-level allow_only (narrows to explicit subset)
 * 5. Pipeline content-writing opt-in (generic write tools excluded by default)
 * 6. Context preset (pipeline/chat/system)
 * 7. Global enablement settings
 * 8. Tool configuration requirements
 *
 * @package DataMachine\Engine\AI\Tools
 * @since 0.39.0
 */

namespace DataMachine\Engine\AI\Tools;

use DataMachine\Engine\AI\Tools\Policy\DataMachineAgentToolPolicyProvider;
use DataMachine\Engine\AI\Tools\Policy\DataMachineMandatoryToolPolicy;
use DataMachine\Engine\AI\Tools\Policy\DataMachineToolAccessPolicy;

defined( 'ABSPATH' ) || exit;

class ToolPolicyResolver {

	/**
	 * Agent mode presets define which tool pools are available.
	 */
	public const MODE_PIPELINE = 'pipeline';
	public const MODE_CHAT     = 'chat';
	public const MODE_SYSTEM   = 'system';

	/**
	 * Tool declaration flag that gates a generic content-writing tool behind
	 * explicit opt-in for pipeline AI steps. Tools marked with this flag are
	 * excluded from the default pipeline preset; a step must list them in
	 * enabled_tools (or an allow-mode tool policy) to use them.
	 *
	 * See https://github.com/Extra-Chill/data-machine/issues/2852.
	 */
	public const PIPELINE_OPT_IN_FLAG = 'requires_pipeline_opt_in';

	/**
	 * Ability categories whose projected tools are content-writing surface and
	 * therefore gated behind explicit opt-in for pipeline AI steps. The
	 * canonical category slugs are owned by AbilityCategories; they are
	 * inlined here as strings so the generic policy layer has no upward
	 * dependency on the Abilities namespace (matches the established pattern
	 * in AgentAuthorize).
	 */
	private const PIPELINE_OPT_IN_CATEGORIES = array( 'datamachine-publishing' );

	private ToolManager $tool_manager;
	private ToolSourceRegistry $tool_source_registry;
	private DataMachineAgentToolPolicyProvider $agent_policy_provider;
	private DataMachineMandatoryToolPolicy $mandatory_tool_policy;
	private DataMachineToolAccessPolicy $tool_access_policy;
	private \WP_Agent_Tool_Policy $tool_policy;
	private \WP_Agent_Tool_Policy_Filter $policy_filter;
	private ?array $last_source_trace = null;

	public function __construct(
		?ToolManager $tool_manager = null,
		?DataMachineAgentToolPolicyProvider $agent_policy_provider = null,
		?DataMachineMandatoryToolPolicy $mandatory_tool_policy = null,
		?DataMachineToolAccessPolicy $tool_access_policy = null,
		?\WP_Agent_Tool_Policy_Filter $policy_filter = null,
		?\WP_Agent_Tool_Policy $tool_policy = null
	) {
		$this->tool_manager           = $tool_manager ?? new ToolManager();
		$this->tool_source_registry   = new ToolSourceRegistry( $this->tool_manager );
		$this->agent_policy_provider  = $agent_policy_provider ?? new DataMachineAgentToolPolicyProvider();
		$this->mandatory_tool_policy  = $mandatory_tool_policy ?? new DataMachineMandatoryToolPolicy();
		$this->tool_access_policy     = $tool_access_policy ?? new DataMachineToolAccessPolicy();
		$this->policy_filter          = $policy_filter ?? new \WP_Agent_Tool_Policy_Filter();
		$this->tool_policy            = $tool_policy ?? new \WP_Agent_Tool_Policy(
			array(
				$this->mandatory_tool_policy,
			),
			$this->policy_filter
		);
	}

	/**
	 * Resolve available tools for given agent modes.
	 *
	 * This is the single entry point. All tool assembly should go through here.
	 *
	 * @param array $args {
	 *     Resolution arguments describing the request.
	 *
	 *     @type string[]    $modes                 Active agent mode slugs.
	 *     @type int|null    $agent_id              Agent ID for per-agent tool policy filtering.
	 *     @type array|null  $previous_step_config  Pipeline only: previous step config.
	 *     @type array|null  $next_step_config      Pipeline only: next step config.
	 *     @type string|null $pipeline_step_id      Pipeline only: current pipeline step ID for per-step filtering.
	 *     @type array       $engine_data           Engine data snapshot for dynamic tool generation.
	 *     @type array       $deny                  Tool names to explicitly deny (highest precedence).
	 *     @type array       $allow_only            If set, only these tools are allowed (allowlist mode).
	 *     @type array       $categories            If set, only tools whose linked ability belongs to one
	 *                                              of these categories are included. Empty = no filtering.
	 *     @type string|null $cache_scope           Scope key for tool cache (e.g. flow_step_id).
	 * }
	 * @return array Resolved tools array keyed by tool name.
	 */
	public function resolve( array $args ): array {
		$this->last_source_trace = null;
		$modes    = self::normalizeModes( $args['modes'] ?? ( array_key_exists( 'mode', $args ) ? array( $args['mode'] ) : array( self::MODE_PIPELINE ) ) );
		$agent_id = isset( $args['agent_id'] ) ? (int) $args['agent_id'] : 0;

		$is_interactive = in_array( self::MODE_CHAT, $modes, true ) || ! empty( $args['interactive'] );

		if ( $is_interactive && ! $this->tool_access_policy->passesChatGate( $args ) ) {
			return array();
		}

		$agent_policy = $agent_id > 0 ? $this->getAgentToolPolicy( $agent_id ) : null;
		$args         = $this->withAgentPolicyAllowOnly( $args, $agent_policy );

		// 1. Gather tools from Data Machine-owned sources.
		$args['modes'] = $modes;
		$source_trace  = $this->tool_source_registry->gatherTrace( $modes, $args );
		$tools         = $this->filterRuntimeToolsByPolicyOptIn( $source_trace['tools'], $args, $agent_policy );
		$gathered_tools = $tools;

		// 2. Delegate generic mode/allow/deny/category policy resolution to Agents API.
		$policy_context = array_merge(
			$args,
			array(
				'mode'              => '',
				'modes'             => $modes,
				'datamachine_tools' => $tools,
			)
		);

		if ( null !== $agent_policy ) {
			$policy_context['agent_config'] = array( 'tool_policy' => $agent_policy );
		} else {
			unset( $policy_context['agent_id'], $policy_context['agent_slug'] );
		}

		// Interactive modes are request-user scoped. Pipeline/system run as product automation.
		if ( $is_interactive ) {
			$policy_context['tool_access_checker'] = array( $this->tool_access_policy, 'canAccessTool' );
		}

		$tools = $this->tool_policy->resolve( $tools, $policy_context );
		$tools = $this->restoreExplicitDelegatedRuntimeTools( $tools, $gathered_tools, $args, $agent_policy );
		$this->last_source_trace = $source_trace;

		// Generic content-writing abilities are opt-in for pipeline AI steps.
		// By default a pipeline step receives only its adjacent-handler tools,
		// research/read tools, and disposition tools. Generic publish/write-any
		// tools (publish-wordpress, upsert-post, insert-content, ...) leak
		// otherwise: a model can improvise arbitrary publishes that bypass the
		// flow's declared handler and its guards. Adjacent-handler plumbing is
		// mandatory and therefore exempt. Chat/system modes are unaffected.
		// See https://github.com/Extra-Chill/data-machine/issues/2852.
		if ( in_array( self::MODE_PIPELINE, $modes, true ) ) {
			$tools = $this->filterPipelineWriteOptInTools( $tools, $args );
		}

		// An explicitly-empty allow_only means "no optional tools." The generic
		// substrate only applies a non-empty allow_only, so it would otherwise
		// fall through to the full preset. Enforce the empty allowlist here while
		// preserving mandatory plumbing tools (adjacent handler + completion).
		if ( ! empty( $args['allow_only_explicit'] ) ) {
			$allow_only = $this->policy_filter->string_list( $args['allow_only'] ?? array() );
			if ( empty( $allow_only ) ) {
				$tools = $this->filterByAllowOnlyPreservingHandlerTools( $tools, array() );
			}
		}

		// 7. Allow external filtering of resolved tools.
		// @phpstan-ignore-next-line WordPress apply_filters accepts additional hook arguments.
		$filter_mode = 1 === count( $modes ) ? $modes[0] : $modes;
		$tools       = apply_filters( 'datamachine_resolved_tools', $tools, $filter_mode, $args );

		return $tools;
	}

	/**
	 * Resolve tools and return bounded evidence for required-tool diagnostics.
	 *
	 * @param array $args                 Resolution arguments.
	 * @param array $required_tool_names  Completion assertion required tool names.
	 * @param array $requested_tool_names Requested/enabled tool names from the step config.
	 * @return array{tools: array<string,array<string,mixed>>, evidence: array<string,mixed>}
	 */
	public function resolveWithEvidence( array $args, array $required_tool_names = array(), array $requested_tool_names = array() ): array {
		$args['capture_trace']                    = true;
		$args['include_unavailable']              = true;
		$args['include_source_rejection_metadata'] = true;
		$args['diagnostic_tool_names']             = array_values( array_unique( array_merge( $required_tool_names, $requested_tool_names ) ) );
		$tools    = $this->resolve( $args );
		if ( is_array( $this->last_source_trace ) ) {
			$args['source_trace'] = $this->last_source_trace;
		}
		$evidence = $this->buildResolutionEvidence( $args, $tools, $required_tool_names, $requested_tool_names );

		return array(
			'tools'    => $tools,
			'evidence' => $evidence,
		);
	}

	/**
	 * Build the local Data Machine view of requested, required, gathered, and resolved tools.
	 *
	 * @param array $args                 Resolution arguments.
	 * @param array $resolved_tools       Final resolved tools keyed by name.
	 * @param array $required_tool_names  Completion assertion required tool names.
	 * @param array $requested_tool_names Requested/enabled tool names from the step config.
	 * @return array<string,mixed>
	 */
	private function buildResolutionEvidence( array $args, array $resolved_tools, array $required_tool_names, array $requested_tool_names ): array {
		$modes                = self::normalizeModes( $args['modes'] ?? ( array_key_exists( 'mode', $args ) ? array( $args['mode'] ) : array( self::MODE_PIPELINE ) ) );
		$requested_tool_names = $this->policy_filter->string_list( $requested_tool_names );
		if ( empty( $requested_tool_names ) && ! empty( $args['allow_only_explicit'] ) ) {
			$requested_tool_names = $this->policy_filter->string_list( $args['allow_only'] ?? array() );
		}
		$required_tool_names = $this->policy_filter->string_list( $required_tool_names );
		$resolved_tool_names = array_keys( $resolved_tools );

		$source_snapshot = is_array( $args['source_trace'] ?? null ) ? $args['source_trace'] : $this->tool_source_registry->gatherTrace(
			$modes,
			array_merge(
				$args,
				array(
					'modes'                             => $modes,
					'include_unavailable'               => true,
					'include_source_rejection_metadata' => true,
					'diagnostic_tool_names'             => array_values( array_unique( array_merge( $required_tool_names, $requested_tool_names ) ) ),
				)
			)
		);
		$source_tools    = is_array( $source_snapshot['tools'] ?? null ) ? $source_snapshot['tools'] : array();
		$sources         = is_array( $source_snapshot['sources'] ?? null ) ? $source_snapshot['sources'] : array();

		$source_by_tool    = array();
		$rejection_by_tool = array();
		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}
			$source_slug = is_string( $source['source'] ?? null ) ? $source['source'] : '';
			foreach ( $source['accepted_tool_names'] ?? array() as $tool_name ) {
				if ( is_string( $tool_name ) && '' !== $tool_name && ! isset( $source_by_tool[ $tool_name ] ) ) {
					$source_by_tool[ $tool_name ] = $source_slug;
				}
			}
			foreach ( $source['rejected_tools'] ?? array() as $tool_name => $rejection ) {
				if ( ! is_string( $tool_name ) || '' === $tool_name || isset( $rejection_by_tool[ $tool_name ] ) || ! is_array( $rejection ) ) {
					continue;
				}
				$rejection['source']              = $source_slug;
				$rejection_by_tool[ $tool_name ] = $rejection;
			}
		}

		$available_names              = $this->availableToolNamesForEvidence( $resolved_tools );
		$unavailable_required_tools   = array_values( array_diff( array_values( array_unique( $required_tool_names ) ), $available_names ) );
		$required_resolution_evidence = array();
		foreach ( $required_tool_names as $tool_name ) {
			$resolved_name = $this->resolvedToolNameForEvidence( $tool_name, $resolved_tools );
			if ( '' !== $resolved_name ) {
				$required_resolution_evidence[] = array(
					'tool_name'     => $tool_name,
					'status'        => 'resolved',
					'reason'        => 'resolved',
					'resolved_name' => $resolved_name,
					'source'        => $source_by_tool[ $resolved_name ] ?? ( $resolved_tools[ $resolved_name ]['source'] ?? 'unknown' ),
				);
				continue;
			}

			$source = null;
			$reason = 'unknown';
			if ( in_array( $tool_name, $this->policy_filter->string_list( $args['deny'] ?? array() ), true ) ) {
				$reason = 'tool_disabled';
				$source = $source_by_tool[ $tool_name ] ?? null;
			} elseif ( isset( $source_tools[ $tool_name ] ) ) {
				$reason = 'policy_filtered';
				$source = $source_by_tool[ $tool_name ] ?? null;
			} elseif ( isset( $rejection_by_tool[ $tool_name ] ) ) {
				$reason = is_string( $rejection_by_tool[ $tool_name ]['reason'] ?? null ) ? $rejection_by_tool[ $tool_name ]['reason'] : 'unknown';
				$source = is_string( $rejection_by_tool[ $tool_name ]['source'] ?? null ) ? $rejection_by_tool[ $tool_name ]['source'] : null;
			}

			$evidence = array(
				'tool_name' => $tool_name,
				'status'    => 'unavailable',
				'reason'    => $reason,
				'source'    => $source,
			);
			foreach ( array( 'ability', 'tool_modes', 'active_modes', 'execution_location' ) as $key ) {
				if ( array_key_exists( $key, $rejection_by_tool[ $tool_name ] ?? array() ) ) {
					$evidence[ $key ] = $rejection_by_tool[ $tool_name ][ $key ];
				}
			}
			$required_resolution_evidence[] = $evidence;
		}

		return array(
			'schema_version'                  => 1,
			'modes'                           => $modes,
			'requested_tool_names'            => $requested_tool_names,
			'required_tool_names'             => $required_tool_names,
			'resolved_tool_ids'               => $resolved_tool_names,
			'unavailable_required_tool_names' => $unavailable_required_tools,
			'available_tool_sources'          => $sources,
			'required_tool_resolution'        => $required_resolution_evidence,
			'filtering'                       => array_filter(
				array(
					'allow_only'          => $this->policy_filter->string_list( $args['allow_only'] ?? array() ),
					'allow_only_explicit' => ! empty( $args['allow_only_explicit'] ),
					'deny'                => $this->policy_filter->string_list( $args['deny'] ?? array() ),
					'categories'          => $this->policy_filter->string_list( $args['categories'] ?? array() ),
					'tool_policy'         => is_array( $args['tool_policy'] ?? null ) ? array(
						'mode'       => is_string( $args['tool_policy']['mode'] ?? null ) ? $args['tool_policy']['mode'] : '',
						'tools'      => $this->policy_filter->string_list( $args['tool_policy']['tools'] ?? array() ),
						'categories' => $this->policy_filter->string_list( $args['tool_policy']['categories'] ?? array() ),
					) : null,
				),
				static fn( $value ) => null !== $value && array() !== $value
			),
		);
	}

	/**
	 * @param array<string,array<string,mixed>> $tools Tools keyed by logical name.
	 * @return array<int,string>
	 */
	private function availableToolNamesForEvidence( array $tools ): array {
		$names = array();
		foreach ( $tools as $tool_name => $tool_def ) {
			$names[] = (string) $tool_name;
			foreach ( array( 'name', 'runtime_tool_id' ) as $key ) {
				$value = is_string( $tool_def[ $key ] ?? null ) ? trim( (string) $tool_def[ $key ] ) : '';
				if ( '' !== $value ) {
					$names[] = $value;
				}
			}
		}

		return array_values( array_unique( array_filter( $names ) ) );
	}

	/**
	 * @param string $required_name Required assertion tool name.
	 * @param array  $tools         Tools keyed by logical name.
	 */
	private function resolvedToolNameForEvidence( string $required_name, array $tools ): string {
		foreach ( $tools as $tool_name => $tool_def ) {
			if ( $required_name === $tool_name ) {
				return (string) $tool_name;
			}

			foreach ( array( 'name', 'runtime_tool_id' ) as $key ) {
				if ( $required_name === ( $tool_def[ $key ] ?? null ) ) {
					return (string) $tool_name;
				}
			}
		}

		return '';
	}

	/**
	 * Gather tools by mode preset.
	 *
	 * @param array $modes Agent mode slugs.
	 * @param array  $args Full resolution arguments.
	 * @return array Tools array.
	 */
	private function gatherByModes( array $modes, array $args ): array {
		return $this->tool_source_registry->gather( $modes, $args );
	}

	/**
	 * Keep client-declared runtime tools only when explicitly opted in.
	 *
	 * Runtime declarations are caller supplied and client-executed. They must be
	 * named by an existing allow path before the generic policy pass can expose
	 * them to a provider request.
	 *
	 * @param array      $tools        Resolved tools keyed by name.
	 * @param array      $args         Resolver args.
	 * @param array|null $agent_policy Optional persisted agent policy.
	 * @return array Filtered tools.
	 */
	private function filterRuntimeToolsByPolicyOptIn( array $tools, array $args, ?array $agent_policy ): array {
		$runtime_tool_names = array();
		foreach ( $tools as $name => $tool ) {
			if ( is_array( $tool ) && ! empty( $tool['runtime_tool'] ) ) {
				$runtime_tool_names[] = (string) $name;
			}
		}

		if ( empty( $runtime_tool_names ) ) {
			return $tools;
		}

		$allowed = $this->policy_filter->string_list( $args['allow_only'] ?? array() );
		foreach ( array( $agent_policy, $args['tool_policy'] ?? null ) as $policy ) {
			if ( is_array( $policy ) && \WP_Agent_Tool_Policy::MODE_ALLOW === ( $policy['mode'] ?? \WP_Agent_Tool_Policy::MODE_DENY ) ) {
				$allowed = array_merge( $allowed, $this->policy_filter->string_list( $policy['tools'] ?? array() ) );
			}
		}

		$allowed = array_flip( array_values( array_unique( $allowed ) ) );
		foreach ( $runtime_tool_names as $name ) {
			if ( ! isset( $allowed[ $name ] ) ) {
				unset( $tools[ $name ] );
			}
		}

		return $tools;
	}

	/**
	 * Restore explicit host-delegated runtime tools after generic policy filtering.
	 *
	 * The generic policy layer may drop minimal client-executed declarations that do
	 * not look like local PHP tools. Host-delegated tools are still safe to expose
	 * when the caller explicitly opted into them; execution is mediated by the
	 * runtime-tool path rather than local PHP handlers.
	 *
	 * @param array      $tools          Resolved tools after generic policy filtering.
	 * @param array      $gathered_tools Tools gathered before generic policy filtering.
	 * @param array      $args           Resolver args.
	 * @param array|null $agent_policy   Optional persisted agent policy.
	 * @return array Resolved tools with explicit delegated runtime tools restored.
	 */
	private function restoreExplicitDelegatedRuntimeTools( array $tools, array $gathered_tools, array $args, ?array $agent_policy ): array {
		$allowed = $this->policy_filter->string_list( $args['allow_only'] ?? array() );
		foreach ( array( $agent_policy, $args['tool_policy'] ?? null ) as $policy ) {
			if ( is_array( $policy ) && \WP_Agent_Tool_Policy::MODE_ALLOW === ( $policy['mode'] ?? \WP_Agent_Tool_Policy::MODE_DENY ) ) {
				$allowed = array_merge( $allowed, $this->policy_filter->string_list( $policy['tools'] ?? array() ) );
			}
		}

		foreach ( array_values( array_unique( $allowed ) ) as $tool_name ) {
			if ( isset( $tools[ $tool_name ] ) || ! is_array( $gathered_tools[ $tool_name ] ?? null ) ) {
				continue;
			}

			$tool = $gathered_tools[ $tool_name ];
			if ( ! empty( $tool['runtime_tool'] ) && 'client' === (string) ( $tool['executor'] ?? '' ) && 'control_plane' === (string) ( $tool['runtime']['execution_location'] ?? '' ) ) {
				$tools[ $tool_name ] = $tool;
			}
		}

		return $tools;
	}

	/**
	 * Include explicit agent allow-policy tools in the early opt-in allow list.
	 *
	 * @param array      $args Resolution args.
	 * @param array|null $agent_policy Optional persisted agent tool policy.
	 * @return array Resolution args.
	 */
	private function withAgentPolicyAllowOnly( array $args, ?array $agent_policy ): array {
		if ( ! is_array( $agent_policy ) || \WP_Agent_Tool_Policy::MODE_ALLOW !== ( $agent_policy['mode'] ?? '' ) ) {
			return $args;
		}

		$args['allow_only'] = array_values( array_unique( array_merge(
			$this->policy_filter->string_list( $args['allow_only'] ?? array() ),
			$this->policy_filter->string_list( $agent_policy['tools'] ?? array() )
		) ) );

		return $args;
	}

	/**
	 * Get tool policy from an agent's config.
	 *
	 * Reads the `tool_policy` key from the agent's `agent_config` JSON.
	 * Returns null if the agent doesn't exist or has no tool policy configured.
	 *
	 * @since 0.42.0
	 * @param int $agent_id Agent ID.
	 * @return array|null Tool policy array with 'mode' and 'tools' keys, or null.
	 */
	public function getAgentToolPolicy( int $agent_id ): ?array {
		return $this->agent_policy_provider->getForAgent( $agent_id );
	}

	/**
	 * Apply an agent's tool policy to a set of resolved tools.
	 *
	 * - `deny` mode: agent can use everything EXCEPT listed tools/categories.
	 * - `allow` mode: agent can ONLY use listed tools/categories.
	 * - No policy (null): no restrictions (backward compatible).
	 *
	 * The policy supports both individual tool names (`tools` key) and ability
	 * categories (`categories` key). When both are present, they compose:
	 * - allow mode: tool passes if it matches a tool name OR a category.
	 * - deny mode: tool is excluded if it matches a tool name OR a category.
	 *
	 * @since 0.42.0
	 * @since 0.55.0 Added category support in tool policies.
	 *
	 * @param array      $tools  Resolved tools array keyed by tool name.
	 * @param array|null $policy Tool policy from getAgentToolPolicy(), or null for no restrictions.
	 * @return array Filtered tools array.
	 */
	public function applyAgentPolicy( array $tools, ?array $policy ): array {
		return $this->policy_filter->apply_named_policy(
			$tools,
			$policy,
			fn( array $tool, string $name ): bool => $this->mandatory_tool_policy->isMandatory( $tool )
		);
	}

	/**
	 * Apply an allow-only list while preserving adjacent handler tools.
	 *
	 * @param array $tools      Tool definitions keyed by tool name.
	 * @param array $allow_only Optional/global tool names to allow.
	 * @return array Filtered tools.
	 */
	private function filterByAllowOnlyPreservingHandlerTools( array $tools, array $allow_only ): array {
		return $this->policy_filter->filter_by_allow_only(
			$tools,
			$allow_only,
			fn( array $tool, string $name ): bool => $this->mandatory_tool_policy->isMandatory( $tool )
		);
	}

	/**
	 * Get available agent mode presets.
	 *
	 * @return array<string, string> Mode slug => description.
	 */
	public static function getModes(): array {
		return array(
			self::MODE_PIPELINE => 'Pipeline execution with handler tools from adjacent steps',
			self::MODE_CHAT     => 'Chat interaction with full management tools',
			self::MODE_SYSTEM   => 'System task execution with minimal toolset',
		);
	}

	/**
	 * Normalize active mode input to sanitized slugs.
	 *
	 * @param mixed $modes Raw mode input.
	 * @return array<int,string>
	 */
	public static function normalizeModes( mixed $modes ): array {
		if ( is_string( $modes ) ) {
			$modes = array( $modes );
		}
		if ( ! is_array( $modes ) ) {
			$modes = array();
		}

		$normalized = array();
		foreach ( $modes as $mode ) {
			if ( ! is_scalar( $mode ) ) {
				continue;
			}
			$mode = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $mode ) : strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $mode ) ?? '' );
			if ( '' !== $mode ) {
				$normalized[] = $mode;
			}
		}

		$normalized = array_values( array_unique( $normalized ) );
		return ! empty( $normalized ) ? $normalized : array( self::MODE_PIPELINE );
	}

	/**
	 * Whether an explicitly opt-in tool is available to this run.
	 *
	 * @param array  $tool_config Tool definition.
	 * @param string $tool_name   Tool identifier.
	 * @param array  $args        Resolver args, including optional allow_only.
	 * @return bool True when the tool should be included.
	 */
	public static function isOptInToolAllowed( array $tool_config, string $tool_name, array $args ): bool {
		if ( empty( $tool_config['requires_opt_in'] ) ) {
			return true;
		}

		$allowed = is_array( $args['allow_only'] ?? null ) ? $args['allow_only'] : array();
		$policy  = is_array( $args['tool_policy'] ?? null ) ? $args['tool_policy'] : null;
		if ( is_array( $policy ) && \WP_Agent_Tool_Policy::MODE_ALLOW === ( $policy['mode'] ?? '' ) ) {
			$allowed = array_merge( $allowed, is_array( $policy['tools'] ?? null ) ? $policy['tools'] : array() );
		}

		return in_array( $tool_name, $allowed, true );
	}

	/**
	 * Whether a tool is a generic content-writing surface gated behind
	 * explicit opt-in for pipeline AI steps.
	 *
	 * A tool is gated when it declares the {@see PIPELINE_OPT_IN_FLAG} flag at
	 * its registration site, or when it is projected from one of the canonical
	 * content-writing ability categories ({@see PIPELINE_OPT_IN_CATEGORIES}).
	 * Adjacent-handler tools do not carry the flag and are not projected from
	 * those categories, so flow plumbing is never gated.
	 *
	 * @param array $tool Tool definition.
	 * @return bool True when the tool requires explicit opt-in in pipeline mode.
	 */
	private function isPipelineWriteOptInTool( array $tool ): bool {
		if ( ! empty( $tool[ self::PIPELINE_OPT_IN_FLAG ] ) ) {
			return true;
		}

		$category = isset( $tool['ability_category'] ) && is_string( $tool['ability_category'] ) ? $tool['ability_category'] : '';
		if ( '' !== $category && in_array( $category, self::PIPELINE_OPT_IN_CATEGORIES, true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Drop pipeline-gated content-writing tools that were not explicitly opted
	 * into by the step. Adjacent-handler plumbing (mandatory tools) and tools
	 * named in an allow path (enabled_tools / allow-mode tool policy) survive.
	 *
	 * @param array<string,array<string,mixed>> $tools Resolved tools keyed by name.
	 * @param array                             $args  Resolver args.
	 * @return array<string,array<string,mixed>> Filtered tools.
	 */
	private function filterPipelineWriteOptInTools( array $tools, array $args ): array {
		$allowed = $this->policy_filter->string_list( $args['allow_only'] ?? array() );
		$policy  = is_array( $args['tool_policy'] ?? null ) ? $args['tool_policy'] : null;
		if ( is_array( $policy ) && \WP_Agent_Tool_Policy::MODE_ALLOW === ( $policy['mode'] ?? '' ) ) {
			$allowed = array_merge( $allowed, $this->policy_filter->string_list( $policy['tools'] ?? array() ) );
		}
		$allowed = array_flip( $allowed );

		foreach ( $tools as $name => $tool ) {
			if ( ! is_array( $tool ) || ! $this->isPipelineWriteOptInTool( $tool ) ) {
				continue;
			}

			// Flow plumbing (adjacent-handler tools) is mandatory and survives.
			if ( $this->mandatory_tool_policy->isMandatory( $tool ) ) {
				continue;
			}

			// A step that explicitly opts in (enabled_tools / allow policy) keeps it.
			if ( isset( $allowed[ $name ] ) ) {
				continue;
			}

			unset( $tools[ $name ] );
		}

		return $tools;
	}
}
