<?php
/**
 * Calendar Query Builder
 *
 * Single source of truth for calendar event queries. Used by both render.php (initial load)
 * and Calendar REST controller (REST API filtering) to ensure consistent behavior.
 *
 * @package DataMachineEvents\Blocks\Calendar
 */

namespace DataMachineEvents\Blocks\Calendar;

use WP_Query;
use DateTime;
use DateTimeZone;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Core\Promoter_Taxonomy;
use DataMachineEvents\Admin\Settings_Page;
use const DataMachineEvents\Core\EVENT_DATETIME_META_KEY;
use const DataMachineEvents\Core\EVENT_END_DATETIME_META_KEY;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DAYS_PER_PAGE             = 5;
const MIN_EVENTS_FOR_PAGINATION = 20;

class Calendar_Query {

	/**
	 * Build WP_Query arguments for calendar events
	 *
	 * @param array $params Query parameters
	 * @return array WP_Query arguments
	 */
	public static function build_query_args( array $params ): array {
		$defaults = array(
			'show_past'          => false,
			'search_query'       => '',
			'date_start'         => '',
			'date_end'           => '',
			'tax_filters'        => array(),
			'tax_query_override' => null,
			'archive_taxonomy'   => '',
			'archive_term_id'    => 0,
			'source'             => 'unknown',
			'user_date_range'    => false,
		);

		$params = wp_parse_args( $params, $defaults );

		/**
		 * Filter the base query constraint for calendar events.
		 *
		 * Allows plugins to modify or replace the archive-based constraint
		 * before user filters are applied. This filter runs on both initial
		 * page load and REST API requests.
		 *
		 * @param array|null $tax_query_override The base tax_query constraint (null if none).
		 * @param array      $context {
		 *     Context information about the request.
		 *
		 *     @type string $archive_taxonomy Taxonomy slug from archive page (empty if not archive).
		 *     @type int    $archive_term_id  Term ID from archive page (0 if not archive).
		 *     @type string $source           'render' for initial load, 'rest' for API requests.
		 * }
		 * @return array|null Modified tax_query constraint or null to remove constraint.
		 */
		$params['tax_query_override'] = apply_filters(
			'datamachine_events_calendar_base_query',
			$params['tax_query_override'],
			array(
				'archive_taxonomy' => $params['archive_taxonomy'],
				'archive_term_id'  => $params['archive_term_id'],
				'source'           => $params['source'],
			)
		);

		$query_args = array(
			'post_type'      => Event_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_key'       => EVENT_DATETIME_META_KEY,
			'orderby'        => 'meta_value',
			'order'          => $params['show_past'] ? 'DESC' : 'ASC',
		);

		$meta_query       = array( 'relation' => 'AND' );
		$current_datetime = current_time( 'mysql' );
		$has_date_range   = ! empty( $params['date_start'] ) || ! empty( $params['date_end'] );

		if ( $params['show_past'] && ! $params['user_date_range'] ) {
			$meta_query[] = array(
				'key'     => EVENT_END_DATETIME_META_KEY,
				'value'   => $current_datetime,
				'compare' => '<',
				'type'    => 'DATETIME',
			);
		} elseif ( ! $params['show_past'] && ! $params['user_date_range'] ) {
			$meta_query[] = array(
				'key'     => EVENT_END_DATETIME_META_KEY,
				'value'   => $current_datetime,
				'compare' => '>=',
				'type'    => 'DATETIME',
			);
		}

		if ( ! empty( $params['date_start'] ) ) {
			$meta_query[] = array(
				'key'     => EVENT_DATETIME_META_KEY,
				'value'   => $params['date_start'] . ' 00:00:00',
				'compare' => '>=',
				'type'    => 'DATETIME',
			);
		}

		if ( ! empty( $params['date_end'] ) ) {
			$meta_query[] = array(
				'key'     => EVENT_DATETIME_META_KEY,
				'value'   => $params['date_end'] . ' 23:59:59',
				'compare' => '<=',
				'type'    => 'DATETIME',
			);
		}

		$query_args['meta_query'] = $meta_query;

		if ( $params['tax_query_override'] ) {
			$query_args['tax_query'] = $params['tax_query_override'];
		}

		if ( ! empty( $params['tax_filters'] ) && is_array( $params['tax_filters'] ) ) {
			$tax_query             = isset( $query_args['tax_query'] ) ? $query_args['tax_query'] : array();
			$tax_query['relation'] = 'AND';

			foreach ( $params['tax_filters'] as $taxonomy => $term_ids ) {
				$term_ids    = is_array( $term_ids ) ? $term_ids : array( $term_ids );
				$tax_query[] = array(
					'taxonomy' => sanitize_key( $taxonomy ),
					'field'    => 'term_id',
					'terms'    => array_map( 'absint', $term_ids ),
					'operator' => 'IN',
				);
			}

			$query_args['tax_query'] = $tax_query;
		}

		if ( ! empty( $params['search_query'] ) ) {
			$query_args['s'] = $params['search_query'];
		}

		return apply_filters( 'datamachine_events_calendar_query_args', $query_args, $params );
	}

