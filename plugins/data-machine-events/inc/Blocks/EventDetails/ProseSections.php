<?php
/**
 * Event Details prose sections (issue #830).
 *
 * Renders derivable in-content prose paragraphs on event pages so the page
 * has ad-insertion slots (substantial <p> elements) and reader-oriented
 * internal links. Everything is derived at render time from data already in
 * the system — no pipeline changes, no post_content growth.
 *
 * Data sources, all through existing primitives:
 *
 *  - Other dates on this tour   → artist term + the canonical
 *                                 data_machine_events_query_events() query.
 *  - Venue context              → Venue_Taxonomy::get_venue_data() (city/state)
 *                                 plus a bounded past-events query.
 *  - Artist history in city     → bounded past-events query for the artist,
 *                                 city-matched through each event's venue term.
 *
 * Upcoming shows at the same venue are deliberately not a section: the theme's
 * "More at {venue}" related-events cards already list them, and repeating that
 * list as prose would duplicate the page.
 *
 * The sections are the renderer's default and only behavior: each one degrades
 * to nothing when its data is missing, so a section with no rows emits zero
 * bytes — no empty headings, no orphan wrappers.
 *
 * Caching rides the calendar cache generation: payloads are stored as
 * transients keyed with CalendarCache::get_generation(), so any event save,
 * term change, or venue edit (CacheInvalidator) drops them automatically.
 *
 * Query budget per cold render: 3 bounded section queries (page sizes 4/2/8
 * over the indexed event-date + term-relationship joins), per-post date point
 * lookups for displayed rows, and one batched venue-term lookup. A warm cache
 * hit performs zero section queries.
 *
 * @package DataMachineEvents\Blocks\EventDetails
 * @since   0.63.0
 */

namespace DataMachineEvents\Blocks\EventDetails;

