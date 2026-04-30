<?php
/**
 * Generic tool policy filter.
 *
 * Applies reusable tool allow/deny/category/capability filtering without
 * knowing about Data Machine flows, handlers, jobs, or persisted agent state.
 * Product adapters can pass a preservation callback for mandatory tools.
 *
 * @package DataMachine\Engine\AI\Tools\Policy
 */

namespace DataMachine\Engine\AI\Tools\Policy;

defined( 'ABSPATH' ) || exit;

final class ToolPolicyFilter {

	/**
	 * Filter tools by linked ability permissions or fallback access level.
	 *
	 * @param array    $tools                Tool definitions keyed by tool name.
	 * @param callable $access_level_checker Callback receiving an access-level string.
	 * @return array Filtered tools.
	 */
	public function filterByAbilityPermissions( array $tools, callable $access_level_checker ): array {
		$registry = \WP_Abilities_Registry::get_instance();
		$filtered = array();

		foreach ( $tools as $name => $tool ) {
			if ( ! is_array( $tool ) ) {
				continue;
			}

			$ability_slugs = $this->getAbilitySlugs( $tool );

			if ( ! empty( $ability_slugs ) ) {
				if ( $this->allAbilitiesPermitted( $ability_slugs, $registry ) ) {
					$filtered[ $name ] = $tool;
				}

				continue;
			}

			$access_level = $tool['access_level'] ?? 'admin';
			if ( is_string( $access_level ) && $access_level_checker( $access_level ) ) {
				$filtered[ $name ] = $tool;
			}
		}

		return $filtered;
	}

	/**
	 * Filter tools by their linked ability category.
	 *
	 * @param array         $tools              Tool definitions keyed by tool name.
	 * @param array         $categories         Allowed category slugs.
	 * @param callable|null $preserve_tool      Optional callback for tools that bypass this filter.
	 * @return array Filtered tools.
	 */
	public function filterByAbilityCategories( array $tools, array $categories, ?callable $preserve_tool = null ): array {
		if ( empty( $categories ) ) {
			return $tools;
		}

		$registry        = \WP_Abilities_Registry::get_instance();
		$categories_flip = array_flip( $categories );
		$filtered        = array();

		foreach ( $tools as $name => $tool ) {
			if ( ! is_array( $tool ) ) {
				continue;
			}

			if ( $preserve_tool && $preserve_tool( $tool ) ) {
				$filtered[ $name ] = $tool;
				continue;
			}

			foreach ( $this->getAbilitySlugs( $tool ) as $slug ) {
				$ability = $registry->get_registered( $slug );
				if ( $ability && isset( $categories_flip[ $ability->get_category() ] ) ) {
					$filtered[ $name ] = $tool;
					break;
				}
			}
		}

		return $filtered;
	}

	/**
	 * Apply a named allow/deny policy to optional tools.
	 *
	 * @param array         $tools         Tool definitions keyed by tool name.
	 * @param array|null    $policy        Policy with mode/tools/categories keys.
	 * @param callable|null $preserve_tool Optional callback for tools that bypass optional policy.
	 * @return array Filtered tools.
	 */
	public function applyNamedPolicy( array $tools, ?array $policy, ?callable $preserve_tool = null ): array {
		if ( null === $policy ) {
			return $tools;
		}

		$mode              = $policy['mode'];
		$tool_names        = $policy['tools'] ?? array();
		$policy_categories = $policy['categories'] ?? array();
		$policy_tools      = $this->splitPreservedTools( $tools, $preserve_tool );
		$preserved_tools   = $policy_tools['preserved'];
		$optional_tools    = $policy_tools['optional'];

		if ( empty( $tool_names ) && empty( $policy_categories ) ) {
			return 'allow' === $mode ? $preserved_tools : $tools;
		}

		if ( empty( $policy_categories ) ) {
			if ( 'deny' === $mode ) {
				return $preserved_tools + array_diff_key( $optional_tools, array_flip( $tool_names ) );
			}

			return $preserved_tools + array_intersect_key( $optional_tools, array_flip( $tool_names ) );
		}

		return $preserved_tools + $this->filterOptionalToolsByNamedPolicy( $optional_tools, $mode, $tool_names, $policy_categories );
	}

