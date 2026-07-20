<?php
/**
 * Calendar REST API Controller
 *
 * Thin wrapper around CalendarAbilities for REST API access.
 * All business logic delegated to CalendarAbilities.
 *
 * Wraps the response in a top-level full-response cache to mitigate
 * crawler-driven DOS on `?past=1` historical archive variants. See
 * Extra-Chill/data-machine-events#246 — Pinterestbot iterating every
 * venue/artist archive with distinct geo params produced one expensive
 * query per request because the underlying bucket cache was keyed
 * without geo params.
 *
 * Response envelopes
 * ------------------
 * - Default (no `format` param, or any value other than `data`):
 *   the legacy HTML-string envelope (`html`, `pagination.html`,
 *   `counter`, `navigation.html`) used by the current Calendar block
 *   frontend bundle. UNCHANGED in phase 1.
 *
 * - `?format=data`: the structured data-only envelope introduced in
 *   phase 1 of refactor #298. Returns event objects, pagination /
 *   counter / navigation metadata, gap map, and a `by_date` grouping
 *   index. Empty results include the canonical server-rendered recovery
 *   fragment. See `docs/calendar-data-schema.md`.
 *
 * Both envelopes are cached independently via
 * `CalendarCache::generate_full_response_key()`, which includes
 * `format` in its key surface as of this phase.
 */

namespace DataMachineEvents\Api\Controllers;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use DataMachineEvents\Abilities\CalendarAbilities;
use DataMachineEvents\Api\BrowserNavigationGuard;
use DataMachineEvents\Blocks\Calendar\Cache\CalendarCache;
use DataMachineEvents\Blocks\Calendar\Display\DisplayVars;
use DataMachineEvents\Blocks\Calendar\Display\EventRenderer;
use DataMachineEvents\Blocks\Calendar\Query\CalendarRequest;
use DataMachineEvents\Blocks\Calendar\Taxonomy\Badges;
use DataMachineEvents\Blocks\Calendar\Template_Loader;

/**
 * Calendar API controller
 */
class Calendar {

	/**
	 * Schema version of the `format=data` response envelope.
	 *
	 * Bump this whenever the data-envelope SHAPE changes in a way the client
	 * renderer depends on. It is folded into the full-response cache key so a
	 * deploy that changes the shape can never serve a stale older-shape
	 * envelope to the newer client (and vice-versa) for the cache TTL window.
	 *
	 * v2 (#381): per-occurrence `display` block added to `grouping.by_date`.
	 * v3 (#465): canonical server-rendered empty-state fragment added.
	 */
	const DATA_SCHEMA_VERSION = 3;

	/**
	 * Calendar endpoint implementation
	 *
	 * @param WP_REST_Request $request REST request object
	 * @return \WP_REST_Response
	 */
	public function calendar( WP_REST_Request $request ) {
		// Browser-direct navigations (address-bar paste, middle-click on
		// stale anchors, share-link) must never receive raw JSON. The
		// guard redirects to the canonical archive URL when possible
		// and falls back to a 404 otherwise. JS callers are passed
		// through because they identify themselves via
		// `X-Requested-With: XMLHttpRequest` and/or an Accept header
		// that prefers application/json. See issue #297.
		$guarded = BrowserNavigationGuard::guard( $request );
		if ( null !== $guarded ) {
			return $guarded;
		}

		$calendar_request = CalendarRequest::fromRestRequest( $request );
		$envelope         = $calendar_request->toAbilitiesArgs();

		// Thread the format selector into the cache-key envelope so the
		// HTML and data-only response shapes never share a cache bucket.
		// `toAbilitiesArgs()` already gates `include_html` on the format,
		// but the cache key needs an explicit `format` field of its own
		// (the ability-side envelope only sees the derived `include_html`).
		$envelope['format'] = $calendar_request->format();
		$is_data_format     = ( 'data' === $envelope['format'] );

		// Fold the data-envelope schema version into the cache key (data
		// format only) so a shape change across a deploy never serves a
		// stale older-shape envelope to the newer client. See #381.
		if ( $is_data_format ) {
			$envelope['data_schema_version'] = self::DATA_SCHEMA_VERSION;
		}

		// Editors with `manage_options` always bypass the cache so they
		// see fresh data immediately after publishing / editing events.
		// Anonymous traffic (the DOS-vector path) uses the cache.
		$bypass_cache = current_user_can( 'manage_options' );

		$cache_key = CalendarCache::generate_full_response_key( $envelope );

		if ( ! $bypass_cache ) {
			$cached = CalendarCache::get_full_response( $cache_key );
			if ( false !== $cached && is_array( $cached ) ) {
				return rest_ensure_response( $cached );
			}
		}

		$abilities = new CalendarAbilities();
		$result    = $abilities->executeGetCalendarPage( $envelope );

		$response_body = $is_data_format
			? $this->build_data_response( $result, $calendar_request )
			: $this->build_html_response( $result, $request );

		if ( ! $bypass_cache ) {
			CalendarCache::set_full_response(
				$cache_key,
				$response_body,
				CalendarCache::ttl_for_envelope( $envelope )
			);
		}

		return rest_ensure_response( $response_body );
	}

