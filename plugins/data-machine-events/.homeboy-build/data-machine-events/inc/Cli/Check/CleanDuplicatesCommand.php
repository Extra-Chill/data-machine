<?php
/**
 * Clean duplicate events by trashing the newer copy.
 *
 * Uses the same detection logic as CheckDuplicatesCommand, then for each
 * duplicate pair keeps the older post and trashes the newer one. Optionally
 * merges ticket URL from the trashed post into the kept post.
 *
 * @package DataMachineEvents\Cli\Check
 * @since   0.16.2
 */

namespace DataMachineEvents\Cli\Check;

use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Utilities\EventIdentifierGenerator;
use const DataMachineEvents\Core\EVENT_TICKET_URL_META_KEY;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CleanDuplicatesCommand {

	use EventQueryTrait;

	/**
	 * Clean duplicate events by trashing the newer copy.
	 *
	 * Scans for duplicate events using fuzzy title + venue matching, keeps
	 * the older post (more link equity), and trashes the newer one. If the
	 * trashed post has a ticket URL that the kept post lacks, it is copied over.
	 *
	 * ## OPTIONS
	 *
	 * [--scope=<scope>]
	 * : Which events to scan.
	 * ---
	 * default: all
	 * options:
	 *   - upcoming
	 *   - past
	 *   - all
	 * ---
	 *
	 * [--days-ahead=<days>]
	 * : Days to look ahead for upcoming scope.
	 * ---
	 * default: 90
	 * ---
	 *
	 * [--dry-run]
	 * : Show what would be cleaned without actually trashing.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp data-machine-events check clean-duplicates --dry-run
	 *     wp data-machine-events check clean-duplicates --scope=upcoming --yes
	 *     wp data-machine-events check clean-duplicates --scope=all --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$scope      = $assoc_args['scope'] ?? 'all';
		$days_ahead = (int) ( $assoc_args['days-ahead'] ?? 90 );
		$dry_run    = isset( $assoc_args['dry-run'] );
		$skip_confirm = isset( $assoc_args['yes'] );

		$events = $this->query_events( $scope, $days_ahead );

		if ( empty( $events ) ) {
			\WP_CLI::success( "No events found ({$scope} scope)." );
			return;
		}

		\WP_CLI::log( sprintf( 'Scanning %d events for duplicates (%s scope)...', count( $events ), $scope ) );

		$duplicate_groups = $this->find_duplicates( $events );

		if ( empty( $duplicate_groups ) ) {
			\WP_CLI::success( sprintf( 'No duplicates found across %d events.', count( $events ) ) );
			return;
		}

		\WP_CLI::log( sprintf( 'Found %d duplicate pair(s).', count( $duplicate_groups ) ) );
		\WP_CLI::log( '' );

		// For each pair: keep older, trash newer.
		$to_trash       = array();
		$ticket_merges  = 0;

		foreach ( $duplicate_groups as $group ) {
			$a_id   = $group['event_a']['id'];
			$b_id   = $group['event_b']['id'];
			$a_date = get_post( $a_id )->post_date;
			$b_date = get_post( $b_id )->post_date;

			// Keep the older one.
			if ( strtotime( $a_date ) <= strtotime( $b_date ) ) {
				$keep_id  = $a_id;
				$trash_id = $b_id;
			} else {
				$keep_id  = $b_id;
				$trash_id = $a_id;
			}

			// Check if we should merge ticket URL.
			$keep_ticket  = get_post_meta( $keep_id, EVENT_TICKET_URL_META_KEY, true );
			$trash_ticket = get_post_meta( $trash_id, EVENT_TICKET_URL_META_KEY, true );
			$should_merge_ticket = ! empty( $trash_ticket ) && empty( $keep_ticket );

			if ( $should_merge_ticket ) {
				++$ticket_merges;
			}

			$keep_title  = get_the_title( $keep_id );
			$trash_title = get_the_title( $trash_id );

			$to_trash[] = array(
				'keep_id'            => $keep_id,
				'keep_title'         => mb_substr( $keep_title, 0, 40 ),
				'trash_id'           => $trash_id,
				'trash_title'        => mb_substr( $trash_title, 0, 40 ),
				'venue'              => $group['event_a']['venue'] ?: $group['event_b']['venue'],
				'date'               => $group['date'],
				'merge_ticket'       => $should_merge_ticket ? 'yes' : 'no',
			);
		}

		\WP_CLI\Utils\format_items( 'table', $to_trash, array( 'keep_id', 'keep_title', 'trash_id', 'trash_title', 'venue', 'date', 'merge_ticket' ) );

		\WP_CLI::log( '' );
		\WP_CLI::log( sprintf( 'Will trash %d posts, merge %d ticket URLs.', count( $to_trash ), $ticket_merges ) );

		if ( $dry_run ) {
			\WP_CLI::log( 'DRY RUN — no changes made.' );
			return;
		}

		if ( ! $skip_confirm ) {
			\WP_CLI::confirm( sprintf( 'Trash %d duplicate events?', count( $to_trash ) ) );
		}

		$trashed = 0;
		$merged  = 0;

		foreach ( $to_trash as $action ) {
			// Merge ticket URL if needed.
			if ( 'yes' === $action['merge_ticket'] ) {
				$trash_ticket = get_post_meta( $action['trash_id'], EVENT_TICKET_URL_META_KEY, true );
				update_post_meta( $action['keep_id'], EVENT_TICKET_URL_META_KEY, $trash_ticket );
				++$merged;
			}

			// Trash the duplicate.
			$result = wp_trash_post( $action['trash_id'] );
			if ( $result ) {
				++$trashed;
			} else {
				\WP_CLI::warning( sprintf( 'Failed to trash post %d.', $action['trash_id'] ) );
			}
		}

		\WP_CLI::success( sprintf( 'Trashed %d duplicate events, merged %d ticket URLs.', $trashed, $merged ) );
	}

	/**
	 * Find duplicate event pairs using fuzzy title + venue matching.
	 *
	 * @param array $events Array of WP_Post objects.
	 * @return array Duplicate groups.
	 */
	private function find_duplicates( array $events ): array {
		$by_date = array();
		foreach ( $events as $event ) {
			$dates      = \DataMachineEvents\Core\EventDatesTable::get( $event->ID );
			$start_meta = $dates ? $dates->start_datetime : '';
			$date       = $start_meta ? substr( $start_meta, 0, 10 ) : '';

			if ( empty( $date ) ) {
				continue;
			}

			$by_date[ $date ][] = $event;
		}

		$duplicate_groups = array();

		foreach ( $by_date as $date => $date_events ) {
			if ( count( $date_events ) < 2 ) {
				continue;
			}

			$venue_cache = array();
			foreach ( $date_events as $event ) {
				$venue_cache[ $event->ID ] = $this->get_venue_name( $event->ID );
			}

			$matched_ids = array();

			for ( $i = 0, $count = count( $date_events ); $i < $count; $i++ ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					$event_a = $date_events[ $i ];
					$event_b = $date_events[ $j ];

					if ( isset( $matched_ids[ $event_b->ID ] ) ) {
						continue;
					}

					if ( ! EventIdentifierGenerator::titlesMatch( $event_a->post_title, $event_b->post_title ) ) {
						continue;
					}

					$venue_a = $venue_cache[ $event_a->ID ];
					$venue_b = $venue_cache[ $event_b->ID ];

					if ( ! EventIdentifierGenerator::venuesMatch( $venue_a, $venue_b ) ) {
						continue;
					}

					$duplicate_groups[] = array(
						'date'    => $date,
						'event_a' => array(
							'id'    => $event_a->ID,
							'title' => $event_a->post_title,
							'venue' => $venue_a,
						),
						'event_b' => array(
							'id'    => $event_b->ID,
							'title' => $event_b->post_title,
							'venue' => $venue_b,
						),
					);

					$matched_ids[ $event_b->ID ] = true;
				}
			}
		}

		return $duplicate_groups;
	}
}
