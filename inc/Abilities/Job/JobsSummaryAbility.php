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

		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/get-jobs-summary',
				array(
					'label'               => __( 'Get Jobs Summary', 'data-machine' ),
					'description'         => __( 'Get job counts grouped by base status. Compound statuses are normalized to their base status.', 'data-machine' ),
					'category'            => 'datamachine-jobs',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'flow_id'     => array(
								'type'        => array( 'integer', 'string', 'null' ),
								'description' => __( 'Filter jobs by flow ID.', 'data-machine' ),
							),
							'pipeline_id' => array(
								'type'        => array( 'integer', 'string', 'null' ),
								'description' => __( 'Filter jobs by pipeline ID.', 'data-machine' ),
							),
							'handler'     => array(
								'type'        => array( 'string', 'null' ),
								'description' => __( 'Filter jobs by handler slug recorded in job outcome metadata.', 'data-machine' ),
							),
							'status'      => array(
								'type'        => array( 'string', 'null' ),
								'description' => __( 'Filter jobs by status prefix.', 'data-machine' ),
							),
							'source'      => array(
								'type'        => array( 'string', 'null' ),
								'description' => __( 'Filter jobs by source.', 'data-machine' ),
							),
							'since'       => array(
								'type'        => array( 'string', 'null' ),
								'description' => __( 'Filter jobs created at or after this datetime (Y-m-d H:i:s).', 'data-machine' ),
							),
						),
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

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute get-jobs-summary ability.
	 *
	 * Returns job counts grouped by base status. Compound statuses (e.g., "agent_skipped - reason")
	 * are normalized to their base status.
	 *
	 * @param array $input Filter parameters.
	 * @return array Result with summary counts.
	 */
	public function execute( array $input ): array {
		$filters = array();
		foreach ( array( 'flow_id', 'pipeline_id', 'handler', 'status', 'source', 'since', 'user_id', 'agent_id' ) as $key ) {
			if ( isset( $input[ $key ] ) && '' !== $input[ $key ] && null !== $input[ $key ] ) {
				$filters[ $key ] = $input[ $key ];
			}
		}

		$summary = $this->db_jobs->get_jobs_summary( $filters );

		return array(
			'success' => true,
			'summary' => $summary,
			'total'   => (int) ( $summary['total'] ?? 0 ),
		);
	}
}
