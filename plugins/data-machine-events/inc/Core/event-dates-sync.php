<?php
/**
 * Event Dates Sync
 *
 * Syncs event dates from Event Details block attributes to the
 * datamachine_event_dates table on save_post. Also handles ticket URL
 * normalization for duplicate detection and post_status denormalization.
 *
 * @package DataMachine_Events
 */

namespace DataMachineEvents\Core;

const EVENT_DATETIME_META_KEY     = '_datamachine_event_datetime';
const EVENT_END_DATETIME_META_KEY = '_datamachine_event_end_datetime';
const EVENT_TICKET_URL_META_KEY   = '_datamachine_ticket_url';

/**
 * Normalize ticket URL for consistent duplicate detection
 *
 * Strips tracking query parameters (UTM, etc.) while preserving identity
 * parameters that contain unique ticket identifiers (affiliate redirect URLs).
 *
 * Identity parameters preserved:
 * - 'u' = redirect URL (Ticketmaster affiliate via evyy.net)
 * - 'e' = event ID (DoStuff, some redirect services)
 *
 * @since 0.8.39 Original implementation (stripped all query params - bug)
 * @since 0.10.11 Fixed to preserve identity parameters for affiliate URLs
 *
 * @param string $url Raw ticket URL
 * @return string Normalized URL (scheme + host + path + identity params)
 */
function datamachine_normalize_ticket_url( string $url ): string {
	if ( empty( $url ) ) {
		return '';
	}

	$parsed = wp_parse_url( $url );
	if ( ! $parsed || empty( $parsed['host'] ) ) {
		return esc_url_raw( $url );
	}

	$scheme     = $parsed['scheme'] ?? 'https';
	$normalized = $scheme . '://' . $parsed['host'];

	if ( ! empty( $parsed['path'] ) ) {
		$normalized .= $parsed['path'];
	}

	// Preserve identity parameters for affiliate/redirect URLs
	// These contain the actual unique ticket identifier
	if ( ! empty( $parsed['query'] ) ) {
		parse_str( $parsed['query'], $query_params );
		$identity_params = array();

		// 'u' = redirect URL (Ticketmaster affiliate, evyy.net)
		if ( ! empty( $query_params['u'] ) ) {
			$identity_params['u'] = $query_params['u'];
		}
		// 'e' = event ID (DoStuff, some redirect services)
		if ( ! empty( $query_params['e'] ) ) {
			$identity_params['e'] = $query_params['e'];
		}

		if ( ! empty( $identity_params ) ) {
			$normalized .= '?' . http_build_query( $identity_params );
		}
	}

	return rtrim( $normalized, '/' );
}

/**
 * Extract the canonical ticket identifier URL for dedup comparison.
 *
 * Unlike datamachine_normalize_ticket_url() which preserves affiliate links
 * for storage, this function unwraps affiliate/redirect wrappers to extract
 * the underlying ticket platform URL. This allows matching:
 * - ticketmaster.evyy.net/c/.../4272?u=<ticketmaster_url>  (affiliate)
 * - www.ticketmaster.com/event/...                          (direct)
 *
 * The result is normalized (scheme + host + path, no query params) for comparison.
 *
 * @param string $url Ticket URL (may be affiliate-wrapped or direct)
 * @return string Canonical URL for dedup comparison
 */
function datamachine_extract_ticket_identity( string $url ): string {
	if ( empty( $url ) ) {
		return '';
	}

	// Unwrap affiliate redirect URLs to get the canonical ticket URL.
	$canonical = datamachine_unwrap_affiliate_url( $url );

	// Normalize to scheme + host + path (strip query params for comparison)
	$parsed = wp_parse_url( $canonical );
	if ( ! $parsed || empty( $parsed['host'] ) ) {
		return '';
	}

	$scheme     = $parsed['scheme'] ?? 'https';
	$normalized = $scheme . '://' . $parsed['host'];

	if ( ! empty( $parsed['path'] ) ) {
		$normalized .= $parsed['path'];
	}

	return rtrim( $normalized, '/' );
}

/**
 * Unwrap affiliate/redirect URLs to extract the canonical ticket URL.
 *
 * Known affiliate wrappers:
 * - evyy.net (Ticketmaster affiliate): ?u=<encoded_url>
 * - redirect.viglink.com: ?u=<encoded_url>
 * - click.linksynergy.com: ?u=<encoded_url>
 *
 * @param string $url Possibly wrapped URL
 * @return string Unwrapped URL, or original if not an affiliate wrapper
 */
function datamachine_unwrap_affiliate_url( string $url ): string {
	$parsed = wp_parse_url( $url );
	if ( ! $parsed || empty( $parsed['host'] ) || empty( $parsed['query'] ) ) {
		return $url;
	}

	// Known affiliate/redirect hosts that wrap ticket URLs in a ?u= parameter
	$affiliate_hosts = array(
		'evyy.net',
		'viglink.com',
		'linksynergy.com',
		'shareasale.com',
		'anrdoezrs.net',
		'jdoqocy.com',
		'dpbolvw.net',
		'kqzyfj.com',
		'tkqlhce.com',
	);

	$host = strtolower( $parsed['host'] );
	$is_affiliate = false;
	foreach ( $affiliate_hosts as $affiliate_host ) {
		if ( $host === $affiliate_host || str_ends_with( $host, '.' . $affiliate_host ) ) {
			$is_affiliate = true;
			break;
		}
	}

	if ( ! $is_affiliate ) {
		return $url;
	}

	parse_str( $parsed['query'], $query_params );

	// Try common redirect parameter names
	foreach ( array( 'u', 'url', 'murl', 'destination' ) as $param ) {
		if ( ! empty( $query_params[ $param ] ) ) {
			$inner_url = urldecode( $query_params[ $param ] );
			// Validate it looks like a URL
			if ( filter_var( $inner_url, FILTER_VALIDATE_URL ) ) {
				return $inner_url;
			}
		}
	}

	return $url;
}

