<?php
/**
 * Check for venue-flow events the source no longer lists.
 *
 * Dry run by default: fetches the flow's live source listing, compares it
 * against the published upcoming events attached to that flow+venue, and
 * prints a candidate table with stable candidate IDs. Applying requires the
 * reviewed candidate IDs from the exact dry run being approved, and trashes
 * (never deletes) the reviewed posts with audit meta.
 *
 * Reconciliation logic lives in StaleListingReconciler so a future
 * flow-lifecycle hook can call the same class; this command is a thin
 * CLI adapter.
 *
 * @package DataMachineEvents\Cli\Check
 * @since   0.57.1
 */

namespace DataMachineEvents\Cli\Check;

use DataMachineEvents\Core\StaleListingReconciler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CheckStaleListingsCommand {

	use EventQueryTrait;

	private const MAX_REVIEWED_CANDIDATES = 100;

	/**
	 * Find (and with --apply, trash) events the source no longer lists.
	 *
	 * Only venue-pinned universal_web_scraper flows are supported.
	 *
	 * ## OPTIONS
	 *
	 * [--flow=<flow-id>]
	 * : Flow ID to reconcile. Required.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--days-ahead=<days>]
	 * : Days to look ahead for upcoming events.
	 * ---
	 * default: 120
	 * ---
	 *
	 * [--reviewed=<candidate-ids>]
	 * : Comma-separated candidate IDs copied from a reviewed dry run. Required with --apply.
	 *
	 * [--dry-run]
	 * : Show what would be retired without changing anything. This is the default.
	 *
	 * [--apply]
	 * : Trash the reviewed stale events. Without this flag no changes are made.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp data-machine-events check stale-listings --flow=3
	 *     wp data-machine-events check stale-listings --flow=3 --days-ahead=60
	 *     wp data-machine-events check stale-listings --flow=3 --reviewed=abc123,def456 --apply --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$flow_id      = (int) ( $assoc_args['flow'] ?? 0 );
		$days_ahead   = (int) ( $assoc_args['days-ahead'] ?? StaleListingReconciler::DEFAULT_DAYS_AHEAD );
		$dry_run      = ! isset( $assoc_args['apply'] ) || isset( $assoc_args['dry-run'] );
		$skip_confirm = isset( $assoc_args['yes'] );
		$reviewed     = $this->parse_reviewed_candidate_ids( (string) ( $assoc_args['reviewed'] ?? '' ) );
		if ( $flow_id <= 0 ) {
			\WP_CLI::error( '--flow with a positive flow ID is required.' );
			return;
		}

		if ( $days_ahead <= 0 ) {
			\WP_CLI::error( '--days-ahead must be a positive number of days.' );
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

		$reconciler = new StaleListingReconciler();

		$scraper_config = $reconciler->loadVenuePinnedScraperConfig( $flow_id );

		if ( is_wp_error( $scraper_config ) ) {
			\WP_CLI::error( $scraper_config->get_error_message() );
			return;
		}

		\WP_CLI::log(
			sprintf(
				'Fetching source listing for flow %d (%s, venue term %d)...',
				$flow_id,
				$scraper_config['source_url'],
				$scraper_config['venue_term_id']
			)
		);

		$source_events = $reconciler->fetchSourceEvents( $scraper_config );

		if ( is_wp_error( $source_events ) ) {
			\WP_CLI::error( $source_events->get_error_message() );
			return;
		}

		\WP_CLI::log( sprintf( 'Source lists %d structured event(s).', count( $source_events ) ) );

		$candidates = $reconciler->findStaleCandidates( $flow_id, (int) $scraper_config['venue_term_id'], $source_events, $days_ahead );

		if ( is_wp_error( $candidates ) ) {
			\WP_CLI::error( $candidates->get_error_message() );
			return;
		}

		if ( empty( $candidates ) ) {
			if ( ! $dry_run ) {
				\WP_CLI::error( 'Reviewed candidate IDs are stale; no changes made.' );
				return;
			}
			\WP_CLI::success( sprintf( 'No stale listings found for flow %d.', $flow_id ) );
			return;
		}

		\WP_CLI::log( sprintf( 'Found %d stale listing candidate(s).', count( $candidates ) ) );
		\WP_CLI::log( '' );

		if ( ! $dry_run ) {
			$selection = $this->select_reviewed_actions( $candidates, $reviewed, 'stale_listing_review' );
			if ( is_wp_error( $selection ) ) {
				\WP_CLI::error( $selection->get_error_message() );
				return;
			}
			$candidates = $selection;
		}

		\WP_CLI\Utils\format_items( 'table', $candidates, array( 'candidate_id', 'post_id', 'title', 'start', 'venue', 'source_listed_that_date' ) );

		\WP_CLI::log( '' );

		if ( $dry_run ) {
			\WP_CLI::log( 'DRY RUN — no changes made.' );
			\WP_CLI::log( 'Apply only reviewed rows with --reviewed=<candidate_id,...> --apply --yes, plus the same --flow and --days-ahead.' );
			return;
		}

		if ( ! $skip_confirm ) {
			\WP_CLI::confirm( sprintf( 'Trash %d explicitly reviewed stale events?', count( $candidates ) ) );
		}

		$result = $reconciler->retireCandidates( $candidates );

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
			return;
		}

		\WP_CLI::success(
			sprintf(
				'Trashed %d reviewed stale event(s); %d failed. Reason meta %s=%s recorded for audit.',
				$result['trashed'],
				$result['failed'],
				StaleListingReconciler::RETIRED_REASON_META,
				StaleListingReconciler::RETIRE_REASON_SOURCE_UNLISTED
			)
		);
	}
}
