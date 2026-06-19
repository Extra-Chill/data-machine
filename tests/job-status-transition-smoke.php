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
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}
	if ( ! defined( 'OBJECT' ) ) {
		define( 'OBJECT', 'OBJECT' );
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

	if ( ! function_exists( 'wp_cache_get' ) ) {
		function wp_cache_get( $key, string $group = '' ) {
			return $GLOBALS['datamachine_transition_cache'][ $group ][ $key ] ?? false;
		}
	}

	if ( ! function_exists( 'wp_cache_set' ) ) {
		function wp_cache_set( $key, $value, string $group = '' ): bool {
			$GLOBALS['datamachine_transition_cache'][ $group ][ $key ] = $value;
			return true;
		}
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

	if ( ! class_exists( 'wpdb' ) ) {
		class wpdb {
			public $prefix;
		}
	}

	class Datamachine_Transition_Smoke_Wpdb extends wpdb {
		public function __construct() {
			$this->prefix = 'wp_';
		}

		/** @var array<int,array<string,mixed>> */
		public array $updates = array();

		/** @var array<int,array<string,mixed>> */
		public array $rows = array(
			10 => array( 'job_id' => 10, 'status' => 'pending', 'engine_data' => null ),
			11 => array( 'job_id' => 11, 'status' => 'pending', 'engine_data' => null ),
			12 => array( 'job_id' => 12, 'status' => 'pending', 'engine_data' => null ),
		);

		public function update( $table, $data, $where, $format = null, $where_format = null ) {
			$this->updates[] = compact( 'table', 'data', 'where', 'format', 'where_format' );
			$job_id          = (int) ( $where['job_id'] ?? 0 );
			if ( isset( $this->rows[ $job_id ] ) ) {
				$this->rows[ $job_id ] = array_merge( $this->rows[ $job_id ], $data );
			}
			return 1;
		}

		public function prepare( $query, ...$args ) {
			return array( $query, $args );
		}

		public function get_row( $query = null, $output = OBJECT, $y = 0 ) {
			$args   = is_array( $query ) ? ( $query[1] ?? array() ) : array();
			$job_id = (int) end( $args );
			$row    = $this->rows[ $job_id ] ?? null;
			if ( $row && isset( $row['engine_data'] ) && is_string( $row['engine_data'] ) ) {
				$decoded = json_decode( $row['engine_data'], true );
				if ( JSON_ERROR_NONE === json_last_error() ) {
					$row['engine_data'] = $decoded;
				}
			}
			return $row ?: null;
		}
	}

	$wpdb = new Datamachine_Transition_Smoke_Wpdb();

	require_once __DIR__ . '/../inc/Core/JobStatus.php';
	require_once __DIR__ . '/../inc/Core/Database/BaseRepository.php';
	require_once __DIR__ . '/../inc/Core/Database/LifecycleStateTransition.php';
	require_once __DIR__ . '/../inc/Core/EngineData.php';
	require_once __DIR__ . '/../inc/Core/RunLifecycleStore.php';
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
	$status_updates = static function () use ( $wpdb ): array {
		return array_values(
			array_filter(
				$wpdb->updates,
				static fn( array $update ): bool => array_key_exists( 'status', $update['data'] ?? array() )
			)
		);
	};

	$assert( 'non-final update succeeds', $jobs->update_job_status( 10, 'processing' ) );
	$non_terminal = $status_updates()[0]['data'] ?? array();
	$assert( 'non-final update writes status only', array( 'status' ) === array_keys( $non_terminal ) );
	$assert( 'non-final update does not fire completion hook', 0 === count( $datamachine_transition_hooks ) );

	$assert( 'terminal update succeeds through update_job_status', $jobs->update_job_status( 11, 'completed' ) );
	$terminal = $status_updates()[1]['data'] ?? array();
	$assert( 'terminal update writes completed_at', isset( $terminal['completed_at'] ) && is_string( $terminal['completed_at'] ) && '' !== $terminal['completed_at'] );
	$assert( 'terminal update records run metrics', 'completed' === ( RunMetrics::$completed[11] ?? '' ) );
	$assert( 'terminal update fires completion hook once', 1 === count( $datamachine_transition_hooks ) );
	$assert( 'completion hook receives terminal status', 'completed' === ( $datamachine_transition_hooks[0]['args'][1] ?? '' ) );

	$assert( 'complete_job rejects non-final statuses', false === $jobs->complete_job( 12, 'processing' ) );
	$assert( 'rejected complete_job does not write', 2 === count( $status_updates() ) );

	if ( $failures > 0 ) {
		echo "\n=== job-status-transition-smoke: {$failures} FAILURE(S) / {$total} assertions ===\n";
		exit( 1 );
	}

	echo "\n=== job-status-transition-smoke: ALL PASS ({$total} assertions) ===\n";
}
