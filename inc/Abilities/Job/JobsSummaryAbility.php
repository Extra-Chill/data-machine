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
							'compact'     => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Return only total/status counts and skip heavier pipeline, flow, and handler breakdowns.', 'data-machine' ),
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

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
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
		$ownership_scope = $this->jobCollectionScope(
			isset( $input['user_id'] ) ? (int) $input['user_id'] : null,
			isset( $input['agent_id'] ) ? (int) $input['agent_id'] : null
		);
		if ( isset( $ownership_scope['error'] ) ) {
			return array(
				'success'    => false,
				'error_code' => 'job_access_denied',
				'error'      => $ownership_scope['error'],
				'status'     => 403,
			);
		}

		$filters = array();
		foreach ( array( 'flow_id', 'pipeline_id', 'handler', 'status', 'source', 'since' ) as $key ) {
			if ( isset( $input[ $key ] ) && '' !== $input[ $key ] ) {
				$filters[ $key ] = $input[ $key ];
			}
		}
		$filters = array_merge( $filters, $ownership_scope );

		$summary = empty( $input['compact'] ) ? $this->db_jobs->get_jobs_summary( $filters ) : $this->getCompactSummary( $filters );

		return array(
			'success' => true,
			'summary' => $summary,
			'total'   => (int) ( $summary['total'] ?? 0 ),
		);
	}

	/**
	 * Get lightweight status counts for polling surfaces.
	 *
	 * @param array<string,mixed> $filters Job filters.
	 * @return array<string,mixed> Compact summary payload.
	 */
	private function getCompactSummary( array $filters ): array {
		return array(
			'total'                  => $this->db_jobs->get_jobs_count( $filters ),
			'failed_count'           => $this->db_jobs->get_jobs_count( array_merge( $filters, array( 'status' => 'failed' ) ) ),
			'stuck_processing_count' => 0,
			'status'                 => array(
				array(
					'status' => 'processing',
					'count'  => $this->db_jobs->get_jobs_count( array_merge( $filters, array( 'status' => 'processing' ) ) ),
				),
				array(
					'status' => 'pending',
					'count'  => $this->db_jobs->get_jobs_count( array_merge( $filters, array( 'status' => 'pending' ) ) ),
				),
				array(
					'status' => 'failed',
					'count'  => $this->db_jobs->get_jobs_count( array_merge( $filters, array( 'status' => 'failed' ) ) ),
				),
			),
			'pipeline'               => array(),
			'flow'                   => array(),
			'handler'                => array(),
			'filters'                => $filters,
		);
	}
}
