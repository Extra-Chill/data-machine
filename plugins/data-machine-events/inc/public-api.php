<?php
/**
 * Data Machine Events — Public Integration API
 *
 * Stable, namespace-free function surface for downstream plugins and themes
 * to consume Data Machine Events data without coupling to internal classes.
 *
 * **Stability contract:** every function and constant declared in this file
 * is part of the supported public API. Internal classes
 * (`DataMachineEvents\Blocks\Calendar\…`, `DataMachineEvents\Core\…`, etc.)
 * are NOT public. They may be renamed, moved, or refactored at any time.
 * Consumers should call the functions in this file and gate registration on
 * the `data_machine_events_loaded` action — never on `class_exists()` against
 * an internal class name.
 *
 * @package DataMachineEvents
 * @since   0.32.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public post type slug for events.
 *
 * Use this constant instead of `\DataMachineEvents\Core\Event_Post_Type::POST_TYPE`.
 *
 * @since 0.32.0
 */
if ( ! defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ) {
	define( 'DATA_MACHINE_EVENTS_POST_TYPE', 'data_machine_events' );
}

/**
 * Public taxonomy slug for venues.
 *
 * @since 0.32.0
 */
if ( ! defined( 'DATA_MACHINE_EVENTS_VENUE_TAXONOMY' ) ) {
	define( 'DATA_MACHINE_EVENTS_VENUE_TAXONOMY', 'venue' );
}

/**
 * Public taxonomy slug for promoters.
 *
 * @since 0.32.0
 */
if ( ! defined( 'DATA_MACHINE_EVENTS_PROMOTER_TAXONOMY' ) ) {
	define( 'DATA_MACHINE_EVENTS_PROMOTER_TAXONOMY', 'promoter' );
}

if ( ! function_exists( 'data_machine_events_is_loaded' ) ) {
	/**
	 * Whether the Data Machine Events plugin has finished bootstrapping.
	 *
	 * Equivalent to `did_action( 'data_machine_events_loaded' )` but reads
	 * more clearly at consumer sites. Use this to gate filter registrations
	 * and rendering calls instead of `class_exists()` against internal classes.
	 *
	 * @since 0.32.0
	 *
	 * @return bool True once the `data_machine_events_loaded` action has fired.
	 */
	function data_machine_events_is_loaded(): bool {
		return (bool) did_action( 'data_machine_events_loaded' );
	}
}

if ( ! function_exists( 'data_machine_events_parse_event_data' ) ) {
	/**
	 * Parse and hydrate event data for a given event post.
	 *
	 * Returns the event's block-attribute payload (start/end dates and times,
	 * venue, promoter, performer, ticket URL, price, etc.) hydrated from the
	 * authoritative storage layer. Returns null if the post has no parseable
	 * Event Details block / startDate.
	 *
	 * Replaces direct calls to
	 * `\DataMachineEvents\Blocks\Calendar\Data\EventHydrator::parse_event_data()`
	 * (and its predecessor `Calendar_Query::parse_event_data()`).
	 *
	 * @since 0.32.0
	 *
	 * @param \WP_Post $post Event post object.
	 * @return array|null Event data array or null when no startDate is present.
	 */
	function data_machine_events_parse_event_data( \WP_Post $post ): ?array {
		if ( ! class_exists( '\DataMachineEvents\Blocks\Calendar\Data\EventHydrator' ) ) {
			return null;
		}

		return \DataMachineEvents\Blocks\Calendar\Data\EventHydrator::parse_event_data( $post );
	}
}

if ( ! function_exists( 'data_machine_events_render_taxonomy_badges' ) ) {
	/**
	 * Render taxonomy badge markup for an event post.
	 *
	 * Outputs the wrapped HTML used on calendar event cards: a `<div>` wrapper
	 * containing an `<a>` (or `<span>` when term has no link) per public taxonomy
	 * term. Honors `data_machine_events_excluded_taxonomies`,
	 * `data_machine_events_badge_wrapper_classes`, and
	 * `data_machine_events_badge_classes` filters.
	 *
	 * Replaces direct calls to
	 * `\DataMachineEvents\Blocks\Calendar\Taxonomy\Badges::render_taxonomy_badges()`.
	 *
	 * @since 0.32.0
	 *
	 * @param int $post_id Event post ID.
	 * @return string Rendered badge HTML, or empty string when no taxonomies apply.
	 */
	function data_machine_events_render_taxonomy_badges( int $post_id ): string {
		if ( ! class_exists( '\DataMachineEvents\Blocks\Calendar\Taxonomy\Badges' ) ) {
			return '';
		}

		return (string) \DataMachineEvents\Blocks\Calendar\Taxonomy\Badges::render_taxonomy_badges( $post_id );
	}
}

