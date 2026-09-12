<?php
/**
 * Storage-agnostic foreground await service for persisted workflow runs.
 *
 * @package AgentsAPI
 * @since   0.5.2
 */

namespace AgentsAPI\AI\Workflows;

defined( 'ABSPATH' ) || exit;

/**
 * Reload a workflow run and, when suspended, drive its scoped scheduler work.
 */
class WP_Agent_Workflow_Run_Awaiter {

	public const SCHEMA = 'agents-api/workflow-run-await/v1';
	private WP_Agent_Workflow_Scoped_Drain $drain;

	/**
	 * @since 0.5.2
	 * @param WP_Agent_Workflow_Scoped_Drain|null $drain Scoped foreground drain.
	 */
	public function __construct( ?WP_Agent_Workflow_Scoped_Drain $drain = null ) {
		$this->drain = $drain ?? new WP_Agent_Workflow_Scoped_Drain();
	}

	/**
	 * Await the current state of a workflow run within the supplied drain budget.
	 *
	 * The recorder is always caller-owned. A suspended run after a budget stop is a
	 * successful, reconnectable response; drain refusal is preserved in `drain`.
	 *
	 * @since 0.5.2
	 * @param string                         $run_id   Workflow run id.
	 * @param WP_Agent_Workflow_Run_Recorder $recorder Caller-owned run recorder.
	 * @param array<string,mixed>            $options  Scoped drain options.
	 * @return array{schema:string,run_id:string,status:string,terminal:bool,reconnectable:bool,result:?array<string,mixed>,drain:array<string,int|string|bool>}|\WP_Error
	 */
	public function await( string $run_id, WP_Agent_Workflow_Run_Recorder $recorder, array $options = array() ) {
		if ( ! isset( $options['group'] ) || ! is_scalar( $options['group'] ) || '' === (string) $options['group'] ) {
			$options['group'] = WP_Agent_Workflow_Action_Scheduler_Branch_Executor::group_for_run( $run_id );
		}
		// A run await must never fall back to hook-only claims: that could execute a
		// different run whose branch/resume hooks share this executor.
		$options['allow_group_fallback'] = false;

		$current = $recorder->find( $run_id );
		if ( null === $current ) {
			return new \WP_Error( 'agents_workflow_run_not_found', 'No workflow run was found for the requested run_id.' );
		}

		if ( $current->is_suspended() ) {
			$awaited = $this->drain_suspended_run( $run_id, $recorder, $options );
			$current = $awaited['result'];
			if ( null === $current ) {
				return new \WP_Error( 'agents_workflow_run_not_found', 'No workflow run was found for the requested run_id.' );
			}
			$stats = $awaited['stats'];
		} else {
			$stats = $this->zero_work_stats( $current->get_status(), $options );
		}

		$terminal = $this->is_terminal( $current );

		return array(
			'schema'        => self::SCHEMA,
			'run_id'        => $current->get_run_id(),
			'status'        => $current->get_status(),
			'terminal'      => $terminal,
			'reconnectable' => ! $terminal,
			'result'        => $terminal ? $current->to_run_result_envelope()->to_array() : null,
			'drain'         => $stats,
		);
	}

	/**
	 * Test seam that delegates to the production scoped drain.
	 *
	 * @param string                         $run_id   Workflow run id.
	 * @param WP_Agent_Workflow_Run_Recorder $recorder Run recorder.
	 * @param array<string,mixed>            $options  Drain options.
	 * @return array{result:?WP_Agent_Workflow_Run_Result,stats:array<string,int|string|bool>}
	 */
	protected function drain_suspended_run( string $run_id, WP_Agent_Workflow_Run_Recorder $recorder, array $options ): array {
		return $this->drain->drain_suspended_run( $run_id, $recorder, $options );
	}

	private function is_terminal( WP_Agent_Workflow_Run_Result $result ): bool {
		return in_array(
			$result->get_status(),
			array(
				WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED,
				WP_Agent_Workflow_Run_Result::STATUS_FAILED,
				WP_Agent_Workflow_Run_Result::STATUS_SKIPPED,
				WP_Agent_Workflow_Run_Result::STATUS_CANCELLED,
			),
			true
		);
	}

	/**
	 * Build the scoped-drain stats shape without touching the queue.
	 *
	 * @param array<string,mixed> $options Await options.
	 * @return array<string,int|string|bool>
	 */
	private function zero_work_stats( string $status, array $options ): array {
		$hooks = isset( $options['hooks'] ) && is_array( $options['hooks'] )
			? array_values( array_filter( $options['hooks'], 'is_string' ) )
			: WP_Agent_Workflow_Scoped_Drain::default_hooks();
		$hooks = empty( $hooks ) ? WP_Agent_Workflow_Scoped_Drain::default_hooks() : $hooks;

		return array(
			'batches'           => 0,
			'actions_processed' => 0,
			'completions'       => 0,
			'failures'          => 0,
			'remaining_pending' => 0,
			'total_pending'     => 0,
			'warnings'          => 0,
			'stop_reason'       => 'terminal_status',
			'terminal_state'    => $status,
			'hooks'             => implode( ',', $hooks ),
			'group'             => isset( $options['group'] ) && is_scalar( $options['group'] ) && '' !== (string) $options['group'] ? (string) $options['group'] : WP_Agent_Workflow_Scoped_Drain::default_group(),
			'available'         => WP_Agent_Workflow_Scoped_Drain::is_available(),
		);
	}
}
