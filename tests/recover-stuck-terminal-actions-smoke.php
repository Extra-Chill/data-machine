<?php
/**
 * Smoke coverage for terminal-backed Action Scheduler stale action recovery.
 *
 * @package DataMachine\Tests
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( $data ) {
		if ( ! is_string( $data ) ) {
			return $data;
		}

		$unserialized = @unserialize( $data );
		return false === $unserialized && 'b:0;' !== $data ? $data : $unserialized;
	}
}

require_once __DIR__ . '/../inc/Core/JobStatus.php';
require_once __DIR__ . '/../inc/Abilities/Job/JobHelpers.php';
require_once __DIR__ . '/../inc/Abilities/Job/RecoverStuckJobsAbility.php';

$failures = array();

$assert = static function ( string $label, bool $condition ) use ( &$failures ): void {
	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}

	echo "FAIL: {$label}\n";
	$failures[] = $label;
};

$reflection = new ReflectionClass( DataMachine\Abilities\Job\RecoverStuckJobsAbility::class );
$ability    = $reflection->newInstanceWithoutConstructor();
$extract    = $reflection->getMethod( 'extractActionJobId' );
$detect     = $reflection->getMethod( 'getTerminalBackedInProgressActions' );

$assert(
	'extracts job_id from JSON object args',
	123 === $extract->invoke( $ability, '{"job_id":123,"flow_step_id":"step-a"}' )
);

$assert(
	'extracts job_id from JSON array args',
	456 === $extract->invoke( $ability, '[{"job_id":456,"flow_step_id":"step-b"}]' )
);

$assert(
	'extracts job_id from serialized args',
	789 === $extract->invoke( $ability, serialize( array( 'job_id' => 789 ) ) )
);

$GLOBALS['wpdb'] = new class() {
	public string $prefix = 'wp_';

	public function prepare( string $query, ...$args ): string {
		unset( $args );
		return $query;
	}

	public function get_results( string $query, string $output = 'OBJECT' ): array {
		unset( $output );

		if ( str_contains( $query, 'actionscheduler_actions' ) ) {
			return array(
				(object) array(
					'action_id' => 9006,
					'args'      => '{"job_id":290,"flow_step_id":"3_bundle_step_1_2"}',
				),
			);
		}

		if ( str_contains( $query, 'datamachine_jobs' ) ) {
			return array(
				array(
					'job_id'  => 290,
					'flow_id' => 98,
					'status'  => 'failed',
				),
			);
		}

		return array();
	}
};

$terminal_actions = $detect->invoke( $ability, null );

$assert(
	'models stale in-progress action backed by terminal job',
	array(
		array(
			'action_id'  => 9006,
			'job_id'     => 290,
			'flow_id'    => 98,
			'job_status' => 'failed',
		),
	) === $terminal_actions
);

$ability_src = file_get_contents( __DIR__ . '/../inc/Abilities/Job/RecoverStuckJobsAbility.php' );
$cli_src     = file_get_contents( __DIR__ . '/../inc/Cli/Commands/JobsCommand.php' );

$assert(
	'detects in-progress execute-step actions with job_id args',
	str_contains( $ability_src, 'AND args LIKE %s' )
		&& str_contains( $ability_src, "'datamachine_execute_step'" )
		&& str_contains( $ability_src, "'in-progress'" )
);

$assert(
	'limits stale action matches to terminal Data Machine jobs',
	str_contains( $ability_src, 'JobStatus::FINAL_STATUSES' )
		&& str_contains( $ability_src, 'getTerminalBackedInProgressActions' )
);

$assert(
	'dry run reports terminal-backed actions without reconciliation',
	str_contains( $ability_src, "'would_reconcile_action'" )
		&& str_contains( $cli_src, 'Would reconcile Action Scheduler action %d for terminal job %d' )
);

$assert(
	'non-dry run completes only the stale Action Scheduler row',
	str_contains( $ability_src, "'status'             => 'complete'" )
		&& str_contains( $ability_src, "'status'    => 'in-progress'" )
		&& str_contains( $ability_src, 'without touching the terminal job' )
);

if ( ! empty( $failures ) ) {
	echo "\nFAILED: " . count( $failures ) . " terminal action recovery assertions failed.\n";
	exit( 1 );
}

echo "\nOK: terminal action recovery smoke assertions passed.\n";
