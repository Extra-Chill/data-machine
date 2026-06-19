<?php
/**
 * Smoke test for generic run lifecycle storage.
 *
 * Run with: php tests/run-lifecycle-store-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Core {
	class RunMetrics {
		public static function start( int $job_id, array $metadata = array() ): bool {
			return true;
		}

		public static function complete( int $job_id, string $status ): bool {
			return true;
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}

	if ( ! function_exists( 'absint' ) ) {
		function absint( $value ): int {
			return abs( (int) $value );
		}
	}

	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ): string {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
		}
	}

	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $value ): string {
			return trim( strip_tags( (string) $value ) );
		}
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data, int $flags = 0, int $depth = 512 ) {
			return json_encode( $data, $flags, $depth );
		}
	}

	if ( ! function_exists( 'current_time' ) ) {
		function current_time( string $type, bool $gmt = false ): string {
			return '2026-06-19 12:00:00';
		}
	}

	if ( ! function_exists( 'do_action' ) ) {
		function do_action( string $hook, ...$args ): void {}
	}

	if ( ! function_exists( 'wp_cache_get' ) ) {
		function wp_cache_get( $key, string $group = '' ) {
			return $GLOBALS['datamachine_run_lifecycle_cache'][ $group ][ $key ] ?? false;
		}
	}

	if ( ! function_exists( 'wp_cache_set' ) ) {
		function wp_cache_set( $key, $value, string $group = '' ): bool {
			$GLOBALS['datamachine_run_lifecycle_cache'][ $group ][ $key ] = $value;
			return true;
		}
	}

	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		public string $last_error = '';

		/** @var array<int,array<string,mixed>> */
		public array $rows = array();

		public function insert( string $table, array $data, array $format ) {
			++$this->insert_id;
			$this->rows[ $this->insert_id ] = array_merge(
				array(
					'job_id'       => $this->insert_id,
					'engine_data'  => null,
					'created_at'   => '2026-06-19 11:59:00',
					'completed_at' => null,
				),
				$data
			);
			return 1;
		}

		public function update( string $table, array $data, array $where, array $format, array $where_format ) {
			$job_id = (int) ( $where['job_id'] ?? 0 );
			if ( ! isset( $this->rows[ $job_id ] ) ) {
				return 0;
			}

			foreach ( $where as $column => $expected ) {
				if ( 'job_id' === $column ) {
					continue;
				}

				$current = $this->rows[ $job_id ][ $column ] ?? null;
				if ( null === $expected ) {
					if ( null !== $current ) {
						return 0;
					}
					continue;
				}

				if ( (string) $current !== (string) $expected ) {
					return 0;
				}
			}

			$this->rows[ $job_id ] = array_merge( $this->rows[ $job_id ], $data );
			return 1;
		}

		public function prepare( string $query, ...$args ): array {
			return array( $query, $args );
		}

		public function get_row( $query, string $output = ARRAY_A ) {
			$args   = is_array( $query ) ? ( $query[1] ?? array() ) : array();
			$job_id = (int) end( $args );
			$row    = $this->rows[ $job_id ] ?? null;
			return $row ?: null;
		}
	}

	$wpdb = new wpdb();

	require_once __DIR__ . '/../inc/Core/JobStatus.php';
	require_once __DIR__ . '/../inc/Core/Database/BaseRepository.php';
	require_once __DIR__ . '/../inc/Core/Database/LifecycleStateTransition.php';
	require_once __DIR__ . '/../inc/Core/EngineData.php';
	require_once __DIR__ . '/../inc/Core/RunLifecycleStore.php';
	require_once __DIR__ . '/../inc/Core/Database/Jobs/Jobs.php';

	use DataMachine\Core\RunLifecycleStore;

	$failures = array();
	$passes   = 0;

	$assert = static function ( bool $condition, string $label ) use ( &$failures, &$passes ): void {
		if ( $condition ) {
			++$passes;
			return;
		}

		$failures[] = $label;
	};

	$store = new RunLifecycleStore();
	$run   = $store->create_run( 'deterministic_loop', array( 'label' => 'Generic lifecycle proof' ) );

	$assert( is_array( $run ) && 'job:1' === ( $run['run_id'] ?? '' ), 'create_run returns stable job-backed run_id' );
	$assert( 'deterministic_loop' === ( $run['run_type'] ?? '' ), 'create_run records generic run_type' );
	$assert( 'pending' === ( $run['status'] ?? '' ), 'create_run records pending status' );
	$assert( 1 === ( $run['attempt'] ?? 0 ), 'create_run initializes attempt' );

	$started = $store->start_run( 'job:1' );
	$assert( 'processing' === ( $started['status'] ?? '' ), 'start_run transitions to processing' );

	$waiting = $store->wait_run( 'job:1', array( 'gate' => 'manual' ) );
	$assert( 'waiting' === ( $waiting['status'] ?? '' ), 'wait_run transitions to waiting' );

	$resumed = $store->resume_run( 'job:1' );
	$assert( 'processing' === ( $resumed['status'] ?? '' ) && 2 === ( $resumed['attempt'] ?? 0 ), 'resume_run transitions to processing and increments attempt' );

	$with_replay = $store->append_replay_event( 'job:1', 'step_completed', array( 'step' => 'fetch' ) );
	$assert( 1 === count( $with_replay['replay_events'] ?? array() ), 'append_replay_event stores replay ledger entries' );

	$with_artifact = $store->add_artifact_ref( 'job:1', 'datamachine://jobs/1/artifacts/result' );
	$assert( array( 'datamachine://jobs/1/artifacts/result' ) === ( $with_artifact['artifact_refs'] ?? array() ), 'add_artifact_ref stores portable artifact refs' );

	$completed = $store->complete_run( 'job:1' );
	$assert( 'completed' === ( $completed['status'] ?? '' ), 'complete_run transitions to completed' );

	$failed_after_final = $store->fail_run( 'job:1', 'too late' );
	$assert( false === $failed_after_final, 'terminal runs are immutable through fail_run' );

	$cancel_candidate = $store->create_run( 'deterministic_loop' );
	$cancelled        = $store->cancel_run( $cancel_candidate['run_id'] ?? '' );
	$assert( 'cancelled' === ( $cancelled['status'] ?? '' ), 'cancel_run transitions to cancelled' );

	if ( $failures ) {
		echo '=== run-lifecycle-store-smoke: ' . count( $failures ) . " FAILURE(S) ===\n";
		foreach ( $failures as $failure ) {
			echo "  [FAIL] {$failure}\n";
		}
		exit( 1 );
	}

	echo "=== run-lifecycle-store-smoke: ALL PASS ({$passes} assertions) ===\n";
}
