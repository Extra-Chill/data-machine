<?php
/**
 * Upcoming Count Abilities
 *
 * Counts upcoming events grouped by taxonomy term. This is the raw data
 * primitive powering homepage badges, cross-site links, and market reports.
 *
 * The query joins event_dates (start_datetime >= today, post_status = 'publish')
 * to filter only future published events, then GROUP BY term for counts.
 * Skips the posts table entirely via denormalized post_status column.
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UpcomingCountAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'data-machine-events/get-upcoming-counts',
				array(
					'label'               => __( 'Get Upcoming Event Counts', 'data-machine-events' ),
					'description'         => __( 'Count upcoming events grouped by taxonomy term. Returns terms sorted by event count descending.', 'data-machine-events' ),
					'category'            => 'datamachine-events-events',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'taxonomy' ),
						'properties' => array(
							'taxonomy'        => array(
								'type'        => 'string',
								'enum'        => array( 'venue', 'location', 'artist', 'festival' ),
								'description' => __( 'Taxonomy to count events for.', 'data-machine-events' ),
							),
							'exclude_roots'   => array(
								'type'        => 'boolean',
								'description' => __( 'Exclude root-level terms (parent = 0). Default true for hierarchical taxonomies like location.', 'data-machine-events' ),
							),
							'rollup'          => array(
								'type'        => 'boolean',
								'description' => __( 'Roll counts up the hierarchy: each non-leaf term reports the number of DISTINCT upcoming events tagged to ANY of its descendant terms (deduped). Default false (count only events tagged directly to each term). Only meaningful for hierarchical taxonomies; ignored otherwise. When true, exclude_roots controls whether root ancestors are returned.', 'data-machine-events' ),
							),
							'filter_taxonomy' => array(
								'type'        => 'string',
								'description' => __( 'Optional. When paired with filter_term_id, restrict counts to upcoming events also tagged with that term in this taxonomy (e.g. taxonomy=venue + filter_taxonomy=artist + filter_term_id=N counts distinct venues of upcoming events tagged with artist N).', 'data-machine-events' ),
							),
							'filter_term_id'  => array(
								'type'        => 'integer',
								'description' => __( 'Optional. Term ID to scope by. Must be paired with filter_taxonomy; provided alone is an error.', 'data-machine-events' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'  => array( 'type' => 'boolean' ),
							'taxonomy' => array( 'type' => 'string' ),
							'terms'    => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'term_id' => array( 'type' => 'integer' ),
										'name'    => array( 'type' => 'string' ),
										'slug'    => array( 'type' => 'string' ),
										'count'   => array( 'type' => 'integer' ),
										'url'     => array( 'type' => 'string' ),
									),
								),
							),
							'total'    => array( 'type' => 'integer' ),
						),
					),
					'execute_callback'    => array( $this, 'executeGetUpcomingCounts' ),
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
	 * Execute get-upcoming-counts ability.
	 *
	 * Single SQL query: counts upcoming events per term using GROUP BY.
	 * Filters to published data_machine_events with _datamachine_event_datetime >= today.
	 *
	 * @param array $input Input parameters.
	 * @return array|\WP_Error Term counts sorted by event count descending.
	 */
	public function executeGetUpcomingCounts( array $input ): array|\WP_Error {
		$taxonomy      = $input['taxonomy'];
		$exclude_roots = $input['exclude_roots'] ?? ( is_taxonomy_hierarchical( $taxonomy ) );

		// Optional co-occurrence filter. Both keys must be set together;
		// providing only one is misuse and returns an error so callers
		// catch the wiring bug instead of silently getting unfiltered results.
		$filter_taxonomy_raw = $input['filter_taxonomy'] ?? null;
		$filter_term_id_raw  = $input['filter_term_id'] ?? null;
		$filter_taxonomy     = null;
		$filter_term_id      = 0;
		$has_filter          = false;

		if ( null !== $filter_taxonomy_raw || null !== $filter_term_id_raw ) {
			if ( null === $filter_taxonomy_raw || null === $filter_term_id_raw ) {
				return new \WP_Error(
					'invalid_filter_pair',
					'filter_taxonomy and filter_term_id must be provided together.',
					array( 'status' => 400 )
				);
			}
			$filter_taxonomy = sanitize_key( (string) $filter_taxonomy_raw );
			$filter_term_id  = absint( $filter_term_id_raw );

			if ( '' === $filter_taxonomy || 0 === $filter_term_id ) {
				return new \WP_Error(
					'invalid_filter_pair',
					'filter_taxonomy and filter_term_id must both be non-empty.',
					array( 'status' => 400 )
				);
			}
			if ( ! taxonomy_exists( $filter_taxonomy ) ) {
				return new \WP_Error(
					'invalid_filter_taxonomy',
					"Filter taxonomy '{$filter_taxonomy}' does not exist.",
					array( 'status' => 400 )
				);
			}
			$has_filter = true;
		}

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'invalid_taxonomy', "Taxonomy '{$taxonomy}' does not exist.", array( 'status' => 400 ) );
		}

		// Rollup mode: ancestor terms report distinct upcoming events across
		// their whole subtree. Only meaningful for hierarchical taxonomies;
		// for flat taxonomies a term has no descendants so rollup === direct
		// counts, and we fall through to the standard path.
		$rollup = ! empty( $input['rollup'] ) && is_taxonomy_hierarchical( $taxonomy );
		if ( $rollup ) {
			return $this->executeRollupCounts( $taxonomy, $exclude_roots, $has_filter, $filter_taxonomy, $filter_term_id );
		}

		global $wpdb;

		$today    = gmdate( 'Y-m-d 00:00:00' );
		$ed_table = \DataMachineEvents\Core\EventDatesTable::table_name();

		$parent_clause = $exclude_roots ? 'AND tt.parent != 0' : '';

		if ( $has_filter ) {
			// Co-occurrence join: require each counted post to ALSO be tagged
			// with $filter_term_id in $filter_taxonomy. Aliases f_tr / f_tt
			// keep the filter join distinct from the primary tr / tt aliases.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT t.term_id, t.name, t.slug, COUNT(DISTINCT tr.object_id) AS event_count
					FROM {$wpdb->term_relationships} tr
					INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
					INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
					INNER JOIN {$ed_table} ed ON tr.object_id = ed.post_id
					INNER JOIN {$wpdb->term_relationships} f_tr ON f_tr.object_id = tr.object_id
					INNER JOIN {$wpdb->term_taxonomy} f_tt ON f_tr.term_taxonomy_id = f_tt.term_taxonomy_id
					WHERE tt.taxonomy = %s
					AND ed.post_status = 'publish'
					AND ed.start_datetime >= %s
					AND f_tt.taxonomy = %s
					AND f_tt.term_id = %d
					{$parent_clause}
					GROUP BY t.term_id
					ORDER BY event_count DESC",
					$taxonomy,
					$today,
					$filter_taxonomy,
					$filter_term_id
				)
			);
		} else {
			// Uses ed.post_status to avoid joining the posts table (3s → <100ms).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT t.term_id, t.name, t.slug, COUNT(DISTINCT tr.object_id) AS event_count
					FROM {$wpdb->term_relationships} tr
					INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
					INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
					INNER JOIN {$ed_table} ed ON tr.object_id = ed.post_id
					WHERE tt.taxonomy = %s
					AND ed.post_status = 'publish'
					AND ed.start_datetime >= %s
					{$parent_clause}
					GROUP BY t.term_id
					ORDER BY event_count DESC",
					$taxonomy,
					$today
				)
			);
		}

		if ( empty( $rows ) ) {
			return array(
				'success'  => true,
				'taxonomy' => $taxonomy,
				'terms'    => array(),
				'total'    => 0,
			);
		}

		$terms = array();
		foreach ( $rows as $row ) {
			$url = get_term_link( (int) $row->term_id, $taxonomy );
			if ( is_wp_error( $url ) ) {
				continue;
			}

			$terms[] = array(
				'term_id' => (int) $row->term_id,
				'name'    => $row->name,
				'slug'    => $row->slug,
				'count'   => (int) $row->event_count,
				'url'     => $url,
			);
		}

		return array(
			'success'  => true,
			'taxonomy' => $taxonomy,
			'terms'    => $terms,
			'total'    => count( $terms ),
		);
	}

	/**
	 * Execute get-upcoming-counts in rollup mode.
	 *
	 * Each non-leaf term reports the number of DISTINCT upcoming events tagged
	 * to any term in its subtree (the term itself plus all descendants). An
	 * event tagged to two sibling cities counts once for the shared ancestor.
	 *
	 * Strategy (taxonomy is shallow — region → state → city): pull every
	 * (term_id, object_id) pair for upcoming published events in ONE query,
	 * then aggregate distinct object_ids per ancestor in PHP using the term
	 * hierarchy. Avoids N per-ancestor queries while keeping the dedup exact.
	 *
	 * @param string      $taxonomy        Hierarchical taxonomy slug.
	 * @param bool        $exclude_roots   Exclude root (parent = 0) ancestors from the result.
	 * @param bool        $has_filter      Whether a co-occurrence filter is active.
	 * @param string|null $filter_taxonomy Co-occurrence filter taxonomy.
	 * @param int         $filter_term_id  Co-occurrence filter term id.
	 * @return array|\WP_Error Ancestor term counts sorted by event count descending.
	 */
	private function executeRollupCounts( string $taxonomy, bool $exclude_roots, bool $has_filter, ?string $filter_taxonomy, int $filter_term_id ): array|\WP_Error {
		global $wpdb;

		$today    = gmdate( 'Y-m-d 00:00:00' );
		$ed_table = \DataMachineEvents\Core\EventDatesTable::table_name();

		// Pull every (term_id, object_id) pair for upcoming published events
		// in this taxonomy. One pass; deduped per-ancestor in PHP below.
		if ( $has_filter ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$pairs = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT tt.term_id AS term_id, tr.object_id AS object_id
					FROM {$wpdb->term_relationships} tr
					INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
					INNER JOIN {$ed_table} ed ON tr.object_id = ed.post_id
					INNER JOIN {$wpdb->term_relationships} f_tr ON f_tr.object_id = tr.object_id
					INNER JOIN {$wpdb->term_taxonomy} f_tt ON f_tr.term_taxonomy_id = f_tt.term_taxonomy_id
					WHERE tt.taxonomy = %s
					AND ed.post_status = 'publish'
					AND ed.start_datetime >= %s
					AND f_tt.taxonomy = %s
					AND f_tt.term_id = %d",
					$taxonomy,
					$today,
					$filter_taxonomy,
					$filter_term_id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$pairs = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT tt.term_id AS term_id, tr.object_id AS object_id
					FROM {$wpdb->term_relationships} tr
					INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
					INNER JOIN {$ed_table} ed ON tr.object_id = ed.post_id
					WHERE tt.taxonomy = %s
					AND ed.post_status = 'publish'
					AND ed.start_datetime >= %s",
					$taxonomy,
					$today
				)
			);
		}

		// Parent map for every term in the taxonomy (term_id => parent_id).
		// hide_empty=false so ancestors with zero direct tags are present.
		$all_terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $all_terms ) ) {
			return $all_terms;
		}

		$parent_of = array();
		$term_meta = array();
		foreach ( $all_terms as $term ) {
			$parent_of[ (int) $term->term_id ] = (int) $term->parent;
			$term_meta[ (int) $term->term_id ] = $term;
		}

		// For each tagged term, walk up to collect its ancestor chain, then
		// attribute the event to the term itself AND every ancestor. Using a
		// set per term keeps the dedup exact (an event in two child cities
		// hits the shared ancestor's set once).
		$ancestors_cache = array();
		$event_sets      = array(); // term_id => array<object_id => true>.

		foreach ( $pairs as $pair ) {
			$leaf      = (int) $pair->term_id;
			$object_id = (int) $pair->object_id;

			if ( ! isset( $ancestors_cache[ $leaf ] ) ) {
				$chain   = array( $leaf );
				$current = $leaf;
				$guard   = 0;
				while ( isset( $parent_of[ $current ] ) && $parent_of[ $current ] > 0 && $guard < 50 ) {
					$current = $parent_of[ $current ];
					$chain[] = $current;
					++$guard;
				}
				$ancestors_cache[ $leaf ] = $chain;
			}

			foreach ( $ancestors_cache[ $leaf ] as $term_id ) {
				$event_sets[ $term_id ][ $object_id ] = true;
			}
		}

		// Emit ONLY non-leaf terms (terms that are a parent of at least one
		// other term). Rollup is about ancestor totals; leaf counts are what
		// the default (non-rollup) path already returns.
		$is_parent = array();
		foreach ( $parent_of as $child => $parent ) {
			if ( $parent > 0 ) {
				$is_parent[ $parent ] = true;
			}
		}

		$terms = array();
		foreach ( $event_sets as $term_id => $object_ids ) {
			if ( empty( $is_parent[ $term_id ] ) ) {
				continue; // Leaf term — not a rollup ancestor.
			}
			if ( $exclude_roots && isset( $parent_of[ $term_id ] ) && 0 === $parent_of[ $term_id ] ) {
				continue; // Root ancestor excluded by request.
			}
			if ( ! isset( $term_meta[ $term_id ] ) ) {
				continue;
			}

			$url = get_term_link( $term_id, $taxonomy );
			if ( is_wp_error( $url ) ) {
				continue;
			}

			$terms[] = array(
				'term_id' => $term_id,
				'name'    => $term_meta[ $term_id ]->name,
				'slug'    => $term_meta[ $term_id ]->slug,
				'count'   => count( $object_ids ),
				'url'     => $url,
			);
		}

		// Sort by count descending to match the non-rollup contract.
		usort(
			$terms,
			static function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);

		return array(
			'success'  => true,
			'taxonomy' => $taxonomy,
			'terms'    => $terms,
			'total'    => count( $terms ),
		);
	}
}
