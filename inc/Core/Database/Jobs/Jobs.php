<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Data Machine owns the datamachine_jobs custom table; job lifecycle reads require fresh queue state and schema methods perform one-time table maintenance.
/**
 * Jobs Database Repository
 *
 * Job lifecycle management with engine data storage. Owns CRUD, status
 * transitions, flow health bookkeeping, and schema migrations for the
 * datamachine_jobs table.
 *
 * Pipeline → Flow architecture implementation.
 *
 * @package    DataMachine
 * @subpackage Core\Database\Jobs
 * @since      0.15.0
 */

namespace DataMachine\Core\Database\Jobs;

use DataMachine\Core\Database\BaseRepository;
use DataMachine\Core\Database\LifecycleStateTransition;
use DataMachine\Core\Database\RunMetadata\RunMetadata;
use DataMachine\Core\ExecutionQuery;
use DataMachine\Core\JobStatus;
use DataMachine\Core\RunMetrics;
use DataMachine\Core\RunLifecycleStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Jobs extends BaseRepository {

	const TABLE_NAME = 'datamachine_jobs';

	/** Job currently owning the connection-wide terminal transaction. */
	private static ?int $terminalizing_job = null;

	/**
	 * Known compound status suffixes for exact-match queries.
	 *
	 * Each key maps a status prefix to the known variants that share it.
	 * Used by delete_old_jobs() and count_old_jobs() to build IN clauses
	 * instead of LIKE, which enables efficient use of the idx_status_created
	 * composite index.
	 *
	 * Only prefixes whose variants are genuinely enumerable belong here.
	 * `failed` is deliberately absent: real failure statuses embed arbitrary
	 * error text (e.g. `failed - packet_failure`, `failed: policy not
	 * satisfied`), so they can never be enumerated and a prefix LIKE
	 * `failed%` is the honest match. That LIKE is a prefix pattern, so it
	 * still uses idx_status_created, and it matches both bare `failed` and
	 * every compound form — matching the LIKE semantics already used by
	 * get_jobs_count(), get_jobs_for_list_table(), and delete_jobs().
	 *
	 * @var array<string, string[]>
	 */
	private const STATUS_VARIANTS = array(
		'completed' => array(
			'completed',
			'completed_no_items',
			'agent_skipped',
		),
	);

	// ---------------------------------------------------------------------
	// CRUD
	// ---------------------------------------------------------------------

	/**
	 * Create a new job record.
	 *
	 * Supports three execution modes:
	 * - Direct execution: pipeline_id='direct', flow_id='direct' (chat/API workflows without saved pipeline/flow)
	 * - Database flow: pipeline_id and flow_id are numeric strings (saved pipelines and flows)
	 * - Standalone: pipeline_id=null, flow_id=null (jobs without pipeline/flow context)
	 *
	 * @param array $job_data Job data with optional pipeline_id and flow_id
	 * @return int|false Job ID on success, false on failure
	 */
	public function create_job( array $job_data ): int|false {
		$prepared = $this->prepare_job_insert( $job_data );
		if ( false === $prepared ) {
			return false;
		}

		$inserted = $this->insert_prepared_job( $prepared );

		if ( false === $inserted ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to insert job',
				array(
					'pipeline_id' => $prepared['pipeline_id'],
					'flow_id'     => $prepared['flow_id'],
					'db_error'    => $this->wpdb->last_error,
				)
			);
			return false;
		}

		$job_id = (int) $this->wpdb->insert_id;
		( new RunLifecycleStore( $this ) )->mark_job_created(
			$job_id,
			array(
				'run_type' => $prepared['data']['source'] ?? 'job',
				'status'   => $prepared['data']['status'] ?? JobStatus::PENDING,
			)
		);

		return $job_id;
	}

	/**
	 * Create a job once for a deterministic idempotency key, or return the existing job.
	 *
	 * @param array $job_data Job data. Requires a non-empty idempotency_key.
	 * @return array{job_id:int,created:bool,already_exists:bool,job:?array}|false Result on success, false on invalid input or insert failure.
	 */
	public function create_or_get_job( array $job_data ): array|false {
		$idempotency_key = $this->normalize_idempotency_key( $job_data['idempotency_key'] ?? '' );
		if ( '' === $idempotency_key ) {
			do_action(
				'datamachine_log',
				'error',
				'Invalid job data: create_or_get_job requires idempotency_key'
			);
			return false;
		}

		$existing = $this->get_job_by_idempotency_key( $idempotency_key );
		if ( null !== $existing ) {
			( new RunLifecycleStore( $this ) )->mark_job_created(
				(int) $existing['job_id'],
				array(
					'run_type' => $existing['source'] ?? 'job',
					'status'   => $existing['status'] ?? JobStatus::PENDING,
				)
			);

			return array(
				'job_id'         => (int) $existing['job_id'],
				'created'        => false,
				'already_exists' => true,
				'job'            => $existing,
			);
		}

		$job_data['idempotency_key'] = $idempotency_key;
		$prepared                    = $this->prepare_job_insert( $job_data );
		if ( false === $prepared ) {
			return false;
		}

		$inserted = $this->insert_prepared_job( $prepared );
		if ( false === $inserted ) {
			$existing = $this->get_job_by_idempotency_key( $idempotency_key );
			if ( null !== $existing ) {
				( new RunLifecycleStore( $this ) )->mark_job_created(
					(int) $existing['job_id'],
					array(
						'run_type' => $existing['source'] ?? 'job',
						'status'   => $existing['status'] ?? JobStatus::PENDING,
					)
				);

				return array(
					'job_id'         => (int) $existing['job_id'],
					'created'        => false,
					'already_exists' => true,
					'job'            => $existing,
				);
			}

			do_action(
				'datamachine_log',
				'error',
				'Failed to insert idempotent job',
				array(
					'pipeline_id'     => $prepared['pipeline_id'],
					'flow_id'         => $prepared['flow_id'],
					'idempotency_key' => $idempotency_key,
					'db_error'        => $this->wpdb->last_error,
				)
			);
			return false;
		}

		$job_id = (int) $this->wpdb->insert_id;
		( new RunLifecycleStore( $this ) )->mark_job_created(
			$job_id,
			array(
				'run_type' => $prepared['data']['source'] ?? 'job',
				'status'   => $prepared['data']['status'] ?? JobStatus::PENDING,
			)
		);

		return array(
			'job_id'         => $job_id,
			'created'        => true,
			'already_exists' => false,
			'job'            => $this->get_job( $job_id ),
		);
	}

	/**
	 * Fetch a job by idempotency key.
	 *
	 * @param string $idempotency_key Job idempotency key.
	 * @return array|null Job row, or null when not found.
	 */
	public function get_job_by_idempotency_key( string $idempotency_key ): ?array {
		$idempotency_key = $this->normalize_idempotency_key( $idempotency_key );
		if ( '' === $idempotency_key ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$job = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM %i WHERE idempotency_key = %s LIMIT 1', $this->table_name, $idempotency_key ), ARRAY_A );

		if ( $job && isset( $job['engine_data'] ) && is_string( $job['engine_data'] ) ) {
			$decoded = json_decode( $job['engine_data'], true );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				$job['engine_data'] = $decoded;
			}
		}

		return $job ? $job : null;
	}

	/**
	 * Prepare sanitized insert data shared by create_job() and create_or_get_job().
	 *
	 * @param array $job_data Raw job data.
	 * @return array{data:array,format:array,pipeline_id:mixed,flow_id:mixed}|false Prepared insert data, or false when invalid.
	 */
	private function prepare_job_insert( array $job_data ): array|false {
		$pipeline_id = $job_data['pipeline_id'] ?? null;
		$flow_id     = $job_data['flow_id'] ?? null;

		// Direct execution: both must be explicitly 'direct'.
		$is_direct_execution = ( 'direct' === $pipeline_id && 'direct' === $flow_id );

		// Database flow: both must be valid numeric IDs > 0.
		$is_database_flow = ( is_numeric( $pipeline_id ) && (int) $pipeline_id > 0 && is_numeric( $flow_id ) && (int) $flow_id > 0 );

		// No pipeline/flow context: both are null.
		$is_contextless = ( null === $pipeline_id && null === $flow_id );

		if ( ! $is_direct_execution && ! $is_database_flow && ! $is_contextless ) {
			do_action(
				'datamachine_log',
				'error',
				'Invalid job data: must provide both IDs as "direct", both as valid numeric IDs, or both as null',
				array(
					'pipeline_id' => $pipeline_id,
					'flow_id'     => $flow_id,
				)
			);
			return false;
		}

		// Normalize to string for database storage (null stays null when no context).
		if ( $is_database_flow ) {
			$pipeline_id = (string) absint( $pipeline_id );
			$flow_id     = (string) absint( $flow_id );
		}
		// Direct and contextless keep their values ('direct' or null).

		// Sanitize source — accept any string, don't gatekeep values.
		$default_source = $is_contextless ? 'direct' : ( $is_direct_execution ? 'direct' : 'pipeline' );
		$source         = sanitize_key( $job_data['source'] ?? $default_source );

		$label = isset( $job_data['label'] ) ? sanitize_text_field( $job_data['label'] ) : null;

		$parent_job_id = isset( $job_data['parent_job_id'] ) ? absint( $job_data['parent_job_id'] ) : 0;
		$user_id       = isset( $job_data['user_id'] ) ? absint( $job_data['user_id'] ) : 0;
		$agent_id      = isset( $job_data['agent_id'] ) ? absint( $job_data['agent_id'] ) : null;

		$data = array(
			'user_id' => $user_id,
			'source'  => $source,
			'label'   => $label,
			'status'  => 'pending',
		);

		$format = array( '%d', '%s', '%s', '%s' );

		if ( null !== $agent_id && $agent_id > 0 ) {
			$data['agent_id'] = $agent_id;
			$format[]         = '%d';
		}

		// Only include pipeline_id/flow_id when they have values (NULL omission lets DB default apply).
		if ( ! $is_contextless ) {
			$data['pipeline_id'] = $pipeline_id;
			$data['flow_id']     = $flow_id;
			$format[]            = '%s';
			$format[]            = '%s';
		}

		if ( $parent_job_id > 0 ) {
			$data['parent_job_id'] = $parent_job_id;
			$format[]              = '%d';
		}

		$idempotency_key = $this->normalize_idempotency_key( $job_data['idempotency_key'] ?? '' );
		if ( '' !== $idempotency_key ) {
			$data['idempotency_key'] = $idempotency_key;
			$format[]                = '%s';
		}

		if ( isset( $job_data['engine_data'] ) && is_array( $job_data['engine_data'] ) ) {
			$encoded_engine_data = wp_json_encode( $job_data['engine_data'] );
			if ( false === $encoded_engine_data ) {
				return false;
			}

			$data['engine_data'] = $encoded_engine_data;
			$format[]            = '%s';
		}

		foreach ( array( 'request_fingerprint', 'operation_state', 'operation_step_id' ) as $operation_field ) {
			if ( isset( $job_data[ $operation_field ] ) && is_scalar( $job_data[ $operation_field ] ) ) {
				$data[ $operation_field ] = sanitize_text_field( (string) $job_data[ $operation_field ] );
				$format[]                 = '%s';
			}
		}

		return array(
			'data'        => $data,
			'format'      => $format,
			'pipeline_id' => $pipeline_id,
			'flow_id'     => $flow_id,
		);
	}

	/**
	 * Claim responsibility for durably enqueueing a job operation.
	 *
	 * Failed/preparing operations are immediately reclaimable. An enqueuing
	 * claim becomes reclaimable after the lease to recover a crashed submitter.
	 *
	 * @param int $job_id        Job ID.
	 * @param int $lease_seconds Claim lease in seconds.
	 * @return array{token:string,generation:int}|false Claim details, or false when another generation owns it.
	 */
	public function claim_operation_enqueue( int $job_id, int $lease_seconds = 30 ): array|false {
		if ( $job_id <= 0 ) {
			return false;
		}

		$lease_cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $lease_seconds ) );
		$claimed_at   = gmdate( 'Y-m-d H:i:s' );
		$claim_token  = bin2hex( random_bytes( 16 ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE %i SET operation_state = 'enqueuing', operation_claimed_at = %s, operation_claim_token = %s, operation_generation = COALESCE(operation_generation, 0) + 1 WHERE job_id = %d AND (operation_state IN ('preparing', 'enqueue_failed') OR (operation_state = 'enqueuing' AND (operation_claimed_at IS NULL OR operation_claimed_at < %s)))",
				$this->table_name,
				$claimed_at,
				$claim_token,
				$job_id,
				$lease_cutoff
			)
		);

		if ( 1 !== (int) $updated ) {
			return false;
		}

		$job = $this->get_job( $job_id );
		if ( ! is_array( $job ) || ! hash_equals( $claim_token, (string) ( $job['operation_claim_token'] ?? '' ) ) ) {
			return false;
		}

		return array(
			'token'      => $claim_token,
			'generation' => (int) ( $job['operation_generation'] ?? 0 ),
		);
	}

	/**
	 * Check whether an enqueue claim still owns the active generation.
	 */
	public function owns_operation_enqueue_claim( int $job_id, string $token, int $generation ): bool {
		$job = $this->get_job( $job_id );

		return is_array( $job )
			&& 'enqueuing' === ( $job['operation_state'] ?? '' )
			&& $generation === (int) ( $job['operation_generation'] ?? 0 )
			&& '' !== $token
			&& hash_equals( $token, (string) ( $job['operation_claim_token'] ?? '' ) );
	}

	/**
	 * Make an enqueued-but-unowned pending operation reclaimable.
	 *
	 * @param int $job_id Job ID.
	 * @return bool Whether the operation was reset.
	 */
	public function reclaim_missing_operation_action( int $job_id ): bool {
		if ( $job_id <= 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE %i SET operation_state = 'enqueue_failed', operation_action_id = NULL WHERE job_id = %d AND status = 'pending' AND operation_state = 'enqueued'",
				$this->table_name,
				$job_id
			)
		);

		return 1 === (int) $updated;
	}

	/**
	 * Record the durable enqueue outcome for an operation.
	 *
	 * @param int    $job_id   Job ID.
	 * @param string $state    enqueued or enqueue_failed.
	 * @param int    $action_id  Action Scheduler action ID when available.
	 * @param string $token      Active claim token.
	 * @param int    $generation Active claim generation.
	 * @return bool
	 */
	public function finish_operation_enqueue( int $job_id, string $state, int $action_id, string $token, int $generation ): bool {
		if ( $job_id <= 0 || '' === $token || $generation <= 0 || ! in_array( $state, array( 'enqueued', 'enqueue_failed' ), true ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE %i SET operation_state = %s, operation_claimed_at = NULL, operation_action_id = %d WHERE job_id = %d AND operation_state = %s AND operation_generation = %d AND operation_claim_token = %s',
				$this->table_name,
				$state,
				max( 0, $action_id ),
				$job_id,
				'enqueuing',
				$generation,
				$token
			)
		);

		return 1 === (int) $updated;
	}

	/**
	 * Reopen a failed job for an explicit retry.
	 *
	 * @param int $job_id Job ID.
	 * @return bool Whether the failed row was reopened.
	 */
	public function reopen_failed_job( int $job_id ): bool {
		if ( $job_id <= 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE %i SET status = 'pending', completed_at = NULL, operation_state = 'preparing', operation_claimed_at = NULL, operation_claim_token = NULL, operation_action_id = NULL WHERE job_id = %d AND status LIKE 'failed%'",
				$this->table_name,
				$job_id
			)
		);

		return 1 === (int) $updated;
	}

	/**
	 * Insert prepared job data.
	 *
	 * @param array{data:array,format:array} $prepared Prepared insert data.
	 * @return int|false Number of rows inserted, or false on failure.
	 */
	private function insert_prepared_job( array $prepared ): int|false {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $this->wpdb->insert( $this->table_name, $prepared['data'], $prepared['format'] );
	}

	/**
	 * Normalize a job idempotency key for storage and lookup.
	 *
	 * @param mixed $idempotency_key Raw idempotency key.
	 * @return string Normalized idempotency key, or empty string.
	 */
	private function normalize_idempotency_key( mixed $idempotency_key ): string {
		if ( ! is_scalar( $idempotency_key ) ) {
			return '';
		}

		return substr( sanitize_text_field( (string) $idempotency_key ), 0, 191 );
	}

	public function get_job( int $job_id ): ?array {
		if ( empty( $job_id ) ) {
			return null;
		}

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$job = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM %i WHERE job_id = %d', $this->table_name, $job_id ), ARRAY_A );

		if ( $job && isset( $job['engine_data'] ) && is_string( $job['engine_data'] ) ) {
			$decoded = json_decode( $job['engine_data'], true );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				$job['engine_data'] = $decoded;
			}
		}

		return $job;
	}

	/**
	 * Get jobs count with optional filtering.
	 *
	 * @param array $args Filter arguments:
	 *                    - flow_id: Filter by flow ID or 'direct' (optional)
	 *                    - pipeline_id: Filter by pipeline ID or 'direct' (optional)
	 *                    - status: Filter by status (optional)
	 * @return int Total count
	 */
	public function get_jobs_count( array $args = array() ): int {
		$where_clauses = array();
		$where_values  = array();

		if ( ! empty( $args['flow_id'] ) ) {
			$where_clauses[] = 'flow_id = %s';
			$where_values[]  = (string) $args['flow_id'];
		}

		if ( ! empty( $args['pipeline_id'] ) ) {
			$where_clauses[] = 'pipeline_id = %s';
			$where_values[]  = (string) $args['pipeline_id'];
		}

		if ( ! empty( $args['status'] ) ) {
			$status_value    = sanitize_text_field( $args['status'] );
			$where_clauses[] = 'status LIKE %s';
			$where_values[]  = $this->wpdb->esc_like( $status_value ) . '%';
		}

		if ( ! empty( $args['source'] ) ) {
			$where_clauses[] = 'source = %s';
			$where_values[]  = sanitize_text_field( $args['source'] );
		}

		if ( ! empty( $args['handler'] ) ) {
			$handler = sanitize_key( (string) $args['handler'] );
			if ( '' !== $handler ) {
				// Match the promoted handler_slug column so filtering survives
				// engine_data being shed from terminal jobs by retention.
				$where_clauses[] = 'handler_slug = %s';
				$where_values[]  = $handler;
			}
		}

		if ( isset( $args['user_id'] ) ) {
			$where_clauses[] = 'user_id = %d';
			$where_values[]  = absint( $args['user_id'] );
		}

		if ( isset( $args['agent_id'] ) ) {
			$where_clauses[] = 'agent_id = %d';
			$where_values[]  = absint( $args['agent_id'] );
		}

		if ( ! empty( $args['since'] ) ) {
			$where_clauses[] = 'created_at >= %s';
			$where_values[]  = sanitize_text_field( $args['since'] );
		}

		if ( isset( $args['parent_job_id'] ) ) {
			$where_clauses[] = 'parent_job_id = %d';
			$where_values[]  = absint( $args['parent_job_id'] );
		}

		if ( ! empty( $args['hide_children'] ) ) {
			$where_clauses[] = '(parent_job_id IS NULL OR parent_job_id = 0)';
		}

		$where_sql = '';
		if ( ! empty( $where_clauses ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where_clauses );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$query = $this->wpdb->prepare(
			"SELECT COUNT(job_id) FROM %i {$where_sql}",
			array_merge( array( $this->table_name ), $where_values )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
		$count = $this->wpdb->get_var( $query );

		return (int) $count;
	}

	/**
	 * Count in-flight (non-terminal) jobs.
	 *
	 * "In-flight" means a job that Action Scheduler is still fanning out work
	 * for: pending (admitted, first step not yet scheduled) or processing
	 * (executing steps). This is the load the scheduler must throttle against
	 * — every in-flight job spawns a chain of `datamachine_execute_step`
	 * actions, so admitting an unbounded number of them is what bloats the
	 * Action Scheduler tables and deadlocks the claim query.
	 *
	 * Deliberately a single cheap COUNT over an indexed status column — it
	 * never hydrates or decodes the heavy engine_data blob.
	 *
	 * @return int Number of pending + processing jobs.
	 */
	public function count_active_jobs(): int {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$query = $this->wpdb->prepare(
			'SELECT COUNT(job_id) FROM %i WHERE status IN (%s, %s)',
			$this->table_name,
			'pending',
			'processing'
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above.
		return (int) $this->wpdb->get_var( $query );
	}

	/**
	 * Get memory-safe aggregate job summary counts.
	 *
	 * Uses grouped SQL queries so operator dashboards can inspect large job
	 * backlogs without hydrating or decoding long engine_data blobs.
	 *
	 * @param array $args Filter arguments matching get_jobs_count().
	 * @return array Summary buckets and totals.
	 */
	public function get_jobs_summary( array $args = array() ): array {
		$where_parts  = $this->build_jobs_summary_where( $args, 'j' );
		$where_sql    = $where_parts['sql'];
		$where_values = $where_parts['values'];

		return array(
			'total'                  => $this->get_jobs_count( $args ),
			'failed_count'           => $this->get_jobs_count( array_merge( $args, array( 'status' => 'failed' ) ) ),
			'stuck_processing_count' => $this->get_stuck_processing_count( $args ),
			'status'                 => $this->get_status_summary_rows( $where_sql, $where_values ),
			'pipeline'               => $this->get_pipeline_summary_rows( $where_sql, $where_values ),
			'flow'                   => $this->get_flow_summary_rows( $where_sql, $where_values ),
			'handler'                => $this->get_handler_summary_rows( $args, $where_sql, $where_values ),
			'filters'                => $this->summarize_job_filters( $args ),
		);
	}

	/**
	 * Build WHERE SQL for aggregate summary queries.
	 *
	 * @param array  $args  Filter arguments.
	 * @param string $alias Table alias.
	 * @return array{sql:string,values:array}
	 */
	private function build_jobs_summary_where( array $args, string $alias = 'j' ): array {
		$prefix        = '' !== $alias ? $alias . '.' : '';
		$where_clauses = array();
		$where_values  = array();

		if ( ! empty( $args['flow_id'] ) ) {
			$where_clauses[] = $prefix . 'flow_id = %s';
			$where_values[]  = (string) $args['flow_id'];
		}

		if ( ! empty( $args['pipeline_id'] ) ) {
			$where_clauses[] = $prefix . 'pipeline_id = %s';
			$where_values[]  = (string) $args['pipeline_id'];
		}

		if ( ! empty( $args['status'] ) ) {
			$status_value    = sanitize_text_field( $args['status'] );
			$where_clauses[] = $prefix . 'status LIKE %s';
			$where_values[]  = $this->wpdb->esc_like( $status_value ) . '%';
		}

		if ( ! empty( $args['source'] ) ) {
			$where_clauses[] = $prefix . 'source = %s';
			$where_values[]  = sanitize_text_field( $args['source'] );
		}

		if ( ! empty( $args['handler'] ) ) {
			$handler = sanitize_key( (string) $args['handler'] );
			if ( '' !== $handler ) {
				// Match the promoted handler_slug column so filtering survives
				// engine_data being shed from terminal jobs by retention.
				$where_clauses[] = $prefix . 'handler_slug = %s';
				$where_values[]  = $handler;
			}
		}

		if ( isset( $args['user_id'] ) ) {
			$where_clauses[] = $prefix . 'user_id = %d';
			$where_values[]  = absint( $args['user_id'] );
		}

		if ( isset( $args['agent_id'] ) ) {
			$where_clauses[] = $prefix . 'agent_id = %d';
			$where_values[]  = absint( $args['agent_id'] );
		}

		if ( ! empty( $args['since'] ) ) {
			$where_clauses[] = $prefix . 'created_at >= %s';
			$where_values[]  = sanitize_text_field( $args['since'] );
		}

		if ( isset( $args['parent_job_id'] ) ) {
			$where_clauses[] = $prefix . 'parent_job_id = %d';
			$where_values[]  = absint( $args['parent_job_id'] );
		}

		if ( ! empty( $args['hide_children'] ) ) {
			$where_clauses[] = '(' . $prefix . 'parent_job_id IS NULL OR ' . $prefix . 'parent_job_id = 0)';
		}

		return array(
			'sql'    => empty( $where_clauses ) ? '' : 'WHERE ' . implode( ' AND ', $where_clauses ),
			'values' => $where_values,
		);
	}

	/**
	 * Get aggregate status summary rows.
	 */
	private function get_status_summary_rows( string $where_sql, array $where_values ): array {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$query = $this->wpdb->prepare(
			"SELECT
				CASE
					WHEN LEFT(j.status, 13) = 'agent_skipped' THEN 'agent_skipped'
					WHEN LEFT(j.status, 18) = 'completed_no_items' THEN 'completed_no_items'
					WHEN LEFT(j.status, 6) = 'failed' THEN 'failed'
					ELSE j.status
				END AS status,
				COUNT(*) AS count
			 FROM %i j
			 {$where_sql}
			 GROUP BY status
			 ORDER BY count DESC",
			array_merge( array( $this->table_name ), $where_values )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above from fixed SQL fragments and placeholders.
		$rows = $this->wpdb->get_results( $query, ARRAY_A );

		return $this->normalize_summary_rows( $rows ? $rows : array(), array( 'status' ) );
	}

	/**
	 * Get aggregate pipeline summary rows.
	 */
	private function get_pipeline_summary_rows( string $where_sql, array $where_values ): array {
		$pipelines_table = $this->wpdb->prefix . 'datamachine_pipelines';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$query = $this->wpdb->prepare(
			"SELECT j.pipeline_id, p.pipeline_name, COUNT(*) AS count
			 FROM %i j
			 LEFT JOIN %i p ON j.pipeline_id = p.pipeline_id
			 {$where_sql}
			 GROUP BY j.pipeline_id, p.pipeline_name
			 ORDER BY count DESC",
			array_merge( array( $this->table_name, $pipelines_table ), $where_values )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above from fixed SQL fragments and placeholders.
		$rows = $this->wpdb->get_results( $query, ARRAY_A );

		return $this->normalize_summary_rows( $rows ? $rows : array(), array( 'pipeline_id', 'pipeline_name' ) );
	}

	/**
	 * Get aggregate flow summary rows.
	 */
	private function get_flow_summary_rows( string $where_sql, array $where_values ): array {
		$flows_table = $this->wpdb->prefix . 'datamachine_flows';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$query = $this->wpdb->prepare(
			"SELECT j.flow_id, f.flow_name, j.pipeline_id, COUNT(*) AS count
			 FROM %i j
			 LEFT JOIN %i f ON j.flow_id = f.flow_id
			 {$where_sql}
			 GROUP BY j.flow_id, f.flow_name, j.pipeline_id
			 ORDER BY count DESC",
			array_merge( array( $this->table_name, $flows_table ), $where_values )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above from fixed SQL fragments and placeholders.
		$rows = $this->wpdb->get_results( $query, ARRAY_A );

		return $this->normalize_summary_rows( $rows ? $rows : array(), array( 'flow_id', 'flow_name', 'pipeline_id' ) );
	}

	/**
	 * Get aggregate handler summary rows without selecting engine_data blobs.
	 */
	private function get_handler_summary_rows( array $args, string $where_sql, array $where_values ): array {
		if ( ! empty( $args['handler'] ) ) {
			$handler = sanitize_key( (string) $args['handler'] );
			return '' === $handler ? array() : array(
				array(
					'handler_slug' => $handler,
					'count'        => $this->get_jobs_count( $args ),
				),
			);
		}

		// Aggregate from the promoted handler_slug column rather than scanning
		// engine_data. This keeps handler summaries correct even after
		// retention sheds engine_data from terminal jobs (the column survives).
		$handler_where = '' === $where_sql
			? "WHERE j.handler_slug IS NOT NULL AND j.handler_slug != ''"
			: $where_sql . " AND j.handler_slug IS NOT NULL AND j.handler_slug != ''";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$query = $this->wpdb->prepare(
			"SELECT j.handler_slug AS handler_slug, COUNT(*) AS count
			 FROM %i j
			 {$handler_where}
			 GROUP BY j.handler_slug
			 ORDER BY count DESC",
			array_merge( array( $this->table_name ), $where_values )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above from fixed SQL fragments and placeholders.
		$rows = $this->wpdb->get_results( $query, ARRAY_A );

		return $this->normalize_summary_rows( $rows ? $rows : array(), array( 'handler_slug' ) );
	}

	/**
	 * Count processing jobs older than the dashboard stuck threshold.
	 */
	private function get_stuck_processing_count( array $args ): int {
		$stuck_args           = $args;
		$stuck_args['status'] = 'processing';
		$where_parts          = $this->build_jobs_summary_where( $stuck_args, 'j' );
		$where_sql            = '' === $where_parts['sql'] ? 'WHERE j.created_at < %s' : $where_parts['sql'] . ' AND j.created_at < %s';
		$stuck_seconds        = defined( 'HOUR_IN_SECONDS' ) ? 2 * HOUR_IN_SECONDS : 7200;
		$where_values         = array_merge( $where_parts['values'], array( gmdate( 'Y-m-d H:i:s', time() - $stuck_seconds ) ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$query = $this->wpdb->prepare(
			"SELECT COUNT(j.job_id) FROM %i j {$where_sql}",
			array_merge( array( $this->table_name ), $where_values )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above from fixed SQL fragments and placeholders.
		return (int) $this->wpdb->get_var( $query );
	}

	/**
	 * Normalize aggregate rows to stable scalar arrays.
	 */
	private function normalize_summary_rows( array $rows, array $fields ): array {
		$normalized = array();

		foreach ( $rows as $row ) {
			$item = array();
			foreach ( $fields as $field ) {
				$item[ $field ] = isset( $row[ $field ] ) ? (string) $row[ $field ] : '';
			}
			$item['count'] = (int) ( $row['count'] ?? 0 );
			$normalized[]  = $item;
		}

		return $normalized;
	}

	/**
	 * Return the filters applied to a job summary query.
	 */
	private function summarize_job_filters( array $args ): array {
		$filters = array();
		foreach ( array( 'pipeline_id', 'flow_id', 'handler', 'status', 'source', 'since', 'user_id', 'agent_id', 'parent_job_id', 'hide_children' ) as $key ) {
			if ( isset( $args[ $key ] ) && '' !== $args[ $key ] && null !== $args[ $key ] ) {
				$filters[ $key ] = $args[ $key ];
			}
		}

		return $filters;
	}

	/**
	 * Get paginated jobs with pipeline and flow names.
	 *
	 * Supports filtering by flow_id, pipeline_id, and status.
	 *
	 * @param array $args Query arguments:
	 *                    - orderby: Column to order by (default: 'j.job_id')
	 *                    - order: ASC or DESC (default: 'DESC')
	 *                    - per_page: Results per page (default: 20)
	 *                    - offset: Pagination offset (default: 0)
	 *                    - flow_id: Filter by flow ID (optional)
	 *                    - pipeline_id: Filter by pipeline ID (optional)
	 *                    - status: Filter by status (optional)
	 * @return array Jobs with pipeline and flow names
	 */
	public function get_jobs_for_list_table( array $args ): array {
		$orderby  = $args['orderby'] ?? 'j.job_id';
		$order    = strtoupper( $args['order'] ?? 'DESC' );
		$per_page = (int) ( $args['per_page'] ?? 20 );
		$offset   = (int) ( $args['offset'] ?? 0 );
		$fields   = $args['fields'] ?? null;

		$pipelines_table = $this->wpdb->prefix . 'datamachine_pipelines';
		$flows_table     = $this->wpdb->prefix . 'datamachine_flows';

		// Validate order direction
		$order = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';

		// Validate orderby column (whitelist approach)
		$valid_orderby = array(
			'j.job_id',
			'j.pipeline_id',
			'j.flow_id',
			'j.status',
			'j.created_at',
			'j.completed_at',
			'p.pipeline_name',
			'f.flow_name',
		);
		if ( ! in_array( $orderby, $valid_orderby, true ) ) {
			$orderby = 'j.job_id';
		}

		$where_clauses = array();
		$where_values  = array();

		if ( ! empty( $args['flow_id'] ) ) {
			$where_clauses[] = 'j.flow_id = %s';
			$where_values[]  = (string) $args['flow_id'];
		}

		if ( ! empty( $args['pipeline_id'] ) ) {
			$where_clauses[] = 'j.pipeline_id = %s';
			$where_values[]  = (string) $args['pipeline_id'];
		}

		if ( ! empty( $args['status'] ) ) {
			$status_value = sanitize_text_field( $args['status'] );
			// Prefix match: --status=failed matches "failed", "failed:reason", etc.
			$where_clauses[] = 'j.status LIKE %s';
			$where_values[]  = $this->wpdb->esc_like( $status_value ) . '%';
		}

		if ( ! empty( $args['source'] ) ) {
			$where_clauses[] = 'j.source = %s';
			$where_values[]  = sanitize_text_field( $args['source'] );
		}

		$job_id_filter = array_values( array_filter( array_map( 'absint', (array) ( $args['job_ids'] ?? array() ) ) ) );
		if ( ! empty( $job_id_filter ) ) {
			$where_clauses[] = 'j.job_id IN (' . implode( ',', array_fill( 0, count( $job_id_filter ), '%d' ) ) . ')';
			$where_values    = array_merge( $where_values, $job_id_filter );
		}

		if ( ! empty( $args['handler'] ) ) {
			$handler = sanitize_key( (string) $args['handler'] );
			if ( '' !== $handler ) {
				// Match the promoted handler_slug column so filtering survives
				// engine_data being shed from terminal jobs by retention.
				$where_clauses[] = 'j.handler_slug = %s';
				$where_values[]  = $handler;
			}
		}

		foreach ( array_filter( (array) ( $args['engine_data_contains'] ?? array() ), 'is_string' ) as $engine_data_marker ) {
			if ( '' === $engine_data_marker ) {
				continue;
			}

			$where_clauses[] = 'j.engine_data LIKE %s';
			$where_values[]  = '%' . $this->wpdb->esc_like( $engine_data_marker ) . '%';
		}

		if ( isset( $args['user_id'] ) ) {
			$where_clauses[] = 'j.user_id = %d';
			$where_values[]  = absint( $args['user_id'] );
		}

		if ( isset( $args['agent_id'] ) ) {
			$where_clauses[] = 'j.agent_id = %d';
			$where_values[]  = absint( $args['agent_id'] );
		}

		if ( ! empty( $args['since'] ) ) {
			$where_clauses[] = 'j.created_at >= %s';
			$where_values[]  = sanitize_text_field( $args['since'] );
		}

		if ( isset( $args['parent_job_id'] ) ) {
			$where_clauses[] = 'j.parent_job_id = %d';
			$where_values[]  = absint( $args['parent_job_id'] );
		}

		if ( ! empty( $args['hide_children'] ) ) {
			$where_clauses[] = '(j.parent_job_id IS NULL OR j.parent_job_id = 0)';
		}

		$where_sql = '';
		if ( ! empty( $where_clauses ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where_clauses );
		}

		$select_fields       = 'j.*, p.pipeline_name, f.flow_name';
		$include_child_count = true;
		$decode_engine_data  = true;

		if ( is_array( $fields ) && ! empty( $fields ) ) {
			$allowed_fields = array(
				'job_id'        => 'j.job_id',
				'user_id'       => 'j.user_id',
				'pipeline_id'   => 'j.pipeline_id',
				'flow_id'       => 'j.flow_id',
				'source'        => 'j.source',
				'label'         => 'j.label',
				'parent_job_id' => 'j.parent_job_id',
				'status'        => 'j.status',
				'engine_data'   => 'j.engine_data',
				'created_at'    => 'j.created_at',
				'completed_at'  => 'j.completed_at',
				'pipeline_name' => 'p.pipeline_name',
				'flow_name'     => 'f.flow_name',
			);

			$requested_fields = array_values( array_unique( array_filter( array_map( 'strval', $fields ) ) ) );
			if ( ! in_array( 'job_id', $requested_fields, true ) ) {
				$requested_fields[] = 'job_id';
			}

			$select_parts = array();
			foreach ( $requested_fields as $field ) {
				if ( isset( $allowed_fields[ $field ] ) ) {
					$select_parts[] = $allowed_fields[ $field ] . ' AS ' . $field;
				}
			}

			if ( ! empty( $select_parts ) ) {
				$select_fields       = implode( ', ', $select_parts );
				$include_child_count = in_array( 'child_count', $requested_fields, true );
				$decode_engine_data  = in_array( 'engine_data', $requested_fields, true );
			}
		}

		$child_count_select = $include_child_count
			? ', (SELECT COUNT(*) FROM %i c WHERE c.parent_job_id = j.job_id) AS child_count'
			: '';
		$query_tables       = $include_child_count
			? array( $this->table_name, $this->table_name, $pipelines_table, $flows_table )
			: array( $this->table_name, $pipelines_table, $flows_table );

		// Note: orderby is validated above, so safe to interpolate.
		// For direct execution jobs, LEFT JOINs will return NULL for pipeline_name/flow_name.
		// JOIN uses j.pipeline_id (varchar) directly against CAST of p.pipeline_id (int) to varchar
		// for index-friendly matching on the jobs table side.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$query = $this->wpdb->prepare(
			"SELECT {$select_fields}{$child_count_select}
			 FROM %i j
			 LEFT JOIN %i p ON j.pipeline_id = p.pipeline_id
			 LEFT JOIN %i f ON j.flow_id = f.flow_id
			 {$where_sql}
			 ORDER BY {$orderby} {$order}
			 LIMIT %d OFFSET %d",
			array_merge(
				$query_tables,
				$where_values,
				array( $per_page, $offset )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
		$results = $this->wpdb->get_results( $query, ARRAY_A );
		if ( $results && $decode_engine_data ) {
			foreach ( $results as &$result ) {
				if ( isset( $result['engine_data'] ) && is_string( $result['engine_data'] ) && '' !== $result['engine_data'] ) {
					$decoded               = json_decode( $result['engine_data'], true );
					$result['engine_data'] = JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ? $decoded : array();
				} else {
					$result['engine_data'] = array();
				}
			}
			unset( $result );
		}

		return $results ? $results : array();
	}

	/**
	 * Query execution job records by exact engine metadata filters.
	 *
	 * This is the supported read-only primitive for downstream consumers that
	 * need to find executions by generic metadata without querying internal
	 * tables or decoding engine_data themselves.
	 *
	 * @param array $args Query arguments. Supports get_jobs_for_list_table args plus:
	 *                    - metadata: array of engine_data dot-path => exact value.
	 *                    - metadata_scan_limit: max candidate rows to scan after indexed filters.
	 * @return array{jobs:array,total:int,scanned:int,scan_limit:int,filters:array}
	 */
	public function query_executions_by_metadata( array $args = array() ): array {
		$metadata_filters = ExecutionQuery::normalize_metadata_filters( $args['metadata'] ?? array() );
		$per_page         = max( 1, min( 500, (int) ( $args['per_page'] ?? 50 ) ) );
		$offset           = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$run_metadata     = new RunMetadata();
		$matching_job_ids = $run_metadata->query_job_ids( $metadata_filters, $per_page, $offset );
		$total            = $run_metadata->count_jobs( $metadata_filters );
		$has_ownership_scope = isset( $args['user_id'] ) || isset( $args['agent_id'] );
		if ( ! $has_ownership_scope && ( ! empty( $matching_job_ids ) || $total > 0 ) ) {
			if ( empty( $matching_job_ids ) ) {
				return array(
					'jobs'       => array(),
					'total'      => $total,
					'scanned'    => 0,
					'scan_limit' => $per_page,
					'filters'    => $metadata_filters,
					'indexed'    => true,
				);
			}

			$query_args             = $args;
			$query_args['job_ids']  = $matching_job_ids;
			$query_args['per_page'] = $per_page;
			$query_args['offset']   = 0;
			unset( $query_args['metadata'], $query_args['metadata_scan_limit'], $query_args['engine_data_contains'] );

			return array(
				'jobs'       => $this->get_jobs_for_list_table( $query_args ),
				'total'      => $total,
				'scanned'    => count( $matching_job_ids ),
				'scan_limit' => $per_page,
				'filters'    => $metadata_filters,
				'indexed'    => true,
			);
		}

		$scan_limit = max( $per_page + $offset, min( 5000, (int) ( $args['metadata_scan_limit'] ?? 1000 ) ) );

		$query_args                         = $args;
		$query_args['per_page']             = $scan_limit;
		$query_args['offset']               = 0;
		$query_args['fields']               = $this->metadata_query_fields( $args['fields'] ?? array() );
		$key_markers                        = array_map(
			static function ( string $path ): string {
				$segments = explode( '.', $path );
				return '"' . end( $segments ) . '"';
			},
			array_keys( $metadata_filters )
		);
		$query_args['engine_data_contains'] = array_values( array_unique( array_merge( (array) ( $args['engine_data_contains'] ?? array() ), $key_markers ) ) );

		unset( $query_args['metadata'], $query_args['metadata_scan_limit'] );

		$candidates = $this->get_jobs_for_list_table( $query_args );
		$matches    = array_values(
			array_filter(
				$candidates,
				static function ( array $job ) use ( $metadata_filters ): bool {
					return ExecutionQuery::matches_metadata_filters( is_array( $job['engine_data'] ?? null ) ? $job['engine_data'] : array(), $metadata_filters );
				}
			)
		);

		$jobs = array_slice( $matches, $offset, $per_page );
		if ( is_array( $args['fields'] ?? null ) && ! in_array( 'engine_data', $args['fields'], true ) ) {
			foreach ( $jobs as &$job ) {
				unset( $job['engine_data'] );
			}
			unset( $job );
		}

		return array(
			'jobs'       => $jobs,
			'total'      => count( $matches ),
			'scanned'    => count( $candidates ),
			'scan_limit' => $scan_limit,
			'filters'    => $metadata_filters,
			'indexed'    => false,
		);
	}

	/**
	 * Ensure metadata queries include engine_data for exact matching.
	 *
	 * @param mixed $fields Requested fields.
	 * @return array Fields needed for candidate matching.
	 */
	private function metadata_query_fields( mixed $fields ): array {
		$fields = is_array( $fields ) ? $fields : array();
		if ( empty( $fields ) ) {
			return array();
		}

		if ( ! in_array( 'engine_data', $fields, true ) ) {
			$fields[] = 'engine_data';
		}

		return $fields;
	}

	/**
	 * Get all jobs for pipeline deletion impact analysis.
	 */
	public function get_jobs_for_pipeline( int $pipeline_id ): array {
		if ( $pipeline_id <= 0 ) {
			return array();
		}

		$db_flows = new \DataMachine\Core\Database\Flows\Flows();

		$flows = $db_flows->get_flows_for_pipeline( $pipeline_id );
		if ( empty( $flows ) ) {
			return array();
		}

		$all_jobs = array();
		foreach ( $flows as $flow ) {
			$flow_id   = $flow['flow_id'];
			$flow_jobs = $this->get_jobs_for_flow( $flow_id );
			$all_jobs  = array_merge( $all_jobs, $flow_jobs );
		}

		if ( ! empty( $all_jobs ) ) {
			usort(
				$all_jobs,
				function ( $a, $b ) {
					$time_a = is_array( $a ) ? $a['created_at'] : $a->created_at;
					$time_b = is_array( $b ) ? $b['created_at'] : $b->created_at;
					return strcmp( $time_b, $time_a ); // DESC order
				}
			);
		}

		return $all_jobs;
	}

	/**
	 * Get all jobs for a flow.
	 *
	 * @param int|string $flow_id Flow ID or 'direct'
	 * @return array Jobs for the flow
	 */
	public function get_jobs_for_flow( int|string $flow_id ): array {

		if ( empty( $flow_id ) ) {
			return array();
		}

		// Skip if numeric and <= 0 (but allow 'direct' string)
		if ( is_numeric( $flow_id ) && (int) $flow_id <= 0 ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL -- Table name and flow ID are prepared.
		$results = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i WHERE flow_id = %s ORDER BY created_at DESC', $this->table_name, (string) $flow_id ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL

		return $results ? $results : array();
	}

	/**
	 * Get all child jobs of a parent job, ordered by job_id ascending.
	 *
	 * Used by fan-out system tasks (parent schedules N children via
	 * TaskScheduler::scheduleBatch) to walk their children's effects
	 * for undo, status aggregation, etc. Children are linked via the
	 * indexed `parent_job_id` column.
	 *
	 * Engine data is decoded from JSON to match get_job()'s shape so
	 * callers can treat parent and child rows uniformly.
	 *
	 * @since 0.83.0
	 *
	 * @param int $parent_job_id Parent job ID.
	 * @return array Array of child job rows with engine_data decoded, or empty array.
	 */
	public function get_children( int $parent_job_id ): array {
		if ( $parent_job_id <= 0 ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL -- Query is prepared on the next line.
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE parent_job_id = %d ORDER BY job_id ASC',
				$this->table_name,
				$parent_job_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( empty( $rows ) ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			if ( isset( $row['engine_data'] ) && is_string( $row['engine_data'] ) && '' !== $row['engine_data'] ) {
				$decoded = json_decode( $row['engine_data'], true );
				if ( json_last_error() === JSON_ERROR_NONE ) {
					$row['engine_data'] = $decoded;
				} else {
					$row['engine_data'] = array();
				}
			} else {
				$row['engine_data'] = array();
			}
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Get the latest job for each flow in a batch.
	 *
	 * Uses a subquery to efficiently get the most recent job per flow_id.
	 *
	 * @param array $flow_ids Array of flow IDs to query (numeric IDs only, not 'direct')
	 * @return array Map of [flow_id => job_row] for flows that have jobs
	 */
	public function get_latest_jobs_by_flow_ids( array $flow_ids ): array {
		if ( empty( $flow_ids ) ) {
			return array();
		}

		// Filter to numeric IDs only (this method is for database flows, not direct execution)
		$flow_ids = array_filter( $flow_ids, fn( $id ) => is_numeric( $id ) && (int) $id > 0 );
		$flow_ids = array_map( fn( $id ) => (string) $id, $flow_ids );

		if ( empty( $flow_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $flow_ids ), '%s' ) );

		// Subquery to get max job_id per flow, then join to get full row
		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$query = $this->wpdb->prepare(
			"SELECT j.* FROM %i j
             INNER JOIN (
                 SELECT flow_id, MAX(job_id) as max_job_id
                 FROM %i
                 WHERE flow_id IN ({$placeholders})
                 GROUP BY flow_id
             ) latest ON j.job_id = latest.max_job_id",
			array_merge( array( $this->table_name, $this->table_name ), $flow_ids )
		);
		// phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// phpcs:disable WordPress.DB.PreparedSQL -- Query is prepared above.
		$results = $this->wpdb->get_results( $query, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( ! $results ) {
			return array();
		}

		// Key by flow_id for easy lookup (keep as string for consistency)
		$jobs_by_flow = array();
		foreach ( $results as $job ) {
			$jobs_by_flow[ $job['flow_id'] ] = $job;
		}

		return $jobs_by_flow;
	}

	/**
	 * Resolve a status prefix to known variants for indexed lookups.
	 *
	 * Falls back to a LIKE pattern when the prefix is not in STATUS_VARIANTS
	 * (e.g. custom statuses from third-party handlers, or `failed` whose
	 * compound forms embed arbitrary error text and cannot be enumerated).
	 * The LIKE fallback is a prefix pattern (`prefix%`) so it still benefits
	 * from idx_status_created and matches both the bare prefix and every
	 * compound form.
	 *
	 * @param string $status_prefix The status prefix (e.g. 'completed', 'failed').
	 * @return array{type: 'in', values: string[]} | array{type: 'like', pattern: string}
	 */
	private function resolve_status_match( string $status_prefix ): array {
		if ( isset( self::STATUS_VARIANTS[ $status_prefix ] ) ) {
			return array(
				'type'   => 'in',
				'values' => self::STATUS_VARIANTS[ $status_prefix ],
			);
		}

		return array(
			'type'    => 'like',
			'pattern' => $this->wpdb->esc_like( $status_prefix ) . '%',
		);
	}

	/**
	 * Delete old jobs by status and age.
	 *
	 * Removes jobs matching the given status pattern that are older than
	 * the specified number of days. Uses LIKE matching to handle compound
	 * statuses (e.g., "failed - timeout").
	 *
	 * @since 0.28.0
	 *
	 * @param string $status_pattern Base status to match (e.g., 'failed'). Uses LIKE prefix matching.
	 * @param int    $older_than_days Delete jobs older than this many days.
	 * @return int|false Number of deleted rows, or false on error.
	 */
	public function delete_old_jobs( string $status_pattern, int $older_than_days ): int|false {
		if ( empty( $status_pattern ) || $older_than_days < 1 ) {
			return false;
		}

		$cutoff_datetime = gmdate( 'Y-m-d H:i:s', time() - ( $older_than_days * DAY_IN_SECONDS ) );
		$match           = $this->resolve_status_match( $status_pattern );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		if ( 'in' === $match['type'] ) {
			$placeholders = implode( ',', array_fill( 0, count( $match['values'] ), '%s' ) );
			$args         = array_merge(
				array( "DELETE FROM %i WHERE status IN ({$placeholders}) AND created_at < %s", $this->table_name ),
				$match['values'],
				array( $cutoff_datetime )
			);
			$result       = $this->wpdb->query( $this->wpdb->prepare( ...$args ) );
		} else {
			$result = $this->wpdb->query(
				$this->wpdb->prepare(
					'DELETE FROM %i WHERE status LIKE %s AND created_at < %s',
					$this->table_name,
					$match['pattern'],
					$cutoff_datetime
				)
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL

		do_action(
			'datamachine_log',
			'info',
			'Deleted old jobs',
			array(
				'status_pattern'  => $status_pattern,
				'older_than_days' => $older_than_days,
				'cutoff_datetime' => $cutoff_datetime,
				'jobs_deleted'    => false !== $result ? $result : 0,
				'success'         => false !== $result,
			)
		);

		return $result;
	}

	/**
	 * Count jobs matching a status pattern older than a given age.
	 *
	 * @since 0.28.0
	 *
	 * @param string $status_pattern Base status to match (e.g., 'failed').
	 * @param int    $older_than_days Count jobs older than this many days.
	 * @return int Number of matching jobs.
	 */
	public function count_old_jobs( string $status_pattern, int $older_than_days ): int {
		if ( empty( $status_pattern ) || $older_than_days < 1 ) {
			return 0;
		}

		$cutoff_datetime = gmdate( 'Y-m-d H:i:s', time() - ( $older_than_days * DAY_IN_SECONDS ) );
		$match           = $this->resolve_status_match( $status_pattern );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		if ( 'in' === $match['type'] ) {
			$placeholders = implode( ',', array_fill( 0, count( $match['values'] ), '%s' ) );
			$args         = array_merge(
				array( "SELECT COUNT(*) FROM %i WHERE status IN ({$placeholders}) AND created_at < %s", $this->table_name ),
				$match['values'],
				array( $cutoff_datetime )
			);
			$count        = $this->wpdb->get_var( $this->wpdb->prepare( ...$args ) );
		} else {
			$count = $this->wpdb->get_var(
				$this->wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE status LIKE %s AND created_at < %s',
					$this->table_name,
					$match['pattern'],
					$cutoff_datetime
				)
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL

		return (int) $count;
	}

	/**
	 * Delete jobs by status criteria or all jobs.
	 */
	public function delete_jobs( array $criteria = array() ): int|false {

		if ( empty( $criteria ) ) {
			do_action( 'datamachine_log', 'warning', 'No criteria provided for jobs deletion' );
			return false;
		}

		if ( ! empty( $criteria['failed'] ) ) {
			$failed_pattern = $this->wpdb->esc_like( 'failed' ) . '%';
			// phpcs:disable WordPress.DB.PreparedSQL -- Table name is the repository table; value is prepared.
			$result = $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM %i WHERE status LIKE %s', $this->table_name, $failed_pattern ) );
			// phpcs:enable WordPress.DB.PreparedSQL
		} else {
			// phpcs:disable WordPress.DB.PreparedSQL -- Table name is the repository table.
			$result = $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM %i', $this->table_name ) );
			// phpcs:enable WordPress.DB.PreparedSQL
		}

		do_action(
			'datamachine_log',
			'debug',
			'Deleted jobs',
			array(
				'criteria'     => $criteria,
				'jobs_deleted' => false !== $result ? $result : 0,
				'success'      => false !== $result,
			)
		);

		return $result;
	}

	// ---------------------------------------------------------------------
	// Engine data
	// ---------------------------------------------------------------------

	/**
	 * Store engine data for centralized access via datamachine_engine_data filter.
	 */
	public function store_engine_data( int $job_id, array $data ): bool {
		if ( $job_id <= 0 ) {
			do_action( 'datamachine_log', 'error', 'Invalid job ID for engine_data storage', array( 'job_id' => $job_id ) );
			return false;
		}

		$encoded          = wp_json_encode( $data );
		$storage_envelope = $this->engine_data_storage_envelope( $data, is_string( $encoded ) ? $encoded : '' );
		$update_data      = $storage_envelope['data'];
		$format           = $storage_envelope['format'];

		$result = $this->wpdb->update(
			$this->table_name,
			$update_data,
			array( 'job_id' => $job_id ),
			$format,
			array( '%d' )
		);

		if ( false === $result ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to store engine_data',
				array(
					'job_id'   => $job_id,
					'db_error' => $this->wpdb->last_error,
				)
			);
			return false;
		}

		wp_cache_set( $job_id, $data, 'datamachine_engine_data' );
		( new RunMetadata() )->replace_for_engine_data( $job_id, $data );

		do_action(
			'datamachine_log',
			'debug',
			'Stored engine_data successfully',
			array(
				'job_id'    => $job_id,
				'data_keys' => array_keys( $data ),
			)
		);

		return true;
	}

	/**
	 * Store engine data only when the persisted snapshot still matches the caller's baseline.
	 *
	 * @param int   $job_id        Job ID.
	 * @param array $expected_data Engine data snapshot read before mutation.
	 * @param array $new_data      Engine data snapshot to persist.
	 * @return array{updated:bool,conflict:bool,retryable?:bool,error:string|null}
	 */
	public function compare_and_swap_engine_data( int $job_id, array $expected_data, array $new_data ): array {
		if ( $job_id <= 0 ) {
			do_action( 'datamachine_log', 'error', 'Invalid job ID for engine_data compare-and-swap', array( 'job_id' => $job_id ) );
			return array(
				'updated'  => false,
				'conflict' => false,
				'error'    => 'invalid_job_id',
			);
		}

		$expected_encoded = wp_json_encode( $expected_data );
		$new_encoded      = wp_json_encode( $new_data );
		if ( ! is_string( $expected_encoded ) || ! is_string( $new_encoded ) ) {
			return array(
				'updated'  => false,
				'conflict' => false,
				'error'    => 'json_encode_failed',
			);
		}

		$storage_envelope = $this->engine_data_storage_envelope( $new_data, $new_encoded );
		$result           = $this->wpdb->update(
			$this->table_name,
			$storage_envelope['data'],
			array(
				'job_id'      => $job_id,
				'engine_data' => $expected_encoded,
			),
			$storage_envelope['format'],
			array( '%d', '%s' )
		);

		if ( false === $result ) {
			$db_error  = (string) $this->wpdb->last_error;
			$retryable = $this->is_retryable_db_error( $db_error );

			do_action(
				'datamachine_log',
				$retryable ? 'warning' : 'error',
				$retryable
					? 'Transient DB lock contention during engine_data compare-and-swap, retrying'
					: 'Failed to compare-and-swap engine_data',
				array(
					'job_id'    => $job_id,
					'db_error'  => $db_error,
					'retryable' => $retryable,
				)
			);

			return array(
				'updated'   => false,
				'conflict'  => false,
				'retryable' => $retryable,
				'error'     => $retryable ? 'deadlock' : 'db_error',
			);
		}

		if ( 0 === (int) $result ) {
			if ( array() === $expected_data ) {
				$job = $this->get_job( $job_id );
				if ( is_array( $job ) && ( ! array_key_exists( 'engine_data', $job ) || null === $job['engine_data'] || '' === $job['engine_data'] ) ) {
					$result = $this->wpdb->update(
						$this->table_name,
						$storage_envelope['data'],
						array(
							'job_id'      => $job_id,
							'engine_data' => null,
						),
						$storage_envelope['format'],
						array( '%d', null )
					);

					if ( false !== $result && (int) $result > 0 ) {
						wp_cache_set( $job_id, $new_data, 'datamachine_engine_data' );
						return array(
							'updated'  => true,
							'conflict' => false,
							'error'    => null,
						);
					}
				}
			}

			return array(
				'updated'  => false,
				'conflict' => true,
				'error'    => null,
			);
		}

		wp_cache_set( $job_id, $new_data, 'datamachine_engine_data' );
		( new RunMetadata() )->replace_for_engine_data( $job_id, $new_data );

		return array(
			'updated'  => true,
			'conflict' => false,
			'error'    => null,
		);
	}

	/**
	 * Determine whether a DB error string represents a transient lock condition
	 * that should be retried by re-reading the latest snapshot.
	 *
	 * Covers InnoDB deadlocks (MySQL 1213) and lock-wait timeouts (MySQL 1205),
	 * both of which MySQL explicitly recommends resolving by restarting the
	 * transaction rather than treating as a fatal failure.
	 *
	 * @param string $db_error Raw $wpdb->last_error message.
	 * @return bool True when the error is transient and retryable.
	 */
	private function is_retryable_db_error( string $db_error ): bool {
		if ( '' === $db_error ) {
			return false;
		}

		$needles = array(
			'deadlock found',
			'try restarting transaction',
			'lock wait timeout exceeded',
		);

		$haystack = strtolower( $db_error );
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Retrieve stored engine data for datamachine_engine_data filter access.
	 */
	public function retrieve_engine_data( int $job_id ): array {
		$job = $this->get_job( $job_id );

		if ( $job && isset( $job['engine_data'] ) && is_array( $job['engine_data'] ) ) {
			return $job['engine_data'];
		}

		return array();
	}

	/**
	 * Build column updates that mirror store_engine_data() promotion behavior.
	 *
	 * @param array  $data    Engine data snapshot.
	 * @param string $encoded JSON-encoded engine_data.
	 * @return array{data:array<string,mixed>,format:array<int,string>}
	 */
	private function engine_data_storage_envelope( array $data, string $encoded ): array {
		$update_data = array( 'engine_data' => $encoded );
		$format      = array( '%s' );

		// Promote task_type to its own indexed column for fast lookups.
		if ( isset( $data['task_type'] ) && is_string( $data['task_type'] ) ) {
			$update_data['task_type'] = sanitize_key( $data['task_type'] );
			$format[]                 = '%s';
		}

		// Promote the first handler_slug to its own column so handler
		// summaries / filtering survive engine_data being shed from terminal
		// jobs by retention (see RetentionCleanup::cleanupEngineData()).
		// Extracted from the encoded blob so the column matches exactly what
		// the legacy REGEXP_SUBSTR( engine_data, ... ) aggregation produced.
		$handler_slug = self::extract_handler_slug( $encoded );
		if ( '' !== $handler_slug ) {
			$update_data['handler_slug'] = $handler_slug;
			$format[]                    = '%s';
		}

		return array(
			'data'   => $update_data,
			'format' => $format,
		);
	}

	/**
	 * Extract the first handler_slug from an encoded engine_data blob.
	 *
	 * Mirrors the legacy `REGEXP_SUBSTR( engine_data, '"handler_slug":"[^"]+"' )`
	 * aggregation so the promoted handler_slug column carries the exact value
	 * the handler-summary query used to derive on the fly. Promoting it lets
	 * retention shed the heavy engine_data blob from terminal jobs without
	 * losing handler stats/filtering (see RetentionCleanup::cleanupEngineData()).
	 *
	 * @param string $encoded JSON-encoded engine_data.
	 * @return string Handler slug, or '' when none present.
	 */
	private static function extract_handler_slug( string $encoded ): string {
		if ( '' === $encoded || ! str_contains( $encoded, '"handler_slug"' ) ) {
			return '';
		}

		if ( preg_match( '/"handler_slug":"([^"]+)"/', $encoded, $matches ) ) {
			return sanitize_key( $matches[1] );
		}

		return '';
	}

	// ---------------------------------------------------------------------
	// Status transitions
	// ---------------------------------------------------------------------

	/**
	 * Update the status for a job.
	 *
	 * @param int    $job_id The job ID.
	 * @param string $status The new status (e.g., 'processing').
	 * @return bool True on success, false on failure.
	 */
	public function start_job( int $job_id, string $status = 'processing' ): bool {
		$result = $this->transition_job_status_result( $job_id, $status );

		if ( $result['changed'] ) {
			RunMetrics::start( $job_id, array( 'status' => $status ) );
		}

		return $result['success'];
	}

	/**
	 * Update the status and completed_at time for a job.
	 *
	 * Accepts compound statuses like "agent_skipped - reason" via JobStatus validation.
	 *
	 * @param int    $job_id The job ID.
	 * @param string $status The final status (any JobStatus final status, may be compound).
	 * @return bool True on success, false on failure.
	 */
	public function complete_job( int $job_id, string $status ): bool {
		return $this->transition_job_status( $job_id, $status, true );
	}

	/**
	 * Update job status.
	 *
	 * @param int    $job_id The job ID.
	 * @param string $status The new status.
	 * @return bool True on success, false on failure.
	 */
	public function update_job_status( int $job_id, string $status ): bool {
		return $this->transition_job_status( $job_id, $status );
	}

	/**
	 * Transition a job to a new status.
	 *
	 * Final statuses receive terminal accounting in one place: completed_at,
	 * run metrics completion, and datamachine_job_complete hooks.
	 *
	 * @param int    $job_id        The job ID.
	 * @param string $status        The new status.
	 * @param bool   $require_final Whether to reject non-final statuses.
	 * @return bool True on success, false on failure.
	 */
	public function transition_job_status( int $job_id, string $status, bool $require_final = false ): bool {
		$result = $this->transition_job_status_result( $job_id, $status, $require_final );

		return $result['success'];
	}

	/**
	 * Transition a job status with compare-and-set/idempotent lifecycle details.
	 *
	 * Non-terminal jobs may move to another non-terminal state or to a terminal
	 * state. Terminal jobs are immutable; repeating the same terminal state is an
	 * idempotent success and does not run terminal side effects again.
	 *
	 * @param int    $job_id        The job ID.
	 * @param string $status        The new status.
	 * @param bool   $require_final Whether to reject non-final statuses.
	 * @return array{success: bool, changed: bool, current_status: ?string, status: string}
	 */
	public function transition_job_status_result( int $job_id, string $status, bool $require_final = false ): array {

		if ( empty( $job_id ) ) {
			return array(
				'success'        => false,
				'changed'        => false,
				'current_status' => null,
				'status'         => $status,
			);
		}

		$is_final = JobStatus::isStatusFinal( $status );
		if ( $require_final && ! $is_final ) {
			return array(
				'success'        => false,
				'changed'        => false,
				'current_status' => null,
				'status'         => $status,
			);
		}
		if ( $is_final ) {
			return $this->transition_terminal_job_status_result( $job_id, $status );
		}

		$job = $this->get_job( $job_id );
		if ( ! is_array( $job ) ) {
			return array(
				'success'        => false,
				'changed'        => false,
				'current_status' => null,
				'status'         => $status,
			);
		}

		$current_status = is_string( $job['status'] ?? null ) ? $job['status'] : '';
		if ( $current_status === $status ) {
			return array(
				'success'        => true,
				'changed'        => false,
				'current_status' => $current_status,
				'status'         => $status,
			);
		}

		if ( JobStatus::isStatusFinal( $current_status ) ) {
			return array(
				'success'        => false,
				'changed'        => false,
				'current_status' => $current_status,
				'status'         => $status,
			);
		}

		// Truncate to fit varchar(255) column.
		if ( strlen( $status ) > 255 ) {
			$status = substr( $status, 0, 252 ) . '...';
		}

		$updated = LifecycleStateTransition::compare_and_set(
			$this->wpdb,
			$this->table_name,
			array( 'job_id' => $job_id ),
			'status',
			$current_status,
			$status,
			array(),
			array( '%d' ),
			array()
		);

		if ( false === $updated ) {
			return array(
				'success'        => false,
				'changed'        => false,
				'current_status' => $current_status,
				'status'         => $status,
			);
		}

		if ( 0 === $updated ) {
			$job_after      = $this->get_job( $job_id );
			$status_after   = is_array( $job_after ) && is_string( $job_after['status'] ?? null ) ? $job_after['status'] : null;
			$race_was_no_op = $status_after === $status;

			return array(
				'success'        => $race_was_no_op,
				'changed'        => false,
				'current_status' => $status_after,
				'status'         => $status,
			);
		}

		( new RunLifecycleStore( $this ) )->mark_job_status( $job_id, $status );

		return array(
			'success'        => true,
			'changed'        => true,
			'current_status' => $current_status,
			'status'         => $status,
		);
	}

	/**
	 * Atomically prepare claim side effects and persist one terminal winner.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $requested_status Requested final status.
	 * @return array{success: bool, changed: bool, current_status: ?string, status: string}
	 */
	private function transition_terminal_job_status_result( int $job_id, string $requested_status ): array {
		if ( null !== self::$terminalizing_job ) {
			return $this->status_transition_result( false, false, null, $requested_status );
		}

		self::$terminalizing_job = $job_id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( false === $this->wpdb->query( 'START TRANSACTION' ) ) {
			self::$terminalizing_job = null;
			return $this->status_transition_result( false, false, null, $requested_status );
		}

		$job = $this->get_job_for_update( $job_id );
		if ( ! is_array( $job ) ) {
			$this->rollback_terminal_transition( $job_id );
			return $this->status_transition_result( false, false, null, $requested_status );
		}

		$current_status = is_string( $job['status'] ?? null ) ? $job['status'] : '';
		if ( $current_status === $requested_status ) {
			$this->rollback_terminal_transition( $job_id );
			return $this->status_transition_result( true, false, $current_status, $current_status );
		}
		if ( JobStatus::isStatusFinal( $current_status ) ) {
			$this->rollback_terminal_transition( $job_id );
			return $this->status_transition_result( false, false, $current_status, $current_status );
		}

		$status = $requested_status;
		if ( JobStatus::isStatusSuccess( $status ) ) {
			try {
				/**
				 * Prepare a successful terminal transition inside the locked job transaction.
				 *
				 * Return WP_Error to roll back all preparation and persist its failure status.
				 *
				 * @param string $status Current terminal status.
				 * @param int    $job_id Job ID.
				 * @param array  $job Locked job row.
				 */
				$prepared_status = apply_filters( 'datamachine_job_terminal_status', $status, $job_id, $job );
			} catch ( \Throwable $exception ) {
				$prepared_status = new \WP_Error(
					'terminal_preparation_exception',
					$exception->getMessage(),
					array( 'status' => JobStatus::failed( 'terminal_preparation_exception' )->toString() )
				);
			}

			if ( is_wp_error( $prepared_status ) ) {
				$this->rollback_terminal_transition( $job_id );
				$failure_data   = $prepared_status->get_error_data();
				$failure_status = is_array( $failure_data ) && is_string( $failure_data['status'] ?? null )
					? $failure_data['status']
					: JobStatus::failed( 'terminal_preparation_failed' )->toString();
				return $this->transition_job_status_result( $job_id, $failure_status, true );
			}
			$status = is_string( $prepared_status ) ? $prepared_status : '';
			if ( ! JobStatus::isStatusSuccess( $status ) ) {
				$this->rollback_terminal_transition( $job_id );
				$failure_status = JobStatus::isStatusFinal( $status )
					? $status
					: JobStatus::failed( 'terminal_preparation_failed' )->toString();
				return $this->transition_job_status_result( $job_id, $failure_status, true );
			}
		}

		if ( ! JobStatus::isStatusFinal( $status ) ) {
			$this->rollback_terminal_transition( $job_id );
			return $this->status_transition_result( false, false, $current_status, $current_status );
		}
		if ( strlen( $status ) > 255 ) {
			$status = substr( $status, 0, 252 ) . '...';
		}

		// The locked row makes this a non-retrying ownership CAS. Any failure rolls
		// back the whole callback/claim/job unit instead of retrying one statement.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $this->wpdb->update(
			$this->table_name,
			array(
				'status'       => $status,
				'completed_at' => current_time( 'mysql', true ),
			),
			array(
				'job_id' => $job_id,
				'status' => $current_status,
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);
		if ( 1 !== $updated ) {
			$this->rollback_terminal_transition( $job_id );
			$winner = $this->get_job( $job_id );
			$winner_status = is_array( $winner ) && is_string( $winner['status'] ?? null ) ? $winner['status'] : $current_status;
			return $this->status_transition_result( false, false, $winner_status, $winner_status );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$committed = false !== $this->wpdb->query( 'COMMIT' );
		self::$terminalizing_job = null;
		if ( ! $committed ) {
			$this->rollback_terminal_transition( $job_id );
			$winner = $this->get_job( $job_id );
			$winner_status = is_array( $winner ) && is_string( $winner['status'] ?? null ) ? $winner['status'] : $current_status;
			return $this->status_transition_result( $status === $winner_status, false, $winner_status, $winner_status );
		}

		do_action( 'datamachine_job_terminal_committed', $job_id, $status );
		RunMetrics::complete( $job_id, $status );
		do_action( 'datamachine_job_complete', $job_id, $status );
		( new RunLifecycleStore( $this ) )->mark_job_status( $job_id, $status );

		return $this->status_transition_result( true, true, $current_status, $status );
	}

	/**
	 * Read and decode one job while holding its row lock.
	 *
	 * @param int $job_id Job ID.
	 * @return array|null Locked job row.
	 */
	private function get_job_for_update( int $job_id ): ?array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$job = $this->wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier uses %i; value uses a typed placeholder.
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE job_id = %d FOR UPDATE', $this->table_name, $job_id ),
			ARRAY_A
		);
		if ( is_array( $job ) && isset( $job['engine_data'] ) && is_string( $job['engine_data'] ) ) {
			$decoded = json_decode( $job['engine_data'], true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				$job['engine_data'] = $decoded;
			}
		}

		return is_array( $job ) ? $job : null;
	}

	/** Roll back a terminal ownership boundary and clear request-shared state. */
	private function rollback_terminal_transition( int $job_id ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->query( 'ROLLBACK' );
		wp_cache_delete( $job_id, 'datamachine_engine_data' );
		self::$terminalizing_job = null;
		do_action( 'datamachine_job_terminal_rolled_back', $job_id );
	}

	/**
	 * Build the canonical status transition result envelope.
	 */
	private function status_transition_result( bool $success, bool $changed, ?string $current_status, string $status ): array {
		return array(
			'success'        => $success,
			'changed'        => $changed,
			'current_status' => $current_status,
			'status'         => $status,
		);
	}

	// ---------------------------------------------------------------------
	// Flow health
	// ---------------------------------------------------------------------

	/**
	 * Get flows with consecutive failures or no_items above threshold.
	 *
	 * Uses cached flow health data when available.
	 * Only checks database flows (numeric IDs), not direct execution jobs.
	 *
	 * @param int $threshold Minimum consecutive count to flag as problem.
	 * @return array Array of [flow_id => counts_array] for problem flows.
	 */
	public function get_problem_flow_ids( int $threshold = 3 ): array {
		// phpcs:disable WordPress.DB.PreparedSQL -- Query is prepared below with repository table name.
		$query = $this->wpdb->prepare(
			"SELECT DISTINCT flow_id FROM %i WHERE flow_id != 'direct' AND flow_id REGEXP '^[0-9]+$'",
			$this->table_name
		);

		$flow_ids = $this->wpdb->get_col( $query );
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( empty( $flow_ids ) ) {
			return array();
		}

		$problem_flows = array();

		foreach ( $flow_ids as $flow_id ) {
			$counts = $this->get_flow_health( $flow_id );

			if ( $counts['consecutive_failures'] >= $threshold || $counts['consecutive_no_items'] >= $threshold ) {
				$problem_flows[ $flow_id ] = $counts;
			}
		}

		return $problem_flows;
	}

	/**
	 * Get recent jobs for a flow (limited).
	 *
	 * @param int|string $flow_id Flow ID.
	 * @param int        $limit   Max jobs to return.
	 * @return array Recent jobs, newest first.
	 */
	public function get_recent_jobs_for_flow( int|string $flow_id, int $limit = 10 ): array {
		if ( empty( $flow_id ) ) {
			return array();
		}

		if ( is_numeric( $flow_id ) && (int) $flow_id <= 0 ) {
			return array();
		}

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE flow_id = %s ORDER BY created_at DESC LIMIT %d',
				$this->table_name,
				(string) $flow_id,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		return $results ? $results : array();
	}

	/**
	 * Get cached flow health or compute if missing.
	 *
	 * @param int|string $flow_id Flow ID.
	 * @return array Counts array.
	 */
	public function get_flow_health( int|string $flow_id ): array {
		$cached = $this->get_flow_health_cache( $flow_id );
		if ( false !== $cached ) {
			return $cached;
		}

		$counts = $this->compute_consecutive_counts( $flow_id );
		$this->set_flow_health_cache( $flow_id, $counts );

		return $counts;
	}

	/**
	 * Update flow health cache when a job completes.
	 *
	 * Called via datamachine_job_complete hook.
	 *
	 * @param int    $job_id Job ID that completed.
	 * @param string $status Final status.
	 */
	public function update_flow_health_cache( int $job_id, string $status ): void {
		$job = $this->get_job( $job_id );
		if ( ! $job ) {
			return;
		}

		$flow_id = $job['flow_id'];

		// Skip direct execution jobs
		if ( 'direct' === $flow_id || ! is_numeric( $flow_id ) ) {
			return;
		}

		// Compute fresh counts and cache
		$counts = $this->compute_consecutive_counts( $flow_id );
		$this->set_flow_health_cache( $flow_id, $counts );
	}

	/**
	 * Get flow health from transient cache.
	 *
	 * @param int|string $flow_id Flow ID.
	 * @return array|false Cached counts or false if not cached.
	 */
	private function get_flow_health_cache( int|string $flow_id ): array|false {
		return get_transient( "datamachine_flow_health_{$flow_id}" );
	}

	/**
	 * Set flow health in transient cache.
	 *
	 * @param int|string $flow_id Flow ID.
	 * @param array      $counts  Counts to cache.
	 */
	private function set_flow_health_cache( int|string $flow_id, array $counts ): void {
		set_transient( "datamachine_flow_health_{$flow_id}", $counts, DAY_IN_SECONDS );
	}

	/**
	 * Compute consecutive counts from recent job history.
	 *
	 * @param int|string $flow_id Flow ID.
	 * @return array Counts array.
	 */
	private function compute_consecutive_counts( int|string $flow_id ): array {
		$jobs = $this->get_recent_jobs_for_flow( $flow_id, 10 );

		$result = array(
			'consecutive_failures' => 0,
			'consecutive_no_items' => 0,
			'latest_job'           => $jobs[0] ?? null,
		);

		if ( empty( $jobs ) ) {
			return $result;
		}

		// Count consecutive failures from most recent
		foreach ( $jobs as $job ) {
			if ( JobStatus::isStatusFailure( $job['status'] ) ) {
				++$result['consecutive_failures'];
			} else {
				break;
			}
		}

		// Count consecutive no_items from most recent
		foreach ( $jobs as $job ) {
			if ( str_starts_with( $job['status'], 'completed_no_items' ) ) {
				++$result['consecutive_no_items'];
			} else {
				break;
			}
		}

		return $result;
	}

	// ---------------------------------------------------------------------
	// Schema
	// ---------------------------------------------------------------------

	public static function create_table() {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'datamachine_jobs';
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// pipeline_id and flow_id are VARCHAR to support multiple execution modes:
		// - Numeric string: database flow execution (e.g. '123')
		// - 'direct': ephemeral workflow execution
		// - NULL: job execution without pipeline/flow context
		// status is VARCHAR(255) to support compound statuses with reasons
		$sql = "CREATE TABLE $table_name (
            job_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            pipeline_id varchar(20) NULL DEFAULT NULL,
            flow_id varchar(20) NULL DEFAULT NULL,
            source varchar(50) NOT NULL DEFAULT 'pipeline',
            label varchar(255) NULL DEFAULT NULL,
            parent_job_id bigint(20) unsigned NULL DEFAULT NULL,
            status varchar(255) NOT NULL,
            engine_data longtext NULL,
            handler_slug varchar(100) NULL DEFAULT NULL,
            idempotency_key varchar(191) NULL DEFAULT NULL,
			request_fingerprint char(64) NULL DEFAULT NULL,
			operation_state varchar(32) NULL DEFAULT NULL,
			operation_step_id varchar(191) NULL DEFAULT NULL,
			operation_claimed_at datetime NULL DEFAULT NULL,
			operation_claim_token varchar(64) NULL DEFAULT NULL,
			operation_generation bigint(20) unsigned NOT NULL DEFAULT 0,
			operation_action_id bigint(20) unsigned NULL DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime NULL DEFAULT NULL,
            PRIMARY KEY  (job_id),
            KEY status (status),
            KEY pipeline_id (pipeline_id),
            KEY flow_id (flow_id),
            KEY source (source),
            KEY parent_job_id (parent_job_id),
            KEY user_id (user_id),
            KEY idx_created_at (created_at),
            KEY idx_flow_created (flow_id, created_at),
            KEY idx_status_created (status(50), created_at),
            KEY idx_source_created (source, created_at),
            UNIQUE KEY idx_idempotency_key (idempotency_key)
        ) $charset_collate;";

		dbDelta( $sql );

		self::migrate_columns( $table_name );
		self::migrate_task_type_column( $table_name );
		self::migrate_handler_slug_column( $table_name );
		self::migrate_idempotency_key_column( $table_name );
		self::migrate_operation_columns( $table_name );
		self::migrate_indexes( $table_name );

		do_action(
			'datamachine_log',
			'debug',
			'Created jobs database table with pipeline+flow architecture',
			array(
				'table_name' => $table_name,
				'action'     => 'create_table',
			)
		);
	}

	/**
	 * Migrate existing table columns to current schema.
	 *
	 * Handles:
	 * - status column: varchar(20/100) -> varchar(255) for compound statuses with reasons
	 * - pipeline_id column: bigint -> varchar(20) for 'direct' execution support
	 * - flow_id column: bigint -> varchar(20) for 'direct' execution support
	 *
	 * Safe to run multiple times - only executes if columns need updating.
	 */
	private static function migrate_columns( string $table_name ): void {
		global $wpdb;

		// Column type migrations (MODIFY COLUMN) are MySQL-only — SQLite does
		// not support ALTER TABLE MODIFY and the columns are created with the
		// correct types from the start via CREATE TABLE / dbDelta.
		$columns = BaseRepository::get_column_meta(
			$table_name,
			array( 'status', 'pipeline_id', 'flow_id', 'source', 'parent_job_id', 'user_id' ),
			$wpdb
		);

		if ( ! empty( $columns ) ) {
			// Migrate status column: varchar(20/100) -> varchar(255)
			if ( isset( $columns['status'] ) && (int) $columns['status']->CHARACTER_MAXIMUM_LENGTH < 255 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
				// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
				$wpdb->query( "ALTER TABLE {$table_name} MODIFY status varchar(255) NOT NULL" );
				// phpcs:enable WordPress.DB.PreparedSQL
				do_action(
					'datamachine_log',
					'info',
					'Migrated jobs.status column to varchar(255)',
					array(
						'table_name'    => $table_name,
						'previous_size' => $columns['status']->CHARACTER_MAXIMUM_LENGTH,
					)
				);
			}

			// Migrate pipeline_id column: bigint -> varchar(20) NULL for contextless job support
			if ( isset( $columns['pipeline_id'] ) && 'bigint' === $columns['pipeline_id']->DATA_TYPE ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
				// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
				$wpdb->query( "ALTER TABLE {$table_name} MODIFY pipeline_id varchar(20) NULL DEFAULT NULL" );
				// phpcs:enable WordPress.DB.PreparedSQL
				do_action(
					'datamachine_log',
					'info',
					'Migrated jobs.pipeline_id column to varchar(20) NULL',
					array(
						'table_name' => $table_name,
					)
				);
			}

			// Migrate flow_id column: bigint -> varchar(20) NULL for contextless job support
			if ( isset( $columns['flow_id'] ) && 'bigint' === $columns['flow_id']->DATA_TYPE ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
				// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
				$wpdb->query( "ALTER TABLE {$table_name} MODIFY flow_id varchar(20) NULL DEFAULT NULL" );
				// phpcs:enable WordPress.DB.PreparedSQL
				do_action(
					'datamachine_log',
					'info',
					'Migrated jobs.flow_id column to varchar(20) NULL',
					array(
						'table_name' => $table_name,
					)
				);
			}

			// Migrate pipeline_id/flow_id from NOT NULL to NULL for contextless job support.
			// Runs on existing varchar(20) installs that haven't been updated yet.
			if ( isset( $columns['pipeline_id'] ) && 'varchar' === $columns['pipeline_id']->DATA_TYPE && 'NO' === $columns['pipeline_id']->IS_NULLABLE ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
				// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
				$wpdb->query( "ALTER TABLE {$table_name} MODIFY pipeline_id varchar(20) NULL DEFAULT NULL" );
				// phpcs:enable WordPress.DB.PreparedSQL
			}
			if ( isset( $columns['flow_id'] ) && 'varchar' === $columns['flow_id']->DATA_TYPE && 'NO' === $columns['flow_id']->IS_NULLABLE ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
				// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
				$wpdb->query( "ALTER TABLE {$table_name} MODIFY flow_id varchar(20) NULL DEFAULT NULL" );
				// phpcs:enable WordPress.DB.PreparedSQL
			}
		}

		// Add source and label columns for pipeline decoupling.
		if ( ! BaseRepository::column_exists( $table_name, 'source', $wpdb ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
			// `AFTER <col>` is MySQL-only; SQLite (Studio) rejects it. Column position
			// is cosmetic — both engines accept the bare ADD COLUMN form.
			$result = $wpdb->query(
				"ALTER TABLE {$table_name}
				 ADD COLUMN source varchar(50) NOT NULL DEFAULT 'pipeline',
				 ADD COLUMN label varchar(255) NULL DEFAULT NULL,
				 ADD KEY source (source)"
			);
			// phpcs:enable WordPress.DB.PreparedSQL

			if ( false === $result ) {
				do_action(
					'datamachine_log',
					'error',
					'Failed to add source/label columns to jobs table',
					array(
						'table_name' => $table_name,
						'db_error'   => $wpdb->last_error,
					)
				);
				return;
			}

			// Backfill existing rows.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
			$wpdb->query( "UPDATE {$table_name} SET source = 'direct' WHERE pipeline_id = 'direct'" );
			// phpcs:enable WordPress.DB.PreparedSQL

			do_action(
				'datamachine_log',
				'info',
				'Added source and label columns to jobs table for pipeline decoupling',
				array( 'table_name' => $table_name )
			);
		}

		// Add parent_job_id column for job hierarchy (batch parents, pipeline sub-jobs).
		if ( ! BaseRepository::column_exists( $table_name, 'parent_job_id', $wpdb ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
			// `AFTER <col>` is MySQL-only; SQLite (Studio) rejects it.
			$result = $wpdb->query(
				"ALTER TABLE {$table_name}
				 ADD COLUMN parent_job_id bigint(20) unsigned NULL DEFAULT NULL,
				 ADD KEY parent_job_id (parent_job_id)"
			);
			// phpcs:enable WordPress.DB.PreparedSQL

			if ( false !== $result ) {
				do_action(
					'datamachine_log',
					'info',
					'Added parent_job_id column to jobs table for job hierarchy',
					array( 'table_name' => $table_name )
				);
			}
		}

		// Add user_id column for multi-agent support.
		if ( ! BaseRepository::column_exists( $table_name, 'user_id', $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
			// `AFTER <col>` is MySQL-only; SQLite (Studio) rejects it.
			$result = $wpdb->query(
				"ALTER TABLE {$table_name}
				 ADD COLUMN user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				 ADD KEY user_id (user_id)"
			);
			// phpcs:enable WordPress.DB.PreparedSQL

			if ( false !== $result ) {
				do_action(
					'datamachine_log',
					'info',
					'Added user_id column to jobs table for multi-agent support',
					array( 'table_name' => $table_name )
				);
			}
		}

		// Add agent_id column for agent-first scoping (#735).
		if ( ! BaseRepository::column_exists( $table_name, 'agent_id', $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
			// `AFTER <col>` is MySQL-only; SQLite (Studio) rejects it.
			$result = $wpdb->query(
				"ALTER TABLE {$table_name}
				 ADD COLUMN agent_id bigint(20) unsigned DEFAULT NULL,
				 ADD KEY agent_id (agent_id)"
			);
			// phpcs:enable WordPress.DB.PreparedSQL

			if ( false !== $result ) {
				do_action(
					'datamachine_log',
					'info',
					'Added agent_id column to jobs table for agent-first scoping',
					array( 'table_name' => $table_name )
				);
			}
		}
	}

	/**
	 * Add task_type column for indexed system task lookups.
	 *
	 * Replaces JSON_EXTRACT(engine_data, '$.task_type') queries with a proper
	 * indexed column. The column is populated by store_engine_data() when the
	 * engine snapshot contains a task_type key, and backfilled from existing
	 * engine_data on migration.
	 *
	 * @since 0.30.0
	 *
	 * @param string $table_name Fully qualified table name.
	 */
	private static function migrate_task_type_column( string $table_name ): void {
		global $wpdb;

		if ( BaseRepository::column_exists( $table_name, 'task_type', $wpdb ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		// `AFTER <col>` is MySQL-only; SQLite (Studio) rejects it.
		$result = $wpdb->query(
			"ALTER TABLE {$table_name}
			 ADD COLUMN task_type varchar(100) NULL DEFAULT NULL,
			 ADD KEY idx_task_type (task_type, source)"
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( false === $result ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to add task_type column to jobs table',
				array(
					'table_name' => $table_name,
					'db_error'   => $wpdb->last_error,
				)
			);
			return;
		}

		// Backfill task_type from engine_data for existing system/pipeline_system_task jobs.
		// Uses PHP json_decode instead of MySQL JSON_UNQUOTE for SQLite compatibility.
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$backfill_rows = $wpdb->get_results(
			"SELECT job_id, engine_data
			 FROM {$table_name}
			 WHERE source IN ('system', 'pipeline_system_task')
			 AND engine_data IS NOT NULL
			 AND task_type IS NULL"
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		foreach ( $backfill_rows as $row ) {
			$engine_data = json_decode( $row->engine_data, true );
			$task_type   = $engine_data['task_type'] ?? null;

			if ( $task_type ) {
				$wpdb->update(
					$table_name,
					array( 'task_type' => $task_type ),
					array( 'job_id' => $row->job_id ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}

		do_action(
			'datamachine_log',
			'info',
			'Added task_type column to jobs table for indexed system task lookups',
			array( 'table_name' => $table_name )
		);
	}

	/**
	 * Add handler_slug column for indexed handler summaries / filtering.
	 *
	 * Promotes the first `"handler_slug":"..."` value out of the engine_data
	 * blob into a dedicated indexed column. This lets retention shed the heavy
	 * engine_data longtext from terminal jobs without losing the handler stats
	 * and filtering that previously REGEXP_SUBSTR-scanned the blob (#2622).
	 *
	 * The column is populated by store_engine_data() going forward and
	 * backfilled here from existing engine_data on migration. Backfill runs in
	 * bounded id-ranged chunks so it never loads or rewrites the whole table at
	 * once on large installs.
	 *
	 * @since TBD
	 *
	 * @param string $table_name Fully qualified table name.
	 */
	private static function migrate_handler_slug_column( string $table_name ): void {
		global $wpdb;

		if ( BaseRepository::column_exists( $table_name, 'handler_slug', $wpdb ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		// `AFTER <col>` is MySQL-only; SQLite (Studio) rejects it.
		$result = $wpdb->query(
			"ALTER TABLE {$table_name}
			 ADD COLUMN handler_slug varchar(100) NULL DEFAULT NULL,
			 ADD KEY idx_handler_slug (handler_slug)"
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( false === $result ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to add handler_slug column to jobs table',
				array(
					'table_name' => $table_name,
					'db_error'   => $wpdb->last_error,
				)
			);
			return;
		}

		// Backfill handler_slug from engine_data in bounded id-ranged chunks.
		// Uses PHP regex (not MySQL REGEXP) for SQLite compatibility, and walks
		// job_id ranges so memory stays flat regardless of table size.
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$max_id     = (int) $wpdb->get_var( "SELECT MAX(job_id) FROM {$table_name}" );
		$chunk_size = 5000;
		// phpcs:enable WordPress.DB.PreparedSQL

		for ( $start = 0; $start < $max_id; $start += $chunk_size ) {
			$end = $start + $chunk_size;

			// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT job_id, engine_data
					 FROM {$table_name}
					 WHERE job_id > %d AND job_id <= %d
					 AND engine_data IS NOT NULL
					 AND engine_data LIKE %s
					 AND handler_slug IS NULL",
					$start,
					$end,
					'%' . $wpdb->esc_like( '"handler_slug"' ) . '%'
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL

			if ( empty( $rows ) ) {
				continue;
			}

			foreach ( $rows as $row ) {
				$handler_slug = self::extract_handler_slug( (string) $row->engine_data );
				if ( '' === $handler_slug ) {
					continue;
				}

				$wpdb->update(
					$table_name,
					array( 'handler_slug' => $handler_slug ),
					array( 'job_id' => $row->job_id ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}

		do_action(
			'datamachine_log',
			'info',
			'Added handler_slug column to jobs table for indexed handler summaries',
			array( 'table_name' => $table_name )
		);
	}

	/**
	 * Add idempotency_key column for deterministic job creation.
	 *
	 * @param string $table_name Fully qualified table name.
	 */
	private static function migrate_idempotency_key_column( string $table_name ): void {
		global $wpdb;

		if ( ! BaseRepository::column_exists( $table_name, 'idempotency_key', $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
			$result = $wpdb->query(
				"ALTER TABLE {$table_name}
				 ADD COLUMN idempotency_key varchar(191) NULL DEFAULT NULL"
			);
			// phpcs:enable WordPress.DB.PreparedSQL

			if ( false === $result ) {
				do_action(
					'datamachine_log',
					'error',
					'Failed to add idempotency_key column to jobs table',
					array(
						'table_name' => $table_name,
						'db_error'   => $wpdb->last_error,
					)
				);
				return;
			}
		}

		self::ensure_jobs_index( $table_name, 'idx_idempotency_key', 'UNIQUE INDEX', '(idempotency_key)' );
	}

	/**
	 * Add retention-safe operation metadata used by idempotent job submission.
	 *
	 * @param string $table_name Fully qualified jobs table name.
	 */
	private static function migrate_operation_columns( string $table_name ): void {
		global $wpdb;

		$columns = array(
			'request_fingerprint'  => 'char(64) NULL DEFAULT NULL',
			'operation_state'      => 'varchar(32) NULL DEFAULT NULL',
			'operation_step_id'    => 'varchar(191) NULL DEFAULT NULL',
			'operation_claimed_at' => 'datetime NULL DEFAULT NULL',
			'operation_claim_token' => 'varchar(64) NULL DEFAULT NULL',
			'operation_generation' => 'bigint(20) unsigned NOT NULL DEFAULT 0',
			'operation_action_id'  => 'bigint(20) unsigned NULL DEFAULT NULL',
		);

		foreach ( $columns as $column => $definition ) {
			if ( BaseRepository::column_exists( $table_name, $column, $wpdb ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed schema identifiers and definitions.
			$wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN {$column} {$definition}" );
		}
	}

	/**
	 * Ensure a jobs table index exists.
	 *
	 * @param string $table_name Fully qualified table name.
	 * @param string $index_name Index name.
	 * @param string $index_type Index type for ALTER TABLE, such as INDEX or UNIQUE INDEX.
	 * @param string $index_def  Index column definition, including parentheses.
	 * @return bool True when the index exists or was added.
	 */
	private static function ensure_jobs_index( string $table_name, string $index_name, string $index_type, string $index_def ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.PreparedSQL -- Table/index names from plugin constants, not user input.
		$existing = $wpdb->get_row( "SHOW INDEX FROM {$table_name} WHERE Key_name = '{$index_name}'" );
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( $existing ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
		// phpcs:disable WordPress.DB.PreparedSQL -- Table/index names from plugin constants, not user input.
		$result = $wpdb->query( "ALTER TABLE {$table_name} ADD {$index_type} {$index_name} {$index_def}" );
		// phpcs:enable WordPress.DB.PreparedSQL

		return false !== $result;
	}

	/**
	 * Ensure performance indexes exist on the jobs table.
	 *
	 * dbDelta() is unreliable at adding indexes to existing tables, so this
	 * method checks for each index via SHOW INDEX and adds any that are
	 * missing. Safe to run on every activation/deploy.
	 *
	 * These indexes become critical once the jobs table grows past ~10k rows.
	 * Without them, common queries (flow listing, status filtering, retention
	 * cleanup, system task lookups) degrade from milliseconds to seconds.
	 *
	 * @param string $table_name Fully qualified table name.
	 */
	private static function migrate_indexes( string $table_name ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$existing = $wpdb->get_results( "SHOW INDEX FROM {$table_name}", ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( empty( $existing ) ) {
			return;
		}

		$index_names = array_unique( array_column( $existing, 'Key_name' ) );

		$indexes_to_add = array(
			'idx_created_at'     => '(created_at)',
			'idx_flow_created'   => '(flow_id, created_at)',
			'idx_status_created' => '(status(50), created_at)',
			'idx_source_created' => '(source, created_at)',
			'idx_handler_slug'   => '(handler_slug)',
		);

		$added = array();

		foreach ( $indexes_to_add as $index_name => $index_def ) {
			if ( in_array( $index_name, $index_names, true ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			// phpcs:disable WordPress.DB.PreparedSQL -- Table/index names from plugin constants, not user input.
			$result = $wpdb->query( "ALTER TABLE {$table_name} ADD INDEX {$index_name} {$index_def}" );
			// phpcs:enable WordPress.DB.PreparedSQL

			if ( false !== $result ) {
				$added[] = $index_name;
			}
		}

		if ( ! empty( $added ) ) {
			do_action(
				'datamachine_log',
				'info',
				'Added performance indexes to jobs table',
				array(
					'table_name' => $table_name,
					'indexes'    => $added,
				)
			);
		}
	}
}