	/**
	 * Build the legacy HTML-string response envelope.
	 *
	 * Existing consumers (the Calendar block's frontend bundle as of phase 1)
	 * receive this shape. Unchanged contract.
	 *
	 * @param array           $result  CalendarAbilities::executeGetCalendarPage() output with HTML strings.
	 * @param WP_REST_Request $request REST request (used to surface the `past` flag in `navigation.show_past`).
	 * @return array<string,mixed>
	 */
	private function build_html_response( array $result, WP_REST_Request $request ): array {
		return array(
			'success'    => true,
			'html'       => $result['html']['events'],
			'pagination' => array(
				'html'         => $result['html']['pagination'],
				'current_page' => $result['current_page'],
				'max_pages'    => $result['max_pages'],
				'total_events' => $result['total_event_count'],
			),
			'counter'    => $result['html']['counter'],
			'navigation' => array(
				'html'         => $result['html']['navigation'],
				'past_count'   => $result['event_counts']['past'],
				'future_count' => $result['event_counts']['future'],
				'show_past'    => ! empty( $request->get_param( 'past' ) ),
			),
		);
	}

	/**
	 * Build the data-only response envelope (phase 1 of refactor #298).
	 *
	 * Returns structured event objects, pagination / counter / navigation
	 * metadata, gap map, and a `by_date` grouping index. The only HTML is the
	 * canonical no-events recovery fragment when the result is empty.
	 *
	 * Performance note: non-empty responses never call a template loader.
	 * `CalendarAbilities::executeGetCalendarPage()` was invoked with
	 * `include_html: false`, so the ability skipped its full `renderHtml()`
	 * branch. Empty responses render only the canonical recovery fragment.
	 *
	 * Schema: see `docs/calendar-data-schema.md`.
	 *
	 * @param array           $result   CalendarAbilities::executeGetCalendarPage() output (no HTML).
	 * @param CalendarRequest $req      Sanitized request, used for navigation context (`past`) and schema versioning.
	 * @return array<string,mixed>
	 */
	private function build_data_response( array $result, CalendarRequest $req ): array {
		$paged_date_groups = $result['paged_date_groups'] ?? array();
		$gaps_detected     = $result['gaps_detected'] ?? array();

		// Flatten paged_date_groups (already serialized by
		// CalendarAbilities::serializeDateGroups()) into:
		//   - `events`: a deduplicated array of structured event objects.
		//   - `grouping.by_date`: a `Y-m-d => [post_id, ...]` index that
		//     preserves the day-bucket ordering AND multi-day expansion
		//     produced by DateGrouper (a multi-day event appears under
		//     every spanned date, in order).
		//
		// Deduplication is keyed on post_id so a multi-day event ships
		// once in `events` but reappears as needed in `grouping.by_date`.
		// The display_context (is_continuation / is_start_day / day_number)
		// lives on the grouping entry, not on the event itself, because
		// the same post can be a "start day" on one date and a
		// "continuation" on the next.
		//
		// The per-occurrence `display` block (server-computed via
		// DisplayVars::build()) ALSO lives on the grouping entry, not the
		// event, for the same reason: the formatted time string differs by
		// occurrence (a multi-day event reads "7:30 PM" on its start day
		// and "Ongoing · ends Mar 22" on continuation days). Shipping it
		// here keeps DisplayVars the single source of truth for all render
		// paths (PHP template, lazy-render hydration, and the client-side
		// event-renderer) — the client never re-derives time/date strings.
		// See #381.
		$events_by_id  = array();
		$grouping      = array();
		$ordered_dates = array();

		foreach ( $paged_date_groups as $date_group ) {
			$date_key = $date_group['date'] ?? '';
			if ( '' === $date_key ) {
				continue;
			}

			$ordered_dates[]       = $date_key;
			$grouping[ $date_key ] = array();

			foreach ( $date_group['events'] as $event_entry ) {
				$post_id = (int) ( $event_entry['post_id'] ?? 0 );
				if ( $post_id <= 0 ) {
					continue;
				}

				if ( ! isset( $events_by_id[ $post_id ] ) ) {
					$events_by_id[ $post_id ] = $this->serialize_event( $post_id, $event_entry );
				}

				$display_context = $event_entry['display_context'] ?? array();

				$grouping[ $date_key ][] = array(
					'post_id'         => $post_id,
					'display_context' => $display_context,
					'display'         => $this->serialize_display(
						$event_entry['event_data'] ?? array(),
						$display_context
					),
				);
			}
		}

		$events     = array_values( $events_by_id );
		$empty_html = '';
		if ( empty( $ordered_dates ) ) {
			Template_Loader::init();
			$empty_html = EventRenderer::render_date_groups( array() );
		}

		$show_past = $req->past();

		return array(
			'success'    => true,
			'schema'     => array(
				'name'    => 'calendar-data',
				// v2 (#381): each `grouping.by_date` occurrence now carries a
				// server-computed `display` block (DisplayVars::build()) so the
				// client renderer never re-derives time/date strings. The cache
				// key folds in this version so stale v1 buckets (which lack the
				// `display` block) are never served to the v2 client.
				'version' => self::DATA_SCHEMA_VERSION,
				'phase'   => 1,
				'issue'   => 298,
			),
			'events'     => $events,
			'grouping'   => array(
				'ordered_dates' => $ordered_dates,
				'by_date'       => $grouping,
				'gaps'          => $gaps_detected,
			),
			'pagination' => array(
				'current_page' => (int) ( $result['current_page'] ?? 1 ),
				'total_pages'  => (int) ( $result['max_pages'] ?? 1 ),
				'total_items'  => (int) ( $result['total_event_count'] ?? 0 ),
				'page_items'   => (int) ( $result['event_count'] ?? 0 ),
			),
			'counter'    => array(
				'showing_count'   => (int) ( $result['event_count'] ?? 0 ),
				'total_count'     => (int) ( $result['total_event_count'] ?? 0 ),
				'page_start_date' => (string) ( $result['date_boundaries']['start_date'] ?? '' ),
				'page_end_date'   => (string) ( $result['date_boundaries']['end_date'] ?? '' ),
			),
			'navigation' => array(
				'show_past'    => $show_past,
				'past_count'   => (int) ( $result['event_counts']['past'] ?? 0 ),
				'future_count' => (int) ( $result['event_counts']['future'] ?? 0 ),
				'has_past'     => (int) ( $result['event_counts']['past'] ?? 0 ) > 0,
				'has_future'   => (int) ( $result['event_counts']['future'] ?? 0 ) > 0,
			),
			'empty_html' => $empty_html,
		);
	}