	/**
	 * Apply an allow-only list to optional tools.
	 *
	 * @param array         $tools         Tool definitions keyed by tool name.
	 * @param array         $allow_only    Optional tool names to allow.
	 * @param callable|null $preserve_tool Optional callback for tools that bypass optional policy.
	 * @return array Filtered tools.
	 */
	public function filterByAllowOnly( array $tools, array $allow_only, ?callable $preserve_tool = null ): array {
		$policy_tools = $this->splitPreservedTools( $tools, $preserve_tool );

		return $policy_tools['preserved'] + array_intersect_key( $policy_tools['optional'], array_flip( $allow_only ) );
	}

	/**
	 * Get ability slugs from tool metadata.
	 *
	 * @param array $tool Tool definition.
	 * @return array<int, string> Ability slugs.
	 */
	private function getAbilitySlugs( array $tool ): array {
		$ability_slugs = array();

		if ( ! empty( $tool['ability'] ) && is_string( $tool['ability'] ) ) {
			$ability_slugs[] = $tool['ability'];
		}

		if ( ! empty( $tool['abilities'] ) && is_array( $tool['abilities'] ) ) {
			foreach ( $tool['abilities'] as $slug ) {
				if ( is_string( $slug ) && '' !== $slug ) {
					$ability_slugs[] = $slug;
				}
			}
		}

		return $ability_slugs;
	}

	/**
	 * Return whether all linked abilities permit the current request.
	 *
	 * @param array                 $ability_slugs Ability slugs.
	 * @param \WP_Abilities_Registry $registry      Abilities registry.
	 * @return bool Whether all abilities pass permission checks.
	 */
	private function allAbilitiesPermitted( array $ability_slugs, \WP_Abilities_Registry $registry ): bool {
		foreach ( $ability_slugs as $slug ) {
			$ability = $registry->get_registered( $slug );

			if ( ! $ability || ! $ability->check_permissions() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Split preserved tools from optional tools.
	 *
	 * @param array         $tools         Tool definitions keyed by tool name.
	 * @param callable|null $preserve_tool Optional preservation callback.
	 * @return array{preserved: array, optional: array} Split tool buckets.
	 */
	private function splitPreservedTools( array $tools, ?callable $preserve_tool ): array {
		if ( null === $preserve_tool ) {
			return array(
				'preserved' => array(),
				'optional'  => $tools,
			);
		}

		$preserved = array_filter(
			$tools,
			static fn( $tool ) => is_array( $tool ) && $preserve_tool( $tool )
		);

		return array(
			'preserved' => $preserved,
			'optional'  => array_diff_key( $tools, $preserved ),
		);
	}

	/**
	 * Filter optional tools by names and categories.
	 *
	 * @param array  $tools             Optional tool definitions keyed by tool name.
	 * @param string $mode              Policy mode: allow or deny.
	 * @param array  $tool_names        Tool names in the policy.
	 * @param array  $policy_categories Ability categories in the policy.
	 * @return array Filtered optional tools.
	 */
	private function filterOptionalToolsByNamedPolicy( array $tools, string $mode, array $tool_names, array $policy_categories ): array {
		$registry        = \WP_Abilities_Registry::get_instance();
		$tool_names_flip = ! empty( $tool_names ) ? array_flip( $tool_names ) : array();
		$categories_flip = array_flip( $policy_categories );
		$filtered        = array();

		foreach ( $tools as $name => $tool ) {
			$matches_tool = isset( $tool_names_flip[ $name ] );
			$matches_cat  = false;

			if ( ! $matches_tool && is_array( $tool ) ) {
				foreach ( $this->getAbilitySlugs( $tool ) as $slug ) {
					$ability = $registry->get_registered( $slug );
					if ( $ability && isset( $categories_flip[ $ability->get_category() ] ) ) {
						$matches_cat = true;
						break;
					}
				}
			}

			$matches = $matches_tool || $matches_cat;

			if ( 'allow' === $mode && $matches ) {
				$filtered[ $name ] = $tool;
			} elseif ( 'deny' === $mode && ! $matches ) {
				$filtered[ $name ] = $tool;
			}
		}

		return $filtered;
	}
}
