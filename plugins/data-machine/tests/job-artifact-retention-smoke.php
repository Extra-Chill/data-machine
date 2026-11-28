<?php
/**
 * Smoke tests for job artifact retention (#3937).
 *
 * Tool-trace and transcript artifacts written by JobArtifacts, including
 * legacy files written without a retention_scope, must age out under the
 * default job-artifact retention policy, in bounded and resumable batches.
 *
 * Run with: php tests/job-artifact-retention-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

$GLOBALS['datamachine_test_filters'] = array();
$GLOBALS['datamachine_test_options'] = array();

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		unset( $args );
		if ( isset( $GLOBALS['datamachine_test_filters'][ $hook ] ) ) {
			return ( $GLOBALS['datamachine_test_filters'][ $hook ] )( $value );
		}
		return $value;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default_value = false ) {
		return array_key_exists( $name, $GLOBALS['datamachine_test_options'] ) ? $GLOBALS['datamachine_test_options'][ $name ] : $default_value;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, $value, $autoload = null ): bool {
		unset( $autoload );
		$GLOBALS['datamachine_test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ): string {
		return rtrim( (string) $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir(): array {
		return array(
			'basedir' => $GLOBALS['datamachine_test_upload_dir'],
			'baseurl' => 'https://example.test/uploads',
		);
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( string $file ): bool {
		return is_file( $file ) && unlink( $file );
	}
}

if ( ! class_exists( 'WP_Filesystem_Base' ) ) {
	class WP_Filesystem_Base {
		public function delete( string $file ): bool {
			return is_file( $file ) && unlink( $file );
		}

		public function rmdir( string $directory ): bool {
			return is_dir( $directory ) && rmdir( $directory );
		}
	}
}

if ( ! function_exists( 'WP_Filesystem' ) ) {
	function WP_Filesystem(): bool {
		$GLOBALS['wp_filesystem'] = new WP_Filesystem_Base();
		return true;
	}
}

require_once __DIR__ . '/bootstrap-unit.php';

use DataMachine\Core\FilesRepository\FileCleanup;
use DataMachine\Core\JobArtifactSurfaces;
use DataMachine\Engine\AI\System\Tasks\Retention\RetentionCleanup;

$failures = array();
$passes   = 0;

$assert = static function ( bool $condition, string $label ) use ( &$failures, &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "  [PASS] {$label}\n";
		return;
	}

	$failures[] = $label;
	echo "  [FAIL] {$label}\n";
};

$remove_tree = static function ( string $directory ) use ( &$remove_tree ): void {
	if ( ! is_dir( $directory ) ) {
		return;
	}

	foreach ( array_diff( scandir( $directory ) ?: array(), array( '.', '..' ) ) as $entry ) {
		$path = $directory . '/' . $entry;
		is_dir( $path ) ? $remove_tree( $path ) : unlink( $path );
	}
	rmdir( $directory );
};

$upload_dir                             = sys_get_temp_dir() . '/datamachine-job-artifact-retention-' . uniqid( '', true );
$GLOBALS['datamachine_test_upload_dir'] = $upload_dir;
$jobs_dir                               = $upload_dir . '/datamachine-artifacts/jobs';

/**
 * Write a JSON artifact for a job with a given age in days.
 */
$write_artifact = static function ( int $job_id, string $file_name, array $payload, int $age_days ) use ( $jobs_dir ): string {
	$dir = $jobs_dir . '/' . $job_id;
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0775, true );
	}

	$path = $dir . '/' . $file_name;
	file_put_contents( $path, json_encode( $payload ) . "\n" );
	$time = time() - ( $age_days * DAY_IN_SECONDS );
	touch( $path, $time );
	touch( $dir, $time );
	return $path;
};

$legacy_tool_trace = array(
	'schema_version' => 1,
	'artifact_type'  => 'tool_trace',
	'artifact_ref'   => 'datamachine://jobs/1/artifacts/tool-trace',
	'entries'        => array(),
);

// A non-default registered policy with a longer window.
$GLOBALS['datamachine_test_filters']['datamachine_job_artifact_retention_policies'] = static function ( $policies ) {
	$policies['long_lived'] = array(
		'label'           => 'Long lived artifacts',
		'retention_scope' => 'long_lived',
		'max_age_days'    => 90,
	);
	return $policies;
};

echo "=== job-artifact-retention-smoke ===\n";