/**
 * Sync event datetime to post meta on save
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @param bool    $update  Whether this is an update.
 */
function data_machine_events_sync_datetime_meta( $post_id, $post, $update ) {
	// Only for data_machine_events post type.
	if ( Event_Post_Type::POST_TYPE !== $post->post_type ) {
		return;
	}

	// Avoid infinite loops during autosave.
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	// Parse blocks to extract event details from Event Details block.
	$blocks = parse_blocks( $post->post_content );

	foreach ( $blocks as $block ) {
		if ( 'data-machine-events/event-details' === $block['blockName'] ) {
			$start_date = $block['attrs']['startDate'] ?? '';
			$start_time = $block['attrs']['startTime'] ?? '';
			$end_date   = $block['attrs']['endDate'] ?? '';
			$end_time   = $block['attrs']['endTime'] ?? '';

			$start_time_parts = $start_time ? explode( ':', $start_time ) : array();
			if ( $start_time && count( $start_time_parts ) === 2 ) {
				$start_time .= ':00';
			}

			$end_time_parts = explode( ':', $end_time );
			if ( $end_time && count( $end_time_parts ) === 2 ) {
				$end_time .= ':00';
			}

			if ( $start_date ) {
				$effective_start_time = $start_time ? $start_time : '00:00:00';
				$datetime             = $start_date . ' ' . $effective_start_time;

				if ( $end_date ) {
					$effective_end_time = $end_time ? $end_time : '23:59:59';
					$end_datetime_val   = $end_date . ' ' . $effective_end_time;
				} elseif ( $end_time ) {
					$end_datetime_val = $start_date . ' ' . $end_time;
				} else {
					$end_datetime_val = null;
				}

				EventDatesTable::upsert( $post_id, $datetime, $end_datetime_val );

				/**
				 * Fires after event dates are written to the event_dates table.
				 *
				 * Replaces the `updated_post_meta`/`added_post_meta` hooks that
				 * previously fired from update_post_meta() calls.
				 *
				 * @param int         $post_id        Post ID.
				 * @param string      $start_datetime Start datetime.
				 * @param string|null $end_datetime   End datetime or null.
				 */
				do_action( 'datamachine_event_dates_updated', $post_id, $datetime, $end_datetime_val );
			} else {
				EventDatesTable::delete( $post_id );
			}

			// Sync ticket URL for duplicate detection queries.
			$ticket_url = $block['attrs']['ticketUrl'] ?? '';
			if ( $ticket_url ) {
				update_post_meta( $post_id, EVENT_TICKET_URL_META_KEY, datamachine_normalize_ticket_url( $ticket_url ) );
			} else {
				delete_post_meta( $post_id, EVENT_TICKET_URL_META_KEY );
			}

			break;
		}
	}
}
add_action( 'save_post', __NAMESPACE__ . '\\data_machine_events_sync_datetime_meta', 10, 3 );

/**
 * Sync post_status to event_dates table on status transitions.
 *
 * Keeps the denormalized post_status column in sync so that date queries
 * can filter by status without joining the full posts table.
 *
 * @param string  $new_status New post status.
 * @param string  $old_status Old post status.
 * @param WP_Post $post       Post object.
 */
function data_machine_events_sync_status( $new_status, $old_status, $post ) {
	if ( Event_Post_Type::POST_TYPE !== $post->post_type ) {
		return;
	}

	if ( $new_status === $old_status ) {
		return;
	}

	EventDatesTable::update_status( $post->ID, $new_status );
}
add_action( 'transition_post_status', __NAMESPACE__ . '\\data_machine_events_sync_status', 10, 3 );

/**
 * Get event dates from the dedicated event_dates table.
 *
 * @param int $post_id Post ID.
 * @return object|null Object with start_datetime and end_datetime properties, or null.
 */
function datamachine_get_event_dates( int $post_id ): ?object {
	return EventDatesTable::get( $post_id );
}

/**
 * Get event timing state for a single event.
 *
 * Applies the same logic as UpcomingFilter (the SQL-level source of truth)
 * to a single event for runtime checks:
 *   upcoming = start >= now
 *   ongoing  = start < now AND end >= now
 *   past     = start < now AND (end < now OR end IS NULL)
 *
 * @param int $post_id Event post ID.
 * @return string 'upcoming' | 'ongoing' | 'past'
 */
function datamachine_get_event_timing( int $post_id ): string {
	$dates = datamachine_get_event_dates( $post_id );

	if ( ! $dates || empty( $dates->start_datetime ) ) {
		return 'past';
	}

	$now         = current_time( 'mysql' );
	$event_start = $dates->start_datetime;
	$event_end   = $dates->end_datetime ?? null;

	if ( $event_start >= $now ) {
		return 'upcoming';
	}

	if ( $event_end && $event_end >= $now ) {
		return 'ongoing';
	}

	return 'past';
}
