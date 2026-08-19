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
								'anyOf'       => array( array( 'type' => 'integer' ), array( 'type' => 'string' ), array( 'type' => 'null' ) ),
								'description' => __( 'Filter jobs by flow ID.', 'data-machine' ),
							),
							'pipeline_id' => array(
								'anyOf'       => array( array( 'type' => 'integer' ), array( 'type' => 'string' ), array( 'type' => 'null' ) ),
								'description' => __( 'Filter jobs by pipeline ID.', 'data-machine' ),
							),
							'handler'     => array(
								'anyOf'       => array( array( 'type' => 'string' ), array( 'type' => 'null' ) ),
								'description' => __( 'Filter jobs by handler slug recorded in job outcome metadata.', 'data-machine' ),
							),
							'status'      => array(
								'anyOf'       => array( array( 'type' => 'string' ), array( 'type' => 'null' ) ),
								'description' => __( 'Filter jobs by status prefix.', 'data-machine' ),
							),
							'source'      => array(
								'anyOf'       => array( array( 'type' => 'string' ), array( 'type' => 'null' ) ),
								'description' => __( 'Filter jobs by source.', 'data-machine' ),
							),
							'since'       => array(
								'anyOf'       => array( array( 'type' => 'string' ), array( 'type' => 'null' ) ),
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
	 * @return array|\WP_Error Result with summary counts or a query failure.
	 */
	public function execute( array $input ): array|\WP_Error {
		$ownership_scope = $this->jobCollectionScope(
			isset( $input['user_id'] ) ? (int) $input['user_id'] : null,
			isset( $input['agent_id'] ) ? (int) $input['agent_id'] : null
		);
		if ( is_wp_error( $ownership_scope ) ) {
			return $ownership_scope;
		}

		$filters = array();
		foreach ( array( 'flow_id', 'pipeline_id', 'handler', 'status', 'source', 'since' ) as $key ) {
			if ( isset( $input[ $key ] ) && '' !== $input[ $key ] ) {
				$filters[ $key ] = $input[ $key ];
			}
		}
		$filters = array_merge( $filters, $ownership_scope );

		$summary = empty( $input['compact'] ) ? $this->db_jobs->get_jobs_summary( $filters ) : $this->getCompactSummary( $filters );
		if ( is_wp_error( $summary ) ) {
			return $summary;
		}
		$query_error = $this->jobQueryFailed();
		if ( $query_error ) {
			return $query_error;
		}

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
	 * @return array<string,mixed>|\WP_Error Compact summary payload or query failure.
	 */
	private function getCompactSummary( array $filters ): array|\WP_Error {
		$total = $this->queryCompactCount( fn() => $this->db_jobs->get_jobs_count( $filters ) );
		if ( is_wp_error( $total ) ) {
			return $total;
		}

		$failed = $this->queryCompactCount( fn() => $this->db_jobs->get_jobs_count( array_merge( $filters, array( 'status' => 'failed' ) ) ) );
		if ( is_wp_error( $failed ) ) {
			return $failed;
		}

		$stuck = $this->queryCompactCount( fn() => $this->db_jobs->get_stuck_processing_count( $filters ) );
		if ( is_wp_error( $stuck ) ) {
			return $stuck;
		}

		$incomplete = $this->queryCompactCount( fn() => $this->db_jobs->count_incomplete_terminal_accounting( $filters ) );
		if ( is_wp_error( $incomplete ) ) {
			return $incomplete;
		}

		$processing = $this->queryCompactCount( fn() => $this->db_jobs->get_jobs_count( array_merge( $filters, array( 'status' => 'processing' ) ) ) );
		if ( is_wp_error( $processing ) ) {
			return $processing;
		}

		$pending = $this->queryCompactCount( fn() => $this->db_jobs->get_jobs_count( array_merge( $filters, array( 'status' => 'pending' ) ) ) );
		if ( is_wp_error( $pending ) ) {
			return $pending;
		}

		return array(
			'total'                                => $total,
			'failed_count'                         => $failed,
			'stuck_processing_count'               => $stuck,
			'incomplete_terminal_accounting_count' => $incomplete,
			'status'                               => array(
				array(
					'status' => 'processing',
					'count'  => $processing,
				),
				array(
					'status' => 'pending',
					'count'  => $pending,
				),
				array(
					'status' => 'failed',
					'count'  => $failed,
				),
			),
			'pipeline'                             => array(),
			'flow'                                 => array(),
			'handler'                              => array(),
			'filters'                              => $filters,
		);
	}

	/** Execute one compact count without allowing a later query to erase its error. */
	private function queryCompactCount( callable $query ): int|\WP_Error {
		$count       = (int) $query();
		$query_error = $this->jobQueryFailed();

		return $query_error ? $query_error : $count;
	}
}