// --- Fixtures -------------------------------------------------------------
$old_trace      = $write_artifact( 101, 'tool-trace.json', $legacy_tool_trace, 40 ); // Legacy, unscoped, outside window.
$recent_trace   = $write_artifact( 102, 'tool-trace.json', $legacy_tool_trace, 1 );  // Legacy, unscoped, inside window.
$old_stamped    = $write_artifact( 103, 'transcript.json', array( 'artifact_type' => 'transcript', 'retention_scope' => JobArtifactSurfaces::DEFAULT_RETENTION_SCOPE ), 40 );
$old_unknown    = $write_artifact( 104, 'tool-trace.json', $legacy_tool_trace + array( 'retention_scope' => 'not_registered' ), 40 );
$long_lived     = $write_artifact( 105, 'result.json', array( 'retention_scope' => 'long_lived' ), 45 ); // Inside its own 90-day window.
$long_lived_old = $write_artifact( 106, 'result.json', array( 'retention_scope' => 'long_lived' ), 120 );
$mixed_old      = $write_artifact( 107, 'tool-trace.json', $legacy_tool_trace, 40 );
$mixed_recent   = $write_artifact( 107, 'transcript.json', array( 'artifact_type' => 'transcript' ), 2 );
touch( $mixed_old, time() - ( 40 * DAY_IN_SECONDS ) );
$keep_other     = $jobs_dir . '/108/notes.txt'; // Non-JSON file keeps its directory.
$write_artifact( 108, 'tool-trace.json', $legacy_tool_trace, 40 );
file_put_contents( $keep_other, 'keep' );
mkdir( $jobs_dir . '/not-a-job', 0775, true );
file_put_contents( $jobs_dir . '/not-a-job/stray.json', '{}' );
touch( $jobs_dir . '/not-a-job/stray.json', time() - ( 40 * DAY_IN_SECONDS ) );
mkdir( $jobs_dir . '/109', 0775, true ); // Fresh empty dir: may be an in-flight write.

$cleanup = new FileCleanup();
$scope   = JobArtifactSurfaces::DEFAULT_RETENTION_SCOPE;

// --- Counting covers unscoped tool traces ---------------------------------
// Eligible: 101, 103, 104, 106, 107/tool-trace, 108, not-a-job/stray (full walk counts every dir).
$assert( 7 === $cleanup->count_old_job_artifacts( $scope, 30 ), 'default scope counts legacy unscoped tool traces and per-scope windows' );
$assert( 2 === $cleanup->count_old_job_artifacts( 'long_lived', 30 ), 'explicit non-default scope count still matches only stamped files' );

// --- Bounded batch --------------------------------------------------------
$first = $cleanup->cleanup_job_artifacts_batch( $scope, 30, 3, 0 );
$assert( 3 === $first['dirs_scanned'], 'first batch visits exactly batch-size job directories' );
$assert( 103 === $first['next_cursor'], 'first batch cursor stops at the last visited job id' );
$assert( false === $first['complete'], 'first batch reports the sweep is incomplete' );
$assert( 2 === $first['deleted'], 'first batch deletes only eligible files within its window of dirs' );
$assert( ! file_exists( $old_trace ), 'old unscoped tool-trace artifact is deleted' );
$assert( ! is_dir( $jobs_dir . '/101' ), 'emptied job directory is removed' );
$assert( file_exists( $recent_trace ), 'tool-trace artifact inside the retention window is kept' );
$assert( is_dir( $jobs_dir . '/102' ), 'job directory with retained artifacts is kept' );
$assert( ! file_exists( $old_stamped ), 'old stamped transcript artifact is deleted' );
$assert( file_exists( $old_unknown ), 'directories beyond the batch bound are untouched' );
$assert( file_exists( $long_lived_old ), 'later job directories wait for the next batch' );

$second = $cleanup->cleanup_job_artifacts_batch( $scope, 30, 3, $first['next_cursor'] );
$assert( 3 === $second['dirs_scanned'], 'second batch resumes after the cursor' );
$assert( 106 === $second['next_cursor'], 'second batch advances the cursor' );
$assert( ! file_exists( $old_unknown ), 'old artifact with an unregistered scope falls back to the default window' );
$assert( file_exists( $long_lived ), 'registered non-default scope keeps its own longer window' );
$assert( ! file_exists( $long_lived_old ), 'registered non-default scope expires past its own window' );

