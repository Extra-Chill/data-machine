<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact legacy reconciliation owns this jobs-table transaction.
/**
 * One-purpose reconciliation for legacy AI concurrency failures.
 *
 * @package DataMachine\Core\Database\Jobs
 */

namespace DataMachine\Core\Database\Jobs;

use DataMachine\Core\RunLifecycleStore;

defined( 'ABSPATH' ) || exit;

class LegacyAIConcurrencyReconciler {
	public const SOURCE_STATUS = 'failed - ai_concurrency_defer_exhausted';
	public const TARGET_STATUS = 'cancelled - ai_concurrency_stranded';

	/**
	 * Apply the fixed-source/fixed-target terminal reclassification.
	 *
	 * @return array{success:bool,changed:bool,current_status:?string,status:string,reconciliation:array<string,mixed>}
	 */
	public function reconcile( int $job_id ): array {
		global $wpdb;

		$audit = array(
			'type'          => 'legacy_ai_concurrency_failure',
			'source_status' => self::SOURCE_STATUS,
			'target_status' => self::TARGET_STATUS,
		);
		if ( $job_id <= 0 || false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $this->result( false, false, null, self::TARGET_STATUS, $audit );
		}

		$table = $wpdb->prefix . 'datamachine_jobs';
		$query = $wpdb->prepare( 'SELECT status, engine_data FROM %i WHERE job_id = %d FOR UPDATE', $table, $job_id );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is fully prepared above, including the table identifier.
		$job = $wpdb->get_row( $query, ARRAY_A );
		if ( ! is_array( $job ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $this->result( false, false, null, self::TARGET_STATUS, $audit );
		}

		$current_status = is_string( $job['status'] ?? null ) ? $job['status'] : '';
		$engine_data    = json_decode( (string) ( $job['engine_data'] ?? '' ), true );
		$engine_data    = is_array( $engine_data ) ? $engine_data : array();
		if ( self::TARGET_STATUS === $current_status ) {
			$wpdb->query( 'ROLLBACK' );
			$existing_audit = is_array( $engine_data['status_reconciliation'] ?? null ) ? $engine_data['status_reconciliation'] : $audit;
			return $this->result( true, false, $current_status, $current_status, $existing_audit );
		}
		if ( self::SOURCE_STATUS !== $current_status ) {
			$wpdb->query( 'ROLLBACK' );
			return $this->result( false, false, $current_status, $current_status, $audit );
		}

		$audit['schema']                      = 'datamachine.status_reconciliation.v1';
		$audit['reason']                      = 'legacy_contention_misclassified_as_failure';
		$audit['reconciled_at']               = current_time( 'mysql', true );
		$audit['actor']                       = defined( 'WP_CLI' ) && WP_CLI ? 'wp_cli' : 'runtime';
		$engine_data['status_reconciliation'] = $audit;
		if ( is_array( $engine_data['run_metrics'] ?? null ) ) {
			$engine_data['run_metrics']['terminal_status']  = self::TARGET_STATUS;
			$engine_data['run_metrics']['counts']['failed'] = 0;
		}
		if ( is_array( $engine_data['run_result'] ?? null ) ) {
			$engine_data['run_result']['status'] = self::TARGET_STATUS;

			$engine_data['run_result']['outputs']['counts']['failed'] = 0;

			$engine_data['run_result']['diagnostics']['reconciliation'] = $audit;
		}

		$updated = $wpdb->update(
			$table,
			array(
				'status'      => self::TARGET_STATUS,
				'engine_data' => wp_json_encode( $engine_data ),
			),
			array(
				'job_id' => $job_id,
				'status' => self::SOURCE_STATUS,
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);
		if ( 1 !== $updated || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			wp_cache_delete( $job_id, 'datamachine_engine_data' );
			$winner        = ( new Jobs() )->get_job( $job_id );
			$winner_status = is_array( $winner ) && is_string( $winner['status'] ?? null ) ? $winner['status'] : $current_status;
			return $this->result( false, false, $winner_status, $winner_status, $audit );
		}

		wp_cache_delete( $job_id, 'datamachine_engine_data' );
		( new RunLifecycleStore( new Jobs() ) )->mark_job_status( $job_id, self::TARGET_STATUS );
		do_action( 'datamachine_job_status_reconciled', $job_id, $audit );
		do_action(
			'datamachine_log',
			'info',
			'Legacy AI concurrency failure reclassified',
			array(
				'job_id'         => $job_id,
				'reconciliation' => $audit,
			)
		);

		return $this->result( true, true, $current_status, self::TARGET_STATUS, $audit );
	}

	private function result( bool $success, bool $changed, ?string $current_status, string $status, array $audit ): array {
		return array(
			'success'        => $success,
			'changed'        => $changed,
			'current_status' => $current_status,
			'status'         => $status,
			'reconciliation' => $audit,
		);
	}
}
