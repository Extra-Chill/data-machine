<?php
// phpcs:disable Universal.Operators.DisallowShortTernary.Found -- Existing callback contracts, trusted identifiers, and renderer boundaries are reviewed and intentional.
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
use DataMachineEvents\Core\DuplicateDetection\EventMergeHelper;
use DataMachineEvents\Utilities\EventIdentifierGenerator;
use const DataMachineEvents\Core\EVENT_TICKET_URL_META_KEY;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CleanDuplicatesCommand {

	use EventQueryTrait;

	private const MAX_REVIEWED_CANDIDATES = 100;

	/**
	 * Clean duplicate events by trashing the newer copy.
	 *
	 * Scans for duplicate events using exact normalized title, start time, and venue
	 * identity, keeps the older post (more link equity), and trashes the newer one. If the
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
	 * [--location=<term-id>]
	 * : Limit candidates to a location term (including its descendants).
	 *
	 * [--reviewed=<candidate-ids>]
	 * : Comma-separated candidate IDs copied from a reviewed dry run. Required with --apply.
	 *
	 * [--dry-run]
	 * : Show what would be cleaned without actually trashing. This is the default.
	 *
	 * [--apply]
	 * : Trash the reviewed duplicates. Without this flag no changes are made.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp data-machine-events check clean-duplicates
	 *     wp data-machine-events check clean-duplicates --scope=upcoming --location=123
	 *     wp data-machine-events check clean-duplicates --scope=upcoming --location=123 --reviewed=abc123,def456 --apply --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$scope        = $assoc_args['scope'] ?? 'all';
		$days_ahead   = (int) ( $assoc_args['days-ahead'] ?? 90 );
		$location     = (int) ( $assoc_args['location'] ?? 0 );
		$dry_run      = ! isset( $assoc_args['apply'] ) || isset( $assoc_args['dry-run'] );
		$skip_confirm = isset( $assoc_args['yes'] );
		$reviewed     = $this->parse_reviewed_candidate_ids( (string) ( $assoc_args['reviewed'] ?? '' ) );

		if ( isset( $assoc_args['location'] ) && $location <= 0 ) {
			\WP_CLI::error( '--location must be a positive location term ID.' );
			return;
		}

		if ( ! $dry_run && empty( $reviewed ) ) {
			\WP_CLI::error( 'Apply requires --reviewed with candidate IDs from the exact dry run being approved.' );
			return;
		}

		if ( ! $dry_run && count( $reviewed ) > self::MAX_REVIEWED_CANDIDATES ) {
			\WP_CLI::error( sprintf( 'Apply is limited to %d reviewed candidates at a time.', self::MAX_REVIEWED_CANDIDATES ) );
			return;
		}

		$events = $this->query_events( $scope, $days_ahead, $location );

		if ( empty( $events ) ) {
			if ( ! $dry_run ) {
				\WP_CLI::error( 'Reviewed candidate IDs are stale or outside the requested scope; no changes made.' );
				return;
			}
			\WP_CLI::success( "No events found ({$scope} scope)." );
			return;
		}

		\WP_CLI::log( sprintf( 'Scanning %d events for duplicates (%s scope)...', count( $events ), $scope ) );

		$duplicate_groups = $this->find_duplicates( $events );

		if ( empty( $duplicate_groups ) ) {
			if ( ! $dry_run ) {
				\WP_CLI::error( 'Reviewed candidate IDs are stale or outside the requested scope; no changes made.' );
				return;
			}
			\WP_CLI::success( sprintf( 'No duplicates found across %d events.', count( $events ) ) );
			return;
		}

		\WP_CLI::log( sprintf( 'Found %d duplicate pair(s).', count( $duplicate_groups ) ) );
		\WP_CLI::log( '' );

		$to_trash = $this->build_actions( $duplicate_groups );
		if ( ! $dry_run ) {
			$selection = $this->select_reviewed_actions( $to_trash, $reviewed );
			if ( is_wp_error( $selection ) ) {
				\WP_CLI::error( $selection->get_error_message() );
				return;
			}
			$to_trash = $selection;
		}
		$ticket_merges = count( array_filter( $to_trash, static fn( $action ) => 'yes' === $action['merge_ticket'] ) );

		\WP_CLI\Utils\format_items( 'table', $to_trash, array( 'candidate_id', 'keep_id', 'keep_title', 'trash_id', 'trash_title', 'venue', 'start', 'merge_ticket' ) );

		\WP_CLI::log( '' );
		\WP_CLI::log( sprintf( 'Will trash %d posts, merge %d ticket URLs.', count( $to_trash ), $ticket_merges ) );

		if ( $dry_run ) {
			\WP_CLI::log( 'DRY RUN — no changes made.' );
			\WP_CLI::log( 'Apply only reviewed rows with --reviewed=<candidate_id,...> plus the same scope, days-ahead, and location options.' );
			return;
		}

		if ( ! $skip_confirm ) {
			\WP_CLI::confirm( sprintf( 'Trash %d explicitly reviewed duplicate events?', count( $to_trash ) ) );
		}

		$result = $this->apply_actions( $to_trash );

		\WP_CLI::success( sprintf( 'Trashed %d reviewed duplicate events, merged %d ticket URLs.', $result['trashed'], $result['merged'] ) );
	}

	/**
	 * Find duplicate event pairs using exact normalized title, start, and venue identity.
	 *
	 * @param array $events Array of WP_Post objects.
	 * @return array Duplicate groups.
	 */
	private function find_duplicates( array $events ): array {
		$by_identity = array();
		foreach ( $events as $event ) {
			$dates      = \DataMachineEvents\Core\EventDatesTable::get( $event->ID );
			$start_meta = $dates ? $dates->start_datetime : '';
			$start      = EventIdentifierGenerator::normalizeStartDateTime( $start_meta );
			$venue      = $this->get_venue_name( $event->ID );
			$identity   = $this->build_exact_identity( $event->post_title, $start, $venue );

			if ( '' === $identity ) {
				continue;
			}

			$by_identity[ $identity ][] = array(
				'post'  => $event,
				'start' => $start,
				'venue' => $venue,
			);
		}

		$duplicate_groups = array();

		foreach ( $by_identity as $identity => $identity_events ) {
			if ( count( $identity_events ) < 2 ) {
				continue;
			}

			usort(
				$identity_events,
				static fn( $a, $b ) => strcmp( $a['post']->post_date, $b['post']->post_date ) ?: ( $a['post']->ID <=> $b['post']->ID )
			);
			$winner = array_shift( $identity_events );

			foreach ( $identity_events as $duplicate ) {
				$duplicate_groups[] = array(
					'identity' => $identity,
					'start'    => $winner['start'],
					'event_a'  => array(
						'id'    => $winner['post']->ID,
						'title' => $winner['post']->post_title,
						'venue' => $winner['venue'],
					),
					'event_b'  => array(
						'id'    => $duplicate['post']->ID,
						'title' => $duplicate['post']->post_title,
						'venue' => $duplicate['venue'],
					),
				);
			}
		}

		return $duplicate_groups;
	}

	private function build_exact_identity( string $title, string $start, string $venue ): string {
		if ( '' === trim( $title ) || '' === trim( $venue ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $start ) ) {
			return '';
		}

		return EventIdentifierGenerator::normalizeBasic( $title ) . "\0" . $start . "\0" . EventIdentifierGenerator::normalizeBasic( $venue );
	}

	private function build_actions( array $duplicate_groups ): array {
		$actions = array();
		foreach ( $duplicate_groups as $group ) {
			$keep_id      = (int) $group['event_a']['id'];
			$trash_id     = (int) $group['event_b']['id'];
			$keep_ticket  = (string) get_post_meta( $keep_id, EVENT_TICKET_URL_META_KEY, true );
			$trash_ticket = (string) get_post_meta( $trash_id, EVENT_TICKET_URL_META_KEY, true );
			$merge_ticket = '' !== $trash_ticket && '' === $keep_ticket;
			$fingerprint  = implode( "\0", array( 'v1', $keep_id, $trash_id, $group['identity'], $keep_ticket, $trash_ticket, $merge_ticket ? '1' : '0' ) );

			$actions[] = array(
				'candidate_id' => hash( 'sha256', $fingerprint ),
				'keep_id'      => $keep_id,
				'keep_title'   => mb_substr( (string) $group['event_a']['title'], 0, 40 ),
				'trash_id'     => $trash_id,
				'trash_title'  => mb_substr( (string) $group['event_b']['title'], 0, 40 ),
				'venue'        => (string) $group['event_a']['venue'],
				'start'        => (string) $group['start'],
				'merge_ticket' => $merge_ticket ? 'yes' : 'no',
			);
		}

		return $actions;
	}

	private function parse_reviewed_candidate_ids( string $value ): array {
		$ids = array_filter( array_map( 'trim', explode( ',', $value ) ) );

		return array_values( array_unique( $ids ) );
	}

	private function select_reviewed_actions( array $actions, array $reviewed ): array|\WP_Error {
		$by_id   = array_column( $actions, null, 'candidate_id' );
		$missing = array_diff( $reviewed, array_keys( $by_id ) );

		if ( ! empty( $missing ) ) {
			return new \WP_Error(
				'stale_duplicate_review',
				'Reviewed candidate IDs are stale or outside the requested scope; no changes made: ' . implode( ', ', $missing )
			);
		}

		return array_values( array_intersect_key( $by_id, array_flip( $reviewed ) ) );
	}

	private function apply_actions( array $actions ): array {
		$trashed = 0;
		$merged  = 0;

		foreach ( $actions as $action ) {
			$merge_result = EventMergeHelper::merge(
				$action['keep_id'],
				$action['trash_id'],
				array( 'merge_ticket_url' => 'yes' === $action['merge_ticket'] )
			);

			if ( $merge_result['success'] ) {
				++$trashed;
				if ( $merge_result['ticket_url_merged'] ) {
					++$merged;
				}
			} else {
				\WP_CLI::warning( $merge_result['error'] ?: sprintf( 'Failed to merge post %d into %d.', $action['trash_id'], $action['keep_id'] ) );
			}
		}

		return compact( 'trashed', 'merged' );
	}
}