if ( ! function_exists( 'data_machine_events_group_by_date' ) ) {
	/**
	 * Group a list of events by date.
	 *
	 * Wraps the calendar block's `DateGrouper::group_events_by_date()` so callers
	 * can produce calendar-shaped output without importing the internal class.
	 *
	 * @since 0.32.0
	 *
	 * @param array  $paged_events Array of event entries (each with a `post` key).
	 * @param bool   $show_past    Whether to include past dates in the grouping.
	 * @param string $date_start   Optional ISO start date (YYYY-MM-DD) for range scoping.
	 * @param string $date_end     Optional ISO end date (YYYY-MM-DD) for range scoping.
	 * @return array Date-grouped events keyed by date string.
	 */
	function data_machine_events_group_by_date(
		array $paged_events,
		bool $show_past = false,
		string $date_start = '',
		string $date_end = ''
	): array {
		if ( ! class_exists( '\DataMachineEvents\Blocks\Calendar\Grouping\DateGrouper' ) ) {
			return array();
		}

		return \DataMachineEvents\Blocks\Calendar\Grouping\DateGrouper::group_events_by_date(
			$paged_events,
			$show_past,
			$date_start,
			$date_end
		);
	}
}

if ( ! function_exists( 'data_machine_events_query_events' ) ) {
	/**
	 * Query events through the canonical Date Query Abilities path.
	 *
	 * Convenience wrapper around `EventDateQueryAbilities::executeQueryEvents()`
	 * for callers that need a list of event posts filtered by scope, taxonomy
	 * filters, and date range without coupling to the abilities class directly.
	 *
	 * Pass-through parameters mirror `executeQueryEvents()`:
	 * - `scope`        (string)  e.g. `'upcoming'`, `'past'`.
	 * - `tax_filters`  (array)   `[ taxonomy_slug => [ term_id, … ] ]`.
	 * - `exclude`      (array)   Post IDs to exclude.
	 * - `per_page`     (int)
	 * - `order`        (string)  `'ASC'` | `'DESC'`.
	 * - `date_start`   (string)  ISO date.
	 * - `date_end`     (string)  ISO date.
	 * - `time_scope`   (string)  Named window: `'today'`, `'tonight'`,
	 *                            `'this-weekend'`, `'this-week'`. Resolves via
	 *                            ScopeResolver and constrains the query to
	 *                            that window through the same UpcomingFilter
	 *                            path the calendar list uses, so a scoped
	 *                            count matches the scoped list. Explicit
	 *                            date_start/date_end take precedence. #428.
	 *
	 * @since 0.32.0
	 *
	 * @param array $params Query parameters; see above.
	 * @return array { posts: WP_Post[], total: int, … } as returned by the ability.
	 */
	function data_machine_events_query_events( array $params ): array {
		if ( ! class_exists( '\DataMachineEvents\Abilities\EventDateQueryAbilities' ) ) {
			return array(
				'posts' => array(),
				'total' => 0,
			);
		}

		$ability = new \DataMachineEvents\Abilities\EventDateQueryAbilities();
		return $ability->executeQueryEvents( $params );
	}
}

if ( ! function_exists( 'data_machine_events_get_venue_data' ) ) {
	/**
	 * Get raw venue term metadata.
	 *
	 * Returns the structured venue data array stored against a venue term —
	 * address, lat/lng, city, region, country, website, etc. Returns null when
	 * the term does not exist or has no associated venue data.
	 *
	 * @since 0.32.1
	 *
	 * @param int $term_id Venue term ID.
	 * @return array|null Venue data array or null.
	 */
	function data_machine_events_get_venue_data( int $term_id ): ?array {
		if ( ! class_exists( '\DataMachineEvents\Core\Venue_Taxonomy' ) ) {
			return null;
		}

		$data = \DataMachineEvents\Core\Venue_Taxonomy::get_venue_data( $term_id );
		return is_array( $data ) ? $data : null;
	}
}

if ( ! function_exists( 'data_machine_events_get_venue_address' ) ) {
	/**
	 * Get the formatted single-line address string for a venue term.
	 *
	 * Combines street, city, region, postal code, and country into a
	 * comma-delimited human-readable address.
	 *
	 * @since 0.32.1
	 *
	 * @param int        $term_id    Venue term ID.
	 * @param array|null $venue_data Optional pre-fetched venue data array
	 *                               to avoid an extra term meta lookup.
	 * @return string Formatted address or empty string.
	 */
	function data_machine_events_get_venue_address( int $term_id, ?array $venue_data = null ): string {
		if ( ! class_exists( '\DataMachineEvents\Core\Venue_Taxonomy' ) ) {
			return '';
		}

		return (string) \DataMachineEvents\Core\Venue_Taxonomy::get_formatted_address( $term_id, $venue_data );
	}
}

