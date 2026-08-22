<?php
/**
 * Default Context Conflict Resolver
 *
 * Deterministic generic resolver for retrieved context conflicts.
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\AI\Context;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Default_Context_Conflict_Resolver implements WP_Agent_Context_Conflict_Resolver {

	/**
	 * @param WP_Agent_Context_Item[] $items   Retrieved context items.
	 * @param array<mixed>                  $context Optional caller context.
	 * @return WP_Agent_Context_Conflict_Resolution[] Conflict resolutions keyed by conflict key.
	 */
	public function resolve( array $items, array $context = array() ): array {
		unset( $context );

		$groups = array();
		foreach ( $items as $index => $item ) {
			if ( null === $item->conflict_key || '' === $item->conflict_key ) {
				continue;
			}

			$groups[ $item->conflict_key ][] = array(
				'item'  => $item,
				'index' => $index,
			);
		}

		$resolutions = array();
		foreach ( $groups as $conflict_key => $group ) {
			$resolutions[ $conflict_key ] = $this->resolve_group( (string) $conflict_key, $group );
		}

		return $resolutions;
	}

	/**
	 * @param string                                                       $conflict_key Conflict key.
	 * @param array<int, array{item: WP_Agent_Context_Item, index: int}>   $group        Group rows with item/index keys.
	 * @return WP_Agent_Context_Conflict_Resolution
	 */
	private function resolve_group( string $conflict_key, array $group ): WP_Agent_Context_Conflict_Resolution {
		$authoritative = array_values(
			array_filter(
				$group,
				static fn ( array $row ): bool => WP_Agent_Context_Conflict_Kind::AUTHORITATIVE_FACT === WP_Agent_Context_Conflict_Kind::normalize( $row['item']->conflict_kind )
			)
		);

		if ( $authoritative ) {
			usort(
				$authoritative,
				static function ( array $left, array $right ): int {
					$rank_delta = WP_Agent_Context_Authority_Tier::authority_rank( $right['item']->authority_tier ) <=> WP_Agent_Context_Authority_Tier::authority_rank( $left['item']->authority_tier );
					return 0 !== $rank_delta ? $rank_delta : ( $left['index'] <=> $right['index'] );
				}
			);

			$winner = $authoritative[0]['item'];

			return new WP_Agent_Context_Conflict_Resolution(
				$conflict_key,
				$winner,
				$this->rejected_items( $group, $winner ),
				'authority_tier',
				'Authoritative facts resolve by authority tier; lower-scope memory cannot override them.'
			);
		}

		usort(
			$group,
			static function ( array $left, array $right ): int {
				$specificity_delta = WP_Agent_Context_Authority_Tier::specificity_rank( $right['item']->authority_tier ) <=> WP_Agent_Context_Authority_Tier::specificity_rank( $left['item']->authority_tier );
				if ( 0 !== $specificity_delta ) {
					return $specificity_delta;
				}

				$authority_delta = WP_Agent_Context_Authority_Tier::authority_rank( $right['item']->authority_tier ) <=> WP_Agent_Context_Authority_Tier::authority_rank( $left['item']->authority_tier );
				return 0 !== $authority_delta ? $authority_delta : ( $left['index'] <=> $right['index'] );
			}
		);

		$winner = $group[0]['item'];

		return new WP_Agent_Context_Conflict_Resolution(
			$conflict_key,
			$winner,
			$this->rejected_items( $group, $winner ),
			'specificity_then_authority',
			'Preferences resolve by specificity, with authority tier used only as a tie-breaker.'
		);
	}

	/**
	 * @param array<int, array{item: WP_Agent_Context_Item, index: int}> $group  Group rows with item/index keys.
	 * @param WP_Agent_Context_Item                                      $winner Selected winner.
	 * @return WP_Agent_Context_Item[]
	 */
	private function rejected_items( array $group, WP_Agent_Context_Item $winner ): array {
		$rejected = array();
		foreach ( $group as $row ) {
			if ( $row['item'] !== $winner ) {
				$rejected[] = $row['item'];
			}
		}

		return $rejected;
	}
}
