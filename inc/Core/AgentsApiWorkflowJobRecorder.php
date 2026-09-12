<?php
/**
 * Data Machine job recorder for Agents API workflow runs.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Recorder;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Result;
use DataMachine\Core\Database\Jobs\Jobs;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Records an Agents API workflow run into the Data Machine jobs table.
 */
class AgentsApiWorkflowJobRecorder implements WP_Agent_Workflow_Run_Recorder {

	private ?int $job_id = null;

	public function __construct(
		private Jobs $jobs,
		private array $spec,
		private array $options = array()
	) {}

	/**
	 * Return the Data Machine job ID created for this run.
	 */
	public function get_job_id(): ?int {
		return $this->job_id;
	}

	/**
	 * Persist the start of the Agents API workflow run.
	 *
	 * @param WP_Agent_Workflow_Run_Result $result Initial run result.
	 * @return string|WP_Error
	 */
	public function start( WP_Agent_Workflow_Run_Result $result ) {
		$job_id = $this->jobs->create_job(
			array_filter(
				array(
					'pipeline_id' => 'direct',
					'flow_id'     => 'direct',
					'source'      => 'agents_api_workflow',
					'label'       => $this->options['label'] ?? sprintf( 'Agents API Workflow: %s', $result->get_workflow_id() ),
					'user_id'     => (int) ( $this->options['user_id'] ?? 0 ),
					'agent_id'    => isset( $this->options['agent_id'] ) ? (int) $this->options['agent_id'] : null,
				),
				static fn( $value ) => null !== $value
			)
		);

		if ( ! $job_id ) {
			return new WP_Error( 'datamachine_job_create_failed', 'Failed to create Data Machine job for Agents API workflow run.' );
		}

		$this->job_id = (int) $job_id;
		$this->jobs->start_job( $this->job_id, JobStatus::PROCESSING );
		$this->persist_result( $result );

		return $result->get_run_id();
	}

	/**
	 * Update the Data Machine job with the latest workflow result.
	 *
	 * @param WP_Agent_Workflow_Run_Result $result Latest run result.
	 * @return true|WP_Error
	 */
	public function update( WP_Agent_Workflow_Run_Result $result ) {
		if ( null === $this->job_id ) {
			return new WP_Error( 'datamachine_job_missing', 'Data Machine job was not created for this Agents API workflow run.' );
		}

		$this->persist_result( $result );

		$status = $this->map_status( $result );
		if ( null !== $status ) {
			$this->jobs->complete_job( $this->job_id, $status );
		}

		return true;
	}

	/**
	 * Look up a previously recorded run.
	 *
	 * @param string $run_id Agents API run ID.
	 * @return WP_Agent_Workflow_Run_Result|null
	 */
	public function find( string $run_id ): ?WP_Agent_Workflow_Run_Result {
		if ( '' === $run_id ) {
			return null;
		}

		foreach ( $this->query_jobs( array( $this->json_field_marker( 'run_id', $run_id ) ), 1, 0 ) as $job ) {
			$result = $this->result_from_job( $job );
			if ( $result && $run_id === $result->get_run_id() ) {
				return $result;
			}
		}

		return null;
	}

	/**
	 * Return recent workflow runs.
	 *
	 * @param array $args Query arguments.
	 * @return WP_Agent_Workflow_Run_Result[]
	 */
	public function recent( array $args = array() ): array {
		$limit   = max( 1, min( 100, (int) ( $args['limit'] ?? 10 ) ) );
		$offset  = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$markers = array();

		if ( is_string( $args['workflow_id'] ?? null ) && '' !== $args['workflow_id'] ) {
			$markers[] = $this->json_field_marker( 'workflow_id', $args['workflow_id'] );
		}

		$results = array();
		foreach ( $this->query_jobs( $markers, $limit, $offset ) as $job ) {
			$result = $this->result_from_job( $job );
			if ( $result ) {
				$results[] = $result;
			}
		}

		return $results;
	}

	/**
	 * Query Agents API workflow jobs with optional exact JSON field markers.
	 *
	 * @param string[] $engine_data_markers JSON fragments that must be present.
	 * @param int      $limit Bounded result limit.
	 * @param int      $offset Result offset.
	 * @return array<int, array<string, mixed>>
	 */
	private function query_jobs( array $engine_data_markers, int $limit, int $offset ): array {
		if ( ! method_exists( $this->jobs, 'get_jobs_for_list_table' ) ) {
			return array();
		}

		return $this->jobs->get_jobs_for_list_table(
			array(
				'source'               => 'agents_api_workflow',
				'engine_data_contains' => $engine_data_markers,
				'fields'               => array( 'job_id', 'engine_data', 'created_at' ),
				'orderby'              => 'j.job_id',
				'order'                => 'DESC',
				'per_page'             => $limit,
				'offset'               => $offset,
			)
		);
	}

	/**
	 * Rebuild an Agents API workflow result from Data Machine job engine data.
	 *
	 * @param array<string, mixed> $job Data Machine job row.
	 */
	private function result_from_job( array $job ): ?WP_Agent_Workflow_Run_Result {
		$engine_data = $job['engine_data'] ?? array();
		if ( ! is_array( $engine_data ) || ! is_array( $engine_data['workflow_run_result'] ?? null ) ) {
			return null;
		}

		return WP_Agent_Workflow_Run_Result::from_array( $engine_data['workflow_run_result'] );
	}

	private function json_field_marker( string $field, string $value ): string {
		return '"' . $field . '":' . wp_json_encode( $value );
	}

	private function persist_result( WP_Agent_Workflow_Run_Result $result ): void {
		if ( null === $this->job_id ) {
			return;
		}

		$this->jobs->store_engine_data(
			$this->job_id,
			array(
				'job'                 => array(
					'job_id'     => $this->job_id,
					'user_id'    => (int) ( $this->options['user_id'] ?? 0 ),
					'created_at' => function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' ),
				),
				'agents_api_workflow' => array(
					'workflow_id' => $result->get_workflow_id(),
					'run_id'      => $result->get_run_id(),
					'spec'        => $this->spec,
					'inputs'      => $result->get_inputs(),
					'metadata'    => $result->get_metadata(),
				),
				'workflow_run_result' => $result->to_array(),
				'step_outcomes'       => $result->get_steps(),
				'output'              => $result->get_output(),
				'artifacts'           => is_array( $result->get_metadata()['artifacts'] ?? null ) ? $result->get_metadata()['artifacts'] : array(),
				'logs'                => is_array( $result->get_metadata()['logs'] ?? null ) ? $result->get_metadata()['logs'] : array(),
				'error'               => $result->get_error(),
				'provenance'          => array(
					'source'      => 'agents-api',
					'bridge'      => 'datamachine/execute-agent-workflow',
					'execution'   => 'WP_Agent_Workflow_Runner',
					'recorded_as' => 'datamachine_job',
					'pipeline_id' => 'direct',
					'flow_id'     => 'direct',
				),
			)
		);
	}

	private function map_status( WP_Agent_Workflow_Run_Result $result ): ?string {
		if ( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED === $result->get_status() ) {
			return JobStatus::COMPLETED;
		}

		if ( WP_Agent_Workflow_Run_Result::STATUS_FAILED === $result->get_status() ) {
			$error  = $result->get_error();
			$reason = (string) ( $error['code'] ?? 'agents_api_workflow_failed' );
			return JobStatus::failed( $reason )->toString();
		}

		if ( WP_Agent_Workflow_Run_Result::STATUS_SKIPPED === $result->get_status() ) {
			return JobStatus::failed( 'agents_api_workflow_skipped' )->toString();
		}

		return null;
	}
}
