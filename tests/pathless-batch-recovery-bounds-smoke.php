<?php
/** Behavioral smoke test for bounded pathless batch action lookup. */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'ARRAY_A', 'ARRAY_A' );

function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value );
}

function maybe_unserialize( mixed $value ): mixed {
	if ( ! is_string( $value ) || ! is_serialized( $value ) ) {
		return $value;
	}
	return unserialize( trim( $value ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Compatibility fixture.
}

function is_serialized( mixed $value ): bool {
	return is_string( $value ) && (bool) preg_match( '/^[aOsibdN]:/', trim( $value ) );
}

function current_time( string $type, bool $gmt = false ): string {
	return '2026-08-09 12:00:00';
}

final class PathlessRecoveryWpdb {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public array $responses = array();
	public array $queries = array();
	public array $result_counts = array();
	public array $var_responses = array();

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
			$query       = preg_replace( '/%[sd]/', $replacement, $query, 1 );
		}
		return $query;
	}

	public function get_var( string $query ): mixed {
		$this->queries[] = $query;
		$this->last_error = '';
		return array_shift( $this->var_responses );
	}

	public function get_results( string $query, mixed $output = null ): ?array {
		$this->queries[] = $query;
		$response        = array_shift( $this->responses );
		if ( is_callable( $response ) ) {
			$response = $response( $query );
		}
		if ( null === $response ) {
			$this->last_error = 'simulated query failure';
			return null;
		}
		$this->last_error = '';
		$this->result_counts[] = count( $response );
		return $response;
	}
}

/** Table-name stand-in for the Jobs repository, which the standalone harness does not load. */
final class PathlessJobsTableStub {
	public const TABLE_NAME = 'datamachine_jobs';
}
class_alias( PathlessJobsTableStub::class, 'DataMachine\\Core\\Database\\Jobs\\Jobs' );

function pathless_action( string $args, string $status = 'pending', string $hook = 'datamachine_pipeline_batch_chunk', int $action_id = 1 ): array {
	$recent = gmdate( 'Y-m-d H:i:s', time() - 60 );
	return array(
		'action_id'          => $action_id,
		'hook'               => $hook,
		'action_args'        => $args,
		'status'             => $status,
		'scheduled_date_gmt' => $recent,
		'last_attempt_gmt'   => 'in-progress' === $status ? $recent : '0000-00-00 00:00:00',
	);
}

function pathless_assert( bool $condition, string $message ): void {
	global $failures, $passes;
	if ( $condition ) {
		++$passes;
		return;
	}
	++$failures;
	echo "[FAIL] {$message}\n";
}

require_once __DIR__ . '/fixtures/scheduler-evidence-bootstrap.php';
require_once __DIR__ . '/../inc/Core/ActionScheduler/PathlessBatchRecovery.php';

use DataMachine\Core\ActionScheduler\PathlessBatchRecovery;
use DataMachine\Core\Jobs\SchedulerEvidence;

$failures = 0;
$passes   = 0;
$engine   = array(
	'batch'       => true,
	'batch_state' => array( 'offset' => 40 ),
);
$active   = static fn( SchedulerEvidence $evidence ): array => PathlessBatchRecovery::diagnoseActiveWork( 7, $engine, 1, $evidence );

global $wpdb;
$wpdb = new PathlessRecoveryWpdb();

$evidence = SchedulerEvidence::fromRows( array( pathless_action( '{"parent_job_id":7,"offset":40}' ) ) );
$result   = $active( $evidence );
pathless_assert( true === $result['owned'] && true === $result['chunk_action'], 'canonical chunk action is active' );
pathless_assert( array() === $wpdb->queries, 'chunk evidence needs no scheduler or child queries' );

$evidence = SchedulerEvidence::fromRows( array( pathless_action( '[{"parent_job_id":7,"offset":40}]' ) ) );
pathless_assert( true === $active( $evidence )['chunk_action'], 'nested JSON chunk action is active' );

$evidence = SchedulerEvidence::fromRows( array( pathless_action( serialize( array( array( 'parent_job_id' => 7, 'offset' => 40 ) ) ) ) ) );
pathless_assert( true === $active( $evidence )['chunk_action'], 'serialized chunk action is active' );

$evidence = SchedulerEvidence::fromRows( array( pathless_action( '{"parent_job_id":7,"offset":40}', 'in-progress' ) ) );
pathless_assert( true === $active( $evidence )['chunk_action'], 'fresh in-progress chunk action is active' );

$old      = gmdate( 'Y-m-d H:i:s', time() - 5 * HOUR_IN_SECONDS );
$stale    = pathless_action( '{"parent_job_id":7,"offset":40}', 'in-progress' );
$stale['scheduled_date_gmt'] = $old;
$stale['last_attempt_gmt']   = $old;
$wpdb                        = new PathlessRecoveryWpdb();
$wpdb->var_responses         = array( 0 );
$wpdb->responses             = array( array() );
$result                      = $active( SchedulerEvidence::fromRows( array( $stale ) ) );
pathless_assert( false === $result['chunk_action'] && false === $result['owned'], 'stale in-progress chunk action does not own the parent' );

$unrelated = array_fill( 0, SchedulerEvidence::SCAN_LIMIT + 1, pathless_action( '{"parent_job_id":999,"offset":0}' ) );
$wpdb      = new PathlessRecoveryWpdb();
$result    = $active( SchedulerEvidence::fromRows( $unrelated, count( $unrelated ) <= SchedulerEvidence::SCAN_LIMIT ) );
pathless_assert( true === $result['owned'] && false === $result['evidence_complete'], 'truncated evidence fails closed' );
pathless_assert( array() === $wpdb->queries, 'truncated evidence stops further queries' );