if ( ! function_exists( 'data_machine_events_get_venue_profile' ) ) {
	/**
	 * Read the bounded editable venue profile and optimistic revision.
	 *
	 * Consumers must authorize the caller before invoking this function.
	 *
	 * @param int $term_id Venue term ID.
	 * @return array|\WP_Error
	 */
	function data_machine_events_get_venue_profile( int $term_id ): array|\WP_Error {
		return \DataMachineEvents\Core\VenueProfileMutations::read( $term_id );
	}
}

if ( ! function_exists( 'data_machine_events_update_venue_profile' ) ) {
	/**
	 * Update bounded editable venue fields with optimistic concurrency.
	 *
	 * Consumers must authorize the caller before invoking this function.
	 * Coordinates, timezone, slug, identity, and unrelated metadata are owner-only.
	 *
	 * @param int    $term_id           Venue term ID.
	 * @param array  $changes           Editable field changes.
	 * @param string $expected_revision Revision returned by the read contract.
	 * @return array|\WP_Error
	 */
	function data_machine_events_update_venue_profile( int $term_id, array $changes, string $expected_revision ): array|\WP_Error {
		return \DataMachineEvents\Core\VenueProfileMutations::updateProfile( $term_id, $changes, $expected_revision );
	}
}

if ( ! function_exists( 'data_machine_events_get_promoter_data' ) ) {
	/**
	 * Get raw promoter term metadata.
	 *
	 * Returns the structured promoter data array stored against a promoter term.
	 * Returns null when the term does not exist or has no associated data.
	 *
	 * @since 0.32.1
	 *
	 * @param int $term_id Promoter term ID.
	 * @return array|null Promoter data array or null.
	 */
	function data_machine_events_get_promoter_data( int $term_id ): ?array {
		if ( ! class_exists( '\DataMachineEvents\Core\Promoter_Taxonomy' ) ) {
			return null;
		}

		$data = \DataMachineEvents\Core\Promoter_Taxonomy::get_promoter_data( $term_id );
		return is_array( $data ) ? $data : null;
	}
}

if ( ! function_exists( 'data_machine_events_get_event_datetime' ) ) {
	/**
	 * Get the start datetime for an event, formatted as a string.
	 *
	 * Convenience wrapper around the existing `datamachine_get_event_dates()`
	 * helper. Returns the `start_datetime` value or empty string when no row
	 * exists for the post.
	 *
	 * @since 0.32.0
	 *
	 * @param int $post_id Event post ID.
	 * @return string MySQL-format datetime or empty string.
	 */
	function data_machine_events_get_event_datetime( int $post_id ): string {
		if ( ! function_exists( 'datamachine_get_event_dates' ) ) {
			return '';
		}

		$dates = datamachine_get_event_dates( $post_id );
		return $dates && ! empty( $dates->start_datetime ) ? (string) $dates->start_datetime : '';
	}
}

if ( ! function_exists( 'data_machine_events_get_timing' ) ) {
	/**
	 * Get the timing state of an event relative to now.
	 *
	 * Returns one of `'upcoming'`, `'ongoing'`, or `'past'`, derived from the
	 * event's start/end datetimes in the authoritative `datamachine_event_dates`
	 * table using the same source-of-truth logic as the calendar's
	 * upcoming/past SQL filters:
	 *
	 *   - `upcoming` — start >= now
	 *   - `ongoing`  — start < now AND end >= now
	 *   - `past`     — start < now AND (end < now OR end is null)
	 *
	 * Events with no parseable start datetime are treated as `'past'`.
	 *
	 * Prefer this prefixed helper over calling `datamachine_get_event_timing()`
	 * directly; both are stable, but this name matches the rest of the public
	 * `data_machine_events_*` surface. Consuming plugins should call this instead
	 * of re-querying the event-dates table to derive tense themselves.
	 *
	 * @since 0.46.0
	 *
	 * @param int $post_id Event post ID.
	 * @return string `'upcoming'` | `'ongoing'` | `'past'`.
	 */
	function data_machine_events_get_timing( int $post_id ): string {
		if ( ! function_exists( 'datamachine_get_event_timing' ) ) {
			return 'past';
		}

		return datamachine_get_event_timing( $post_id );
	}
}
