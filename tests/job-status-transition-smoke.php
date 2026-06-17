<?php
/**
 * Pure-PHP smoke test for centralized job status transitions.
 *
 * Run with: php tests/job-status-transition-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Core {
	class RunMetrics {
		/** @var array<int,string> */
		public static array $completed = array();

		public static function complete( int $job_id, string $status ): bool {
			self::$completed[ $job_id ] = $status;
			return true;
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$datamachine_transition_hooks = array();

	if ( ! function_exists( 'current_time' ) ) {
		function current_time( string $type, bool $gmt = false ): string {
			return $gmt ? '2026-06-17 03:00:00' : '2026-06-17 03:00:01';
		}
	}

	if ( ! function_exists( 'do_action' ) ) {
		function do_action( string $hook, ...$args ): void {
			$GLOBALS['datamachine_transition_hooks'][] = array(
				'hook' => $hook,
				'args' => $args,
			);
		}
	}

	if ( function_exists( 'add_action' ) ) {
		add_action(
			'datamachine_job_complete',
			function ( int $job_id, string $status ): void {
				$GLOBALS['datamachine_transition_hooks'][] = array(
					'hook' => 'datamachine_job_complete',
					'args' => array( $job_id, $status ),
				);
			},
			10,
			2
		);
	}

	class DataMachineTransitionWpdbStub {
		public string $prefix = 'wp_';

		/** @var array<int,array<string,mixed>> */
		public array $updates = array();

		public function update( string $table, array $data, array $where, array $format, array $where_format ) {
			$this->updates[] = compact( 'table', 'data', 'where', 'format', 'where_format' );
			return 1;
		}
	}

	$wpdb = new DataMachineTransitionWpdbStub();

	require_once __DIR__ . '/../inc/Core/JobStatus.php';
	require_once __DIR__ . '/../inc/Core/Database/BaseRepository.php';
	require_once __DIR__ . '/../inc/Core/Database/Jobs/Jobs.php';

	use DataMachine\Core\Database\Jobs\Jobs;
	use DataMachine\Core\RunMetrics;

	$failures = 0;
	$total    = 0;

	$assert = function ( string $label, bool $condition ) use ( &$failures, &$total ): void {
		++$total;
		if ( $condition ) {
			echo "  [PASS] {$label}\n";
			return;
		}

		++$failures;
		echo "  [FAIL] {$label}\n";
	};

	echo "=== job-status-transition-smoke ===\n";

	$jobs = new Jobs();

	$assert( 'non-final update succeeds', $jobs->update_job_status( 10, 'processing' ) );
	$non_terminal = $wpdb->updates[0]['data'] ?? array();
	$assert( 'non-final update writes status only', array( 'status' ) === array_keys( $non_terminal ) );
	$assert( 'non-final update does not fire completion hook', 0 === count( $datamachine_transition_hooks ) );

	$assert( 'terminal update succeeds through update_job_status', $jobs->update_job_status( 11, 'completed' ) );
	$terminal = $wpdb->updates[1]['data'] ?? array();
	$assert( 'terminal update writes completed_at', isset( $terminal['completed_at'] ) && is_string( $terminal['completed_at'] ) && '' !== $terminal['completed_at'] );
	$assert( 'terminal update records run metrics', 'completed' === ( RunMetrics::$completed[11] ?? '' ) );
	$assert( 'terminal update fires completion hook once', 1 === count( $datamachine_transition_hooks ) );
	$assert( 'completion hook receives terminal status', 'completed' === ( $datamachine_transition_hooks[0]['args'][1] ?? '' ) );

	$assert( 'complete_job rejects non-final statuses', false === $jobs->complete_job( 12, 'processing' ) );
	$assert( 'rejected complete_job does not write', 2 === count( $wpdb->updates ) );

	if ( $failures > 0 ) {
		echo "\n=== job-status-transition-smoke: {$failures} FAILURE(S) / {$total} assertions ===\n";
		exit( 1 );
	}

	echo "\n=== job-status-transition-smoke: ALL PASS ({$total} assertions) ===\n";
}