$result = $active( SchedulerEvidence::fromRows( array(), false ) );
pathless_assert( true === $result['owned'] && false === $result['evidence_complete'], 'query failure fails closed' );

$wpdb                = new PathlessRecoveryWpdb();
$wpdb->var_responses = array( 0 );
$wpdb->responses     = array( array() );
$result              = $active( SchedulerEvidence::fromRows( array( pathless_action( '{"parent_job_id":999,"offset":0}' ) ) ) );
pathless_assert( false === $result['owned'] && true === $result['evidence_complete'], 'complete evidence with no owner proves absence' );
pathless_assert( 2 === count( $wpdb->queries ), 'absence check reads child rows and never re-queries the scheduler' );

$old_child_created   = gmdate( 'Y-m-d H:i:s', time() - 5 * HOUR_IN_SECONDS );
$wpdb                = new PathlessRecoveryWpdb();
$wpdb->var_responses = array( 1 );
$wpdb->responses     = array( array( array( 'job_id' => 71, 'status' => 'pending', 'created_at' => $old_child_created ) ) );
$evidence            = SchedulerEvidence::fromRows( array( pathless_action( '{"job_id":71,"flow_step_id":"step"}', 'pending', 'datamachine_execute_step', 901 ) ) );
$result              = $active( $evidence );
pathless_assert( true === $result['owned'] && array( 71 ) === $result['active_child_job_ids'] && array( 901 ) === $result['child_action_ids'], 'stale child with a live step action owns the parent through the shared snapshot' );
pathless_assert( 2 === count( $wpdb->queries ), 'child work costs no scheduler query beyond the shared snapshot' );

$children = PathlessBatchRecovery::diagnoseChildRows(
	array(
		array( 'job_id' => 70, 'status' => 'pending', 'created_at' => '2026-08-09 11:30:00' ),
		array( 'job_id' => 71, 'status' => 'processing', 'created_at' => '2026-08-09 09:00:00' ),
		array( 'job_id' => 72, 'status' => 'completed', 'created_at' => '2026-08-09 11:30:00' ),
	),
	HOUR_IN_SECONDS,
	strtotime( '2026-08-09 12:00:00 UTC' )
);
pathless_assert( array( 70 ) === $children['active_job_ids'], 'fresh child row protects the scheduling race' );
pathless_assert( array( 71 ) === $children['stale_job_ids'], 'old child row ages out of scheduler ownership' );

$old_child = array( array( 'job_id' => 71, 'status' => 'pending', 'created_at' => '2026-08-09 09:00:00' ) );
$current_action = array(
	array(
		'action_id'          => 901,
		'args'               => '{"job_id":71,"flow_step_id":"step"}',
		'status'             => 'pending',
		'scheduled_date_gmt' => '2026-08-09 11:59:00',
		'last_attempt_gmt'   => '0000-00-00 00:00:00',
	),
);
$owned = PathlessBatchRecovery::diagnoseChildRows( $old_child, HOUR_IN_SECONDS, strtotime( '2026-08-09 12:00:00 UTC' ), $current_action );
pathless_assert( array( 71 ) === $owned['active_job_ids'], 'old child with current action still owns parent' );
pathless_assert( array( 901 ) === $owned['active_action_ids'], 'current child action ID is exposed' );

$stale_action = $current_action;
$stale_action[0]['status'] = 'in-progress';
$stale_action[0]['last_attempt_gmt'] = '2026-08-09 09:00:00';
$historical = PathlessBatchRecovery::diagnoseChildRows( $old_child, HOUR_IN_SECONDS, strtotime( '2026-08-09 12:00:00 UTC' ), $stale_action );
pathless_assert( array( 71 ) === $historical['stale_job_ids'], 'old child with only stale action ages out' );
pathless_assert( array() === $historical['active_action_ids'], 'stale child action is not ownership' );

$completed_action = $current_action;
$completed_action[0]['status'] = 'complete';
$completed = PathlessBatchRecovery::diagnoseChildRows( $old_child, HOUR_IN_SECONDS, strtotime( '2026-08-09 12:00:00 UTC' ), $completed_action );
pathless_assert( array( 71 ) === $completed['stale_job_ids'], 'old child with only historical action ages out' );

$incomplete = PathlessBatchRecovery::diagnoseChildRows( $old_child, HOUR_IN_SECONDS, strtotime( '2026-08-09 12:00:00 UTC' ), array(), false );
pathless_assert( false === $incomplete['evidence_complete'], 'bounded evidence failure is explicit' );
pathless_assert( array( 71 ) === $incomplete['active_job_ids'], 'bounded evidence failure preserves ownership' );

$source = file_get_contents( __DIR__ . '/../inc/Core/ActionScheduler/PathlessBatchRecovery.php' ) ?: '';
pathless_assert( str_contains( $source, 'self::CHILD_QUERY_LIMIT + 1' ), 'active child query uses a truncation sentinel' );
pathless_assert( str_contains( $source, 'count( $children ) <= self::CHILD_QUERY_LIMIT' ), 'child evidence fails closed when truncated' );
pathless_assert( ! str_contains( $source, 'LIKE' ) && ! str_contains( $source, 'ACTION_QUERY_LIMIT' ), 'pathless recovery issues no per-job scheduler LIKE query' );
pathless_assert( str_contains( $source, 'SchedulerEvidence::load()' ), 'child action evidence comes from the shared snapshot' );

echo "Pathless batch recovery bounds: {$passes} passed, {$failures} failed.\n";
exit( $failures > 0 ? 1 : 0 );
