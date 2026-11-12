<?php
/**
 * Generic agent tool visibility policy resolver.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Tool_Policy' ) ) {
	/**
	 * Resolves visible tools for a runtime context.
	 */
	class WP_Agent_Tool_Policy {

		public const MODE_ALLOW = 'allow';
		public const MODE_DENY  = 'deny';

		/**
		 * Canonical interactive runtime mode.
		 *
		 * Default value for the generic tool `mode` filter
		 * ({@see WP_Agent_Tool_Policy_Filter::filter_by_mode()}), which lets
		 * tools declare the modes they are available in. This is a
		 * substrate-native interactive surface and is intentionally kept.
		 *
		 * NOTE: The write-capable curation gate does NOT key off this mode.
		 * It keys off the execution principal's autonomy instead — see
		 * {@see is_autonomous_principal_context()}. Mode-name coupling was
		 * removed from the gate so the substrate names no consumer modes.
		 */
		public const RUNTIME_CHAT = 'chat';

		/**
		 * @var WP_Agent_Tool_Policy_Filter
		 */
		private WP_Agent_Tool_Policy_Filter $filter;

		/**
		 * @var WP_Agent_Tool_Access_Policy[]
		 */
		private array $policy_providers;

		/**
		 * Constructor.
		 *
		 * @param WP_Agent_Tool_Access_Policy[]|null $policy_providers Host policy providers.
		 * @param WP_Agent_Tool_Policy_Filter|null             $filter           Tool policy filter.
		 */
		public function __construct( ?array $policy_providers = null, ?WP_Agent_Tool_Policy_Filter $filter = null ) {
			$this->policy_providers = is_array( $policy_providers ) ? $policy_providers : array();
			$this->filter           = $filter ?? new WP_Agent_Tool_Policy_Filter();
		}

		/**
		 * Resolve the visible tool set for a runtime context.
		 *
		 * @param array<string, array<mixed>> $tools   Tool definitions keyed by tool name.
		 * @param array<string, mixed>        $context Runtime context.
		 * @return array<string, array<string, mixed>> Visible tools keyed by tool name.
		 */
		public function resolve( array $tools, array $context = array() ): array {
			$tools           = $this->normalize_tools( $tools );
			$mode            = $this->string_value( $context['mode'] ?? self::RUNTIME_CHAT );
			$policies        = $this->collect_policies( $context );
			$mandatory_tools = $this->collect_policy_list( $policies, 'mandatory_tools' );
			$mandatory_cats  = $this->collect_policy_list( $policies, 'mandatory_categories' );
			/**
			 * @param array<string,mixed> $tool Tool definition.
			 */
			$preserve_tool   = function ( array $tool, string $name ) use ( $mandatory_tools, $mandatory_cats ): bool {
				return in_array( $name, $mandatory_tools, true )
					|| true === ( $tool['mandatory'] ?? false )
					|| $this->filter->tool_matches_categories( $this->string_keyed_array( $tool ), $mandatory_cats );
			};

			$tools = $this->filter->filter_by_mode( $tools, $mode );

			$access_checker = $context['tool_access_checker'] ?? null;
			if ( is_callable( $access_checker ) ) {
				$tools = $this->filter->filter_by_access_checker( $tools, $access_checker );
			}

			foreach ( $policies as $policy ) {
				$tools = $this->filter->apply_named_policy( $tools, $this->normalize_named_policy( $policy ), $preserve_tool );
			}

			$categories = $this->filter->string_list( $context['categories'] ?? array() );
			if ( ! empty( $categories ) ) {
				$tools = $this->filter->filter_by_categories( $tools, $categories, $preserve_tool );
			}

			$allow_only = $this->filter->string_list( $context['allow_only'] ?? array() );
			foreach ( $policies as $policy ) {
				$allow_only = array_merge( $allow_only, $this->filter->string_list( $policy['allow_only'] ?? array() ) );
			}
			if ( ! empty( $allow_only ) ) {
				$tools = $this->filter->filter_by_allow_only( $tools, array_values( array_unique( $allow_only ) ), $preserve_tool );
			}

			$runtime_opt_in = $this->collect_runtime_tool_opt_in( $policies, $context, $allow_only, $mandatory_tools, $mandatory_cats );
			$tools          = $this->filter->filter_runtime_tools_by_policy_opt_in(
				$tools,
				$runtime_opt_in['tools'],
				$runtime_opt_in['categories'],
				$preserve_tool
			);

			if ( $this->is_autonomous_principal_context( $context ) && ! $this->allows_ambient_write_tools( $context, $policies ) ) {
				$tools = $this->filter->filter_write_capable_by_policy_opt_in(
					$tools,
					$runtime_opt_in['tools'],
					$runtime_opt_in['categories'],
					$this->filter->write_capable_categories( $context ),
					$preserve_tool
				);
			}

			$deny = $this->filter->string_list( $context['deny'] ?? array() );
			foreach ( $policies as $policy ) {
				$deny = array_merge( $deny, $this->filter->string_list( $policy['deny'] ?? array() ) );
			}
			if ( ! empty( $deny ) ) {
				$tools = array_diff_key( $tools, array_flip( array_values( array_unique( $deny ) ) ) );
			}

			if ( function_exists( 'apply_filters' ) ) {
				$filtered = apply_filters( 'agents_api_resolved_tools', $tools, $mode, $context, $this );
				$tools    = is_array( $filtered ) ? $this->normalize_tools( $filtered ) : $tools;
			}

			return $tools;
		}

		/**
		 * Collect policy fragments from agent config, runtime context, and providers.
		 *
		 * @param array<string, mixed> $context Runtime context.
		 * @return array<int, array<string, mixed>> Policy fragments.
		 */
		private function collect_policies( array $context ): array {
			$policies = array();

			$agent_config = $this->agent_config_from_context( $context );
			if ( is_array( $agent_config['tool_policy'] ?? null ) ) {
				$policies[] = $this->string_keyed_array( $agent_config['tool_policy'] );
			}

			if ( is_array( $context['tool_policy'] ?? null ) ) {
				$policies[] = $this->string_keyed_array( $context['tool_policy'] );
			}

			foreach ( $this->get_policy_providers( $context ) as $provider ) {
				$policy = $provider->get_tool_policy( $context );
				if ( is_array( $policy ) ) {
					$policies[] = $this->string_keyed_array( $policy );
				}
			}

			return $policies;
		}

		/**
		 * Return policy providers from constructor, context, and filter.
		 *
		 * @param array<string, mixed> $context Runtime context.
		 * @return WP_Agent_Tool_Access_Policy[] Providers.
		 */
		private function get_policy_providers( array $context ): array {
			$providers = $this->policy_providers;
			if ( is_array( $context['tool_policy_providers'] ?? null ) ) {
				$providers = array_merge( $providers, $context['tool_policy_providers'] );
			}

			if ( function_exists( 'apply_filters' ) ) {
				$providers = apply_filters( 'agents_api_tool_policy_providers', $providers, $context, $this );
			}

			return array_values(
				array_filter(
					is_array( $providers ) ? $providers : array(),
					static fn( $provider ): bool => $provider instanceof WP_Agent_Tool_Access_Policy
				)
			);
		}

		/**
		 * Return registered or runtime agent config from context.
		 *
		 * @param array<string, mixed> $context Runtime context.
		 * @return array<string, mixed> Agent config.
		 */
		private function agent_config_from_context( array $context ): array {
			if ( is_array( $context['agent_config'] ?? null ) ) {
				return $this->string_keyed_array( $context['agent_config'] );
			}

			$agent = $context['agent'] ?? null;
			if ( $agent instanceof WP_Agent ) {
				return $agent->get_default_config();
			}

			$agent_slug = $this->string_value( $context['agent_slug'] ?? ( $context['agent_id'] ?? '' ) );
			if ( '' !== $agent_slug && function_exists( 'wp_get_agent' ) ) {
				$registered = wp_get_agent( $agent_slug );
				if ( $registered instanceof WP_Agent ) {
					return $registered->get_default_config();
				}
			}

			return array();
		}

		/**
		 * Normalize allow/deny policy shape.
		 *
		 * @param array<string, mixed> $policy Raw policy.
		 * @return array<string, mixed>|null Normalized policy.
		 */
		private function normalize_named_policy( array $policy ): ?array {
			$mode = $this->string_value( $policy['mode'] ?? self::MODE_DENY );
			if ( ! in_array( $mode, array( self::MODE_ALLOW, self::MODE_DENY ), true ) ) {
				return null;
			}

			return array(
				'mode'       => $mode,
				'tools'      => $this->filter->string_list( $policy['tools'] ?? array() ),
				'categories' => $this->filter->string_list( $policy['categories'] ?? array() ),
			);
		}

		/**
		 * Collect list values from every policy fragment.
		 *
		 * @param array<int, array<string, mixed>> $policies Policy fragments.
		 * @param string                          $key      Policy key.
		 * @return string[] Values.
		 */
		private function collect_policy_list( array $policies, string $key ): array {
			$values = array();
			foreach ( $policies as $policy ) {
				$values = array_merge( $values, $this->filter->string_list( $policy[ $key ] ?? array() ) );
			}

			return array_values( array_unique( $values ) );
		}

		/**
		 * Convert scalar/Stringable input to a string.
		 *
		 * @param mixed $value Raw value.
		 * @return string String value, or empty string for non-stringable input.
		 */
		private function string_value( $value ): string {
			return is_scalar( $value ) || $value instanceof Stringable ? (string) $value : '';
		}

		/**
		 * Convert scalar/Stringable input to a non-negative integer.
		 *
		 * @param mixed $value Raw value.
		 * @return int Integer value (0 for non-stringable/negative input).
		 */
		private function int_value( $value ): int {
			if ( is_int( $value ) ) {
				return $value < 0 ? 0 : $value;
			}

			if ( is_bool( $value ) ) {
				return $value ? 1 : 0;
			}

			if ( is_float( $value ) ) {
				return $value > 0 ? (int) $value : 0;
			}

			if ( is_string( $value ) || $value instanceof Stringable ) {
				$int = (int) (string) $value;

				return $int < 0 ? 0 : $int;
			}

			return 0;
		}

		/**
		 * Keep only string-keyed entries from a policy map.
		 *
		 * @param array<mixed,mixed> $values Raw map.
		 * @return array<string,mixed>
		 */
		private function string_keyed_array( array $values ): array {
			$prepared = array();
			foreach ( $values as $key => $value ) {
				if ( is_string( $key ) ) {
					$prepared[ $key ] = $value;
				}
			}

			return $prepared;
		}

		/**
		 * Keep only valid string-keyed tool definitions.
		 *
		 * @param array<mixed,mixed> $tools Raw tool map.
		 * @return array<string,array<string,mixed>> Normalized tool map.
		 */
		private function normalize_tools( array $tools ): array {
			$prepared = array();
			foreach ( $tools as $name => $tool ) {
				if ( is_string( $name ) && is_array( $tool ) ) {
					$prepared[ $name ] = $this->string_keyed_array( $tool );
				}
			}

			return $prepared;
		}

		/**
		 * Collect explicit opt-ins for caller-provided runtime tools.
		 *
		 * @param array<int, array<string, mixed>> $policies        Policy fragments.
		 * @param array<string, mixed>             $context         Runtime context.
		 * @param string[]                         $allow_only      Effective allow-only names.
		 * @param string[]                         $mandatory_tools Mandatory tool names.
		 * @param string[]                         $mandatory_cats  Mandatory category slugs.
		 * @return array{tools: string[], categories: string[]} Runtime opt-in names/categories.
		 */
		private function collect_runtime_tool_opt_in( array $policies, array $context, array $allow_only, array $mandatory_tools, array $mandatory_cats ): array {
			$allowed_tools      = array_merge(
				$allow_only,
				$mandatory_tools,
				$this->filter->string_list( $context['runtime_tools'] ?? array() )
			);
			$allowed_categories = array_merge(
				$mandatory_cats,
				$this->filter->string_list( $context['runtime_categories'] ?? array() )
			);

			foreach ( $policies as $policy ) {
				$allowed_tools      = array_merge( $allowed_tools, $this->filter->string_list( $policy['runtime_tools'] ?? array() ) );
				$allowed_categories = array_merge( $allowed_categories, $this->filter->string_list( $policy['runtime_categories'] ?? array() ) );

				if ( self::MODE_ALLOW !== $this->string_value( $policy['mode'] ?? self::MODE_DENY ) ) {
					continue;
				}

				$allowed_tools      = array_merge( $allowed_tools, $this->filter->string_list( $policy['tools'] ?? array() ) );
				$allowed_categories = array_merge( $allowed_categories, $this->filter->string_list( $policy['categories'] ?? array() ) );
			}

			return array(
				'tools'      => array_values( array_unique( $allowed_tools ) ),
				'categories' => array_values( array_unique( $allowed_categories ) ),
			);
		}

		/**
		 * Whether the runtime context is driven by an autonomous principal.
		 *
		 * TOOL-SURFACE CURATION (defense-in-depth), NOT the enforcement
		 * boundary. This gate only decides whether write-capable tools are
		 * *offered* to the model. It reduces prompt surface and stops an
		 * autonomous agent from even trying a write tool. The actual
		 * security boundary is capability-ceiling ENFORCEMENT at
		 * ability/tool execution — tracked in #412. Consumers must not
		 * treat this listing gate as authorization.
		 *
		 * Autonomy is read from the execution principal — which the
		 * substrate already models precisely — instead of a mode/interactive
		 * string. The substrate therefore names no consumer modes here. A
		 * principal is autonomous when EITHER:
		 *
		 *   - its `auth_source` is an automation source
		 *     (`system` / `runtime` / `agent_token`), OR
		 *   - it has no human backing it (`acting_user_id` === 0).
		 *
		 * The principal is resolved from, in order: a `principal` entry on
		 * the context (a {@see \AgentsAPI\AI\WP_Agent_Execution_Principal}
		 * instance or its array shape), or flat `auth_source` /
		 * `acting_user_id` fields on the context root. When no principal
		 * information is present at all, the gate falls back SAFE: autonomy
		 * is assumed and the gate engages. Prefer the principal at the call
		 * site; the fallback exists so an unannotated context cannot
		 * silently expose write tools.
		 *
		 * @param array<string, mixed> $context Runtime context.
		 * @return bool Whether the principal is autonomous (gate engages).
		 */
		private function is_autonomous_principal_context( array $context ): bool {
			$signals = $this->principal_autonomy_signals( $context );

			if ( ! $signals['present'] ) {
				return true;
			}

			if ( 0 === $signals['acting_user_id'] ) {
				return true;
			}

			return in_array( $signals['auth_source'], array( 'system', 'runtime', 'agent_token' ), true );
		}

		/**
		 * Resolve principal autonomy signals from the runtime context.
		 *
		 * @param array<string, mixed> $context Runtime context.
		 * @return array{auth_source: string, acting_user_id: int, present: bool} Resolved signals.
		 */
		private function principal_autonomy_signals( array $context ): array {
			$principal = $context['principal'] ?? null;

			if ( $principal instanceof AgentsAPI\AI\WP_Agent_Execution_Principal ) {
				return array(
					'auth_source'    => $principal->auth_source,
					'acting_user_id' => $principal->acting_user_id,
					'present'        => true,
				);
			}

			if ( is_array( $principal ) && ( array_key_exists( 'auth_source', $principal ) || array_key_exists( 'acting_user_id', $principal ) ) ) {
				return array(
					'auth_source'    => $this->string_value( $principal['auth_source'] ?? '' ),
					'acting_user_id' => $this->int_value( $principal['acting_user_id'] ?? 0 ),
					'present'        => true,
				);
			}

			if ( array_key_exists( 'auth_source', $context ) || array_key_exists( 'acting_user_id', $context ) ) {
				return array(
					'auth_source'    => $this->string_value( $context['auth_source'] ?? '' ),
					'acting_user_id' => $this->int_value( $context['acting_user_id'] ?? 0 ),
					'present'        => true,
				);
			}

			return array(
				'auth_source'    => '',
				'acting_user_id' => 0,
				'present'        => false,
			);
		}

		/**
		 * Whether the context or any policy fragment opts in to ambient write tools.
		 *
		 * When true, the write-capable curation gate is skipped entirely
		 * for this resolution. This is the explicit escape hatch for consumers
		 * that intentionally want ambient write tools surfaced to an
		 * autonomous principal (defense-in-depth curation only; capability
		 * enforcement is tracked in #412).
		 *
		 * @param array<string, mixed>             $context  Runtime context.
		 * @param array<int, array<string, mixed>> $policies Policy fragments.
		 * @return bool Whether ambient write tools are allowed.
		 */
		private function allows_ambient_write_tools( array $context, array $policies ): bool {
			if ( true === ( $context['allow_write_tools'] ?? false ) ) {
				return true;
			}

			foreach ( $policies as $policy ) {
				if ( true === ( $policy['allow_write_tools'] ?? false ) ) {
					return true;
				}
			}

			return false;
		}
	}
}