$third = $cleanup->cleanup_job_artifacts_batch( $scope, 30, 3, $second['next_cursor'] );
$assert( 3 === $third['dirs_scanned'], 'third batch visits the remaining numeric job directories' );
$assert( ! file_exists( $mixed_old ) && file_exists( $mixed_recent ), 'mixed-age directory loses only expired files' );
$assert( is_dir( $jobs_dir . '/108' ) && file_exists( $keep_other ), 'non-artifact files keep their job directory' );
$assert( is_dir( $jobs_dir . '/109' ), 'freshly created empty job directory is not removed' );
$assert( 109 === $third['next_cursor'] && false === $third['complete'], 'a full final batch cannot yet prove the sweep is complete' );

$fourth = $cleanup->cleanup_job_artifacts_batch( $scope, 30, 3, $third['next_cursor'] );
$assert( 0 === $fourth['dirs_scanned'] && true === $fourth['complete'] && 0 === $fourth['next_cursor'], 'exhausted sweep completes and wraps the cursor to 0' );
$assert( file_exists( $jobs_dir . '/not-a-job/stray.json' ), 'batched sweep ignores non-numeric directories' );

// --- Deadline bound ---------------------------------------------------------
$write_artifact( 201, 'tool-trace.json', $legacy_tool_trace, 40 );
$write_artifact( 202, 'tool-trace.json', $legacy_tool_trace, 40 );
$deadline = $cleanup->cleanup_job_artifacts_batch( $scope, 30, 100, 200, microtime( true ) - 1 );
$assert( 1 === $deadline['dirs_scanned'] && 201 === $deadline['next_cursor'] && false === $deadline['complete'], 'expired deadline stops after one directory and keeps the cursor' );
$assert( is_dir( $jobs_dir . '/202' ), 'directory past the deadline is left for the next run' );

// --- Old empty directory is reclaimed ----------------------------------------
mkdir( $jobs_dir . '/301', 0775, true );
touch( $jobs_dir . '/301', time() - ( 40 * DAY_IN_SECONDS ) );
$cleanup->cleanup_job_artifacts_batch( $scope, 30, 10, 300 );
$assert( ! is_dir( $jobs_dir . '/301' ), 'stale empty job directory is removed' );

// --- RetentionCleanup persists the cursor between runs ------------------------
$GLOBALS['datamachine_test_filters']['datamachine_job_artifacts_cleanup_batch_size'] = static fn() => 1;
update_option( RetentionCleanup::JOB_ARTIFACTS_CURSOR_OPTION, 0 );
$write_artifact( 401, 'tool-trace.json', $legacy_tool_trace, 40 );
$run = RetentionCleanup::cleanupJobArtifacts();
$assert( 1 === $run['dirs_scanned'] && 1 === $run['batch_size'], 'scheduled cleanup honors the batch-size filter' );
$assert( (int) $run['next_cursor'] === (int) get_option( RetentionCleanup::JOB_ARTIFACTS_CURSOR_OPTION ), 'scheduled cleanup persists the resume cursor' );
$first_cursor = (int) $run['next_cursor'];
$run          = RetentionCleanup::cleanupJobArtifacts();
$assert( (int) $run['cursor'] === $first_cursor && (int) $run['next_cursor'] > $first_cursor, 'next scheduled run resumes from the stored cursor' );
unset( $GLOBALS['datamachine_test_filters']['datamachine_job_artifacts_cleanup_batch_size'] );
for ( $i = 0; $i < 5 && ! ( $run['sweep_complete'] ?? false ); $i++ ) {
	$run = RetentionCleanup::cleanupJobArtifacts();
}
$assert( true === $run['sweep_complete'] && 0 === (int) get_option( RetentionCleanup::JOB_ARTIFACTS_CURSOR_OPTION ), 'scheduled sweep completes and resets the stored cursor' );
$assert( ! is_dir( $jobs_dir . '/401' ), 'scheduled cleanup deletes old unscoped tool traces' );
$assert( file_exists( $recent_trace ), 'scheduled cleanup keeps tool traces inside the window' );

$remove_tree( $upload_dir );

if ( $failures ) {
	echo 'FAILED: ' . count( $failures ) . " job artifact retention assertions failed.\n";
	foreach ( $failures as $failure ) {
		echo " - {$failure}\n";
	}
	exit( 1 );
}

echo "=== job-artifact-retention-smoke: ALL PASS ({$passes} assertions) ===\n";
