<?php
/**
 * Regression coverage for global worker status job counts.
 *
 * Run with: php tests/worker-status-job-counts-smoke.php
 *
 * @package DataMachine\Tests
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_CLI_Command {}

function sanitize_text_field( $value ): string {
	return trim( (string) $value );
}

function esc_html( $value ): string {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function get_option( string $option, $default = false ) {
	return $default;
}

final class WorkerStatusCountsWpdb {
	public string $prefix = 'wp_';
	public string $last_error = '';
	/** @var int[] */
	public array $counts = array( 9, 1122, 5739 );
	/** @var array<int,array<int,mixed>> */
	public array $prepare_calls = array();

	public function esc_like( string $value ): string {
		return $value;
	}

	public function prepare( string $query, ...$args ): string {
		$this->prepare_calls[] = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
		return $query;
	}

	public function get_var( string $query ) {
		unset( $query );
		return array_shift( $this->counts );
	}
}

require_once __DIR__ . '/../inc/Core/Database/BaseRepository.php';
require_once __DIR__ . '/../inc/Core/JobStatus.php';
require_once __DIR__ . '/../inc/Core/Database/Jobs/JobStatusMigration.php';
require_once __DIR__ . '/../inc/Core/Database/Jobs/Jobs.php';
require_once __DIR__ . '/../inc/Cli/BaseCommand.php';
require_once __DIR__ . '/../inc/Cli/Commands/WorkerCommand.php';

use DataMachine\Cli\Commands\WorkerCommand;

$assertions = 0;
$assert     = static function ( bool $condition, string $message ) use ( &$assertions ): void {
	++$assertions;
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
};

$wpdb            = new WorkerStatusCountsWpdb();
$GLOBALS['wpdb'] = $wpdb;
$method          = new ReflectionMethod( WorkerCommand::class, 'jobStatusCounts' );
$counts          = $method->invoke( null );

$assert( array( 'processing' => 9, 'pending' => 1122, 'failed' => 5739 ) === $counts, 'operator status returns global repository counts without an acting user' );
$assert( 3 === count( $wpdb->prepare_calls ), 'operator status performs only three COUNT queries' );
$assert( array( 'processing', 'processing - %', 'processing:%' ) === array_slice( $wpdb->prepare_calls[0], 1 ), 'processing count covers exact and bounded legacy rows' );
$assert( array( 'pending', 'pending - %', 'pending:%' ) === array_slice( $wpdb->prepare_calls[1], 1 ), 'pending count covers exact and bounded legacy rows' );
$assert( array( 'failed', 'failed - %', 'failed:%' ) === array_slice( $wpdb->prepare_calls[2], 1 ), 'failed count covers exact and bounded legacy rows' );

$wpdb->counts     = array( null );
$wpdb->last_error = 'simulated count failure';

try {
	$method->invoke( null );
	$assert( false, 'count failures must not become healthy-looking zeros' );
} catch ( RuntimeException $exception ) {
	$assert( str_contains( $exception->getMessage(), 'simulated count failure' ), 'count failure is surfaced with database context' );
}

echo "OK ({$assertions} assertions)\n";
