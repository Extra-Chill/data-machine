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

		$valid = self::validateInput( $input );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$venue_id = $input['venue_id'];
		$venue    = get_term( $venue_id, 'venue' );
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
		if ( $this->intervalTouchesRepeatedTime( $start, $end, $timezone ) ) {
			return new \WP_Error( 'venue_overlap_unrepresentable_interval', __( 'The requested interval touches a repeated local time that the canonical event index cannot distinguish.', 'data-machine-events' ), array( 'status' => 400 ) );
		}

		$exclude  = $input['exclude'] ?? array();
		$page     = $input['page'] ?? 1;
		$per_page = $input['per_page'] ?? self::DEFAULT_PER_PAGE;
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

		$events = array();
		foreach ( $rows as $row ) {
			$event_start = $this->parseIndexedDatetime( $row['start_datetime'], $timezone );
			$event_end   = $this->parseIndexedDatetime( $row['end_datetime'], $timezone );
			if ( is_wp_error( $event_start ) || is_wp_error( $event_end ) ) {
				return new \WP_Error(
					'venue_overlap_unrepresentable_index',
					__( 'A returned event contains an ambiguous or nonexistent local datetime in the canonical index.', 'data-machine-events' ),
					array(
						'status'   => 500,
						'event_id' => (int) $row['post_id'],
					)
				);
			}

			$events[] = array(
				'event_id' => (int) $row['post_id'],
				'start'    => $event_start->format( DATE_RFC3339 ),
				'end'      => $event_end->format( DATE_RFC3339 ),
				'status'   => (string) $row['post_status'],
			);
		}

		return array(
			'venue_id' => $venue_id,
			'timezone' => $timezone->getName(),
			'interval' => array(
				'start' => $start->format( DATE_RFC3339 ),
				'end'   => $end->format( DATE_RFC3339 ),
			),
			'events'   => $events,
			'page'     => $page,
			'per_page' => $per_page,
			'has_more' => $has_more,
		);
	}

	/**
	 * Validate the shared PHP, Ability, and REST input contract without coercion.
	 *
	 * @param array $input Query input.
	 * @return true|\WP_Error
	 */
	public static function validateInput( array $input ): true|\WP_Error {
		foreach ( array( 'venue_id', 'page', 'per_page' ) as $property ) {
			if ( isset( $input[ $property ] ) && ! is_int( $input[ $property ] ) ) {
				return self::invalidType( $property, sprintf( '%s must be an integer.', $property ) );
			}
		}

		foreach ( array( 'statuses', 'exclude' ) as $property ) {
			if ( isset( $input[ $property ] ) && ( ! is_array( $input[ $property ] ) || ! array_is_list( $input[ $property ] ) ) ) {
				return self::invalidType( $property, sprintf( '%s must be a JSON array.', $property ) );
			}
		}
		if ( isset( $input['exclude'] ) ) {
			foreach ( $input['exclude'] as $event_id ) {
				if ( ! is_int( $event_id ) ) {
					return self::invalidType( 'exclude', 'exclude must contain integer event IDs.' );
				}
			}
		}

		$valid = rest_validate_value_from_schema( $input, self::inputSchema(), 'venue_interval_overlap' );
		if ( is_wp_error( $valid ) ) {
			$data           = (array) $valid->get_error_data();
			$data['status'] = 400;
			$valid->add_data( $data );
			return $valid;
		}

		foreach ( array( 'start', 'end' ) as $property ) {
			if ( is_wp_error( self::parseRfc3339( $input[ $property ] ) ) ) {
				return new \WP_Error(
					'venue_overlap_invalid_datetime',
					__( 'start and end must be valid RFC3339 instants with seconds and an explicit offset.', 'data-machine-events' ),
					array(
						'status' => 400,
						'param'  => $property,
					)
				);
			}
		}

		return true;
	}

	private static function invalidType( string $property, string $message ): \WP_Error {
		return new \WP_Error(
			'rest_invalid_type',
			$message,
			array(
				'status' => 400,
				'param'  => $property,
			)
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
		$instant = self::parseRfc3339( $value );
		return is_wp_error( $instant ) ? $instant : $instant->setTimezone( $timezone );
	}

	private static function parseRfc3339( mixed $value ): DateTimeImmutable|\WP_Error {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(Z|[+-]\d{2}:\d{2})$/', $value, $parts ) ) {
			return new \WP_Error( 'venue_overlap_invalid_datetime' );
		}
		if ( ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
			return new \WP_Error( 'venue_overlap_invalid_datetime' );
		}
		if ( (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59 ) {
			return new \WP_Error( 'venue_overlap_invalid_datetime' );
		}
		if ( 'Z' !== $parts[7] && ( (int) substr( $parts[7], 1, 2 ) > 23 || (int) substr( $parts[7], 4, 2 ) > 59 ) ) {
			return new \WP_Error( 'venue_overlap_invalid_datetime' );
		}

		$instant = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:sP', $value );
		$errors  = DateTimeImmutable::getLastErrors();
		if ( false === $instant || ( false !== $errors && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			return new \WP_Error( 'venue_overlap_invalid_datetime' );
		}
		$normalized = 'Z' === $parts[7] ? substr( $value, 0, -1 ) . '+00:00' : $value;
		if ( $instant->format( 'Y-m-d\TH:i:sP' ) !== $normalized ) {
			return new \WP_Error( 'venue_overlap_invalid_datetime' );
		}

		return $instant;
	}

	private function intervalTouchesRepeatedTime( DateTimeImmutable $start, DateTimeImmutable $end, DateTimeZone $timezone ): bool {
		$start_wall = $this->wallTimestamp( $start->format( 'Y-m-d H:i:s' ) );
		$end_wall   = $this->wallTimestamp( $end->format( 'Y-m-d H:i:s' ) );
		if ( null === $start_wall || null === $end_wall || $start_wall >= $end_wall ) {
			return true;
		}
		if ( false === $timezone->getLocation() ) {
			return false;
		}

		$transitions     = $timezone->getTransitions( $start->getTimestamp() - DAY_IN_SECONDS, $end->getTimestamp() + DAY_IN_SECONDS );
		$previous_offset = $transitions[0]['offset'];
		foreach ( array_slice( $transitions, 1 ) as $transition ) {
			if ( $transition['offset'] < $previous_offset ) {
				$repeated_start = $transition['ts'] + $transition['offset'];
				$repeated_end   = $transition['ts'] + $previous_offset;
				if ( $start_wall < $repeated_end && $end_wall > $repeated_start ) {
					return true;
				}
			}
			$previous_offset = $transition['offset'];
		}

		return false;
	}

	private function parseIndexedDatetime( string $value, DateTimeZone $timezone ): DateTimeImmutable|\WP_Error {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			return new \WP_Error( 'venue_overlap_unrepresentable_index' );
		}
		$datetime = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, $timezone );
		$errors   = DateTimeImmutable::getLastErrors();
		if ( false === $datetime || ( false !== $errors && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) || $datetime->format( 'Y-m-d H:i:s' ) !== $value ) {
			return new \WP_Error( 'venue_overlap_unrepresentable_index' );
		}

		$wall = $this->wallTimestamp( $value );
		if ( null === $wall ) {
			return new \WP_Error( 'venue_overlap_unrepresentable_index' );
		}
		if ( false === $timezone->getLocation() ) {
			return $datetime;
		}

		$transitions     = $timezone->getTransitions( $datetime->getTimestamp() - DAY_IN_SECONDS, $datetime->getTimestamp() + DAY_IN_SECONDS );
		$previous_offset = $transitions[0]['offset'];
		foreach ( array_slice( $transitions, 1 ) as $transition ) {
			$first  = $transition['ts'] + min( $previous_offset, $transition['offset'] );
			$second = $transition['ts'] + max( $previous_offset, $transition['offset'] );
			if ( $wall >= $first && $wall < $second ) {
				return new \WP_Error( 'venue_overlap_unrepresentable_index' );
			}
			$previous_offset = $transition['offset'];
		}

		return $datetime;
	}

	private function wallTimestamp( string $value ): ?int {
		$wall   = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		$errors = DateTimeImmutable::getLastErrors();
		if ( false === $wall || ( false !== $errors && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) || $wall->format( 'Y-m-d H:i:s' ) !== $value ) {
			return null;
		}
		return $wall->getTimestamp();
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
					'maximum' => self::MAX_PAGE,
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
