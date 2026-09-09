<?php
/**
 * Repair missing venue term assignments on published events.
 *
 * For published events carrying no venue term, resolves the venue identity
 * from the post's own event-details block attributes via
 * Venue_Taxonomy::resolve_venue_identity() and — with apply enabled —
 * assigns the matched term through the same helper the upsert path uses.
 * Conflict candidates (#806) create a distinct venue through the same
 * find_or_create_venue() path upsert uses and assign it; ambiguous and
 * unmatched events are only reported (#803).
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
	 * @param bool   $apply      Assign matched terms and create distinct conflict venues. False = dry run.
	 * @param int    $limit      Max missing-venue events to process (0 = no cap).
	 * @return array{
	 *   scope: string, scanned: int, missing: int, matched: int,
	 *   created: int, conflict: int, assigned: int, ambiguous: int,
	 *   no_match: int, empty: int,
	 *   candidates: array<int, array<string, mixed>>
	 * }
	 */
	public function repair( string $scope = 'upcoming', int $days_ahead = 90, bool $apply = false, int $limit = 0 ): array {
		$result = array(
			'scope'      => $scope,
			'scanned'    => 0,
			'missing'    => 0,
			'matched'    => 0,
			'created'    => 0,
			'conflict'   => 0,
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
	 * @return array{tally: 'matched'|'created'|'conflict'|'ambiguous'|'no_match', assigned: bool, candidate: array<string,mixed>}
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
			'created'      => false,
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
						$assigned              = true;
						$candidate['assigned'] = true;
					}
				}
				break;

			case 'conflict':
				// A conflicting name candidate is a different venue (#806):
				// with apply, create it through the same path upsert uses and
				// assign the new term. Dry run stays read-only.
				$tally = 'conflict';

				if ( $apply ) {
					$creation = Venue_Taxonomy::find_or_create_venue(
						$venue_name,
						array(
							'address' => $address,
							'city'    => trim( (string) ( $attrs['city'] ?? '' ) ),
							'state'   => trim( (string) ( $attrs['state'] ?? '' ) ),
							'country' => trim( (string) ( $attrs['country'] ?? '' ) ),
						),
						array( 'post_id' => $post_id )
					);

					$created_term_id = (int) ( $creation['term_id'] ?? 0 );

					if ( $created_term_id > 0 ) {
						$tally                  = ! empty( $creation['was_created'] ) ? 'created' : 'matched';
						$term                   = get_term( $created_term_id, 'venue' );
						$candidate['term_id']   = $created_term_id;
						$candidate['term_name'] = $term instanceof \WP_Term ? $term->name : '';
						$candidate['created']   = ! empty( $creation['was_created'] );

						$assignment = Venue::assign_venue_to_event( $post_id, array( 'venue' => $created_term_id ) );

						if ( ! empty( $assignment['success'] ) && empty( $assignment['skipped'] ) ) {
							update_post_meta( $post_id, self::REPAIRED_AT_META, gmdate( 'Y-m-d H:i:s' ) );
							$assigned              = true;
							$candidate['assigned'] = true;
						}
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
	 * Group venue-less events whose venue identity resolves to a conflict.
	 *
	 * Operator-facing bucket C report (#806): unresolved candidates grouped
	 * by venue name with the incoming address set, the stored term's
	 * id/address/city, event count, and the flow ids that produced the
	 * events. Read-only; safe to run without apply.
	 *
	 * @param string $scope      Event scope: 'upcoming', 'past', or 'all'.
	 * @param int    $days_ahead Days to look ahead for the upcoming scope.
	 * @return array<int, array{venue_name: string, event_count: int, incoming_addresses: string[], stored: array<int, array{term_id: int, name: string, address: string, city: string}>, flow_ids: int[]}>
	 */
	public function conflicts_report( string $scope = 'upcoming', int $days_ahead = 90 ): array {
		$groups = array();

		foreach ( $this->query_events( $scope, $days_ahead ) as $event ) {
			if ( $this->has_venue_term( (int) $event->ID ) ) {
				continue;
			}

			$attrs      = $this->extract_block_attributes( (int) $event->ID );
			$venue_name = trim( (string) ( $attrs['venue'] ?? '' ) );

			if ( '' === $venue_name ) {
				continue;
			}

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

			if ( 'conflict' !== $identity['match_status'] ) {
				continue;
			}

			$group_key = mb_strtolower( $venue_name );

			if ( ! isset( $groups[ $group_key ] ) ) {
				$groups[ $group_key ] = array(
					'venue_name'         => $venue_name,
					'event_count'        => 0,
					'incoming_addresses' => array(),
					'stored'             => array(),
					'flow_ids'           => array(),
				);
			}

			$group = $groups[ $group_key ];

			++$group['event_count'];

			if ( '' !== $address && ! in_array( $address, $group['incoming_addresses'], true ) ) {
				$group['incoming_addresses'][] = $address;
			}

			$stored_ids = array_map( 'intval', array_column( $group['stored'], 'term_id' ) );

			foreach ( $identity['conflicting_term_ids'] as $term_id ) {
				$term_id = (int) $term_id;

				if ( $term_id <= 0 || in_array( $term_id, $stored_ids, true ) ) {
					continue;
				}

				$term = get_term( $term_id, 'venue' );
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}

				$group['stored'][] = array(
					'term_id' => $term_id,
					'name'    => $term->name,
					'address' => (string) get_term_meta( $term_id, '_venue_address', true ),
					'city'    => (string) get_term_meta( $term_id, '_venue_city', true ),
				);
				$stored_ids[]      = $term_id;
			}

			$flow_id = (int) get_post_meta( $event->ID, '_datamachine_post_flow_id', true );
			if ( $flow_id > 0 && ! in_array( $flow_id, $group['flow_ids'], true ) ) {
				$group['flow_ids'][] = $flow_id;
			}

			$groups[ $group_key ] = $group;
		}

		return array_values( $groups );
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