	/**
	 * Build the structured event object for the data envelope.
	 *
	 * Pulls authoritative data from `EventHydrator::parse_event_data()` (already
	 * baked into `event_entry['event_data']` by the ability) and supplements
	 * with permalink, post title, venue term metadata, and taxonomy term lists.
	 *
	 * Field choices mirror what the HTML templates render server-side so the
	 * client can produce the same surface without a second round-trip. Anything
	 * the server currently inlines into `data.html` should be representable
	 * here as JSON.
	 *
	 * Schema notes:
	 * - `taxonomies` is a flat `slug => [term_summary, ...]` map, NOT a nested
	 *   structure. Mirrors the shape used by FilterAbilities and keeps the
	 *   client free to render badges / facets without a second mapping pass.
	 * - `venue` is denormalized out of taxonomies into its own field because
	 *   it's a first-class display attribute (every event has at most one
	 *   venue, the templates render it in a fixed slot, the geo system keys
	 *   off it).
	 * - `badges_html` ships the server-rendered, filter-applied badge markup
	 *   (via `Badges::render_taxonomy_badges()`) so client-rendered cards can
	 *   honor the badge-class filters themes hook for styling. The raw
	 *   `taxonomies` map is still provided for any consumer that wants to
	 *   render badges itself. See #381.
	 * - `button_classes` ships the server-filtered "More Info" button class
	 *   list (via the `data_machine_events_more_info_button_classes` filter)
	 *   for the same client-render parity reason. See #381.
	 *
	 * @param int   $post_id     Event post ID.
	 * @param array $event_entry The serialized entry from `paged_date_groups`.
	 * @return array<string,mixed>
	 */
	private function serialize_event( int $post_id, array $event_entry ): array {
		$event_data = $event_entry['event_data'] ?? array();
		$title      = (string) ( $event_entry['title'] ?? get_the_title( $post_id ) );

		return array(
			'id'             => $post_id,
			'title'          => $title,
			'permalink'      => (string) get_permalink( $post_id ),
			'date'           => array(
				'start_date'     => (string) ( $event_data['startDate'] ?? '' ),
				'start_time'     => (string) ( $event_data['startTime'] ?? '' ),
				'end_date'       => (string) ( $event_data['endDate'] ?? '' ),
				'end_time'       => (string) ( $event_data['endTime'] ?? '' ),
				'venue_timezone' => (string) ( $event_data['venueTimezone'] ?? '' ),
			),
			'venue'          => $this->serialize_venue( $post_id, $event_data ),
			'organizer'      => $this->serialize_organizer( $event_data ),
			'ticket'         => array(
				'url' => (string) ( $event_data['ticketUrl'] ?? '' ),
			),
			'performer'      => array(
				'name' => (string) ( $event_data['performerName'] ?? '' ),
			),
			'address'        => (string) ( $event_data['address'] ?? '' ),
			'taxonomies'     => $this->serialize_taxonomies( $post_id ),
			// Server-filtered badge HTML so client-rendered (Load More)
			// cards honor the `data_machine_events_badge_wrapper_classes`
			// and `data_machine_events_badge_classes` filters that themes
			// hook for styling. Without this, the TS renderer rebuilds
			// badges from raw terms and drops the theme classes, leaving
			// appended events with unstyled fallback badges. See #381.
			'badges_html'    => Badges::render_taxonomy_badges( $post_id ),
			// Server-filtered "More Info" button class list so client-rendered
			// (Load More) cards honor the
			// `data_machine_events_more_info_button_classes` filter, mirroring
			// the badge-class parity fix above and the lazy-render path in
			// EventRenderer.php. See #381.
			'button_classes' => implode(
				' ',
				apply_filters(
					'data_machine_events_more_info_button_classes',
					array( 'data-machine-more-info-button' )
				)
			),
		);
	}

