<?php
/**
 * Jobs Summary Ability
 *
 * Returns job counts grouped by base status.
 *
 * @package DataMachine\Abilities\Job
 * @since 0.17.0
 */

namespace DataMachine\Abilities\Job;

defined( 'ABSPATH' ) || exit;

class JobsSummaryAbility {

	use JobHelpers;

	public function __construct() {
		$this->initDatabases();

		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/get-jobs-summary',
				array(
					'label'               => __( 'Get Jobs Summary', 'data-machine' ),
					'description'         => __( 'Get job counts grouped by base status. Compound statuses are normalized to their base status.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'summary' => array( 'type' => 'object' ),
							'total'   => array( 'type' => 'integer' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
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
	 * Execute get-jobs-summary ability.
	 *
	 * Returns job counts grouped by base status. Compound statuses (e.g., "agent_skipped - reason")
	 * are normalized to their base status.
	 *
	 * @param array $input Input parameters (none required).
	 * @return array Result with summary counts.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Ability interface requires $input
	public function execute( array $input ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_jobs';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			"SELECT
				CASE
					WHEN status LIKE 'agent_skipped%' THEN 'agent_skipped'
					WHEN status LIKE 'completed_no_items%' THEN 'completed_no_items'
					ELSE status
				END as base_status,
				COUNT(*) as count
			 FROM {$table}
			 GROUP BY base_status
			 ORDER BY count DESC"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$summary = array();
		$total   = 0;

		foreach ( $results as $row ) {
			$summary[ $row->base_status ] = (int) $row->count;
			$total                       += (int) $row->count;
		}

		return array(
			'success' => true,
			'summary' => $summary,
			'total'   => $total,
		);
	}
}
