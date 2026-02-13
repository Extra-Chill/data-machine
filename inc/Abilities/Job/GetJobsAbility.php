<?php
/**
 * Get Jobs Ability
 *
 * Handles job querying and listing with filtering, pagination, and sorting.
 *
 * @package DataMachine\Abilities\Job
 * @since 0.17.0
 */

namespace DataMachine\Abilities\Job;

defined( 'ABSPATH' ) || exit;

class GetJobsAbility {

	use JobHelpers;

	private const DEFAULT_PER_PAGE = 50;

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
				'datamachine/get-jobs',
				array(
					'label'               => __( 'Get Jobs', 'data-machine' ),
					'description'         => __( 'List jobs with optional filtering by flow_id, pipeline_id, or status. Supports pagination, sorting, and single job lookup via job_id.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'job_id'      => array(
								'type'        => array( 'integer', 'null' ),
								'description' => __( 'Get a specific job by ID (ignores pagination/filters when provided)', 'data-machine' ),
							),
							'flow_id'     => array(
								'type'        => array( 'integer', 'string', 'null' ),
								'description' => __( 'Filter jobs by flow ID (integer or "direct")', 'data-machine' ),
							),
							'pipeline_id' => array(
								'type'        => array( 'integer', 'string', 'null' ),
								'description' => __( 'Filter jobs by pipeline ID (integer or "direct")', 'data-machine' ),
							),
							'status'      => array(
								'type'        => array( 'string', 'null' ),
								'description' => __( 'Filter jobs by status (pending, processing, completed, failed, completed_no_items, agent_skipped)', 'data-machine' ),
							),
							'source'      => array(
								'type'        => array( 'string', 'null' ),
								'description' => __( 'Filter jobs by source (pipeline, chat, system, api, direct)', 'data-machine' ),
							),
							'per_page'    => array(
								'type'        => 'integer',
								'default'     => self::DEFAULT_PER_PAGE,
								'minimum'     => 1,
								'maximum'     => 100,
								'description' => __( 'Number of jobs per page', 'data-machine' ),
							),
							'offset'      => array(
								'type'        => 'integer',
								'default'     => 0,
								'minimum'     => 0,
								'description' => __( 'Offset for pagination', 'data-machine' ),
							),
							'orderby'     => array(
								'type'        => 'string',
								'default'     => 'j.job_id',
								'description' => __( 'Column to order by', 'data-machine' ),
							),
							'order'       => array(
								'type'        => 'string',
								'enum'        => array( 'ASC', 'DESC' ),
								'default'     => 'DESC',
								'description' => __( 'Sort order', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'         => array( 'type' => 'boolean' ),
							'jobs'            => array( 'type' => 'array' ),
							'total'           => array( 'type' => 'integer' ),
							'per_page'        => array( 'type' => 'integer' ),
							'offset'          => array( 'type' => 'integer' ),
							'filters_applied' => array( 'type' => 'object' ),
							'error'           => array( 'type' => 'string' ),
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
	 * Execute get-jobs ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with jobs list.
	 */
	public function execute( array $input ): array {
		$job_id      = $input['job_id'] ?? null;
		$flow_id     = $input['flow_id'] ?? null;
		$pipeline_id = $input['pipeline_id'] ?? null;
		$status      = $input['status'] ?? null;
		$source      = $input['source'] ?? null;
		$per_page    = (int) ( $input['per_page'] ?? self::DEFAULT_PER_PAGE );
		$offset      = (int) ( $input['offset'] ?? 0 );
		$orderby     = $input['orderby'] ?? 'j.job_id';
		$order       = $input['order'] ?? 'DESC';

		// Direct job lookup by ID - bypasses pagination and filters.
		if ( $job_id ) {
			if ( ! is_numeric( $job_id ) || (int) $job_id <= 0 ) {
				return array(
					'success' => false,
					'error'   => 'job_id must be a positive integer',
				);
			}

			$job = $this->db_jobs->get_job( (int) $job_id );

			if ( ! $job ) {
				return array(
					'success'         => true,
					'jobs'            => array(),
					'total'           => 0,
					'per_page'        => $per_page,
					'offset'          => $offset,
					'filters_applied' => array( 'job_id' => (int) $job_id ),
				);
			}

			$job = $this->addDisplayFields( $job );

			return array(
				'success'         => true,
				'jobs'            => array( $job ),
				'total'           => 1,
				'per_page'        => $per_page,
				'offset'          => $offset,
				'filters_applied' => array( 'job_id' => (int) $job_id ),
			);
		}

		$args = array(
			'orderby'  => $orderby,
			'order'    => $order,
			'per_page' => $per_page,
			'offset'   => $offset,
		);

		$filters_applied = array();

		if ( null !== $flow_id ) {
			$args['flow_id']            = $flow_id;
			$filters_applied['flow_id'] = $flow_id;
		}

		if ( null !== $pipeline_id ) {
			$args['pipeline_id']            = $pipeline_id;
			$filters_applied['pipeline_id'] = $pipeline_id;
		}

		if ( null !== $status && '' !== $status ) {
			$args['status']            = sanitize_text_field( $status );
			$filters_applied['status'] = $args['status'];
		}

		if ( null !== $source && '' !== $source ) {
			$args['source']            = sanitize_text_field( $source );
			$filters_applied['source'] = $args['source'];
		}

		$jobs  = $this->db_jobs->get_jobs_for_list_table( $args );
		$total = $this->db_jobs->get_jobs_count( $args );

		$jobs = array_map( array( $this, 'addDisplayFields' ), $jobs );

		return array(
			'success'         => true,
			'jobs'            => $jobs,
			'total'           => $total,
			'per_page'        => $per_page,
			'offset'          => $offset,
			'filters_applied' => $filters_applied,
		);
	}
}