	/**
	 * Serialize the per-occurrence display block for the data envelope.
	 *
	 * Thin pass-through over `DisplayVars::build()` — the single source of
	 * truth for every render path's display strings (formatted time range,
	 * multi-day "through"/"Ongoing · ends" labels, ISO start date, decoded
	 * venue/performer names, and the show_* flags). The client-side
	 * `event-renderer.ts` consumes these verbatim instead of re-deriving
	 * time/date/unicode logic in JavaScript, which is what let badge and
	 * time formatting drift between server- and client-rendered cards. See #381.
	 *
	 * Lives per-occurrence (on the `grouping.by_date` entry) rather than on
	 * the event because the formatted time string varies by occurrence for
	 * multi-day events — the same post reads as a timed range on its start
	 * day and "Ongoing · ends X" on continuation days.
	 *
	 * @param array $event_data      Hydrated event_data array for the event.
	 * @param array $display_context Per-occurrence context (is_multi_day / is_continuation / ...).
	 * @return array<string,mixed>
	 */
	private function serialize_display( array $event_data, array $display_context ): array {
		$vars = DisplayVars::build( $event_data, $display_context );

		return array(
			'formatted_time_display' => (string) ( $vars['formatted_time_display'] ?? '' ),
			'multi_day_label'        => (string) ( $vars['multi_day_label'] ?? '' ),
			'iso_start_date'         => (string) ( $vars['iso_start_date'] ?? '' ),
			'venue_name'             => (string) ( $vars['venue_name'] ?? '' ),
			'performer_name'         => (string) ( $vars['performer_name'] ?? '' ),
			'show_performer'         => (bool) ( $vars['show_performer'] ?? false ),
			'show_ticket_link'       => (bool) ( $vars['show_ticket_link'] ?? true ),
			'is_continuation'        => (bool) ( $vars['is_continuation'] ?? false ),
			'is_multi_day'           => (bool) ( $vars['is_multi_day'] ?? false ),
		);
	}

