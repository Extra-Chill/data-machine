<?php
/**
 * Upcoming Count Abilities
 *
 * Counts upcoming events grouped by taxonomy term. This is the raw data
 * primitive powering homepage badges, cross-site links, and market reports.
 *
 * The query joins postmeta (_datamachine_event_datetime >= today) to filter
 * only future events, then GROUP BY term for counts. On 35K+ events this
 * takes ~7s, so consumers should always cache the results.
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
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'taxonomy' ),
						'properties' => array(
							'taxonomy'      => array(
								'type'        => 'string',
								'enum'        => array( 'venue', 'location', 'artist', 'festival' ),
								'description' => __( 'Taxonomy to count events for.', 'data-machine-events' ),
							),
							'exclude_roots' => array(
								'type'        => 'boolean',
								'description' => __( 'Exclude root-level terms (parent = 0). Default true for hierarchical taxonomies like location.', 'data-machine-events' ),
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
	 * @return array Term counts sorted by event count descending.
	 */
	public function executeGetUpcomingCounts( array $input ): array {
		$taxonomy      = $input['taxonomy'];
		$exclude_roots = $input['exclude_roots'] ?? ( is_taxonomy_hierarchical( $taxonomy ) );

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array(
				'success'  => false,
				'taxonomy' => $taxonomy,
				'terms'    => array(),
				'total'    => 0,
			);
		}

		global $wpdb;

		$today    = gmdate( 'Y-m-d 00:00:00' );
		$ed_table = \DataMachineEvents\Core\EventDatesTable::table_name();

		$parent_clause = $exclude_roots ? 'AND tt.parent != 0' : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, t.name, t.slug, COUNT(DISTINCT p.ID) AS event_count
				FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
				INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
				INNER JOIN {$ed_table} ed ON p.ID = ed.post_id
				WHERE tt.taxonomy = %s
				AND p.post_type = 'data_machine_events'
				AND p.post_status = 'publish'
				AND ed.start_datetime >= %s
				{$parent_clause}
				GROUP BY t.term_id
				ORDER BY event_count DESC",
				$taxonomy,
				$today
			)
		);

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
}
