<?php
/**
 * Repair missing venue term assignments on published events.
 *
 * For published events carrying no venue term, resolves the venue identity
 * from the post's own event-details block attributes via
 * Venue_Taxonomy::resolve_venue_identity() and — with apply enabled —
 * assigns the matched term through the same helper the upsert path uses.
 * Terms are never created here; ambiguous and unmatched events are only
 * reported (#803).
 *
 * Reconciliation logic lives in this class so a future maintenance hook can
 * call the same surface; the check venues CLI command is a thin adapter.
 *
 * @package DataMachineEvents\Core
 */

namespace DataMachineEvents\Core;

use DataMachineEvents\Abilities\EventDateQueryAbilities;
use DataMachineEvents\Steps\Upsert\Events\Venue;

defined( 'ABSPATH' ) || exit;

class MissingVenueRepairer {

	/**
	 * Post meta written when a venue term was assigned by this repair.
	 */
	public const REPAIRED_AT_META = '_datamachine_venue_repaired_at';

	/**
	 * Scan events for missing venue terms and optionally repair them.
	 *
	 * @param string $scope      Event scope: 'upcoming', 'past', or 'all'.
	 * @param int    $days_ahead Days to look ahead for the upcoming scope.
	 * @param bool   $apply      Assign matched venue terms. False = dry run.
	 * @param int    $limit      Max missing-venue events to process (0 = no cap).
	 * @return array{
	 *   scope: string, scanned: int, missing: int, matched: int,
	 *   assigned: int, ambiguous: int, no_match: int, empty: int,
	 *   candidates: array<int, array<string, mixed>>
	 * }
	 */
	public function repair( string $scope = 'upcoming', int $days_ahead = 90, bool $apply = false, int $limit = 0 ): array {
		$result = array(
			'scope'      => $scope,
			'scanned'    => 0,
			'missing'    => 0,
			'matched'    => 0,
			'assigned'   => 0,
			'ambiguous'  => 0,
			'no_match'   => 0,
			'empty'      => 0,
			'candidates' => array(),
		);

		foreach ( $this->query_events( $scope, $days_ahead ) as $event ) {
			++$result['scanned'];

			if ( $this->has_venue_term( (int) $event->ID ) ) {
				continue;
			}

			++$result['missing'];

			if ( $limit > 0 && $result['missing'] > $limit ) {
				break;
			}

			$attrs      = $this->extract_block_attributes( (int) $event->ID );
			$venue_name = trim( (string) ( $attrs['venue'] ?? '' ) );

			if ( '' === $venue_name ) {
				++$result['empty'];
				continue;
			}

			$outcome = $this->resolve_event( (int) $event->ID, $event->post_title, $attrs, $venue_name, $apply );

			++$result[ $outcome['tally'] ];

			if ( $outcome['assigned'] ) {
				++$result['assigned'];
			}

			$result['candidates'][] = $outcome['candidate'];
		}

		return $result;
	}

	/**
	 * Resolve one event's venue identity and (optionally) assign the match.
	 *
	 * @param int                 $post_id    Event post ID.
	 * @param string              $title      Event title.
	 * @param array<string,mixed> $attrs      Event-details block attributes.
	 * @param string              $venue_name Venue name from the block.
	 * @param bool                $apply      Whether to assign the matched term.
	 * @return array{tally: 'matched'|'ambiguous'|'no_match', assigned: bool, candidate: array<string,mixed>}
	 */
	private function resolve_event( int $post_id, string $title, array $attrs, string $venue_name, bool $apply ): array {
		$address  = trim( (string) ( $attrs['address'] ?? '' ) );
		$identity = Venue_Taxonomy::resolve_venue_identity(
			$venue_name,
			array(
				'address' => $address,
				'city'    => trim( (string) ( $attrs['city'] ?? '' ) ),
				'state'   => trim( (string) ( $attrs['state'] ?? '' ) ),
				'country' => trim( (string) ( $attrs['country'] ?? '' ) ),
			)
		);

		$candidate = array(
			'post_id'      => $post_id,
			'title'        => $title,
			'date'         => (string) ( $attrs['startDate'] ?? '' ),
			'venue_name'   => $venue_name,
			'address'      => $address,
			'match_status' => (string) $identity['match_status'],
			'term_id'      => null,
			'term_name'    => '',
			'assigned'     => false,
		);

		$tally    = 'no_match';
		$assigned = false;

		switch ( $identity['match_status'] ) {
			case 'matched':
				$tally = 'matched';

				$term                   = $identity['term'];
				$candidate['term_id']   = $identity['term_id'] ? (int) $identity['term_id'] : null;
				$candidate['term_name'] = $term instanceof \WP_Term ? $term->name : '';

				if ( $apply && ! empty( $identity['term_id'] ) ) {
					$assignment = Venue::assign_venue_to_event( $post_id, array( 'venue' => (int) $identity['term_id'] ) );

					if ( ! empty( $assignment['success'] ) && empty( $assignment['skipped'] ) ) {
						update_post_meta( $post_id, self::REPAIRED_AT_META, gmdate( 'Y-m-d H:i:s' ) );
						$assigned = true;
						$candidate['assigned'] = true;
					}
				}
				break;

			case 'ambiguous':
				$tally = 'ambiguous';
				break;
		}

		return array(
			'tally'     => $tally,
			'assigned'  => $assigned,
			'candidate' => $candidate,
		);
	}

	/**
	 * Query events by scope via the canonical event query ability.
	 *
	 * @param string $scope      'upcoming', 'past', or 'all'.
	 * @param int    $days_ahead Days to look ahead for the upcoming scope.
	 * @return \WP_Post[]
	 */
	private function query_events( string $scope, int $days_ahead ): array {
		$input = array(
			'scope' => $scope,
			'order' => 'past' === $scope ? 'DESC' : 'ASC',
		);

		if ( 'upcoming' === $scope && $days_ahead > 0 ) {
			$input['days_ahead'] = $days_ahead;
		}

		$ability = new EventDateQueryAbilities();
		$result  = $ability->executeQueryEvents( $input );

		return $result['posts'] ?? array();
	}

	/**
	 * Whether the post already carries a venue term.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function has_venue_term( int $post_id ): bool {
		$terms = wp_get_post_terms( $post_id, 'venue', array( 'fields' => 'ids' ) );

		return ! is_wp_error( $terms ) && ! empty( $terms );
	}

	/**
	 * Extract Event Details block attributes from a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed> Block attributes or empty array.
	 */
	private function extract_block_attributes( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		foreach ( parse_blocks( $post->post_content ) as $block ) {
			if ( 'data-machine-events/event-details' === $block['blockName'] ) {
				return $block['attrs'];
			}
		}

		return array();
	}
}