	/**
	 * Get past and future event counts
	 *
	 * @return array ['past' => int, 'future' => int]
	 */
	public static function get_event_counts(): array {
		$current_datetime = current_time( 'mysql' );

		$future_query = new WP_Query(
			array(
				'post_type'      => Event_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => array(
					array(
						'key'     => EVENT_END_DATETIME_META_KEY,
						'value'   => $current_datetime,
						'compare' => '>=',
						'type'    => 'DATETIME',
					),
				),
			)
		);

		$past_query = new WP_Query(
			array(
				'post_type'      => Event_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => array(
					array(
						'key'     => EVENT_END_DATETIME_META_KEY,
						'value'   => $current_datetime,
						'compare' => '<',
						'type'    => 'DATETIME',
					),
				),
			)
		);

		return array(
			'past'   => $past_query->found_posts,
			'future' => $future_query->found_posts,
		);
	}

	/**
	 * Parse event data from post, hydrating from authoritative sources.
	 *
	 * Combines block attributes with post meta (datetime) and taxonomy terms
	 * (venue, promoter) to return complete, authoritative event data.
	 *
	 * @param \WP_Post $post Post object
	 * @return array|null Event data array or null if not found
	 */
	public static function parse_event_data( \WP_Post $post ): ?array {
		$blocks     = parse_blocks( $post->post_content );
		$event_data = array();

		foreach ( $blocks as $block ) {
			if ( 'datamachine-events/event-details' === $block['blockName'] ) {
				$event_data = $block['attrs'] ?? array();
				break;
			}
		}

		self::hydrate_datetime_from_meta( $post->ID, $event_data );
		self::hydrate_venue_from_taxonomy( $post->ID, $event_data );
		self::hydrate_promoter_from_taxonomy( $post->ID, $event_data );

		return ! empty( $event_data['startDate'] ) ? $event_data : null;
	}

	/**
	 * Hydrate datetime fields from post meta.
	 *
	 * Post meta is the source of truth for datetime.
	 * When meta values exist, they override any block attribute values.
	 *
	 * @param int $post_id Post ID
	 * @param array $event_data Event data array (modified by reference)
	 */
	private static function hydrate_datetime_from_meta( int $post_id, array &$event_data ): void {
		$start_datetime = get_post_meta( $post_id, EVENT_DATETIME_META_KEY, true );
		if ( $start_datetime ) {
			$date_obj = date_create( $start_datetime );
			if ( $date_obj ) {
				$event_data['startDate'] = $date_obj->format( 'Y-m-d' );
				$event_data['startTime'] = $date_obj->format( 'H:i:s' );
			}
		}

		$end_datetime = get_post_meta( $post_id, EVENT_END_DATETIME_META_KEY, true );
		if ( $end_datetime ) {
			$date_obj = date_create( $end_datetime );
			if ( $date_obj ) {
				$event_data['endDate'] = $date_obj->format( 'Y-m-d' );
				$event_data['endTime'] = $date_obj->format( 'H:i:s' );
			}
		}
	}

	/**
	 * Hydrate venue fields from taxonomy.
	 *
	 * Venue taxonomy is the source of truth. If event has an assigned venue
	 * term, its name, formatted address, and timezone override any block attribute values.
	 *
	 * @param int $post_id Post ID
	 * @param array $event_data Event data array (modified by reference)
	 */
	private static function hydrate_venue_from_taxonomy( int $post_id, array &$event_data ): void {
		$venue_terms = get_the_terms( $post_id, 'venue' );
		if ( ! $venue_terms || is_wp_error( $venue_terms ) ) {
			return;
		}

		$venue_term = $venue_terms[0];
		$venue_data = Venue_Taxonomy::get_venue_data( $venue_term->term_id );

		$event_data['venue']   = $venue_data['name'];
		$event_data['address'] = Venue_Taxonomy::get_formatted_address( $venue_term->term_id, $venue_data );

		if ( ! empty( $venue_data['timezone'] ) ) {
			$event_data['venueTimezone'] = $venue_data['timezone'];
		}
	}

