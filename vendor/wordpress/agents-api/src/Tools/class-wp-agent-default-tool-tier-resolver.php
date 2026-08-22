<?php
/**
 * Default tool tier resolver.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Default_Tool_Tier_Resolver' ) ) {
	/**
	 * Resolves Tier-1 full-schema tools and Tier-2 compact-manifest tools.
	 */
	final class WP_Agent_Default_Tool_Tier_Resolver implements WP_Agent_Tool_Tier_Resolver {

		private WP_Agent_Tool_Usage_Tracker $usage_tracker;
		private int $hard_cap;

		public function __construct( ?WP_Agent_Tool_Usage_Tracker $usage_tracker = null, int $hard_cap = 15 ) {
			$this->usage_tracker = $usage_tracker ?? new WP_Agent_Null_Tool_Usage_Tracker();
			$this->hard_cap      = max( 1, $hard_cap );
		}

		/** @inheritDoc */
		public function resolve( array $tools, array $context = array() ): array {
			$cap      = $this->hard_cap( $context );
			$tier_1   = array();
			$selected = array();

			foreach ( $this->candidate_names( $tools, $context, $cap ) as $name ) {
				if ( count( $tier_1 ) >= $cap ) {
					break;
				}

				if ( isset( $selected[ $name ] ) || ! isset( $tools[ $name ] ) ) {
					continue;
				}

				$selected[ $name ] = true;
				$tier_1[ $name ]   = $tools[ $name ];
			}

			$tier_2 = array_diff_key( $tools, $tier_1 );

			return array(
				'tier_1'   => $tier_1,
				'tier_2'   => $tier_2,
				'manifest' => $this->manifest( $tier_2 ),
			);
		}

		/**
		 * @param array<string, array<string, mixed>> $tools   Visible tool declarations.
		 * @param array<string, mixed>                $context Runtime context.
		 * @param int                                 $cap     Tier-1 hard cap.
		 * @return string[] Candidate tool names in selection order.
		 */
		private function candidate_names( array $tools, array $context, int $cap ): array {
			$workspace_id = $this->workspace_id( $context );

			return array_values(
				array_unique(
					array_merge(
						$this->string_list( $context['tier_1_tools'] ?? array() ),
						$this->usage_tracker->top_n( $workspace_id, $cap ),
						$this->mandatory_tool_names( $tools ),
						array_keys( $tools )
					)
				)
			);
		}

		/**
		 * @param array<string, array<string, mixed>> $tools Visible tool declarations.
		 * @return string[] Mandatory tool names.
		 */
		private function mandatory_tool_names( array $tools ): array {
			$names = array();
			foreach ( $tools as $name => $tool ) {
				if ( true === ( $tool['mandatory'] ?? false ) ) {
					$names[] = $name;
				}
			}
			return $names;
		}

		/**
		 * @param array<string, array<string, mixed>> $tools Tier-2 tools.
		 * @return array<int, array<string, mixed>> Compact manifest entries.
		 */
		private function manifest( array $tools ): array {
			$manifest = array();
			foreach ( $tools as $name => $tool ) {
				$description = $tool['description'] ?? '';
				$manifest[] = array(
					'name'            => $name,
					'summary'         => is_scalar( $description ) ? trim( (string) $description ) : '',
					'required_fields' => $this->required_fields( $tool ),
				);
			}
			return $manifest;
		}

		/**
		 * @param array<string, mixed> $tool Tool declaration.
		 * @return string[] Required parameter names.
		 */
		private function required_fields( array $tool ): array {
			$parameters = $tool['parameters'] ?? array();
			if ( ! is_array( $parameters ) ) {
				return array();
			}

			return $this->string_list( $parameters['required'] ?? array() );
		}

		/** @param array<string, mixed> $context Runtime context. */
		private function hard_cap( array $context ): int {
			$cap = $context['tool_tier_hard_cap'] ?? $this->hard_cap;
			return max( 1, is_numeric( $cap ) ? (int) $cap : $this->hard_cap );
		}

		/** @param array<string, mixed> $context Runtime context. */
		private function workspace_id( array $context ): string {
			$workspace = $context['workspace_id'] ?? ( is_array( $context['workspace'] ?? null ) ? ( $context['workspace']['id'] ?? '' ) : '' );
			return is_scalar( $workspace ) ? (string) $workspace : '';
		}

		/**
		 * @param mixed $values Raw list.
		 * @return string[] Non-empty strings.
		 */
		private function string_list( $values ): array {
			$values = is_array( $values ) ? $values : array( $values );
			$values = array_filter(
				array_map(
					static fn( $value ): string => is_scalar( $value ) ? trim( (string) $value ) : '',
					$values
				),
				static fn( string $value ): bool => '' !== $value
			);

			return array_values( array_unique( $values ) );
		}
	}
}
