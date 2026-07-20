<?php
/**
 * Pure-PHP smoke test for system run task params + guardrails.
 *
 * Run with: php tests/system-run-task-params-smoke.php
 *
 * @package DataMachine\Tests
 */

$failures = 0;
$total    = 0;

$assert = function ( string $label, bool $cond ) use ( &$failures, &$total ): void {
	$total++;
	if ( $cond ) {
		echo "  [PASS] {$label}\n";
		return;
	}
	$failures++;
	echo "  [FAIL] {$label}\n";
};

function smoke_coerce_run_task_param_value( string $value ): mixed {
	$trimmed = trim( $value );
	if ( 'true' === strtolower( $trimmed ) ) {
		return true;
	}
	if ( 'false' === strtolower( $trimmed ) ) {
		return false;
	}
	if ( 'null' === strtolower( $trimmed ) ) {
		return null;
	}
	if ( is_numeric( $trimmed ) ) {
		return str_contains( $trimmed, '.' ) ? (float) $trimmed : (int) $trimmed;
	}
	return $value;
}

function smoke_collect_run_task_param_args( array $assoc_args, array $argv = array() ): array|string {
	$raw_params  = array();
	$argv_values = array_values( $argv );

	foreach ( $argv_values as $index => $arg ) {
		if ( ! is_string( $arg ) ) {
			continue;
		}
		if ( str_starts_with( $arg, '--param=' ) ) {
			$raw_params[] = substr( $arg, strlen( '--param=' ) );
			continue;
		}
		if ( '--param' === $arg && isset( $argv_values[ $index + 1 ] ) ) {
			$raw_params[] = (string) $argv_values[ $index + 1 ];
		}
	}

	if ( count( $raw_params ) > 1 || ( ! isset( $assoc_args['param'] ) && ! empty( $raw_params ) ) ) {
		return $raw_params;
	}

	$param_args = $assoc_args['param'] ?? array();
	if ( is_string( $param_args ) ) {
		$param_args = array( $param_args );
	}
	return $param_args;
}

function smoke_parse_run_task_params( array $assoc_args, array $argv = array() ): array {
	$params = array();

	if ( isset( $assoc_args['params'] ) ) {
		$decoded = json_decode( (string) $assoc_args['params'], true );
		if ( ! is_array( $decoded ) || array_is_list( $decoded ) ) {
			return array( 'error' => '--params must be a JSON object.' );
		}
		$params = $decoded;
	}

	$param_args = smoke_collect_run_task_param_args( $assoc_args, $argv );
	if ( ! is_array( $param_args ) ) {
		return array( 'error' => '--param must be key=value.' );
	}

	foreach ( $param_args as $param_arg ) {
		if ( ! is_string( $param_arg ) || ! str_contains( $param_arg, '=' ) ) {
			return array( 'error' => '--param must be key=value.' );
		}
		list( $key, $value ) = explode( '=', $param_arg, 2 );
		if ( '' === trim( $key ) ) {
			return array( 'error' => '--param key cannot be empty.' );
		}
		$params[ trim( $key ) ] = smoke_coerce_run_task_param_value( $value );
	}

	if ( array_key_exists( 'agent', $assoc_args ) ) {
		if ( '' === trim( (string) $assoc_args['agent'] ) ) {
			return array( 'error' => '--agent cannot be empty.' );
		}
		$params['agent'] = smoke_coerce_run_task_param_value( (string) $assoc_args['agent'] );
	}

	if ( ! empty( $assoc_args['dry-run'] ) && ! empty( $assoc_args['apply'] ) ) {
		return array( 'error' => 'Use either --dry-run or --apply, not both.' );
	}
	if ( ! empty( $assoc_args['dry-run'] ) ) {
		$params['dry_run'] = true;
	}
	if ( ! empty( $assoc_args['apply'] ) ) {
		$params['dry_run'] = false;
		$params['apply']   = true;
	}

	return $params;
}

function smoke_extract_run_task_context( array &$params ): array {
	$context = array();
	if ( array_key_exists( 'agent', $params ) ) {
		$agent = $params['agent'];
		unset( $params['agent'] );

		if ( is_int( $agent ) || ( is_string( $agent ) && is_numeric( $agent ) ) ) {
			$context['agent_id'] = (int) $agent;
		} elseif ( null !== $agent && '' !== trim( (string) $agent ) ) {
			$context['agent_slug'] = (string) $agent;
		}
	}

	foreach ( array( 'agent_id', 'agent_slug' ) as $key ) {
		if ( array_key_exists( $key, $params ) ) {
			$context[ $key ] = $params[ $key ];
			unset( $params[ $key ] );
		}
	}

	return $context;
}