	/**
	 * Hydrate promoter/organizer fields from taxonomy.
	 *
	 * Promoter taxonomy is the source of truth. If event has an assigned
	 * promoter term, its data overrides any block attribute values.
	 *
	 * @param int $post_id Post ID
	 * @param array $event_data Event data array (modified by reference)
	 */
	private static function hydrate_promoter_from_taxonomy( int $post_id, array &$event_data ): void {
		$promoter_terms = get_the_terms( $post_id, 'promoter' );
		if ( ! $promoter_terms || is_wp_error( $promoter_terms ) ) {
			return;
		}

		$promoter_term = $promoter_terms[0];
		$promoter_data = Promoter_Taxonomy::get_promoter_data( $promoter_term->term_id );

		$event_data['organizer'] = $promoter_data['name'];
		if ( ! empty( $promoter_data['url'] ) ) {
			$event_data['organizerUrl'] = $promoter_data['url'];
		}
		if ( ! empty( $promoter_data['type'] ) ) {
			$event_data['organizerType'] = $promoter_data['type'];
		}
	}

	/**
	 * Build paged events array from WP_Query
	 *
	 * @param WP_Query $query Events query
	 * @return array Array of event items with post, datetime, and event_data
	 */
	public static function build_paged_events( WP_Query $query ): array {
		$paged_events = array();

		if ( ! $query->have_posts() ) {
			return $paged_events;
		}

		while ( $query->have_posts() ) {
			$query->the_post();
			$event_post = get_post();
			$event_data = self::parse_event_data( $event_post );

			if ( $event_data ) {
				$start_time     = $event_data['startTime'] ?? '00:00:00';
				$event_tz       = self::get_event_timezone( $event_data );
				$event_datetime = new DateTime(
					$event_data['startDate'] . ' ' . $start_time,
					$event_tz
				);

				$paged_events[] = array(
					'post'       => $event_post,
					'datetime'   => $event_datetime,
					'event_data' => $event_data,
				);
			}
		}

		wp_reset_postdata();

		return $paged_events;
	}

	/**
	 * Get DateTimeZone for an event.
	 *
	 * Uses venue timezone if available, falls back to WordPress site timezone.
	 *
	 * @param array $event_data Event data array
	 * @return DateTimeZone Timezone for the event
	 */
	private static function get_event_timezone( array $event_data ): DateTimeZone {
		$tz_string = $event_data['venueTimezone'] ?? '';

		if ( ! empty( $tz_string ) ) {
			try {
				return new DateTimeZone( $tz_string );
			} catch ( \Exception $e ) {
				// Invalid timezone, fall through to default
			}
		}

		return wp_timezone();
	}

