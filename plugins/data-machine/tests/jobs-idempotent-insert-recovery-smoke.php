<?php
/**
 * Regression coverage for bounded idempotent job insertion recovery.
 *
 * Run with: php tests/jobs-idempotent-insert-recovery-smoke.php
 */

namespace DataMachine\Core {
	class JobStatus {
		public const PENDING = 'pending';
	}

	class RunLifecycleStore {
		public function __construct( mixed $jobs = null ) {}

		public function mark_job_created( int $job_id, array $seed = array() ): void {}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'OBJECT', 'OBJECT' );

	$GLOBALS['job_insert_test_actions'] = array();
	$GLOBALS['job_insert_test_cache']   = array();

	function absint( mixed $value ): int {
		return abs( (int) $value );
	}

	function sanitize_key( mixed $value ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}

	function sanitize_text_field( mixed $value ): string {
		return trim( strip_tags( (string) $value ) );
	}

	function wp_json_encode( mixed $value ): string|false {
		return json_encode( $value );
	}

	function current_time( string $type, bool $gmt = false ): string {
		return '2026-08-06 15:00:00';
	}

	function do_action( string $hook, mixed ...$args ): void {
		$GLOBALS['job_insert_test_actions'][ $hook ][] = $args;
	}

	function wp_cache_add( string $key, mixed $value, string $group = '', int $expire = 0 ): bool {
		if ( isset( $GLOBALS['job_insert_test_cache'][ $group ][ $key ] ) ) {
			return false;
		}
		$GLOBALS['job_insert_test_cache'][ $group ][ $key ] = $value;
		return true;
	}

	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		public string $last_error = '';
	}

	class Jobs_Idempotent_Insert_Test_Wpdb extends wpdb {
		public string $mode = 'success';
		public int $insert_calls = 0;
		public int $lookup_calls = 0;
		/** @var array<int,array<string,mixed>> */
		public array $rows = array();

		public function insert( mixed $table, array $data, array $format ): int|false {
			++$this->insert_calls;
			if ( 'duplicate_race' === $this->mode ) {
				$this->last_error = "Duplicate entry 'redacted' for key 'idx_idempotency_key' (1062)";
				return false;
			}
			if ( 'contention' === $this->mode ) {
				$this->last_error = 'Deadlock found when trying to get lock; try restarting transaction (1213)';
				return false;
			}
			if ( 'contention_then_success' === $this->mode && $this->insert_calls < 3 ) {
				$this->last_error = 'Lock wait timeout exceeded; try restarting transaction (1205)';
				return false;
			}
			if ( 'non_retryable' === $this->mode ) {
				$this->last_error = "Data too long for column 'operation_step_id' at row 1";
				return false;
			}

			$this->last_error = '';
			$this->insert_id  = count( $this->rows ) + 1;
			$this->rows[ $this->insert_id ] = array_merge(
				array(
					'job_id'     => $this->insert_id,
					'created_at' => '2026-08-06 15:00:00',
				),
				$data
			);
			return 1;
		}

		public function prepare( string $query, mixed ...$args ): array {
			return array( $query, $args );
		}

		public function get_row( mixed $query, mixed $output = OBJECT ): ?array {
			$args = is_array( $query ) ? $query[1] : array();
			if ( str_contains( (string) $query[0], 'idempotency_key' ) ) {
				++$this->lookup_calls;
				$key = (string) end( $args );
				if ( 'duplicate_race' === $this->mode && $this->lookup_calls >= 3 ) {
					return array(
						'job_id'         => 77,
						'idempotency_key' => $key,
						'source'          => 'pipeline',
						'status'          => 'pending',
					);
				}
				foreach ( $this->rows as $row ) {
					if ( $key === (string) ( $row['idempotency_key'] ?? '' ) ) {
						return $row;
					}
				}
				return null;
			}

			$job_id = (int) end( $args );
			return $this->rows[ $job_id ] ?? null;
		}
	}

	require_once dirname( __DIR__ ) . '/inc/Core/Database/BaseRepository.php';
	require_once dirname( __DIR__ ) . '/inc/Core/Database/Jobs/Jobs.php';

	use DataMachine\Core\Database\Jobs\Jobs;

	$assertions = 0;
	$assert     = static function ( bool $condition, string $message ) use ( &$assertions ): void {
		++$assertions;
		if ( ! $condition ) {
			throw new RuntimeException( "Assertion failed: {$message}" );
		}
	};
	$new_jobs   = static function ( string $mode ): array {
		global $wpdb;
		$wpdb       = new Jobs_Idempotent_Insert_Test_Wpdb();
		$wpdb->mode = $mode;
		return array( new Jobs(), $wpdb );
	};

	list( $jobs, $wpdb ) = $new_jobs( 'success' );
	$result              = $jobs->create_or_get_job(
		array(
			'label'           => str_repeat( 'x', 300 ),
			'idempotency_key' => 'pipeline-batch:bounded-label',
		)
	);
	$assert( is_array( $result ) && $result['created'], 'a valid idempotent job is created' );
	$assert( 255 === mb_strlen( $wpdb->rows[1]['label'], 'UTF-8' ), 'label is bounded to its varchar(255) schema' );

	list( $jobs, $wpdb ) = $new_jobs( 'duplicate_race' );
	$result              = $jobs->create_or_get_job( array( 'idempotency_key' => 'pipeline-batch:race' ) );
	$assert( is_array( $result ) && 77 === $result['job_id'], 'a delayed duplicate-race winner becomes canonical' );
	$assert( 1 === $wpdb->insert_calls, 'duplicate errors do not retry the insert' );
	$assert( 3 === $wpdb->lookup_calls, 'duplicate winner visibility polling is bounded' );

	list( $jobs, $wpdb ) = $new_jobs( 'contention_then_success' );
	$result              = $jobs->create_or_get_job( array( 'idempotency_key' => 'pipeline-batch:transient' ) );
	$assert( is_array( $result ) && $result['created'], 'transient contention can recover' );
	$assert( 3 === $wpdb->insert_calls, 'contention retries stop at the bounded successful attempt' );

	$GLOBALS['job_insert_test_actions'] = array();
	$GLOBALS['job_insert_test_cache']   = array();
	list( $jobs, $wpdb )                = $new_jobs( 'contention' );
	for ( $call = 0; $call < 5; ++$call ) {
		$assert( false === $jobs->create_or_get_job( array( 'idempotency_key' => 'pipeline-batch:persistent' ) ), 'persistent contention remains visible to its caller' );
	}
	$logs    = $GLOBALS['job_insert_test_actions']['datamachine_log'] ?? array();
	$signals = $GLOBALS['job_insert_test_actions']['datamachine_idempotent_job_insert_failed'] ?? array();
	$assert( 15 === $wpdb->insert_calls, 'persistent contention is bounded to three attempts per call' );
	$assert( 1 === count( $logs ), 'identical persistent failures produce one log per throttle window' );
	$assert( 5 === count( $signals ), 'every persistent failure remains observable to metrics listeners' );
	$assert( 3 === $logs[0][2]['attempts'], 'the final diagnostic records retry exhaustion' );
	$assert( str_contains( $logs[0][2]['db_error'], 'Deadlock found' ), 'the original insert error survives recovery lookups' );
	$assert( ! isset( $logs[0][2]['idempotency_key'] ), 'diagnostics do not expose raw idempotency keys' );

	$GLOBALS['job_insert_test_actions'] = array();
	$GLOBALS['job_insert_test_cache']   = array();
	list( $jobs, $wpdb )                = $new_jobs( 'non_retryable' );
	$assert( false === $jobs->create_or_get_job( array( 'idempotency_key' => 'pipeline-batch:invalid' ) ), 'non-retryable database failures return false' );
	$assert( 1 === $wpdb->insert_calls, 'non-retryable database failures are not amplified' );
	$log = $GLOBALS['job_insert_test_actions']['datamachine_log'][0][2] ?? array();
	$assert( 'non_retryable' === ( $log['error_type'] ?? '' ), 'non-retryable errors are classified safely' );

	echo "jobs-idempotent-insert-recovery-smoke: {$assertions} assertions passed.\n";
}
