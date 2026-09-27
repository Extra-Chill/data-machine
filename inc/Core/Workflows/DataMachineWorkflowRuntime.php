<?php
/**
 * Data Machine's runtime registration behind the canonical Agents API
 * `agents/run-workflow` ability.
 *
 * Replaces the deleted per-ability workflow bridge (Extra-Chill/data-machine#3552).
 * Data Machine now runs simple `ability`/`agent` workflow specs through the
 * runtime-scoped seams introduced by Automattic/agents-api#567:
 *
 * - `wp_agent_workflow_runtime_handlers` — dispatch, scoped to the
 *   `datamachine` runtime key only.
 * - `wp_agent_workflow_run_recorder` — recorder resolution for
 *   reconcile/resume, scoped by `$runtime`/`$run_id` so foreign runtimes
 *   (e.g. Intelligence on studio.extrachill.com) are never reconciled here.
 * - `wp_agent_workflow_run_status_handler` — `agents/get-workflow-run`
 *   support, answering only for run ids this recorder actually owns.
 *
 * `foreach`/`parallel` steps stay out of scope; those are phases 3-4 of
 * Extra-Chill/data-machine#3428.
 *
 * @package DataMachine\Core\Workflows
 */

namespace DataMachine\Core\Workflows;

use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Registry;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Recorder;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Runner;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\AgentsApiWorkflowJobRecorder;
use DataMachine\Core\Database\Jobs\Jobs;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class DataMachineWorkflowRuntime {

	/**
	 * The opaque runtime key Data Machine owns on the runtime-scoped seams.
	 */
	public const RUNTIME_KEY = 'datamachine';

	private const SUPPORTED_STEP_TYPES = array( 'ability', 'agent' );

	private Jobs $db_jobs;

	public function __construct( ?Jobs $db_jobs = null ) {
		$this->db_jobs = $db_jobs ?? new Jobs();
		$this->registerHooks();
	}

	private function registerHooks(): void {
		\AgentsAPI\AI\Workflows\register_workflow_runtime_handler( self::RUNTIME_KEY, array( $this, 'run' ) );
		add_filter( 'wp_agent_workflow_run_recorder', array( $this, 'resolveRecorder' ), 10, 3 );
		add_filter( 'wp_agent_workflow_run_status_handler', array( $this, 'resolveRunStatusHandler' ), 10, 2 );
		add_filter( 'agents_run_workflow_permission', array( $this, 'filterPermission' ), 10, 2 );
	}

	/**
	 * `agents/run-workflow` handler for the `datamachine` runtime key.
	 *
	 * Ports the execution and authority logic from the deleted per-ability
	 * workflow bridge onto the canonical input/output contract.
	 *
	 * @param array $input Canonical agents/run-workflow input.
	 * @return array|WP_Error Canonical output, or a native error for
	 *                         dispatch-level failures (invalid spec,
	 *                         unsupported step/trigger types). A workflow
	 *                         that ran and failed is NOT a WP_Error — it is
	 *                         a canonical output with `status: failed`.
	 */
	public function run( array $input ): array|WP_Error {
		$spec_array = $this->resolveSpecArray( $input );
		if ( is_wp_error( $spec_array ) ) {
			return $spec_array;
		}

		$unsupported = $this->validateBridgeableSpec( $spec_array );
		if ( is_wp_error( $unsupported ) ) {
			return new WP_Error(
				$unsupported->get_error_code(),
				$unsupported->get_error_message(),
				array_merge( array( 'status' => 400 ), is_array( $unsupported->get_error_data() ) ? $unsupported->get_error_data() : array() )
			);
		}

		$spec = WP_Agent_Workflow_Spec::from_array( $spec_array );
		if ( is_wp_error( $spec ) ) {
			return new WP_Error(
				$spec->get_error_code(),
				$spec->get_error_message(),
				array_merge( array( 'status' => 400 ), is_array( $spec->get_error_data() ) ? $spec->get_error_data() : array() )
			);
		}

		$options_in = is_array( $input['options'] ?? null ) ? $input['options'] : array();

		$recorder = new AgentsApiWorkflowJobRecorder(
			$this->db_jobs,
			$spec->to_array(),
			array(
				'label'    => is_string( $options_in['label'] ?? null ) ? $options_in['label'] : null,
				'user_id'  => PermissionHelper::acting_user_id(),
				'agent_id' => PermissionHelper::get_acting_agent_id(),
			)
		);

		$run_options = array(
			'runtime'           => self::RUNTIME_KEY,
			'continue_on_error' => ! empty( $options_in['continue_on_error'] ),
			'metadata'          => is_array( $options_in['metadata'] ?? null ) ? $options_in['metadata'] : array(),
			'evidence_refs'     => is_array( $options_in['evidence_refs'] ?? null ) ? $options_in['evidence_refs'] : array(),
			'artifacts'         => is_array( $options_in['artifacts'] ?? null ) ? $options_in['artifacts'] : array(),
			'logs'              => is_array( $options_in['logs'] ?? null ) ? $options_in['logs'] : array(),
		);
		if ( is_string( $options_in['run_id'] ?? null ) && '' !== $options_in['run_id'] ) {
			$run_options['run_id'] = $options_in['run_id'];
		}

		$result = ( new WP_Agent_Workflow_Runner( $recorder ) )->run(
			$spec,
			is_array( $input['inputs'] ?? null ) ? $input['inputs'] : array(),
			$run_options
		);

		// Canonical output schema declares `status` with a `failed` enum
		// member — a workflow that ran and failed is a completed dispatch,
		// not a transport error. `job_id` is additive (not part of the
		// output schema, but not forbidden either) so callers can jump
		// straight to the recorded Data Machine job.
		return array_merge(
			$result->to_array(),
			array( 'job_id' => $recorder->get_job_id() )
		);
	}

	/**
	 * Resolve the target spec array from either an inline `spec` or a
	 * registered `workflow_id`.
	 *
	 * @param array $input Canonical agents/run-workflow input.
	 * @return array|WP_Error
	 */
	private function resolveSpecArray( array $input ): array|WP_Error {
		if ( is_array( $input['spec'] ?? null ) ) {
			return $input['spec'];
		}

		$workflow_id = is_string( $input['workflow_id'] ?? null ) ? trim( $input['workflow_id'] ) : '';
		if ( '' !== $workflow_id ) {
			$registered = WP_Agent_Workflow_Registry::find( $workflow_id );
			if ( null !== $registered ) {
				return $registered->to_array();
			}

			return new WP_Error(
				'agents_workflow_not_found',
				sprintf( 'No workflow is registered under `%s`.', $workflow_id ),
				array(
					'status'    => 404,
					'retryable' => false,
				)
			);
		}

		return new WP_Error(
			'invalid_spec',
			'Either `spec` or a registered `workflow_id` must be provided.',
			array(
				'status'    => 400,
				'retryable' => false,
			)
		);
	}

	/**
	 * Validate Data Machine's bridge subset without replacing the Agents API
	 * validator/runner: on-demand triggers only, `ability`/`agent` top-level
	 * steps only.
	 *
	 * @param array $spec Workflow spec array.
	 * @return true|WP_Error
	 */
	private function validateBridgeableSpec( array $spec ): bool|WP_Error {
		$triggers = $spec['triggers'] ?? array();
		if ( ! empty( $triggers ) ) {
			foreach ( $triggers as $index => $trigger ) {
				$type = is_array( $trigger ) ? (string) ( $trigger['type'] ?? '' ) : '';
				if ( 'on_demand' !== $type ) {
					return new WP_Error(
						'agents_api_workflow_trigger_unsupported',
						sprintf( 'Data Machine can execute Agents API workflows on demand only; trigger at index %d uses `%s`.', $index, '' !== $type ? $type : 'unknown' ),
						array(
							'trigger_index' => $index,
							'trigger_type'  => $type,
						)
					);
				}
			}
		}

		foreach ( (array) ( $spec['steps'] ?? array() ) as $index => $step ) {
			$type = is_array( $step ) ? (string) ( $step['type'] ?? '' ) : '';
			if ( ! in_array( $type, self::SUPPORTED_STEP_TYPES, true ) ) {
				return new WP_Error(
					'agents_api_workflow_step_unsupported',
					sprintf( 'Data Machine can record Agents API workflow steps of type ability or agent only; step at index %d uses `%s`.', $index, '' !== $type ? $type : 'unknown' ),
					array(
						'step_index'           => $index,
						'step_type'            => $type,
						'supported_step_types' => self::SUPPORTED_STEP_TYPES,
					)
				);
			}
		}

		return true;
	}

	/**
	 * `wp_agent_workflow_run_recorder` — scoped recorder resolution for
	 * reconcile/resume (#567). Returns a recorder bound to the persisted job
	 * only when `$runtime` is `datamachine` AND a job actually recorded that
	 * run id; otherwise returns the incoming value untouched so foreign
	 * runtimes (or "no recorder found") keep resolving normally.
	 *
	 * @param WP_Agent_Workflow_Run_Recorder|null $recorder Currently resolved recorder.
	 * @param string                               $runtime Owning runtime key ('' = legacy/unattributed).
	 * @param string                               $run_id  The suspended run id.
	 * @return WP_Agent_Workflow_Run_Recorder|null
	 */
	public function resolveRecorder( $recorder, string $runtime = '', string $run_id = '' ) {
		if ( self::RUNTIME_KEY !== $runtime ) {
			return $recorder;
		}

		return AgentsApiWorkflowJobRecorder::for_run( $this->db_jobs, $run_id ) ?? $recorder;
	}

	/**
	 * `wp_agent_workflow_run_status_handler` — resolves `agents/get-workflow-run`
	 * for runs Data Machine owns. Returns a callable handler only when a Data
	 * Machine job actually recorded `run_id`; otherwise returns the incoming
	 * value so other runtimes / the default run-control store still resolve it.
	 *
	 * @param callable|null $existing Currently resolved handler.
	 * @param array         $input    Canonical `agents/get-workflow-run` input.
	 * @return callable|null
	 */
	public function resolveRunStatusHandler( $existing, array $input ) {
		if ( is_callable( $existing ) ) {
			return $existing;
		}

		$run_id = is_string( $input['run_id'] ?? null ) ? trim( $input['run_id'] ) : '';
		if ( '' === $run_id ) {
			return $existing;
		}

		$found = ( new AgentsApiWorkflowJobRecorder( $this->db_jobs, array() ) )->find( $run_id );
		if ( null === $found ) {
			return $existing;
		}

		return static function ( array $input ) use ( $found ): array {
			unset( $input );
			return array(
				'run_id'      => $found->get_run_id(),
				'workflow_id' => $found->get_workflow_id(),
				'status'      => $found->get_status(),
				'started_at'  => (string) $found->get_started_at(),
				'updated_at'  => (string) $found->get_ended_at(),
				'metadata'    => $found->get_metadata(),
			);
		};
	}

	/**
	 * `agents_run_workflow_permission` — widens the canonical `manage_options`
	 * default to Data Machine's own authority model, but ONLY for dispatches
	 * that resolve to the `datamachine` runtime. Every other dispatch keeps
	 * the substrate default (or whatever another consumer's filter already
	 * granted).
	 *
	 * @param bool  $allowed Default: current_user_can( 'manage_options' ), or a
	 *                       decision an earlier filter already granted.
	 * @param array $input   Canonical agents/run-workflow input.
	 * @return bool
	 */
	public function filterPermission( bool $allowed, array $input ): bool {
		if ( $allowed ) {
			return true;
		}

		if ( self::RUNTIME_KEY !== \AgentsAPI\AI\Workflows\agents_workflow_resolve_dispatch_runtime( $input ) ) {
			return $allowed;
		}

		return PermissionHelper::can_manage();
	}
}
