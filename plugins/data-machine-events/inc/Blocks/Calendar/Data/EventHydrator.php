<?php
/**
 * Event Hydrator
 *
 * Parses event data from block attributes and hydrates from authoritative
 * sources (post meta for datetime, taxonomy for venue/promoter).
 *
 * @package DataMachineEvents\Blocks\Calendar\Data
 * @since   0.14.0
 */

namespace DataMachineEvents\Blocks\Calendar\Data;

use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Core\Promoter_Taxonomy;
use const DataMachineEvents\Core\EVENT_DATETIME_META_KEY;
use const DataMachineEvents\Core\EVENT_END_DATETIME_META_KEY;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventHydrator {

	/**
	 * Parse event data from post, hydrating from authoritative sources.
	 *
	 * Combines block attributes with post meta (datetime) and taxonomy terms
	 * (venue, promoter) to return complete, authoritative event data.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array|null Event data array or null if no startDate found.
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
	 * @param int   $post_id    Post ID.
	 * @param array $event_data Event data array (modified by reference).
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
				$end_time_from_meta    = $date_obj->format( 'H:i:s' );
				// Only set if not the sentinel value (23:59:59 means "no end time provided")
				if ( '23:59:59' !== $end_time_from_meta ) {
					$event_data['endTime'] = $end_time_from_meta;
				}
			}
		}
	}

	/**
	 * Hydrate venue fields from taxonomy.
	 *
	 * Venue taxonomy is the source of truth. If event has an assigned venue
	 * term, its name, formatted address, and timezone override any block attribute values.
	 *
	 * @param int   $post_id    Post ID.
	 * @param array $event_data Event data array (modified by reference).
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
	 * @param int   $post_id    Post ID.
	 * @param array $event_data Event data array (modified by reference).
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
}
