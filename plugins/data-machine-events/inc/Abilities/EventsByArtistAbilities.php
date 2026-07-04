<?php
/**
 * Events By Artist Abilities
 *
 * Cross-site-callable primitive that returns the LIST of events tagged to an
 * artist term on the events site. The whole point is the CONSUMER (e.g. the
 * artist profile hub on a different blog) can call this ability even though
 * this plugin's PHP is NOT loaded there — switch_to_blog() changes the DB
 * context, not the loaded code, so a consumer on another blog cannot call
 * data_machine_events_query_events() directly. The ability is the bridge.
 *
 * It internally switches to the events blog (resolved via ec_get_blog_id when
 * available, with a filterable fallback), reads the datamachine_event_dates
 * table + venue/date data directly, and returns a PLAIN STRUCTURED ARRAY with
 * every presentational string (title, permalink, venue name, formatted date /
 * time) pre-resolved while still in events-blog context — because the caller
 * renders on a DIFFERENT blog and cannot resolve those afterward.
 *
 * Layer purity: this ability is generic "events for artist term X on this
 * events site". It carries no consumer-specific identity and returns data,
 * not markup — presentation is the consumer's job.
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\EventDatesTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventsByArtistAbilities {

	private static bool $registered = false;

	/**
	 * Default per-scope result limit when the caller omits `limit`.
	 */
	private const DEFAULT_LIMIT = 12;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'data-machine-events/events-by-artist',
				array(
					'label'               => __( 'Events By Artist', 'data-machine-events' ),
					'description'         => __( 'Return the list of events for an artist term on the events site, split into upcoming and past. Cross-site callable: resolves everything (permalinks, venue names, formatted dates) on the events blog so consumers on any other site can render the result directly.', 'data-machine-events' ),
					'category'            => 'datamachine-events-events',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'artist_slug' ),
						'properties' => array(
							'artist_slug' => array(
								'type'        => 'string',
								'description' => __( 'Artist term slug (the canonical cross-blog join key) to look up on the events site.', 'data-machine-events' ),
							),
							'scope'       => array(
								'type'        => 'string',
								'enum'        => array( 'upcoming', 'past', 'all' ),
								'description' => __( 'Which events to return. Default all.', 'data-machine-events' ),
							),
							'limit'       => array(
								'type'        => 'integer',
								'description' => __( 'Maximum events to return per scope (upcoming and past are limited independently). Default 12.', 'data-machine-events' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'artist_slug' => array( 'type' => 'string' ),
							'found'       => array( 'type' => 'boolean' ),
							'upcoming'    => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
							'past'        => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
						),
					),
					'execute_callback'    => array( $this, 'executeEventsByArtist' ),
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
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute the events-by-artist ability.
	 *
	 * Switches to the events blog, resolves the artist term by slug, queries
	 * the event_dates table for that term split by now, and returns a plain
	 * structured array with presentational strings pre-resolved.
	 *
	 * @param array $input Input parameters.
	 * @return array|\WP_Error { artist_slug, found, upcoming: [...], past: [...] }
	 */
	public function executeEventsByArtist( array $input ): array|\WP_Error {
		$artist_slug = isset( $input['artist_slug'] ) ? sanitize_title( (string) $input['artist_slug'] ) : '';
		$scope       = $input['scope'] ?? 'all';
		if ( ! in_array( $scope, array( 'upcoming', 'past', 'all' ), true ) ) {
			$scope = 'all';
		}
		$limit = isset( $input['limit'] ) ? absint( $input['limit'] ) : self::DEFAULT_LIMIT;
		if ( $limit < 1 ) {
			$limit = self::DEFAULT_LIMIT;
		}

		if ( '' === $artist_slug ) {
			return new \WP_Error(
				'invalid_artist_slug',
				__( 'A non-empty artist_slug is required.', 'data-machine-events' ),
				array( 'status' => 400 )
			);
		}

		$events_blog_id = $this->resolveEventsBlogId();
		if ( ! $events_blog_id ) {
			return new \WP_Error(
				'events_site_unresolved',
				__( 'Could not resolve the events site blog ID.', 'data-machine-events' ),
				array( 'status' => 500 )
			);
		}

		switch_to_blog( $events_blog_id );
		try {
			return $this->collectEventsForArtist( $artist_slug, $scope, $limit );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Resolve the events site blog ID.
	 *
	 * Prefers the network helper ec_get_blog_id('events') when available.
	 * A filter provides a graceful override / fallback for installs where
	 * that helper is not present, keeping this plugin generic (it does not
	 * hard-code any site's blog ID).
	 *
	 * @return int Blog ID, or 0 when unresolved.
	 */
	private function resolveEventsBlogId(): int {
		$blog_id = 0;

		if ( function_exists( 'ec_get_blog_id' ) ) {
			$blog_id = (int) ec_get_blog_id( 'events' );
		}

		/**
		 * Filter the resolved events-site blog ID for the events-by-artist ability.
		 *
		 * Lets non-Extra-Chill installs (or tests) point the ability at the
		 * blog that actually holds event posts without hard-coding an ID in
		 * this generic plugin. Return a positive integer blog ID.
		 *
		 * @param int $blog_id Blog ID resolved from ec_get_blog_id('events'), or 0.
		 */
		$blog_id = (int) apply_filters( 'data_machine_events_events_blog_id', $blog_id );

		return $blog_id > 0 ? $blog_id : 0;
	}

	/**
	 * Collect upcoming and past events for an artist term.
	 *
	 * Must be called in events-blog context (after switch_to_blog). Reads the
	 * event_dates table joined to term_relationships so past/upcoming split
	 * comes straight from start_datetime vs now, then pre-resolves every
	 * presentational string per event.
	 *
	 * @param string $artist_slug Artist term slug.
	 * @param string $scope       upcoming|past|all.
	 * @param int    $limit       Per-scope result limit.
	 * @return array Structured result.
	 */
	private function collectEventsForArtist( string $artist_slug, string $scope, int $limit ): array {
		$empty = array(
			'artist_slug' => $artist_slug,
			'found'       => false,
			'upcoming'    => array(),
			'past'        => array(),
		);

		if ( ! taxonomy_exists( 'artist' ) ) {
			return $empty;
		}

		$term = get_term_by( 'slug', $artist_slug, 'artist' );
		if ( ! $term || is_wp_error( $term ) ) {
			return $empty;
		}

		$result = array(
			'artist_slug' => $artist_slug,
			'found'       => true,
			'upcoming'    => array(),
			'past'        => array(),
		);

		if ( 'past' !== $scope ) {
			$result['upcoming'] = $this->queryScope( (int) $term->term_id, 'upcoming', $limit );
		}
		if ( 'upcoming' !== $scope ) {
			$result['past'] = $this->queryScope( (int) $term->term_id, 'past', $limit );
		}

		return $result;
	}

	/**
	 * Query one scope (upcoming or past) of events for an artist term.
	 *
	 * Reads post IDs directly from the event_dates table joined to
	 * term_relationships / term_taxonomy, filtered by start_datetime vs now
	 * and post_status = 'publish'. Upcoming is ordered soonest-first; past is
	 * ordered most-recent-first. Each event is then hydrated into a plain
	 * presentational array.
	 *
	 * @param int    $term_id Artist term ID.
	 * @param string $scope   upcoming|past.
	 * @param int    $limit   Max events to return.
	 * @return array List of event arrays.
	 */
	private function queryScope( int $term_id, string $scope, int $limit ): array {
		global $wpdb;

		$ed_table = EventDatesTable::table_name();
		$now      = current_time( 'mysql' );

		// Two fully-literal query strings keep the date comparator and sort
		// direction out of variable interpolation so $wpdb->prepare() sees a
		// stable placeholder count. The only interpolated token is the table
		// name (a trusted internal constant), matching the sibling
		// UpcomingCountAbilities pattern.
		$timing = ( 'upcoming' === $scope ) ? 'upcoming' : 'past';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $ed_table is a trusted internal constant; the two literal query strings keep placeholder counts stable.
		if ( 'upcoming' === $scope ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ed.post_id AS post_id, ed.start_datetime AS start_datetime
					FROM {$ed_table} ed
					INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = ed.post_id
					INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
					WHERE tt.taxonomy = 'artist'
					AND tt.term_id = %d
					AND ed.post_status = 'publish'
					AND ed.start_datetime >= %s
					ORDER BY ed.start_datetime ASC
					LIMIT %d",
					$term_id,
					$now,
					$limit
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ed.post_id AS post_id, ed.start_datetime AS start_datetime
					FROM {$ed_table} ed
					INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = ed.post_id
					INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
					WHERE tt.taxonomy = 'artist'
					AND tt.term_id = %d
					AND ed.post_status = 'publish'
					AND ed.start_datetime < %s
					ORDER BY ed.start_datetime DESC
					LIMIT %d",
					$term_id,
					$now,
					$limit
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $rows ) ) {
			return array();
		}

		$events = array();
		foreach ( $rows as $row ) {
			$event = $this->hydrateEvent( (int) $row->post_id, (string) $row->start_datetime, $timing );
			if ( null !== $event ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	/**
	 * Hydrate a single event post into a plain presentational array.
	 *
	 * Resolves title, permalink, venue name, and formatted date / time strings
	 * while in events-blog context so the consumer (on another blog) can render
	 * them directly without re-resolving anything.
	 *
	 * @param int    $post_id        Event post ID.
	 * @param string $start_datetime MySQL start datetime from the event_dates table.
	 * @param string $timing         'upcoming' | 'past'.
	 * @return array|null Event array, or null when the post is unavailable.
	 */
	private function hydrateEvent( int $post_id, string $start_datetime, string $timing ): ?array {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return null;
		}

		$permalink = get_permalink( $post_id );
		if ( ! $permalink || is_wp_error( $permalink ) ) {
			return null;
		}

		$venue_name  = '';
		$venue_terms = get_the_terms( $post_id, 'venue' );
		if ( $venue_terms && ! is_wp_error( $venue_terms ) ) {
			$venue_name = html_entity_decode( (string) $venue_terms[0]->name, ENT_QUOTES, 'UTF-8' );
		}

		$timestamp    = strtotime( $start_datetime );
		$date_iso     = $timestamp ? gmdate( 'c', $timestamp ) : '';
		$date_display = $timestamp ? date_i18n( get_option( 'date_format' ), $timestamp ) : '';

		// A midnight start time is the sentinel for "no known time"; only emit
		// a time string when the event actually carries one.
		$time_display = '';
		if ( $timestamp && '00:00:00' !== gmdate( 'H:i:s', $timestamp ) ) {
			$time_display = date_i18n( get_option( 'time_format' ), $timestamp );
		}

		return array(
			'event_id'     => $post_id,
			'title'        => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
			'permalink'    => $permalink,
			'venue_name'   => $venue_name,
			'date_iso'     => $date_iso,
			'date_display' => $date_display,
			'time_display' => $time_display,
			'timing'       => $timing,
		);
	}
}