function smoke_string_list( mixed $value ): array {
	if ( is_string( $value ) ) {
		$value = array( $value );
	}
	if ( ! is_array( $value ) ) {
		return array();
	}
	return array_values( array_filter( array_map( 'strval', $value ), static fn( string $item ): bool => '' !== $item ) );
}

function smoke_has_non_empty_param( array $params, string $key ): bool {
	return array_key_exists( $key, $params ) && null !== $params[ $key ] && '' !== $params[ $key ];
}

function smoke_validate_run_task_params( string $task_type, array $meta, array $params ): array {
	$schema          = is_array( $meta['params_schema'] ?? null ) ? $meta['params_schema'] : array();
	$accepted_params = smoke_string_list( $schema['accepted'] ?? $schema['accepted_params'] ?? array() );
	$required_params = smoke_string_list( $schema['required'] ?? $schema['required_params'] ?? array() );
	$scope_params    = smoke_string_list( $schema['scope'] ?? $schema['scope_params'] ?? array() );

	$core_params = array( 'dry_run', 'apply', 'mode' );
	if ( ! empty( $accepted_params ) ) {
		$allowed = array_unique( array_merge( $accepted_params, $required_params, $scope_params, $core_params ) );
		$unknown = array_diff( array_keys( $params ), $allowed );
		if ( ! empty( $unknown ) ) {
			return array( 'success' => false, 'error' => sprintf( "Task '%s' does not accept param(s): %s", $task_type, implode( ', ', $unknown ) ) );
		}
	}

	$missing = array();
	foreach ( $required_params as $required_param ) {
		if ( ! smoke_has_non_empty_param( $params, $required_param ) ) {
			$missing[] = $required_param;
		}
	}
	if ( ! empty( $missing ) ) {
		return array( 'success' => false, 'error' => sprintf( "Task '%s' is missing required param(s): %s", $task_type, implode( ', ', $missing ) ) );
	}

	if ( ! empty( $meta['requires_scope'] ) ) {
		$scope_candidates = ! empty( $scope_params ) ? $scope_params : $required_params;
		if ( empty( $scope_candidates ) ) {
			return array( 'success' => false, 'error' => sprintf( "Task '%s' declares requires_scope but no scope params_schema entries.", $task_type ) );
		}

		$has_scope = false;
		foreach ( $scope_candidates as $scope_param ) {
			if ( smoke_has_non_empty_param( $params, $scope_param ) ) {
				$has_scope = true;
				break;
			}
		}
		if ( ! $has_scope ) {
			return array( 'success' => false, 'error' => sprintf( "Task '%s' requires an explicit scope param: %s", $task_type, implode( ', ', $scope_candidates ) ) );
		}
	}

	if ( ! empty( $meta['mutates'] ) && ! empty( $meta['supports_dry_run'] ) && empty( $params['apply'] ) && ! array_key_exists( 'dry_run', $params ) ) {
		$params['dry_run'] = true;
	}

	return array( 'success' => true, 'task_params' => $params );
}

function smoke_build_task_scheduler_initial_data( string $task_type, array $params ): array {
	return array(
		'task_type'     => $task_type,
		'task_params'   => $params,
		'task_context'  => array(),
		'job_source'    => 'system',
		'job_label'     => ucfirst( str_replace( '_', ' ', $task_type ) ),
		'parent_job_id' => 0,
		'user_id'       => 0,
		'agent_id'      => 0,
		'job'           => array( 'user_id' => 0 ),
	);
}

function smoke_default_system_task_workflow( string $task_type, array $params ): array {
	return array(
		'steps' => array(
			array(
				'step_type'          => 'system_task',
				'flow_step_settings' => array(
					'task_type' => $task_type,
					'params'    => $params,
				),
			),
		),
	);
}

function smoke_system_run_schedule_failure_response( ?array $scheduler_error ): array {
	if ( is_array( $scheduler_error ) && ! empty( $scheduler_error['message'] ) ) {
		return array(
			'success' => false,
			'error'   => $scheduler_error['error'],
			'message' => $scheduler_error['message'],
		);
	}

	return array(
		'success' => false,
		'error'   => 'Failed to schedule task.',
		'message' => 'TaskScheduler returned false — check logs for details.',
	);
}