use DataMachineEvents\Blocks\Calendar\Cache\CalendarCache;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ProseSections {

	private const CACHE_TTL         = HOUR_IN_SECONDS;
	private const TOUR_LIMIT        = 4;
	private const VENUE_PAST_LIMIT  = 2;
	private const ARTIST_PAST_LIMIT = 8;
	private const MAX_LINKS         = 3;

	/**
	 * Render the prose sections HTML for an event page.
	 *
	 * Returns an empty string when the post is not an event or no section has
	 * data — callers may echo the result directly.
	 *
	 * @param int   $post_id Event post ID.
	 * @param array $context Optional pre-resolved renderer context:
	 *                       venue_term_id (int) and venue_data (array) to
	 *                       skip re-resolving what render.php already fetched.
	 * @return string HTML or ''.
	 */
	public static function render( int $post_id, array $context = array() ): string {
		if ( $post_id < 1 ) {
			return '';
		}

		if ( ! function_exists( 'data_machine_events_query_events' ) ) {
			return '';
		}

		$sections = self::sections_for_post( $post_id, $context );
		if ( empty( $sections ) ) {
			return '';
		}

		$out = '<div class="event-details-prose">';
		foreach ( $sections as $section ) {
			$out .= '<section class="event-prose-section event-prose--' . esc_attr( $section['id'] ) . '">';
			$out .= '<h2 class="event-prose-heading">' . esc_html( $section['heading'] ) . '</h2>';
			foreach ( $section['paragraphs'] as $paragraph ) {
				$out .= '<p>' . wp_kses_post( $paragraph ) . '</p>';
			}
			$out .= '</section>';
		}
		$out .= '</div>';

		return $out;
	}

	/**
	 * Build the section list for one post: cache read → data build → section data.
	 *
	 * @param int   $post_id Event post ID.
	 * @param array $context Pre-resolved context (venue_term_id, venue_data).
	 * @return array[] Each { id, heading, paragraphs[] }.
	 */
	private static function sections_for_post( int $post_id, array $context ): array {
		if ( Event_Post_Type::POST_TYPE !== get_post_type( $post_id ) ) {
			return array();
		}

		$payload = self::payload( $post_id, $context );

		return self::build_sections( $payload );
	}

	/**
	 * Cached data payload for one post, keyed on the calendar cache generation.
	 *
	 * @param int   $post_id Event post ID.
	 * @param array $context Pre-resolved context.
	 * @return array
	 */
	private static function payload( int $post_id, array $context ): array {
		$generation = CalendarCache::get_generation();
		$storage    = 'data-machine-events-prose-' . $post_id . '_' . $generation;

		$cached = get_transient( $storage );
		if ( is_array( $cached ) && isset( $cached['generation'] ) && $cached['generation'] === $generation ) {
			return $cached;
		}

		$payload               = self::build_payload( $post_id, $context );
		$payload['generation'] = $generation;

		set_transient( $storage, $payload, self::CACHE_TTL );

		return $payload;
	}

	/**
	 * Query the data slices through the canonical query primitive.
	 *
	 * @param int   $post_id Event post ID.
	 * @param array $context Pre-resolved context.
	 * @return array Raw slice data (may be mostly empty).
	 */
	private static function build_payload( int $post_id, array $context ): array {
		$payload = array(
			'artist_name'     => '',
			'artist_link'     => '',
			'venue_name'      => '',
			'city'            => '',
			'state'           => '',
			'tour_events'     => array(),
			'venue_past'      => array(),
			'artist_past'     => array(),
			'artist_city_map' => array(),
		);

		$venue_term_id = isset( $context['venue_term_id'] ) ? (int) $context['venue_term_id'] : 0;
		$venue_data    = isset( $context['venue_data'] ) && is_array( $context['venue_data'] ) && ! empty( $context['venue_data'] )
			? $context['venue_data']
			: null;

		if ( $venue_term_id < 1 ) {
			$venue_terms = get_the_terms( $post_id, 'venue' );
			if ( $venue_terms && ! is_wp_error( $venue_terms ) ) {
				$venue_term_id = (int) $venue_terms[0]->term_id;
			}
		}

		if ( $venue_term_id > 0 && ! is_array( $venue_data ) ) {
			$venue_data = Venue_Taxonomy::get_venue_data( $venue_term_id );
		}

		if ( is_array( $venue_data ) && ! empty( $venue_data ) ) {
			$payload['venue_name'] = (string) ( $venue_data['name'] ?? '' );
			$payload['city']       = trim( (string) ( $venue_data['city'] ?? '' ) );
			$payload['state']      = trim( (string) ( $venue_data['state'] ?? '' ) );
		}

		$artist_term_id = 0;
		if ( taxonomy_exists( 'artist' ) ) {
			$artist_terms = get_the_terms( $post_id, 'artist' );
			if ( $artist_terms && ! is_wp_error( $artist_terms ) ) {
				$artist_term_id         = (int) $artist_terms[0]->term_id;
				$payload['artist_name'] = (string) $artist_terms[0]->name;
				$link                   = get_term_link( $artist_terms[0] );
				if ( is_string( $link ) ) {
					$payload['artist_link'] = $link;
				}
			}
		}

		if ( $artist_term_id > 0 ) {
			$artist_filter          = array( 'artist' => array( $artist_term_id ) );
			$payload['tour_events'] = self::upcoming_events( $artist_filter, $post_id, self::TOUR_LIMIT );
			$payload['artist_past'] = self::past_event_ids( $artist_filter, $post_id, self::ARTIST_PAST_LIMIT );
		}

		if ( $venue_term_id > 0 ) {
			$venue_filter          = array( 'venue' => array( $venue_term_id ) );
			$payload['venue_past'] = self::hydrate_events( self::past_event_ids( $venue_filter, $post_id, self::VENUE_PAST_LIMIT ) );
		}

		if ( $venue_term_id > 0 && '' !== $payload['city'] && ! empty( $payload['artist_past'] ) ) {
			$payload['artist_city_map'] = self::venue_city_map( $payload['artist_past'] );
		}

		return $payload;
	}

	/**
	 * Turn the raw payload into renderable sections.
	 *
	 * @param array $payload Raw payload.
	 * @return array[] Sections with id, heading, paragraphs.
	 */
	private static function build_sections( array $payload ): array {
		$sections = array();

		$tour = self::tour_paragraphs( $payload );
		if ( ! empty( $tour ) ) {
			$sections[] = array(
				'id'         => 'tour',
				'heading'    => sprintf(
					/* translators: %s: artist name. */
					__( 'More from %s', 'data-machine-events' ),
					$payload['artist_name']
				),
				'paragraphs' => $tour,
			);
		}

		$venue_ctx = self::venue_context_paragraphs( $payload );
		if ( ! empty( $venue_ctx ) ) {
			$sections[] = array(
				'id'         => 'venue-context',
				'heading'    => sprintf(
					/* translators: %s: venue name. */
					__( 'About %s', 'data-machine-events' ),
					$payload['venue_name']
				),
				'paragraphs' => $venue_ctx,
			);
		}

		$artist_city = self::artist_city_paragraph( $payload );
		if ( ! empty( $artist_city ) ) {
			$sections[] = array(
				'id'         => 'artist-city',
				'heading'    => sprintf(
					/* translators: 1: artist name, 2: city name. */
					__( '%1$s in %2$s', 'data-machine-events' ),
					$payload['artist_name'],
					$payload['city']
				),
				'paragraphs' => $artist_city,
			);
		}

		return $sections;
	}

	/**
	 * "Other dates on this tour" paragraphs.
	 *
	 * @param array $payload Raw payload.
	 * @return string[] Paragraph HTML pieces, or [] when no data.
	 */
	private static function tour_paragraphs( array $payload ): array {
		if ( '' === $payload['artist_name'] || empty( $payload['tour_events'] ) ) {
			return array();
		}

		$list = self::linked_event_list( $payload['tour_events'] );
		if ( '' === $list ) {
			return array();
		}

		$sentence = sprintf(
			/* translators: 1: artist name, 2: linked upcoming event list. */
			__( '%1$s has more upcoming shows on the calendar, including %2$s.', 'data-machine-events' ),
			esc_html( $payload['artist_name'] ),
			$list
		);

		if ( '' !== $payload['artist_link'] ) {
			$sentence .= ' ' . sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $payload['artist_link'] ),
				sprintf(
					/* translators: %s: artist name. */
					esc_html__( 'See all upcoming %s shows.', 'data-machine-events' ),
					esc_html( $payload['artist_name'] )
				)
			);
		}

		return array( $sentence );
	}

	/**
	 * Venue context paragraphs: location plus recent shows, when derivable.
	 *
	 * @param array $payload Raw payload.
	 * @return string[] Paragraph HTML pieces, or [] when no data.
	 */
	private static function venue_context_paragraphs( array $payload ): array {
		if ( '' === $payload['venue_name'] ) {
			return array();
		}

		$paragraphs = array();

		if ( '' !== $payload['city'] ) {
			$place = '' !== $payload['state']
				? $payload['city'] . ', ' . $payload['state']
				: $payload['city'];

			$paragraphs[] = sprintf(
				/* translators: 1: venue name, 2: city (and state). */
				__( '%1$s is located in %2$s.', 'data-machine-events' ),
				esc_html( $payload['venue_name'] ),
				esc_html( $place )
			);
		}

		if ( ! empty( $payload['venue_past'] ) ) {
			$list = self::linked_event_list( $payload['venue_past'] );
			if ( '' !== $list ) {
				$paragraphs[] = sprintf(
					/* translators: 1: venue name, 2: linked recent event list. */
					__( 'Recent shows at %1$s include %2$s.', 'data-machine-events' ),
					esc_html( $payload['venue_name'] ),
					$list
				);
			}
		}

		return $paragraphs;
	}

	/**
	 * Artist history in the current city paragraph, when derivable cheaply.
	 *
	 * Uses only the bounded artist-past slice plus venue-city matching over
	 * those rows — no cross-taxonomy query. The artist-past slice is ordered
	 * most-recent-first, so the first city match is the most recent one.
	 *
	 * @param array $payload Raw payload.
	 * @return string[] Paragraph HTML pieces, or [] when no data.
	 */
	private static function artist_city_paragraph( array $payload ): array {
		if ( '' === $payload['artist_name'] || '' === $payload['city'] || empty( $payload['artist_past'] ) ) {
			return array();
		}

		foreach ( $payload['artist_past'] as $candidate_post_id ) {
			$venue = $payload['artist_city_map'][ $candidate_post_id ] ?? null;
			if ( null === $venue || '' === $venue['city'] || 0 !== strcasecmp( $venue['city'], $payload['city'] ) ) {
				continue;
			}

			$rows = self::hydrate_events( array( $candidate_post_id ) );
			if ( empty( $rows ) ) {
				continue;
			}

			$row = $rows[0];

			return array(
				sprintf(
					/* translators: 1: artist name, 2: city, 3: linked past event, 4: venue name. */
					__( '%1$s has a history in %2$s — most recently %3$s at %4$s.', 'data-machine-events' ),
					esc_html( $payload['artist_name'] ),
					esc_html( $payload['city'] ),
					self::event_link( $row ),
					esc_html( (string) $venue['name'] )
				),
			);
		}

		return array();
	}

	/**
	 * Query upcoming events for a taxonomy filter, excluding the current post.
	 *
	 * @param array $tax_filters { taxonomy => [term_ids] }.
	 * @param int   $post_id     Current post ID to exclude.
	 * @param int   $limit       Bounded page size.
	 * @return array[] Hydrated event rows.
	 */
	private static function upcoming_events( array $tax_filters, int $post_id, int $limit ): array {
		$result = data_machine_events_query_events(
			array(
				'scope'       => 'upcoming',
				'tax_filters' => $tax_filters,
				'exclude'     => array( $post_id ),
				'per_page'    => $limit,
				'fields'      => 'ids',
				'order'       => 'ASC',
			)
		);

		return self::hydrate_events( array_map( 'intval', (array) ( $result['posts'] ?? array() ) ) );
	}

	/**
	 * Query past event IDs for a taxonomy filter, most recent first.
	 *
	 * @param array $tax_filters { taxonomy => [term_ids] }.
	 * @param int   $post_id     Current post ID to exclude.
	 * @param int   $limit       Bounded page size.
	 * @return int[] Post IDs.
	 */
	private static function past_event_ids( array $tax_filters, int $post_id, int $limit ): array {
		$result = data_machine_events_query_events(
			array(
				'scope'       => 'past',
				'tax_filters' => $tax_filters,
				'exclude'     => array( $post_id ),
				'per_page'    => $limit,
				'fields'      => 'ids',
				'order'       => 'DESC',
			)
		);

		return array_map( 'intval', (array) ( $result['posts'] ?? array() ) );
	}

	/**
	 * Hydrate post IDs into bounded link rows (title, permalink, date).
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array[] Rows; unpublished or undated posts are dropped.
	 */
	private static function hydrate_events( array $post_ids ): array {
		$rows = array();
		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
				continue;
			}

			$permalink = get_permalink( $post );
			$dates     = EventDatesTable::get( $post_id );
			if ( '' === $permalink || ! $dates || empty( $dates->start_datetime ) ) {
				continue;
			}

			$rows[] = array(
				'post_id'   => $post_id,
				'title'     => get_the_title( $post ),
				'permalink' => $permalink,
				'date'      => date_i18n( get_option( 'date_format' ), strtotime( (string) $dates->start_datetime ) ),
			);
		}

		return $rows;
	}

	/**
	 * Map post IDs to their first venue term's city and name.
	 *
	 * Primes the object-term cache for the whole ID list (one grouped
	 * term-relationship query), then reads each post's first venue term from
	 * cache. Venue meta reads ride WordPress's term-meta cache.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array { post_id => { city: string, name: string } }
	 */
	private static function venue_city_map( array $post_ids ): array {
		$map = array();
		if ( empty( $post_ids ) ) {
			return $map;
		}

		_prime_post_caches( $post_ids, true, false );

		$term_cache = array();
		foreach ( $post_ids as $post_id ) {
			$terms = get_the_terms( $post_id, 'venue' );
			if ( ! $terms || is_wp_error( $terms ) ) {
				continue;
			}

			$term_id = (int) $terms[0]->term_id;
			if ( ! isset( $term_cache[ $term_id ] ) ) {
				$data                   = Venue_Taxonomy::get_venue_data( $term_id );
				$term_cache[ $term_id ] = array(
					'city' => trim( (string) ( $data['city'] ?? '' ) ),
					'name' => (string) ( $data['name'] ?? '' ),
				);
			}

			$map[ $post_id ] = $term_cache[ $term_id ];
		}

		return $map;
	}

	/**
	 * Build a comma list of linked "Title (Date)" items, capped at MAX_LINKS.
	 *
	 * @param array[] $rows Hydrated rows (most relevant first).
	 * @return string HTML list fragment or '' when nothing linkable.
	 */
	private static function linked_event_list( array $rows ): string {
		$items = array();
		foreach ( $rows as $row ) {
			$link = self::event_link( $row );
			if ( '' !== $link ) {
				$items[] = $link . ' (' . esc_html( $row['date'] ) . ')';
			}
			if ( count( $items ) >= self::MAX_LINKS ) {
				break;
			}
		}

		if ( empty( $items ) ) {
			return '';
		}

		if ( 1 === count( $items ) ) {
			return $items[0];
		}

		$last = array_pop( $items );

		return implode( ', ', $items ) . ' ' . __( 'and', 'data-machine-events' ) . ' ' . $last;
	}

	/**
	 * One escaped anchor for a hydrated row.
	 *
	 * @param array $row Hydrated row.
	 * @return string Anchor HTML or ''.
	 */
	private static function event_link( array $row ): string {
		if ( empty( $row['permalink'] ) || empty( $row['title'] ) ) {
			return '';
		}

		return '<a href="' . esc_url( (string) $row['permalink'] ) . '">' . esc_html( (string) $row['title'] ) . '</a>';
	}
}