	/**
	 * Check if an event spans multiple days
	 *
	 * Events ending before the next_day_cutoff time on the following day
	 * are treated as single-day events (typical late-night shows).
	 *
	 * @param array $event_data Event data array
	 * @return bool True if event spans multiple days
	 */
	private static function is_multi_day_event( array $event_data ): bool {
		$start_date = $event_data['startDate'] ?? '';
		$end_date   = $event_data['endDate'] ?? '';
		$end_time   = $event_data['endTime'] ?? '';

		if ( empty( $start_date ) || empty( $end_date ) ) {
			return false;
		}

		if ( $start_date === $end_date ) {
			return false;
		}

		$start = new DateTime( $start_date );
		$end   = new DateTime( $end_date );
		$diff  = $start->diff( $end )->days;

		if ( 1 === $diff && ! empty( $end_time ) ) {
			$cutoff         = Settings_Page::get_next_day_cutoff();
			$cutoff_parts   = explode( ':', $cutoff );
			$cutoff_seconds = ( (int) $cutoff_parts[0] * 3600 ) + ( (int) ( $cutoff_parts[1] ?? 0 ) * 60 );

			$end_time_parts = explode( ':', $end_time );
			$end_seconds    = ( (int) $end_time_parts[0] * 3600 ) + ( (int) ( $end_time_parts[1] ?? 0 ) * 60 );

			if ( $end_seconds < $cutoff_seconds ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Generate all dates an event spans
	 *
	 * @param string $start_date Start date (Y-m-d)
	 * @param string $end_date End date (Y-m-d)
	 * @param DateTimeZone $event_tz Event timezone
	 * @return array Array of date strings (Y-m-d)
	 */
	private static function get_event_date_range( string $start_date, string $end_date, DateTimeZone $event_tz ): array {
		$dates = array();

		$start = new DateTime( $start_date, $event_tz );
		$end   = new DateTime( $end_date, $event_tz );

		$max_days  = 90;
		$day_count = 0;

		while ( $start <= $end && $day_count < $max_days ) {
			$dates[] = $start->format( 'Y-m-d' );
			$start->modify( '+1 day' );
			++$day_count;
		}

		return $dates;
	}

	/**
	 * Group events by date, expanding multi-day events across their date range
	 *
	 * @param array $paged_events Array of event items
	 * @param bool $show_past Whether showing past events (affects sort order)
	 * @return array Date-grouped events
	 */
	public static function group_events_by_date( array $paged_events, bool $show_past = false ): array {
		$date_groups = array();

		foreach ( $paged_events as $event_item ) {
			$event_data = $event_item['event_data'];
			$start_date = $event_data['startDate'] ?? '';
			$end_date   = $event_data['endDate'] ?? $start_date;

			if ( empty( $start_date ) ) {
				continue;
			}

			$event_tz     = self::get_event_timezone( $event_data );
			$is_multi_day = self::is_multi_day_event( $event_data );

			$event_dates = $is_multi_day
				? self::get_event_date_range( $start_date, $end_date, $event_tz )
				: array( $start_date );

			foreach ( $event_dates as $index => $date_key ) {
				$display_datetime_obj = new DateTime( $date_key . ' 00:00:00', $event_tz );

				if ( ! isset( $date_groups[ $date_key ] ) ) {
					$date_groups[ $date_key ] = array(
						'date_obj' => $display_datetime_obj,
						'events'   => array(),
					);
				}

				$display_item                    = $event_item;
				$display_item['display_context'] = array(
					'is_multi_day'        => $is_multi_day,
					'is_start_day'        => ( $date_key === $start_date ),
					'is_end_day'          => ( $date_key === $end_date ),
					'is_continuation'     => ( $date_key !== $start_date ),
					'display_date'        => $date_key,
					'original_start_date' => $start_date,
					'original_end_date'   => $end_date,
					'day_number'          => $index + 1,
					'total_days'          => count( $event_dates ),
				);

				$date_groups[ $date_key ]['events'][] = $display_item;
			}
		}

		// Allow reordering events within each day group.
		foreach ( $date_groups as $date_key => &$date_group ) {
			$date_group['events'] = apply_filters(
				'datamachine_events_day_group_events',
				$date_group['events'],
				$date_key,
				array(
					'date_obj'  => $date_group['date_obj'],
					'show_past' => $show_past,
				)
			);
		}
		unset( $date_group );

		uksort(
			$date_groups,
			function ( $a, $b ) use ( $show_past ) {
				return $show_past ? strcmp( $b, $a ) : strcmp( $a, $b );
			}
		);

		return $date_groups;
	}

	/**
	 * Build display variables for an event
	 *
	 * @param array $event_data Event data from block attributes
	 * @param array $display_context Optional display context for multi-day events
	 * @return array Display variables
	 */
	public static function build_display_vars( array $event_data, array $display_context = array() ): array {
		$start_date = $event_data['startDate'] ?? '';
		$start_time = $event_data['startTime'] ?? '';
		$end_date   = $event_data['endDate'] ?? '';
		$end_time   = $event_data['endTime'] ?? '';

		$formatted_time_display = '';
		$iso_start_date         = '';
		$multi_day_label        = '';

		if ( $start_date ) {
			$event_tz           = self::get_event_timezone( $event_data );
			$start_datetime_obj = new DateTime( $start_date . ' ' . $start_time, $event_tz );
			$iso_start_date     = $start_datetime_obj->format( 'c' );

			$is_multi_day    = ! empty( $display_context['is_multi_day'] );
			$is_continuation = ! empty( $display_context['is_continuation'] );

			if ( $is_multi_day && ! empty( $end_date ) ) {
				$end_datetime_obj = new DateTime( $end_date, $event_tz );
				$multi_day_label  = sprintf(
					__( 'through %s', 'datamachine-events' ),
					$end_datetime_obj->format( 'M j' )
				);

				if ( $is_continuation ) {
					$formatted_time_display = __( 'All Day', 'datamachine-events' );
				} else {
					$formatted_time_display = self::format_time_range( $start_datetime_obj, $end_date, $end_time, $event_tz );
				}
			} else {
				$formatted_time_display = self::format_time_range( $start_datetime_obj, $end_date, $end_time, $event_tz );
			}
		}

		return array(
			'formatted_time_display' => $formatted_time_display,
			'venue_name'             => self::decode_unicode( $event_data['venue'] ?? '' ),
			'performer_name'         => self::decode_unicode( $event_data['performer'] ?? '' ),
			'iso_start_date'         => $iso_start_date,
			'show_performer'         => false,
			'show_price'             => $event_data['showPrice'] ?? true,
			'show_ticket_link'       => $event_data['showTicketLink'] ?? true,
			'multi_day_label'        => $multi_day_label,
			'is_continuation'        => $display_context['is_continuation'] ?? false,
			'is_multi_day'           => $display_context['is_multi_day'] ?? false,
		);
	}

	/**
	 * Format time range for display
	 *
	 * Formats start and end times into a readable range. When both times share
	 * the same AM/PM period, only shows the period once (e.g., "7:30 - 10:00 PM").
	 *
	 * @param DateTime $start_datetime_obj Start datetime object
	 * @param string $end_date End date (Y-m-d format)
	 * @param string $end_time End time (H:i:s format)
	 * @param DateTimeZone $event_tz Event timezone
	 * @return string Formatted time display
	 */
	private static function format_time_range( DateTime $start_datetime_obj, string $end_date, string $end_time, \DateTimeZone $event_tz ): string {
		$start_formatted_full = $start_datetime_obj->format( 'g:i A' );

		if ( empty( $end_date ) || empty( $end_time ) ) {
			return $start_formatted_full;
		}

		$end_datetime_obj = new DateTime( $end_date . ' ' . $end_time, $event_tz );

		$is_same_day = $start_datetime_obj->format( 'Y-m-d' ) === $end_datetime_obj->format( 'Y-m-d' );
		if ( ! $is_same_day ) {
			return $start_formatted_full;
		}

		$start_period = $start_datetime_obj->format( 'A' );
		$end_period   = $end_datetime_obj->format( 'A' );

		if ( $start_period === $end_period ) {
			$start_time_only    = $start_datetime_obj->format( 'g:i' );
			$end_formatted_full = $end_datetime_obj->format( 'g:i A' );
			return $start_time_only . ' - ' . $end_formatted_full;
		}

		$end_formatted_full = $end_datetime_obj->format( 'g:i A' );
		return $start_formatted_full . ' - ' . $end_formatted_full;
	}

	/**
	 * Decode unicode escape sequences in strings
	 *
	 * @param string $str Input string
	 * @return string Decoded string
	 */
	public static function decode_unicode( string $str ): string {
		return html_entity_decode(
			preg_replace( '/\\\\u([0-9a-fA-F]{4})/', '&#x$1;', $str ),
			ENT_NOQUOTES,
			'UTF-8'
		);
	}

	/**
	 * Detect time gaps between date groups for carousel mode
	 *
	 * @param array $date_groups Date-grouped events
	 * @return array Map of date_key => gap_days for gaps >= 2 days
	 */
	public static function detect_time_gaps( array $date_groups ): array {
		$gaps          = array();
		$previous_date = null;

		foreach ( $date_groups as $date_key => $date_group ) {
			if ( null !== $previous_date ) {
				$current_date = new DateTime( $date_key, wp_timezone() );
				$days_diff    = $current_date->diff( $previous_date )->days;

				if ( $days_diff > 1 ) {
					$gaps[ $date_key ] = $days_diff;
				}
			}
			$previous_date = new DateTime( $date_key, wp_timezone() );
		}

		return $gaps;
	}

	/**
	 * Get unique event dates for pagination calculations
	 *
	 * Expands multi-day events to count on each day they span.
	 *
	 * @param array $params Query parameters (show_past, search_query, tax_filters, etc.)
	 * @return array {
	 *     @type array $dates        Ordered array of unique date strings (Y-m-d)
	 *     @type int   $total_events Total number of matching events
	 * }
	 */
	public static function get_unique_event_dates( array $params ): array {
		$query_args           = self::build_query_args( $params );
		$query_args['fields'] = 'ids';

		$query           = new WP_Query( $query_args );
		$total_events    = $query->found_posts;
		$events_per_date = array();

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post_id ) {
				$start_datetime = get_post_meta( $post_id, EVENT_DATETIME_META_KEY, true );
				$end_datetime   = get_post_meta( $post_id, EVENT_END_DATETIME_META_KEY, true );

				if ( ! $start_datetime ) {
					continue;
				}

				$start_date = date( 'Y-m-d', strtotime( $start_datetime ) );
				$end_date   = $end_datetime ? date( 'Y-m-d', strtotime( $end_datetime ) ) : $start_date;

				$event_dates = ( $start_date !== $end_date )
					? self::get_event_date_range( $start_date, $end_date, wp_timezone() )
					: array( $start_date );

				foreach ( $event_dates as $date ) {
					if ( isset( $events_per_date[ $date ] ) ) {
						++$events_per_date[ $date ];
					} else {
						$events_per_date[ $date ] = 1;
					}
				}
			}
		}

		if ( $params['show_past'] ?? false ) {
			krsort( $events_per_date );
		} else {
			ksort( $events_per_date );
		}

		$dates = array_keys( $events_per_date );

		return array(
			'dates'           => $dates,
			'total_events'    => $total_events,
			'events_per_date' => $events_per_date,
		);
	}

	/**
	 * Get date boundaries for a specific page
	 *
	 * Pages must contain at least DAYS_PER_PAGE (5) days AND at least
	 * MIN_EVENTS_FOR_PAGINATION (20) events. Days are added beyond the
	 * minimum until the event threshold is met. The day that crosses
	 * the 20-event threshold is included in full (never split days).
	 *
	 * If total_events is below the threshold, all dates are shown on one page.
	 *
	 * @param array $unique_dates Ordered array of unique dates
	 * @param int $page Page number (1-based)
	 * @param int $total_events Total event count for pagination threshold check
	 * @param array $events_per_date Event counts keyed by date ['Y-m-d' => count]
	 * @return array ['start_date' => 'Y-m-d', 'end_date' => 'Y-m-d', 'max_pages' => int]
	 */
	public static function get_date_boundaries_for_page( array $unique_dates, int $page, int $total_events = 0, array $events_per_date = array() ): array {
		$total_days = count( $unique_dates );

		if ( 0 === $total_days ) {
			return array(
				'start_date' => '',
				'end_date'   => '',
				'max_pages'  => 0,
			);
		}

		if ( $total_events > 0 && $total_events < MIN_EVENTS_FOR_PAGINATION ) {
			return array(
				'start_date' => $unique_dates[0],
				'end_date'   => $unique_dates[ $total_days - 1 ],
				'max_pages'  => 1,
			);
		}

		if ( empty( $events_per_date ) ) {
			$max_pages = (int) ceil( $total_days / DAYS_PER_PAGE );
			$page      = max( 1, min( $page, $max_pages ) );

			$start_index = ( $page - 1 ) * DAYS_PER_PAGE;
			$end_index   = min( $start_index + DAYS_PER_PAGE - 1, $total_days - 1 );

			return array(
				'start_date' => $unique_dates[ $start_index ],
				'end_date'   => $unique_dates[ $end_index ],
				'max_pages'  => $max_pages,
			);
		}

		$page_boundaries      = array();
		$current_page_start   = 0;
		$cumulative_events    = 0;
		$days_in_current_page = 0;

		for ( $i = 0; $i < $total_days; $i++ ) {
			$date               = $unique_dates[ $i ];
			$cumulative_events += $events_per_date[ $date ] ?? 0;
			++$days_in_current_page;

			$is_last_date   = ( $i === $total_days - 1 );
			$meets_minimums = ( $days_in_current_page >= DAYS_PER_PAGE && $cumulative_events >= MIN_EVENTS_FOR_PAGINATION );

			if ( $meets_minimums || $is_last_date ) {
				$page_boundaries[]    = array(
					'start' => $current_page_start,
					'end'   => $i,
				);
				$current_page_start   = $i + 1;
				$cumulative_events    = 0;
				$days_in_current_page = 0;
			}
		}

		$max_pages = count( $page_boundaries );
		$page      = max( 1, min( $page, $max_pages ) );
		$boundary  = $page_boundaries[ $page - 1 ];

		return array(
			'start_date' => $unique_dates[ $boundary['start'] ],
			'end_date'   => $unique_dates[ $boundary['end'] ],
			'max_pages'  => $max_pages,
		);
	}
}
