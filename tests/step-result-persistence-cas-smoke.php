<?php
/**
 * Pure-PHP smoke test for canonical step result persistence and CAS retry.
 *
 * Run with: php tests/step-result-persistence-cas-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Core\Database\Jobs {

	class Jobs {

		public static array $snapshots = array();

		public static bool $inject_conflict = false;

		public static int $compare_and_swap_calls = 0;

		public function retrieve_engine_data( int $job_id ): array {
			return self::$snapshots[ $job_id ] ?? array();
		}

		public function store_engine_data( int $job_id, array $snapshot ): bool {
			self::$snapshots[ $job_id ] = $snapshot;
			return true;
		}

		public function compare_and_swap_engine_data( int $job_id, array $expected_data, array $new_data ): array {
			self::$compare_and_swap_calls++;

			if ( self::$inject_conflict ) {
				self::$inject_conflict             = false;
				$current                          = self::$snapshots[ $job_id ] ?? array();
				$current['concurrent_writer_kept'] = true;
				self::$snapshots[ $job_id ]        = $current;

				return array(
					'updated'  => false,
					'conflict' => true,
					'error'    => null,
				);
			}

			if ( ( self::$snapshots[ $job_id ] ?? array() ) !== $expected_data ) {
				return array(
					'updated'  => false,
					'conflict' => true,
					'error'    => null,
				);
			}

			self::$snapshots[ $job_id ] = $new_data;

			return array(
				'updated'  => true,
				'conflict' => false,
				'error'    => null,
			);
		}
	}
}

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
		}
	}

	if ( ! function_exists( 'current_time' ) ) {
		function current_time( $type, $gmt = false ) {
			return '2026-06-18 14:30:00';
		}
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data, $flags = 0, $depth = 512 ) {
			return json_encode( $data, $flags, $depth );
		}
	}

	if ( ! function_exists( 'wp_cache_get' ) ) {
		function wp_cache_get( $key, $group = '' ) {
			return false;
		}
	}

	if ( ! function_exists( 'wp_cache_set' ) ) {
		function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
			return true;
		}
	}

	if ( ! function_exists( 'do_action' ) ) {
		function do_action( $hook_name, ...$arg ) {
			return null;
		}
	}

	require_once __DIR__ . '/../inc/Core/JobStatus.php';
	require_once __DIR__ . '/../inc/Core/JobArtifactSurfaces.php';
	require_once __DIR__ . '/../inc/Core/StepResult.php';
	require_once __DIR__ . '/../inc/Core/RunResult.php';
	require_once __DIR__ . '/../inc/Core/EngineData.php';
	require_once __DIR__ . '/../inc/Core/RunMetrics.php';

	use DataMachine\Core\Database\Jobs\Jobs;
	use DataMachine\Core\RunMetrics;
	use DataMachine\Core\RunResult;

	function datamachine_step_result_persistence_assert( bool $condition, string $message ): void {
		if ( $condition ) {
			echo "  [PASS] {$message}\n";
			return;
		}

		echo "  [FAIL] {$message}\n";
		exit( 1 );
	}

	echo "=== step-result-persistence-cas-smoke ===\n";

	$job_id                   = 991;
	Jobs::$snapshots[ $job_id ] = array(
		'existing' => array(
			'value' => 'preserved',
		),
	);
	Jobs::$inject_conflict      = true;

	$step_result = array(
		'schema_version' => 'datamachine.step_result.v1',
		'status'         => 'succeeded',
		'outputs'        => array(
			'packet_count' => 2,
			'nested'       => array(
				'value' => 'kept',
			),
		),
		'artifact_refs'  => array(
			array(
				'id'   => 'artifact-1',
				'hash' => 'sha256:abc',
			),
		),
		'packet_refs'    => array(
			array(
				'index'        => 0,
				'content_hash' => 'sha256:def',
			),
		),
		'diagnostics'    => array(
			'reason' => 'completed',
		),
		'replay'         => array(
			'content_hashes' => array(
				'outputs' => 'sha256:123',
			),
		),
	);

	$recorded = RunMetrics::recordStepResult(
		$job_id,
		'fetch_sources',
		array(
			'step_type'    => 'fetch',
			'result'       => 'completed',
			'step_success' => true,
			'packet_count' => 2,
			'status'       => 'completed',
			'reason'       => 'completed',
			'step_result'  => $step_result,
		)
	);

	$snapshot = Jobs::$snapshots[ $job_id ];

	datamachine_step_result_persistence_assert( $recorded, 'recordStepResult reports success after retry' );
	datamachine_step_result_persistence_assert( 2 === Jobs::$compare_and_swap_calls, 'CAS conflict is retried against the latest snapshot' );
	datamachine_step_result_persistence_assert( true === ( $snapshot['concurrent_writer_kept'] ?? false ), 'concurrent engine_data write is preserved' );
	datamachine_step_result_persistence_assert( 'preserved' === ( $snapshot['existing']['value'] ?? null ), 'pre-existing engine_data remains intact' );
	datamachine_step_result_persistence_assert( 'datamachine.step_result.v1' === ( $snapshot['step_results']['fetch_sources']['step_result']['schema_version'] ?? null ), 'canonical StepResult envelope is persisted' );
	datamachine_step_result_persistence_assert( 'kept' === ( $snapshot['step_results']['fetch_sources']['step_result']['outputs']['nested']['value'] ?? null ), 'nested envelope data survives sanitization' );
	datamachine_step_result_persistence_assert( 2 === ( $snapshot['run_metrics']['counts']['fetch_packets'] ?? 0 ), 'fetch packet count is recorded in run metrics' );

	$metrics = RunMetrics::fromJob(
		array(
			'job_id'      => $job_id,
			'source'      => 'pipeline',
			'status'      => 'completed',
			'created_at'  => '2026-06-18 14:00:00',
			'engine_data' => $snapshot,
		)
	);

	datamachine_step_result_persistence_assert( RunResult::SCHEMA_VERSION === ( $metrics['run_result']['schema_version'] ?? null ), 'RunMetrics exposes canonical RunResult summary' );
	datamachine_step_result_persistence_assert( 'datamachine.step_result.v1' === ( $metrics['run_result']['step_results'][0]['schema_version'] ?? null ), 'RunResult summary includes persisted StepResult envelope' );

	echo "\n=== step-result-persistence-cas-smoke: ALL PASS ===\n";
}
