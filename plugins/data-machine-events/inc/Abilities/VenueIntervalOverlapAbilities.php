<?php
/**
 * Canonical venue interval-overlap query.
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;

defined( 'ABSPATH' ) || exit;

class VenueIntervalOverlapAbilities {

	public const ABILITY_NAME = 'data-machine-events/query-venue-interval-overlaps';

	private const DEFAULT_PER_PAGE = 50;
	private const MAX_PER_PAGE     = 100;
	private const MAX_EXCLUSIONS   = 100;
	private const MAX_PAGE         = 10000;

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		add_action( 'wp_abilities_api_init', array( $this, 'registerAbility' ) );
		self::$registered = true;
	}

	public function registerAbility(): void {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Query Venue Interval Overlaps', 'data-machine-events' ),
				'description'         => __( 'Return published canonical events whose indexed closed range overlaps a half-open interval at one venue.', 'data-machine-events' ),
				'category'            => AbilityCategories::EVENTS,
				'input_schema'        => self::inputSchema(),
				'output_schema'       => self::outputSchema(),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => '__return_true',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);
	}

	/**
	 * Query canonical indexed ranges at one venue.
	 *
	 * Input instants must be RFC3339 values with an explicit offset. They are
	 * normalized to the venue's IANA timezone before comparison with the local
	 * wall-clock values in the event-date index. Open, zero-length, and inverted
	 * indexed ranges are not intervals and therefore never overlap.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute( array $input ): array|\WP_Error {
		global $wpdb;

		$venue_id = absint( $input['venue_id'] ?? 0 );
		$venue    = $venue_id > 0 ? get_term( $venue_id, 'venue' ) : null;
		if ( ! $venue || is_wp_error( $venue ) ) {
			return new \WP_Error( 'venue_overlap_invalid_venue', __( 'venue_id must identify a canonical venue term on the current site.', 'data-machine-events' ), array( 'status' => 400 ) );
		}

		$timezone = $this->venueTimezone( $venue_id );
		$start    = $this->parseInstant( $input['start'] ?? null, $timezone );
		$end      = $this->parseInstant( $input['end'] ?? null, $timezone );
		if ( is_wp_error( $start ) || is_wp_error( $end ) ) {
			return is_wp_error( $start ) ? $start : $end;
		}
		if ( $start >= $end ) {
			return new \WP_Error( 'venue_overlap_invalid_interval', __( 'end must be later than start.', 'data-machine-events' ), array( 'status' => 400 ) );
		}

		$statuses = $input['statuses'] ?? array( 'publish' );
		if ( ! is_array( $statuses ) || array( 'publish' ) !== array_values( array_unique( $statuses ) ) ) {
			return new \WP_Error( 'venue_overlap_invalid_statuses', __( 'The public overlap query supports published events only.', 'data-machine-events' ), array( 'status' => 400 ) );
		}

		$exclude = $input['exclude'] ?? array();
		if ( ! is_array( $exclude ) || count( $exclude ) > self::MAX_EXCLUSIONS ) {
			return new \WP_Error( 'venue_overlap_invalid_exclusions', __( 'exclude must contain no more than 100 event IDs.', 'data-machine-events' ), array( 'status' => 400 ) );
		}
		$exclude = array_values( array_unique( array_filter( array_map( 'absint', $exclude ) ) ) );

		$page = absint( $input['page'] ?? 1 );
		if ( $page < 1 || $page > self::MAX_PAGE ) {
			return new \WP_Error( 'venue_overlap_invalid_page', __( 'page must be between 1 and 10000.', 'data-machine-events' ), array( 'status' => 400 ) );
		}

		$per_page = min( self::MAX_PER_PAGE, max( 1, absint( $input['per_page'] ?? self::DEFAULT_PER_PAGE ) ) );
		$offset   = ( $page - 1 ) * $per_page;
		$table    = EventDatesTable::table_name();

		$sql  = "SELECT ed.post_id, ed.start_datetime, ed.end_datetime, ed.post_status
			FROM {$table} ed
			INNER JOIN {$wpdb->posts} p ON p.ID = ed.post_id
			INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = ed.post_id
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			WHERE p.post_type = %s
			AND p.post_status = ed.post_status
			AND tt.taxonomy = %s
			AND tt.term_id = %d
			AND ed.post_status = %s
			AND ed.end_datetime IS NOT NULL
			AND ed.end_datetime > ed.start_datetime
			AND ed.start_datetime < %s
			AND ed.end_datetime > %s";
		$args = array(
			Event_Post_Type::POST_TYPE,
			'venue',
			$venue_id,
			'publish',
			$end->format( 'Y-m-d H:i:s' ),
			$start->format( 'Y-m-d H:i:s' ),
		);

		if ( $exclude ) {
			$sql .= ' AND ed.post_id NOT IN (' . implode( ', ', array_fill( 0, count( $exclude ), '%d' ) ) . ')';
			$args = array_merge( $args, $exclude );
		}
		$sql   .= ' ORDER BY ed.start_datetime ASC, ed.post_id ASC LIMIT %d OFFSET %d';
		$args[] = $per_page + 1;
		$args[] = $offset;

		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		// Identifiers come from the active site connection; every request value is prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.PreparedSQL
		if ( ! is_array( $rows ) ) {
			return new \WP_Error( 'venue_overlap_query_failed', __( 'The canonical event index could not be queried.', 'data-machine-events' ), array( 'status' => 500 ) );
		}

		$has_more = count( $rows ) > $per_page;
		$rows     = array_slice( $rows, 0, $per_page );

		return array(
			'venue_id' => $venue_id,
			'timezone' => $timezone->getName(),
			'interval' => array(
				'start' => $start->format( DATE_RFC3339 ),
				'end'   => $end->format( DATE_RFC3339 ),
			),
			'events'   => array_map(
				function ( array $row ) use ( $timezone ): array {
					return array(
						'event_id' => (int) $row['post_id'],
						'start'    => $this->formatIndexedDatetime( $row['start_datetime'], $timezone ),
						'end'      => $this->formatIndexedDatetime( $row['end_datetime'], $timezone ),
						'status'   => (string) $row['post_status'],
					);
				},
				$rows
			),
			'page'     => $page,
			'per_page' => $per_page,
			'has_more' => $has_more,
		);
	}

	private function venueTimezone( int $venue_id ): DateTimeZone {
		$data = Venue_Taxonomy::get_venue_data( $venue_id );
		try {
			return new DateTimeZone( (string) ( $data['timezone'] ?? '' ) );
		} catch ( Exception $exception ) {
			return wp_timezone();
		}
	}

	private function parseInstant( mixed $value, DateTimeZone $timezone ): DateTimeImmutable|\WP_Error {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/', $value ) ) {
			return new \WP_Error( 'venue_overlap_invalid_datetime', __( 'start and end must be RFC3339 instants with seconds and an explicit offset.', 'data-machine-events' ), array( 'status' => 400 ) );
		}

		try {
			return ( new DateTimeImmutable( $value ) )->setTimezone( $timezone );
		} catch ( Exception $exception ) {
			return new \WP_Error( 'venue_overlap_invalid_datetime', __( 'start and end must be valid RFC3339 instants.', 'data-machine-events' ), array( 'status' => 400 ) );
		}
	}

	private function formatIndexedDatetime( string $value, DateTimeZone $timezone ): string {
		return ( new DateTimeImmutable( $value, $timezone ) )->format( DATE_RFC3339 );
	}

	private static function inputSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'venue_id', 'start', 'end' ),
			'properties'           => array(
				'venue_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'start'    => array(
					'type'        => 'string',
					'format'      => 'date-time',
					'description' => 'Inclusive RFC3339 interval start with an explicit offset.',
				),
				'end'      => array(
					'type'        => 'string',
					'format'      => 'date-time',
					'description' => 'Exclusive RFC3339 interval end with an explicit offset.',
				),
				'statuses' => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'publish' ),
					),
					'minItems'    => 1,
					'maxItems'    => 1,
					'uniqueItems' => true,
					'description' => 'Bounded public post statuses. Only publish is exposed.',
				),
				'exclude'  => array(
					'type'        => 'array',
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'maxItems'    => self::MAX_EXCLUSIONS,
					'uniqueItems' => true,
				),
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => self::MAX_PER_PAGE,
				),
			),
		);
	}

	private static function outputSchema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'venue_id', 'timezone', 'interval', 'events', 'page', 'per_page', 'has_more' ),
			'properties' => array(
				'venue_id' => array( 'type' => 'integer' ),
				'timezone' => array( 'type' => 'string' ),
				'interval' => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'start', 'end' ),
					'properties'           => array(
						'start' => self::dateTimeSchema(),
						'end'   => self::dateTimeSchema(),
					),
				),
				'events'   => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'event_id', 'start', 'end', 'status' ),
						'properties'           => array(
							'event_id' => array( 'type' => 'integer' ),
							'start'    => self::dateTimeSchema(),
							'end'      => self::dateTimeSchema(),
							'status'   => array(
								'type' => 'string',
								'enum' => array( 'publish' ),
							),
						),
					),
				),
				'page'     => array( 'type' => 'integer' ),
				'per_page' => array( 'type' => 'integer' ),
				'has_more' => array( 'type' => 'boolean' ),
			),
		);
	}

	private static function dateTimeSchema(): array {
		return array(
			'type'   => 'string',
			'format' => 'date-time',
		);
	}
}
