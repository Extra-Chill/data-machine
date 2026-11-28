<?php
/**
 * Diagnosis and evidence policy for missing direct-operation actions.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

use DataMachine\Core\Database\Jobs\Jobs;

defined( 'ABSPATH' ) || exit;

class DirectOperationRecoveryPolicy {

	/** Return recovery evidence only for an absent, fully fenced direct-operation receipt. */
	public static function diagnose( array $job, string $live_execution, bool $recorded_action_exists ): ?array {
		$job_id     = (int) ( $job['job_id'] ?? 0 );
		$action_id  = (int) ( $job['operation_action_id'] ?? 0 );
		$generation = (int) ( $job['operation_generation'] ?? 0 );
		$token      = (string) ( $job['operation_claim_token'] ?? '' );
		$step_id    = (string) ( $job['operation_step_id'] ?? '' );
		if ( $job_id <= 0
			|| 'direct' !== (string) ( $job['flow_id'] ?? '' )
			|| JobStatus::PROCESSING !== (string) ( $job['status'] ?? '' )
			|| 'enqueued' !== (string) ( $job['operation_state'] ?? '' )
			|| $action_id <= 0
			|| $generation <= 0
			|| '' === $token
			|| '' === $step_id
			|| 'none' !== $live_execution
			|| $recorded_action_exists ) {
			return null;
		}

		return array(
			'action_id'                 => $action_id,
			'generation'                => $generation,
			'step_id'                   => $step_id,
			'live_generation_execution' => $live_execution,
		);
	}

	/** Check the durable Action Scheduler receipt by primary key, regardless of current status. */
	public static function recordedActionExists( int $action_id ): bool {
		global $wpdb;
		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Recovery must read the current durable Action Scheduler receipt.
		$found = $wpdb->get_var( $wpdb->prepare( 'SELECT action_id FROM %i WHERE action_id = %d', $actions_table, $action_id ) );
		return null !== $found;
	}

	/** @return array<int,int> */
	public static function getProcessingSystemTaskChildren( int $parent_job_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . Jobs::TABLE_NAME;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Recovery must read current processing children before terminalizing them.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT job_id FROM %i WHERE parent_job_id = %d AND source = %s AND status = %s ORDER BY job_id ASC',
				$table,
				$parent_job_id,
				'pipeline_system_task',
				JobStatus::PROCESSING
			)
		);
		return array_map( 'intval', $ids );
	}

	/** @return array<string,mixed> */
	public static function evidence( array $job, array $diagnosis, string $status, string $trigger, int $children_terminalized, int $now = 0 ): array {
		$created = strtotime( (string) ( $job['created_at'] ?? '' ) . ' UTC' );
		$now     = $now > 0 ? $now : time();
		return array(
			'job_id'                       => (int) ( $job['job_id'] ?? 0 ),
			'flow_id'                      => (string) ( $job['flow_id'] ?? '' ),
			'parent_job_id'                => (int) ( $job['parent_job_id'] ?? 0 ),
			'status'                       => $status,
			'disposition'                  => $status,
			'reason'                       => 'recorded_operation_action_missing',
			'job_age_seconds'              => false === $created ? 0 : max( 0, $now - $created ),
			'scheduler_path'               => 'none',
			'action_id'                    => (int) $diagnosis['action_id'],
			'action_status'                => 'missing',
			'action_generation'            => (int) $diagnosis['generation'],
			'action_generation_state'      => 'current',
			'operation_state'              => (string) ( $job['operation_state'] ?? '' ),
			'operation_generation'         => (int) ( $job['operation_generation'] ?? 0 ),
			'operation_effects_begun'      => ! empty( $job['operation_effects_begun_at'] ),
			'operation_step_id'            => (string) $diagnosis['step_id'],
			'live_generation_execution'    => (string) $diagnosis['live_generation_execution'],
			'system_children_terminalized' => $children_terminalized,
			'recovery_trigger'             => $trigger,
		);
	}
}
