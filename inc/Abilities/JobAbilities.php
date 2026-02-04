<?php
/**
 * Job Abilities
 *
 * Facade that loads and registers all modular Job ability classes.
 * Maintains backward compatibility by delegating to individual ability instances.
 *
 * @package DataMachine\Abilities
 * @since 0.17.0 Refactored to facade pattern with modular ability classes.
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\PermissionHelper;

use DataMachine\Abilities\Job\GetJobsAbility;
use DataMachine\Abilities\Job\DeleteJobsAbility;
use DataMachine\Abilities\Job\ExecuteWorkflowAbility;
use DataMachine\Abilities\Job\FlowHealthAbility;
use DataMachine\Abilities\Job\ProblemFlowsAbility;
use DataMachine\Abilities\Job\RecoverStuckJobsAbility;
use DataMachine\Abilities\Job\JobsSummaryAbility;

defined( 'ABSPATH' ) || exit;

class JobAbilities {

	private static bool $registered = false;

	private GetJobsAbility $get_jobs;
	private DeleteJobsAbility $delete_jobs;
	private ExecuteWorkflowAbility $execute_workflow;
	private FlowHealthAbility $flow_health;
	private ProblemFlowsAbility $problem_flows;
	private RecoverStuckJobsAbility $recover_stuck_jobs;
	private JobsSummaryAbility $jobs_summary;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) || self::$registered ) {
			return;
		}

		$this->get_jobs           = new GetJobsAbility();
		$this->delete_jobs        = new DeleteJobsAbility();
		$this->execute_workflow   = new ExecuteWorkflowAbility();
		$this->flow_health        = new FlowHealthAbility();
		$this->problem_flows      = new ProblemFlowsAbility();
		$this->recover_stuck_jobs = new RecoverStuckJobsAbility();
		$this->jobs_summary       = new JobsSummaryAbility();

		self::$registered = true;
	}

	/**
	 * Permission callback for abilities.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Execute get-jobs ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with jobs list.
	 */
	public function executeGetJobs( array $input ): array {
		if ( ! isset( $this->get_jobs ) ) {
			$this->get_jobs = new GetJobsAbility();
		}
		return $this->get_jobs->execute( $input );
	}

	/**
	 * Execute delete-jobs ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with deleted count.
	 */
	public function executeDeleteJobs( array $input ): array {
		if ( ! isset( $this->delete_jobs ) ) {
			$this->delete_jobs = new DeleteJobsAbility();
		}
		return $this->delete_jobs->execute( $input );
	}

	/**
	 * Execute workflow ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with job_id(s) and execution info.
	 */
	public function executeWorkflow( array $input ): array {
		if ( ! isset( $this->execute_workflow ) ) {
			$this->execute_workflow = new ExecuteWorkflowAbility();
		}
		return $this->execute_workflow->execute( $input );
	}

	/**
	 * Execute get-flow-health ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with health metrics.
	 */
	public function executeGetFlowHealth( array $input ): array {
		if ( ! isset( $this->flow_health ) ) {
			$this->flow_health = new FlowHealthAbility();
		}
		return $this->flow_health->execute( $input );
	}

	/**
	 * Execute get-problem-flows ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with problem flows.
	 */
	public function executeGetProblemFlows( array $input ): array {
		if ( ! isset( $this->problem_flows ) ) {
			$this->problem_flows = new ProblemFlowsAbility();
		}
		return $this->problem_flows->execute( $input );
	}

	/**
	 * Execute recover-stuck-jobs ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with recovered/skipped counts.
	 */
	public function executeRecoverStuckJobs( array $input ): array {
		if ( ! isset( $this->recover_stuck_jobs ) ) {
			$this->recover_stuck_jobs = new RecoverStuckJobsAbility();
		}
		return $this->recover_stuck_jobs->execute( $input );
	}

	/**
	 * Execute get-jobs-summary ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with summary counts.
	 */
	public function executeGetJobsSummary( array $input ): array {
		if ( ! isset( $this->jobs_summary ) ) {
			$this->jobs_summary = new JobsSummaryAbility();
		}
		return $this->jobs_summary->execute( $input );
	}
}
