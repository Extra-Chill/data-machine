<?php
/**
 * Events By Term Abilities
 *
 * Returns the list of events assigned to a term in any registered taxonomy on
 * the configured events site.
 *
 * It internally switches to the configured events blog (the current site by
 * default), reads the datamachine_event_dates
 * table + venue/date data directly, and returns a PLAIN STRUCTURED ARRAY with
 * every presentational string (title, permalink, venue name, formatted date /
 * time) pre-resolved while still in events-blog context — because the caller
 * renders on a DIFFERENT blog and cannot resolve those afterward.
 *
 * The ability is taxonomy-agnostic and returns data rather than markup.
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\EventDatesTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventsByTermAbilities {

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
				'data-machine-events/events-by-term',
				array(
					'label'               => __( 'Events By Term', 'data-machine-events' ),
					'description'         => __( 'Return the list of events for a local term in any registered taxonomy, split into upcoming and past.', 'data-machine-events' ),
					'category'            => 'datamachine-events-events',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'taxonomy' ),
						'properties' => array(
							'taxonomy'  => array(
								'type'        => 'string',
								'description' => __( 'Registered taxonomy name on the events site.', 'data-machine-events' ),
							),
							'term_id'   => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => __( 'Positive local term ID. When supplied, this takes precedence over term_slug.', 'data-machine-events' ),
							),
							'term_slug' => array(
								'type'        => 'string',
								'description' => __( 'Local term slug. Required when term_id is omitted.', 'data-machine-events' ),
							),
							'scope'     => array(
								'type'        => 'string',
								'enum'        => array( 'upcoming', 'past', 'all' ),
								'description' => __( 'Which events to return. Default all.', 'data-machine-events' ),
							),
							'limit'     => array(
								'type'        => 'integer',
								'description' => __( 'Maximum events to return per scope (upcoming and past are limited independently). Default 12.', 'data-machine-events' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'taxonomy'  => array( 'type' => 'string' ),
							'term_id'   => array( 'type' => 'integer' ),
							'term_slug' => array( 'type' => 'string' ),
							'found'     => array( 'type' => 'boolean' ),
							'upcoming'  => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
							'past'      => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
						),
					),
					'execute_callback'    => array( $this, 'executeEventsByTerm' ),
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

		add_action( 'wp_abilities_api_init', $register_callback );
	}

	/**
	 * Execute the events-by-term ability.
	 *
	 * Switches to the events blog, resolves the local term in the requested
	 * taxonomy, queries the event_dates table for that term split by now, and
	 * returns a plain structured array with presentational strings pre-resolved.
	 *
	 * @param array $input Input parameters.
	 * @return array|\WP_Error { taxonomy, term_id, term_slug, found, upcoming: [...], past: [...] }
	 */
	public function executeEventsByTerm( array $input ): array|\WP_Error {
		$taxonomy         = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
		$term_id_supplied = array_key_exists( 'term_id', $input );
		$term_id          = $term_id_supplied ? (int) $input['term_id'] : 0;
		$term_slug        = isset( $input['term_slug'] ) ? sanitize_title( (string) $input['term_slug'] ) : '';
		$scope            = $input['scope'] ?? 'all';
		if ( ! in_array( $scope, array( 'upcoming', 'past', 'all' ), true ) ) {
			$scope = 'all';
		}
		$limit = isset( $input['limit'] ) ? absint( $input['limit'] ) : self::DEFAULT_LIMIT;
		if ( $limit < 1 ) {
			$limit = self::DEFAULT_LIMIT;
		}

		if ( '' === $taxonomy ) {
			return new \WP_Error(
				'invalid_taxonomy',
				__( 'A non-empty taxonomy is required.', 'data-machine-events' ),
				array( 'status' => 400 )
			);
		}

		if ( $term_id_supplied && $term_id < 1 ) {
			return new \WP_Error(
				'invalid_term_id',
				__( 'term_id must be a positive local term ID.', 'data-machine-events' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $term_id_supplied && '' === $term_slug ) {
			return new \WP_Error(
				'invalid_term_slug',
				__( 'A non-empty term_slug is required when term_id is omitted.', 'data-machine-events' ),
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
			$result = $this->collectEventsForTerm( $taxonomy, $term_id, $term_slug, $scope, $limit );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			/**
			 * Filter events-by-term results in the canonical events-site context.
			 *
			 * Consumers can compose domain-owned relationship data while term and
			 * permalink APIs still resolve against the events site. This primitive
			 * remains taxonomy-agnostic: it neither assumes nor declares consumers'
			 * response fields.
			 *
			 * @param array $result Hydrated events-by-term response.
			 * @param array $input  Normalized ability input.
			 */
			return apply_filters( 'data_machine_events_events_by_term_result', $result, $input );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Resolve the events site blog ID.
	 *
	 * Defaults to the current site so standalone installs work without any
	 * configuration. Consumers that keep events on another site can override
	 * the target through the filter below.
	 *
	 * @return int Blog ID, or 0 when unresolved.
	 */
	private function resolveEventsBlogId(): int {
		$blog_id = get_current_blog_id();

		/**
		 * Filter the resolved events-site blog ID for the events-by-term ability.
		 *
		 * Lets consumers point the ability at the blog that holds event posts.
		 * Return a positive integer blog ID.
		 *
		 * @param int $blog_id Current-site blog ID by default.
		 */
		$blog_id = (int) apply_filters( 'data_machine_events_events_blog_id', $blog_id );

		return $blog_id > 0 ? $blog_id : 0;
	}

	/**
	 * Collect upcoming and past events for a term in a taxonomy.
	 *
	 * Must be called in events-blog context (after switch_to_blog). Reads the
	 * event_dates table joined to term_relationships so past/upcoming split
	 * comes straight from start_datetime vs now, then pre-resolves every
	 * presentational string per event.
	 *
	 * @param string $taxonomy  Taxonomy name on the events site.
	 * @param int    $term_id   Local term ID, or zero when resolving by slug.
	 * @param string $term_slug Local term slug to look up when no ID is supplied.
	 * @param string $scope     upcoming|past|all.
	 * @param int    $limit     Per-scope result limit.
	 * @return array|\WP_Error Structured result or invalid-term error.
	 */
	private function collectEventsForTerm( string $taxonomy, int $term_id, string $term_slug, string $scope, int $limit ): array|\WP_Error {
		$empty = array(
			'taxonomy'  => $taxonomy,
			'term_id'   => 0,
			'term_slug' => $term_slug,
			'found'     => false,
			'upcoming'  => array(),
			'past'      => array(),
		);

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return $empty;
		}

		$term = $term_id > 0
			? get_term( $term_id, $taxonomy )
			: get_term_by( 'slug', $term_slug, $taxonomy );

		if ( $term_id > 0 && ( ! $term || is_wp_error( $term ) ) ) {
			return new \WP_Error(
				'invalid_term_id',
				__( 'term_id does not exist in the requested taxonomy.', 'data-machine-events' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $term || is_wp_error( $term ) ) {
			return $empty;
		}

		$result = array(
			'taxonomy'  => $taxonomy,
			'term_id'   => (int) $term->term_id,
			'term_slug' => (string) $term->slug,
			'found'     => true,
			'upcoming'  => array(),
			'past'      => array(),
		);

		if ( 'past' !== $scope ) {
			$result['upcoming'] = $this->queryScope( (int) $term->term_id, $taxonomy, 'upcoming', $limit );
		}
		if ( 'upcoming' !== $scope ) {
			$result['past'] = $this->queryScope( (int) $term->term_id, $taxonomy, 'past', $limit );
		}

		return $result;
	}

	/**
	 * Query one scope (upcoming or past) of events for a term.
	 *
	 * Reads post IDs directly from the event_dates table joined to
	 * term_relationships / term_taxonomy, filtered by start_datetime vs now
	 * and post_status = 'publish'. Upcoming is ordered soonest-first; past is
	 * ordered most-recent-first. Each event is then hydrated into a plain
	 * presentational array.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @param string $scope    upcoming|past.
	 * @param int    $limit    Max events to return.
	 * @return array List of event arrays.
	 */
	private function queryScope( int $term_id, string $taxonomy, string $scope, int $limit ): array {
		global $wpdb;

		$ed_table = EventDatesTable::table_name();
		$now      = current_time( 'mysql' );

		// Two fully-literal query strings keep the date comparator and sort
		// direction out of variable interpolation so $wpdb->prepare() sees a
		// stable placeholder count. The only interpolated token is the table
		// name (a trusted internal constant); the taxonomy is passed as a %s
		// placeholder, matching the sibling UpcomingCountAbilities pattern.
		$timing = ( 'upcoming' === $scope ) ? 'upcoming' : 'past';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $ed_table is a trusted internal constant; the two literal query strings keep placeholder counts stable.
		if ( 'upcoming' === $scope ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ed.post_id AS post_id, ed.start_datetime AS start_datetime
					FROM {$ed_table} ed
					INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = ed.post_id
					INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
					WHERE tt.taxonomy = %s
					AND tt.term_id = %d
					AND ed.post_status = 'publish'
					AND ed.start_datetime >= %s
					ORDER BY ed.start_datetime ASC
					LIMIT %d",
					$taxonomy,
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
					WHERE tt.taxonomy = %s
					AND tt.term_id = %d
					AND ed.post_status = 'publish'
					AND ed.start_datetime < %s
					ORDER BY ed.start_datetime DESC
					LIMIT %d",
					$taxonomy,
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