echo "\n[1] CLI structured params parse\n";
$params = smoke_parse_run_task_params(
	array(
		'param'   => array( 'root_path=woocommerce', 'limit=25', 'enabled=true' ),
		'dry-run' => true,
	)
);
$assert( 'root_path parsed', 'woocommerce' === $params['root_path'] );
$assert( 'numeric value coerced', 25 === $params['limit'] );
$assert( 'boolean value coerced', true === $params['enabled'] );
$assert( '--dry-run sets dry_run', true === $params['dry_run'] );

$params = smoke_parse_run_task_params(
	array(
		'param' => 'pending_decision_limit=20',
	),
	array(
		'wp',
		'datamachine',
		'system',
		'run',
		'wiki_maintain',
		'--param=root_path=matt',
		'--param=dry_run=1',
		'--param=stage_pending_actions=1',
		'--param=pending_decision_limit=20',
	)
);
$assert( 'raw argv preserves repeated root_path param', 'matt' === $params['root_path'] );
$assert( 'raw argv preserves repeated dry_run param', 1 === $params['dry_run'] );
$assert( 'raw argv preserves repeated stage_pending_actions param', 1 === $params['stage_pending_actions'] );
$assert( 'raw argv preserves repeated pending_decision_limit param', 20 === $params['pending_decision_limit'] );

$params = smoke_parse_run_task_params(
	array(
		'agent' => 'intelligence-chubes4',
	)
);
$assert( '--agent stores agent alias before ability extraction', 'intelligence-chubes4' === $params['agent'] );
$assert( 'empty --agent rejected', isset( smoke_parse_run_task_params( array( 'agent' => '' ) )['error'] ) );

$params = smoke_parse_run_task_params(
	array(
		'params' => '{"root_path":"woocommerce","limit":10}',
		'apply'  => true,
	)
);
$assert( 'JSON params parsed', 'woocommerce' === $params['root_path'] );
$assert( '--apply sets apply', true === $params['apply'] );
$assert( '--apply disables dry_run', false === $params['dry_run'] );
$assert( 'conflicting mode rejected', isset( smoke_parse_run_task_params( array( 'dry-run' => true, 'apply' => true ) )['error'] ) );

echo "\n[2] Ability guardrails\n";
$mutating_meta = array(
	'mutates'          => true,
	'supports_dry_run' => true,
	'requires_scope'   => true,
	'params_schema'    => array(
		'accepted' => array( 'root_path', 'limit' ),
		'scope'    => array( 'root_path' ),
	),
);
$result        = smoke_validate_run_task_params( 'wiki_maintain', $mutating_meta, array() );
$assert( 'requires_scope rejects empty params', false === $result['success'] );
$assert( 'scope error names accepted scope param', str_contains( $result['error'], 'root_path' ) );

$result = smoke_validate_run_task_params( 'wiki_maintain', $mutating_meta, array( 'root_path' => 'woocommerce' ) );
$assert( 'scope param allows scheduling', true === $result['success'] );
$assert( 'mutating dry-run task defaults to dry_run', true === $result['task_params']['dry_run'] );

$result = smoke_validate_run_task_params( 'wiki_maintain', $mutating_meta, array( 'root_path' => 'woocommerce', 'unexpected' => 'x' ) );
$assert( 'accepted params reject unknown keys', false === $result['success'] );

$readonly_result = smoke_validate_run_task_params( 'daily_memory_generation', array( 'params_schema' => array() ), array() );
$assert( 'read-only/simple task remains schedulable', true === $readonly_result['success'] );

$params  = array( 'agent' => 'intelligence-chubes4', 'date' => '2026-06-15' );
$context = smoke_extract_run_task_context( $params );
$assert( 'agent alias becomes scheduler agent_slug context', 'intelligence-chubes4' === $context['agent_slug'] );
$assert( 'agent alias removed before task param validation', ! array_key_exists( 'agent', $params ) );
$assert( 'other task params preserved after agent extraction', '2026-06-15' === $params['date'] );

$params  = array( 'agent' => 7 );
$context = smoke_extract_run_task_context( $params );
$assert( 'numeric agent alias becomes scheduler agent_id context', 7 === $context['agent_id'] );

