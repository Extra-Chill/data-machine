<?php
/**
 * Generic tool action policy resolver.
 *
 * @package AgentsAPI
 */

use AgentsAPI\AI\Tools\WP_Agent_Action_Policy;
use AgentsAPI\AI\Approvals\WP_Agent_Approval_Memory_Store;
use AgentsAPI\AI\Approvals\WP_Agent_Null_Approval_Memory_Store;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Action_Policy_Resolver' ) ) {
	/**
	 * Resolves whether a tool call runs directly, previews, or is forbidden.
	 */
	class WP_Agent_Action_Policy_Resolver {

		/**
		 * @var WP_Agent_Action_Policy_Provider[]
		 */
		private array $policy_providers;

		/**
		 * @var WP_Agent_Tool_Policy_Filter
		 */
		private WP_Agent_Tool_Policy_Filter $tool_filter;

		/**
		 * @var WP_Agent_Approval_Memory_Store
		 */
		private WP_Agent_Approval_Memory_Store $approval_memory_store;

		/**
		 * Constructor.
		 *
		 * @param WP_Agent_Action_Policy_Provider[]|null $policy_providers      Host policy providers.
		 * @param WP_Agent_Tool_Policy_Filter|null       $tool_filter           Shared tool filter.
		 * @param WP_Agent_Approval_Memory_Store|null    $approval_memory_store Approval memory store.
		 */
		public function __construct( ?array $policy_providers = null, ?WP_Agent_Tool_Policy_Filter $tool_filter = null, ?WP_Agent_Approval_Memory_Store $approval_memory_store = null ) {
			$this->policy_providers      = is_array( $policy_providers ) ? $policy_providers : array();
			$this->tool_filter           = $tool_filter ?? new WP_Agent_Tool_Policy_Filter();
			$this->approval_memory_store = $approval_memory_store ?? new WP_Agent_Null_Approval_Memory_Store();
		}

		/**
		 * Resolve action policy for one tool invocation.
		 *
		 * @param array<string, mixed> $context Resolution context.
		 * @return string One of direct, preview, forbidden.
		 */
		public function resolve_for_tool( array $context ): string {
			$tool_name = $this->string_value( $context['tool_name'] ?? '' );
			$mode      = $this->string_value( $context['mode'] ?? WP_Agent_Tool_Policy::RUNTIME_CHAT );
			$tool_def  = is_array( $context['tool_def'] ?? null ) ? $this->string_keyed_array( $context['tool_def'] ) : array();

			if ( '' === $tool_name ) {
				return WP_Agent_Action_Policy::DIRECT;
			}

			if ( in_array( $tool_name, $this->string_list( $context['deny'] ?? array() ), true ) ) {
				return $this->apply_filter( WP_Agent_Action_Policy::FORBIDDEN, $tool_name, $mode, $context );
			}

			$agent_policy = $this->agent_action_policy_from_context( $context );
			$agent_tool   = $this->agent_tool_override( $agent_policy, $tool_name );
			if ( null !== $agent_tool ) {
				return $this->apply_filter( $agent_tool, $tool_name, $mode, $context );
			}

			$agent_category = $this->agent_category_override( $agent_policy, $tool_def );
			if ( null !== $agent_category ) {
				return $this->apply_filter( $agent_category, $tool_name, $mode, $context );
			}

			foreach ( $this->get_policy_providers( $context ) as $provider ) {
				$provided = WP_Agent_Action_Policy::normalize( $provider->get_action_policy( $context ) );
				if ( null !== $provided ) {
					return $this->apply_filter( $provided, $tool_name, $mode, $context );
				}
			}

			$remembered = $this->remembered_action_policy( $context, $tool_name );
			if ( null !== $remembered ) {
				return $this->apply_filter( $remembered, $tool_name, $mode, $context );
			}

			$tool_default = $this->tool_declared_default( $tool_def );
			if ( null !== $tool_default ) {
				return $this->apply_filter( $tool_default, $tool_name, $mode, $context );
			}

			$mode_default = $this->mode_declared_default( $tool_def, $mode );
			if ( null !== $mode_default ) {
				return $this->apply_filter( $mode_default, $tool_name, $mode, $context );
			}

			return $this->apply_filter( WP_Agent_Action_Policy::DIRECT, $tool_name, $mode, $context );
		}

		/**
		 * Return policy providers from constructor, context, and filters.
		 *
		 * @param array<string, mixed> $context Runtime context.
		 * @return WP_Agent_Action_Policy_Provider[] Providers.
		 */
		private function get_policy_providers( array $context ): array {
			$providers = $this->policy_providers;
			if ( is_array( $context['action_policy_providers'] ?? null ) ) {
				$providers = array_merge( $providers, $context['action_policy_providers'] );
			}

			if ( function_exists( 'apply_filters' ) ) {
				$providers = apply_filters( 'agents_api_action_policy_providers', $providers, $context, $this );
			}

			return array_values(
				array_filter(
					is_array( $providers ) ? $providers : array(),
					static fn( $provider ): bool => $provider instanceof WP_Agent_Action_Policy_Provider
				)
			);
		}

		/**
		 * Return approval memory store from context, filters, or constructor.
		 *
		 * @param array<string, mixed> $context Runtime context.
		 * @return WP_Agent_Approval_Memory_Store Store.
		 */
		private function get_approval_memory_store( array $context ): WP_Agent_Approval_Memory_Store {
			$store = $context['approval_memory_store'] ?? $this->approval_memory_store;

			if ( function_exists( 'apply_filters' ) ) {
				$store = apply_filters( 'agents_api_approval_memory_store', $store, $context, $this );
			}

			return $store instanceof WP_Agent_Approval_Memory_Store ? $store : new WP_Agent_Null_Approval_Memory_Store();
		}

		/**
		 * Recall remembered action policy for the current invocation, when enough identity exists.
		 *
		 * @param array<string, mixed> $context   Runtime context.
		 * @param string               $tool_name Tool name.
		 * @return string|null Normalized remembered policy or null.
		 */
		private function remembered_action_policy( array $context, string $tool_name ): ?string {
			$workspace = $this->workspace_from_context( $context );
			$user_id   = $this->int_value( $context['user_id'] ?? ( $context['acting_user_id'] ?? 0 ) );
			$agent_id  = $this->string_value( $context['agent_id'] ?? ( $context['agent_slug'] ?? '' ) );

			if ( null === $workspace || $user_id <= 0 || '' === $agent_id ) {
				return null;
			}

			return WP_Agent_Action_Policy::normalize(
				$this->get_approval_memory_store( $context )->recall( $workspace, $user_id, $agent_id, $tool_name )
			);
		}

		/**
		 * Return workspace scope from context.
		 *
		 * @param array<string, mixed> $context Runtime context.
		 * @return WP_Agent_Workspace_Scope|null Workspace scope when present and valid.
		 */
		private function workspace_from_context( array $context ): ?WP_Agent_Workspace_Scope {
			$workspace = $context['workspace'] ?? null;
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

			return null;
		}

		/**
		 * Return action policy from runtime or registered agent config.
		 *
		 * @param array<string, mixed> $context Runtime context.
		 * @return array<string, mixed> Policy map.
		 */
		private function agent_action_policy_from_context( array $context ): array {
			if ( is_array( $context['action_policy'] ?? null ) ) {
				return $this->string_keyed_array( $context['action_policy'] );
			}

			$agent_config = array();
			if ( is_array( $context['agent_config'] ?? null ) ) {
				$agent_config = $context['agent_config'];
			} elseif ( ( $context['agent'] ?? null ) instanceof WP_Agent ) {
				$agent_config = $context['agent']->get_default_config();
			} else {
				$agent_slug = $this->string_value( $context['agent_slug'] ?? ( $context['agent_id'] ?? '' ) );
				if ( '' !== $agent_slug && function_exists( 'wp_get_agent' ) ) {
					$agent = wp_get_agent( $agent_slug );
					if ( $agent instanceof WP_Agent ) {
						$agent_config = $agent->get_default_config();
					}
				}
			}

			return is_array( $agent_config['action_policy'] ?? null ) ? $this->string_keyed_array( $agent_config['action_policy'] ) : array();
		}

		/**
		 * Resolve per-tool agent override.
		 *
		 * @param array<string, mixed> $policy    Agent policy.
		 * @param string               $tool_name Tool name.
		 * @return string|null Normalized policy or null.
		 */
		private function agent_tool_override( array $policy, string $tool_name ): ?string {
			$tools = is_array( $policy['tools'] ?? null ) ? $policy['tools'] : array();
			return WP_Agent_Action_Policy::normalize( $tools[ $tool_name ] ?? null );
		}

		/**
		 * Resolve per-category agent override.
		 *
		 * @param array<string, mixed> $policy   Agent policy.
		 * @param array<string, mixed> $tool_def Tool definition.
		 * @return string|null Normalized policy or null.
		 */
		private function agent_category_override( array $policy, array $tool_def ): ?string {
			$categories = is_array( $policy['categories'] ?? null ) ? $policy['categories'] : array();
			foreach ( $categories as $category => $raw_policy ) {
				if ( ! is_string( $category ) || ! $this->tool_filter->tool_matches_categories( $tool_def, array( $category ) ) ) {
					continue;
				}

				$policy_value = WP_Agent_Action_Policy::normalize( $raw_policy );
				if ( null !== $policy_value ) {
					return $policy_value;
				}
			}

			return null;
		}

		/**
		 * Return tool-declared default action policy.
		 *
		 * @param array<string, mixed> $tool_def Tool definition.
		 * @return string|null Normalized policy or null.
		 */
		private function tool_declared_default( array $tool_def ): ?string {
			return WP_Agent_Action_Policy::normalize( $tool_def['action_policy'] ?? null );
		}

		/**
		 * Return mode-specific tool-declared action policy.
		 *
		 * @param array<string, mixed> $tool_def Tool definition.
		 * @param string               $mode     Runtime mode.
		 * @return string|null Normalized policy or null.
		 */
		private function mode_declared_default( array $tool_def, string $mode ): ?string {
			return WP_Agent_Action_Policy::normalize( $tool_def[ 'action_policy_' . $mode ] ?? null );
		}

		/**
		 * Apply final WordPress filter and keep only canonical values.
		 *
		 * @param string               $policy    Computed policy.
		 * @param string               $tool_name Tool name.
		 * @param string               $mode      Runtime mode.
		 * @param array<string, mixed> $context   Resolution context.
		 * @return string Filtered policy.
		 */
		private function apply_filter( string $policy, string $tool_name, string $mode, array $context ): string {
			if ( ! function_exists( 'apply_filters' ) ) {
				return $policy;
			}

			$filtered = apply_filters( 'agents_api_tool_action_policy', $policy, $tool_name, $mode, $context, $this );
			return WP_Agent_Action_Policy::normalize( $filtered ) ?? $policy;
		}

		/**
		 * Normalize a list of strings.
		 *
		 * @param mixed $values Raw list.
		 * @return string[] Non-empty strings.
		 */
		private function string_list( $values ): array {
			$values = is_array( $values ) ? $values : array( $values );
			$values = array_filter(
				array_map(
					static fn( $value ) => is_string( $value ) ? trim( $value ) : '',
					$values
				),
				static fn( string $value ): bool => '' !== $value
			);

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
		 * Convert scalar/Stringable input to an integer.
		 *
		 * @param mixed $value Raw value.
		 * @return int Integer value, or zero for non-stringable input.
		 */
		private function int_value( $value ): int {
			if ( is_int( $value ) ) {
				return $value;
			}

			if ( is_bool( $value ) ) {
				return $value ? 1 : 0;
			}

			if ( is_float( $value ) ) {
				return (int) $value;
			}

			if ( is_string( $value ) || $value instanceof Stringable ) {
				return (int) (string) $value;
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
	}
}
