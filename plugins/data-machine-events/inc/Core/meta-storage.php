<?php
/**
 * Event DateTime Meta Storage
 *
 * Core plugin feature that stores event datetime in post meta for efficient SQL queries.
 * Monitors Event Details block changes and syncs to post meta automatically.
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
			$start_time = $block['attrs']['startTime'] ?? '00:00:00';
			$end_date   = $block['attrs']['endDate'] ?? '';
			$end_time   = $block['attrs']['endTime'] ?? '';

			$start_time_parts = explode( ':', $start_time );
			if ( count( $start_time_parts ) === 2 ) {
				$start_time .= ':00';
			}

			$end_time_parts = explode( ':', $end_time );
			if ( $end_time && count( $end_time_parts ) === 2 ) {
				$end_time .= ':00';
			}

			if ( $start_date ) {
				$datetime = $start_date . ' ' . $start_time;
				update_post_meta( $post_id, EVENT_DATETIME_META_KEY, $datetime );

				if ( $end_date ) {
					$effective_end_time = $end_time ? $end_time : '23:59:59';
					$end_datetime_val   = $end_date . ' ' . $effective_end_time;
				} else {
					try {
						$start_dt = new \DateTime( $datetime );
						$start_dt->modify( '+3 hours' );
						$end_datetime_val = $start_dt->format( 'Y-m-d H:i:s' );
					} catch ( \Exception $e ) {
						$end_datetime_val = $datetime;
					}
				}
				update_post_meta( $post_id, EVENT_END_DATETIME_META_KEY, $end_datetime_val );
			} else {
				// No date found, delete meta if it exists.
				delete_post_meta( $post_id, EVENT_DATETIME_META_KEY );
				delete_post_meta( $post_id, EVENT_END_DATETIME_META_KEY );
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