	/**
	 * Serialize the venue term attached to an event, when present.
	 *
	 * Returns null when the event has no venue term, mirroring the optional
	 * nature of the venue slot in the HTML templates.
	 *
	 * @param int   $post_id    Event post ID.
	 * @param array $event_data Already-hydrated event_data array.
	 * @return array<string,mixed>|null
	 */
	private function serialize_venue( int $post_id, array $event_data ): ?array {
		$venue_terms = get_the_terms( $post_id, 'venue' );
		if ( ! $venue_terms || is_wp_error( $venue_terms ) ) {
			return null;
		}
		$term = $venue_terms[0];

		return array(
			'term_id' => (int) $term->term_id,
			'name'    => (string) ( $event_data['venue'] ?? $term->name ),
			'slug'    => (string) $term->slug,
			'address' => (string) ( $event_data['address'] ?? '' ),
		);
	}

	/**
	 * Serialize organizer (promoter) data from the hydrated event_data.
	 *
	 * Returns null when no organizer is attached. Promoter data already
	 * lives on the hydrated event_data via
	 * `EventHydrator::hydrate_promoter_from_taxonomy()`.
	 *
	 * @param array $event_data Hydrated event_data array.
	 * @return array<string,mixed>|null
	 */
	private function serialize_organizer( array $event_data ): ?array {
		if ( empty( $event_data['organizer'] ) ) {
			return null;
		}
		return array(
			'name' => (string) $event_data['organizer'],
			'url'  => (string) ( $event_data['organizerUrl'] ?? '' ),
			'type' => (string) ( $event_data['organizerType'] ?? '' ),
		);
	}

	/**
	 * Serialize taxonomy term assignments for an event.
	 *
	 * Honors the `data_machine_events_excluded_taxonomies` filter (context:
	 * 'badge') so the data envelope matches what the badge HTML would have
	 * surfaced. Returns a `slug => [{term_id, name, slug, link}, ...]` map.
	 *
	 * @param int $post_id Event post ID.
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function serialize_taxonomies( int $post_id ): array {
		$taxonomies = Badges::get_event_taxonomies( $post_id );
		$out        = array();

		foreach ( $taxonomies as $taxonomy_slug => $bundle ) {
			$terms = $bundle['terms'] ?? array();
			$list  = array();
			foreach ( $terms as $term ) {
				$link   = get_term_link( $term, $taxonomy_slug );
				$list[] = array(
					'term_id' => (int) $term->term_id,
					'name'    => (string) $term->name,
					'slug'    => (string) $term->slug,
					'link'    => is_wp_error( $link ) ? '' : (string) $link,
				);
			}
			if ( ! empty( $list ) ) {
				$out[ $taxonomy_slug ] = $list;
			}
		}

		return $out;
	}
}
