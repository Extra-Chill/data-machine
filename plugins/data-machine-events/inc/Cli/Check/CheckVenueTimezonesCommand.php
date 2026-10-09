<?php
/**
 * `wp data-machine-events check venue-timezones`
 *
 * Audit stored venue timezones against venue location and repair the ones
 * that are provably wrong. Offline only: never calls GeoNames or Nominatim.
 *
 * Repairs (with --apply) only `invalid` and `mismatch` venues, i.e. values
 * that are unusable or that contradict an exact single-zone country/state
 * rule. Estimated disagreements and coordinate conflicts are reported for
 * review. See VenueTimezoneAudit for the classification and #898.
 *
 * @package DataMachineEvents\Cli\Check
 */

namespace DataMachineEvents\Cli\Check;

use DataMachineEvents\Core\VenueProfileMutations;
use DataMachineEvents\Core\VenueTimezoneAudit;

defined( 'ABSPATH' ) || exit;

class CheckVenueTimezonesCommand {

	/**
	 * Audit and repair venue timezones.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Repair invalid and mismatched venues. Without this flag the command
	 *   only reports.
	 *
	 * [--status=<status>]
	 * : Only list venues with this status. Default lists every non-ok venue.
	 * ---
	 * options:
	 *   - invalid
	 *   - mismatch
	 *   - review
	 *   - coordinates_conflict
	 *   - missing
	 *   - unresolvable
	 * ---
	 *
	 * [--venue-id=<id>]
	 * : Audit a single venue.
	 *
	 * [--limit=<count>]
	 * : Maximum venues to repair per run.
	 * ---
	 * default: 500
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format for the venue list.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp data-machine-events check venue-timezones
	 *     wp data-machine-events check venue-timezones --status=review --format=csv
	 *     wp data-machine-events check venue-timezones --apply
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$apply  = isset( $assoc_args['apply'] );
		$filter = (string) ( $assoc_args['status'] ?? '' );
		$limit  = max( 1, (int) ( $assoc_args['limit'] ?? 500 ) );
		$format = (string) ( $assoc_args['format'] ?? 'table' );

		$term_ids = isset( $assoc_args['venue-id'] )
			? array( (int) $assoc_args['venue-id'] )
			: get_terms(
				array(
					'taxonomy'   => 'venue',
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);

		if ( is_wp_error( $term_ids ) ) {
			\WP_CLI::error( $term_ids->get_error_message() );
		}

		$counts   = array();
		$rows     = array();
		$repaired = 0;
		$failed   = 0;

		foreach ( $term_ids as $term_id ) {
			$term_id = (int) $term_id;
			$stored  = (string) get_term_meta( $term_id, '_venue_timezone', true );
			$audit   = VenueTimezoneAudit::classify(
				$stored,
				(string) get_term_meta( $term_id, '_venue_coordinates', true ),
				(string) get_term_meta( $term_id, '_venue_country', true ),
				(string) get_term_meta( $term_id, '_venue_state', true )
			);

			$status            = $audit['status'];
			$counts[ $status ] = ( $counts[ $status ] ?? 0 ) + 1;

			$action = '';
			if ( $apply && $audit['repairable'] && $repaired + $failed < $limit ) {
				$result = VenueProfileMutations::updateSystem( $term_id, array( 'timezone' => $audit['expected'] ) );
				if ( is_wp_error( $result ) || empty( $result['success'] ) ) {
					$action = 'failed';
					++$failed;
				} else {
					$action = 'repaired';
					++$repaired;
				}
			} elseif ( $audit['repairable'] ) {
				$action = 'would_repair';
			}

			if ( VenueTimezoneAudit::OK === $status || ( '' !== $filter && $filter !== $status ) ) {
				continue;
			}

			$term   = get_term( $term_id, 'venue' );
			$rows[] = array(
				'term_id'  => $term_id,
				'name'     => $term instanceof \WP_Term ? html_entity_decode( $term->name ) : '',
				'city'     => (string) get_term_meta( $term_id, '_venue_city', true ),
				'state'    => (string) get_term_meta( $term_id, '_venue_state', true ),
				'stored'   => $stored,
				'expected' => $audit['expected'],
				'source'   => $audit['source'],
				'status'   => $status,
				'action'   => $action,
			);
		}

		if ( ! empty( $rows ) ) {
			\WP_CLI\Utils\format_items( $format, $rows, array( 'term_id', 'name', 'city', 'state', 'stored', 'expected', 'source', 'status', 'action' ) );
		}

		ksort( $counts );
		$summary = array();
		foreach ( $counts as $status => $count ) {
			$summary[] = "{$status}: {$count}";
		}
		\WP_CLI::log( sprintf( 'Audited %d venue(s). %s', count( $term_ids ), implode( ', ', $summary ) ) );

		if ( ! $apply ) {
			\WP_CLI::log( 'Report only. Re-run with --apply to repair invalid and mismatch venues.' );
			return;
		}

		if ( $failed > 0 ) {
			\WP_CLI::warning( sprintf( 'Repaired %d venue(s); %d failed.', $repaired, $failed ) );
			return;
		}

		\WP_CLI::success( sprintf( 'Repaired %d venue(s).', $repaired ) );
	}
}