echo "\n[3] Scheduler + SystemTask propagation\n";
$scheduled_params = array(
	'root_path' => 'woocommerce',
	'dry_run'   => true,
);
$initial          = smoke_build_task_scheduler_initial_data( 'wiki_maintain', $scheduled_params );
$assert( 'TaskScheduler initial_data stores task_params', $scheduled_params === $initial['task_params'] );
$assert( 'TaskScheduler marks run-now jobs as system jobs', 'system' === $initial['job_source'] );
$assert( 'TaskScheduler labels system task jobs from task type', 'Wiki maintain' === $initial['job_label'] );
$workflow = smoke_default_system_task_workflow( 'wiki_maintain', $initial['task_params'] );
$assert( 'SystemTask workflow stores params in flow_step_settings', $scheduled_params === $workflow['steps'][0]['flow_step_settings']['params'] );
$assert( 'SystemTask workflow stores canonical task_type', 'wiki_maintain' === $workflow['steps'][0]['flow_step_settings']['task_type'] );
$assert( 'SystemTask workflow does not store legacy task alias', ! array_key_exists( 'task', $workflow['steps'][0]['flow_step_settings'] ) );

echo "\n[3b] Scheduler rejection details surface in run response\n";
$response = smoke_system_run_schedule_failure_response(
	array(
		'error'      => 'task_scheduler_agent_context_required',
		'error_code' => 'task_scheduler_agent_context_required',
		'message'    => 'TaskScheduler: queued task requires agent context',
	)
);
$assert( 'scheduler error code becomes run error', 'task_scheduler_agent_context_required' === $response['error'] );
$assert( 'scheduler log message becomes run message', 'TaskScheduler: queued task requires agent context' === $response['message'] );
$response = smoke_system_run_schedule_failure_response( null );
$assert( 'missing scheduler details preserve generic fallback', 'Failed to schedule task.' === $response['error'] );

echo "\n[4] Source tripwires\n";
$system_command = file_get_contents( __DIR__ . '/../inc/Cli/Commands/SystemCommand.php' );
$abilities      = file_get_contents( __DIR__ . '/../inc/Abilities/SystemAbilities.php' );
$registry       = file_get_contents( __DIR__ . '/../inc/Engine/Tasks/TaskRegistry.php' );
$scheduler        = file_get_contents( __DIR__ . '/../inc/Engine/Tasks/TaskScheduler.php' );
$workflow_ability = file_get_contents( __DIR__ . '/../inc/Abilities/Job/ExecuteWorkflowAbility.php' );
$assert( 'CLI avoids invalid --param key=value synopsis placeholder', ! str_contains( $system_command, '[--param=<key=value>]' ) );
$assert( 'CLI avoids WP-CLI-invalid repeatable --param synopsis placeholder', ! str_contains( $system_command, '[--param=<param>]...' ) );
$assert( 'CLI documents valid --param synopsis placeholder', str_contains( $system_command, '[--param=<param>]' ) );
$assert( 'CLI documents --param key=value semantics', str_contains( $system_command, 'Structured task param as key=value. Repeatable.' ) );
$assert( 'CLI documents agent option for agent-scoped tasks', str_contains( $system_command, '[--agent=<agent>]' ) && str_contains( $system_command, 'Agent ID or slug for agent-scoped tasks.' ) );
$assert( 'CLI forwards task_params to runTask', str_contains( $system_command, "'task_params' => $" . 'params' ) );
$assert( 'run-task ability schema accepts task_params', str_contains( $abilities, "'task_params' => array" ) );
$assert( 'run-task ability extracts agent alias before validation', str_contains( $abilities, "array_key_exists( 'agent', $" . 'params' ) && str_contains( $abilities, "'agent_slug'" ) );
$assert( 'run-task ability schedules merged task params', str_contains( $abilities, 'array_merge( $task_params' ) );
$assert( 'run-task ability surfaces scheduler errors', str_contains( $abilities, 'TaskScheduler::getLastScheduleError()' ) );
$assert( 'TaskRegistry exposes mutates metadata', str_contains( $registry, "'mutates'" ) );
$assert( 'TaskRegistry exposes requires_scope metadata', str_contains( $registry, "'requires_scope'" ) );
$assert( 'TaskScheduler passes system job source to execute-workflow', str_contains( $scheduler, "'job_source'    => 'system'" ) );
$assert( 'TaskScheduler records rejection details for callers', str_contains( $scheduler, 'recordScheduleError' ) && str_contains( $scheduler, 'getLastScheduleError' ) );
$assert( 'execute-workflow honors caller job source', (bool) preg_match( "/'source'\\s*=>\\s*\\\$job_source/", $workflow_ability ) );

echo "\nAssertions: {$total}, Failures: {$failures}\n";
if ( $failures > 0 ) {
	exit( 1 );
}
