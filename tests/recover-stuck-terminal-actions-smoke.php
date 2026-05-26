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
$extract_for_hook = $reflection->getMethod( 'extractActionJobIdForHook' );
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

$assert(
	'extracts parent_job_id from pipeline batch args',
	321 === $extract_for_hook->invoke( $ability, '{"parent_job_id":321}', 'datamachine_pipeline_batch_chunk' )
);

$assert(
	'extracts optional run-flow job_id from positional args',
	654 === $extract_for_hook->invoke( $ability, '[98,654]', 'datamachine_run_flow_now' )
);

$GLOBALS['wpdb'] = new class() {
	public string $prefix = 'wp_';

	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		foreach ( $args as $arg ) {
			$replacement = is_numeric( $arg ) ? (string) $arg : "'" . (string) $arg . "'";
			$query       = preg_replace( '/%[ds]/', $replacement, $query, 1 );
		}

		return $query;
	}

	public function esc_like( string $text ): string {
		return $text;
	}

	public function get_results( string $query, string $output = 'OBJECT' ): array {
		unset( $output );

		if ( str_contains( $query, 'actionscheduler_actions' ) && str_contains( $query, 'datamachine_execute_step' ) ) {
			return array(
				(object) array(
					'action_id' => 9001,
					'hook'      => 'datamachine_execute_step',
					'args'      => '{"job_id":290,"flow_step_id":"3_bundle_step_1_2"}',
				),
			);
		}

		if ( str_contains( $query, 'actionscheduler_actions' ) && str_contains( $query, 'datamachine_pipeline_batch_chunk' ) ) {
			return array(
				(object) array(
					'action_id' => 9002,
					'hook'      => 'datamachine_pipeline_batch_chunk',
					'args'      => '{"parent_job_id":291}',
				),
			);
		}

		if ( str_contains( $query, 'actionscheduler_actions' ) && str_contains( $query, 'datamachine_run_flow_now' ) ) {
			return array(
				(object) array(
					'action_id' => 9003,
					'hook'      => 'datamachine_run_flow_now',
					'args'      => '[98,292]',
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
				array(
					'job_id'  => 291,
					'flow_id' => 98,
					'status'  => 'agent_skipped - source-rejected',
				),
				array(
					'job_id'  => 292,
					'flow_id' => 98,
					'status'  => 'failed - empty_data_packet_returned',
				),
			);
		}

		return array();
	}
};

$terminal_actions = $detect->invoke( $ability, null );

$assert(
	'models stale in-progress actions backed by terminal jobs',
	array(
		array(
			'action_id'  => 9001,
			'hook'       => 'datamachine_execute_step',
			'job_id'     => 290,
			'flow_id'    => 98,
			'job_status' => 'failed',
		),
		array(
			'action_id'  => 9002,
			'hook'       => 'datamachine_pipeline_batch_chunk',
			'job_id'     => 291,
			'flow_id'    => 98,
			'job_status' => 'agent_skipped - source-rejected',
		),
		array(
			'action_id'  => 9003,
			'hook'       => 'datamachine_run_flow_now',
			'job_id'     => 292,
			'flow_id'    => 98,
			'job_status' => 'failed - empty_data_packet_returned',
		),
	) === $terminal_actions
);

$ability_src = file_get_contents( __DIR__ . '/../inc/Abilities/Job/RecoverStuckJobsAbility.php' );
$cli_src     = file_get_contents( __DIR__ . '/../inc/Cli/Commands/JobsCommand.php' );

$assert(
	'detects orphaned in-progress Data Machine actions with paired job args',
	str_contains( $ability_src, 'AND args LIKE %s' )
		&& str_contains( $ability_src, "'datamachine_execute_step'" )
		&& str_contains( $ability_src, "'datamachine_pipeline_batch_chunk'" )
		&& str_contains( $ability_src, "'datamachine_run_flow_now'" )
		&& str_contains( $ability_src, 'a.claim_id = 0 OR c.claim_id IS NULL' )
		&& str_contains( $ability_src, "'in-progress'" )
);

$assert(
	'limits stale action matches to terminal Data Machine jobs',
	str_contains( $ability_src, 'JobStatus::isStatusFinal' )
		&& str_contains( $ability_src, 'getTerminalBackedInProgressActions' )
);

$assert(
	'dry run reports terminal-backed actions without reconciliation',
	str_contains( $ability_src, "'would_reconcile_action'" )
		&& str_contains( $cli_src, 'Would reconcile Action Scheduler action %d (%s) for terminal job %d' )
);

$assert(
	'non-dry run completes only the stale Action Scheduler row',
	str_contains( $ability_src, 'SET a.status = %s' )
		&& str_contains( $ability_src, 'AND a.status = %s' )
		&& str_contains( $ability_src, 'AND (a.claim_id = 0 OR c.claim_id IS NULL)' )
		&& str_contains( $ability_src, 'without touching the terminal job' )
);

if ( ! empty( $failures ) ) {
	echo "\nFAILED: " . count( $failures ) . " terminal action recovery assertions failed.\n";
	exit( 1 );
}

echo "\nOK: terminal action recovery smoke assertions passed.\n";
