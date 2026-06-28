<?php
/**
 * Generic agent tool visibility filters.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Tool_Policy_Filter' ) ) {
	/**
	 * Applies reusable allow/deny/category/mode filtering to tool declarations.
	 */
	final class WP_Agent_Tool_Policy_Filter {

		/**
		 * Filter tools by mode declarations.
		 *
		 * Tools without a mode declaration are available in every mode.
		 *
		 * @param array<string, array<string, mixed>> $tools Tool definitions keyed by tool name.
		 * @param string              $mode Runtime mode.
		 * @return array<string, array<string, mixed>> Filtered tools.
		 */
		public function filter_by_mode( array $tools, string $mode ): array {
			if ( '' === $mode ) {
				return $tools;
			}

			$filtered = array();
			foreach ( $tools as $name => $tool ) {
				$modes = $tool['modes'] ?? ( $tool['mode'] ?? null );
				if ( null === $modes ) {
					$filtered[ $name ] = $tool;
					continue;
				}

				$modes = is_array( $modes ) ? $modes : array( $modes );
				if ( in_array( $mode, $modes, true ) ) {
					$filtered[ $name ] = $tool;
				}
			}

			return $filtered;
		}

		/**
		 * Filter tools through a caller-owned access checker.
		 *
		 * @param array<string, array<string, mixed>> $tools          Tool definitions keyed by tool name.
		 * @param callable            $access_checker Callback receiving ($tool, $name).
		 * @return array<string, array<string, mixed>> Filtered tools.
		 */
		public function filter_by_access_checker( array $tools, callable $access_checker ): array {
			$filtered = array();
			foreach ( $tools as $name => $tool ) {
				if ( $access_checker( $tool, $name ) ) {
					$filtered[ $name ] = $tool;
				}
			}

			return $filtered;
		}

		/**
		 * Apply an allow/deny policy to optional tools.
		 *
		 * @param array<string, array<string, mixed>> $tools         Tool definitions keyed by tool name.
		 * @param array<string, mixed>|null $policy     Policy with mode/tools/categories keys.
		 * @param callable|null         $preserve_tool Optional callback for mandatory tools.
		 * @return array<string, array<string, mixed>> Filtered tools.
		 */
		public function apply_named_policy( array $tools, ?array $policy, ?callable $preserve_tool = null ): array {
			if ( null === $policy ) {
				return $tools;
			}

			$mode_value = $policy['mode'] ?? WP_Agent_Tool_Policy::MODE_DENY;
			$mode       = is_scalar( $mode_value ) ? (string) $mode_value : WP_Agent_Tool_Policy::MODE_DENY;
			$tool_names = $this->string_list( $policy['tools'] ?? array() );
			$categories = $this->string_list( $policy['categories'] ?? array() );
			$split      = $this->split_preserved_tools( $tools, $preserve_tool );

			if ( empty( $tool_names ) && empty( $categories ) ) {
				return WP_Agent_Tool_Policy::MODE_ALLOW === $mode ? $split['preserved'] : $tools;
			}

			$filtered = array();
			foreach ( $split['optional'] as $name => $tool ) {
				$matches = in_array( $name, $tool_names, true ) || $this->tool_matches_categories( $this->string_keyed_array( $tool ), $categories );

				if ( WP_Agent_Tool_Policy::MODE_ALLOW === $mode && $matches ) {
					$filtered[ $name ] = $tool;
				} elseif ( WP_Agent_Tool_Policy::MODE_DENY === $mode && ! $matches ) {
					$filtered[ $name ] = $tool;
				}
			}

			return $split['preserved'] + $filtered;
		}

		/**
		 * Filter tools by category while preserving mandatory tools.
		 *
		 * @param array<string, array<string, mixed>> $tools         Tool definitions keyed by tool name.
		 * @param string[]            $categories    Allowed categories.
		 * @param callable|null       $preserve_tool Optional callback for mandatory tools.
		 * @return array<string, array<string, mixed>> Filtered tools.
		 */
		public function filter_by_categories( array $tools, array $categories, ?callable $preserve_tool = null ): array {
			$categories = $this->string_list( $categories );
			if ( empty( $categories ) ) {
				return $tools;
			}

			$filtered = array();
			foreach ( $tools as $name => $tool ) {
				if ( $preserve_tool && $preserve_tool( $tool, $name ) ) {
					$filtered[ $name ] = $tool;
					continue;
				}

				if ( $this->tool_matches_categories( $this->string_keyed_array( $tool ), $categories ) ) {
					$filtered[ $name ] = $tool;
				}
			}

			return $filtered;
		}

		/**
		 * Apply an allow-only list while preserving mandatory tools.
		 *
		 * @param array<string, array<string, mixed>> $tools         Tool definitions keyed by tool name.
		 * @param string[]            $allow_only    Tool names to allow.
		 * @param callable|null       $preserve_tool Optional callback for mandatory tools.
		 * @return array<string, array<string, mixed>> Filtered tools.
		 */
		public function filter_by_allow_only( array $tools, array $allow_only, ?callable $preserve_tool = null ): array {
			$allow_only = $this->string_list( $allow_only );
			$split      = $this->split_preserved_tools( $tools, $preserve_tool );

			return $split['preserved'] + array_intersect_key( $split['optional'], array_flip( $allow_only ) );
		}

		/**
		 * Exclude caller-provided runtime tools unless policy explicitly opts them in.
		 *
		 * Non-runtime tools are never affected by this guard. Runtime tools match neutral
		 * declaration metadata (`runtime_tool` or `executor=client`) and
		 * must be explicitly named, category-matched, or preserved by host policy.
		 *
		 * @param array<string, array<string, mixed>> $tools              Tool definitions keyed by tool name.
		 * @param string[]            $allowed_tools      Runtime tool names explicitly allowed.
		 * @param string[]            $allowed_categories Runtime tool categories explicitly allowed.
		 * @param callable|null       $preserve_tool      Optional callback for mandatory tools.
		 * @return array<string, array<string, mixed>> Filtered tools.
		 */
		public function filter_runtime_tools_by_policy_opt_in( array $tools, array $allowed_tools, array $allowed_categories = array(), ?callable $preserve_tool = null ): array {
			$allowed_tools      = $this->string_list( $allowed_tools );
			$allowed_categories = $this->string_list( $allowed_categories );
			$filtered           = array();

			foreach ( $tools as $name => $tool ) {
				if ( ! $this->is_runtime_tool( $tool ) ) {
					$filtered[ $name ] = $tool;
					continue;
				}

				if ( in_array( $name, $allowed_tools, true ) ) {
					$filtered[ $name ] = $tool;
					continue;
				}

				if ( $this->tool_matches_categories( $tool, $allowed_categories ) ) {
					$filtered[ $name ] = $tool;
					continue;
				}

				if ( $preserve_tool && $preserve_tool( $tool, $name ) ) {
					$filtered[ $name ] = $tool;
				}
			}

			return $filtered;
		}

		/**
		 * Whether a declaration represents a caller-provided runtime tool.
		 *
		 * @param array<string, mixed> $tool Tool definition.
		 * @return bool Whether the tool is a runtime/client tool.
		 */
		public function is_runtime_tool( array $tool ): bool {
			return true === ( $tool['runtime_tool'] ?? false )
				|| 'client' === ( $tool['executor'] ?? null );
		}

		/**
		 * Whether a tool matches any category in a policy.
		 *
		 * @param array<string, mixed> $tool       Tool definition.
		 * @param string[]             $categories Category slugs.
		 * @return bool Whether the tool matches.
		 */
		public function tool_matches_categories( array $tool, array $categories ): bool {
			$categories = $this->string_list( $categories );
			if ( empty( $categories ) ) {
				return false;
			}

			$tool_categories = $this->tool_categories( $tool );
			return (bool) array_intersect( $tool_categories, $categories );
		}

		/**
		 * Return normalized category slugs declared by a tool.
		 *
		 * @param array<string, mixed> $tool Tool definition.
		 * @return string[] Category slugs.
		 */
		private function tool_categories( array $tool ): array {
			$categories = array();

			foreach ( array( 'category', 'ability_category' ) as $key ) {
				if ( is_string( $tool[ $key ] ?? null ) && '' !== $tool[ $key ] ) {
					$categories[] = $tool[ $key ];
				}
			}

			foreach ( array( 'categories', 'ability_categories' ) as $key ) {
				if ( is_array( $tool[ $key ] ?? null ) ) {
					$categories = array_merge( $categories, $this->string_list( $tool[ $key ] ) );
				}
			}

			if ( class_exists( 'WP_Abilities_Registry' ) ) {
				$registry = WP_Abilities_Registry::get_instance();
				foreach ( $this->ability_slugs( $tool ) as $slug ) {
					$ability = $registry->get_registered( $slug );
					if ( $ability ) {
						$categories[] = $ability->get_category();
					}
				}
			}

			return array_values( array_unique( $this->string_list( $categories ) ) );
		}

		/**
		 * Return linked ability slugs from a tool declaration.
		 *
		 * @param array<string, mixed> $tool Tool definition.
		 * @return string[] Ability slugs.
		 */
		private function ability_slugs( array $tool ): array {
			$ability_slugs = array();
			if ( is_string( $tool['ability'] ?? null ) && '' !== $tool['ability'] ) {
				$ability_slugs[] = $tool['ability'];
			}

			if ( is_array( $tool['abilities'] ?? null ) ) {
				$ability_slugs = array_merge( $ability_slugs, $this->string_list( $tool['abilities'] ) );
			}

			return array_values( array_unique( $ability_slugs ) );
		}

		/**
		 * Split mandatory tools from optional tools.
		 *
		 * @param array<string, array<string, mixed>> $tools         Tool definitions keyed by tool name.
		 * @param callable|null $preserve_tool Optional callback.
		 * @return array{preserved: array<string, array<string, mixed>>, optional: array<string, array<string, mixed>>} Split buckets.
		 */
		private function split_preserved_tools( array $tools, ?callable $preserve_tool ): array {
			if ( null === $preserve_tool ) {
				return array(
					'preserved' => array(),
					'optional'  => $tools,
				);
			}

			$preserved = array();
			$optional  = array();
			foreach ( $tools as $name => $tool ) {
				if ( $preserve_tool( $tool, $name ) ) {
					$preserved[ $name ] = $tool;
				} else {
					$optional[ $name ] = $tool;
				}
			}

			return array(
				'preserved' => $preserved,
				'optional'  => $optional,
			);
		}

		/**
		 * Normalize a list of string values.
		 *
		 * @param mixed $values Raw list.
		 * @return string[] Non-empty strings.
		 */
		public function string_list( $values ): array {
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
		 * @param array<mixed> $values Raw values.
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
