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

function smoke_parse_run_task_params( array $assoc_args ): array {
	$params = array();

	if ( isset( $assoc_args['params'] ) ) {
		$decoded = json_decode( (string) $assoc_args['params'], true );
		if ( ! is_array( $decoded ) || array_is_list( $decoded ) ) {
			return array( 'error' => '--params must be a JSON object.' );
		}
		$params = $decoded;
	}

	$param_args = $assoc_args['param'] ?? array();
	if ( is_string( $param_args ) ) {
		$param_args = array( $param_args );
	}
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
				'type'           => 'system_task',
				'handler_config' => array(
					'task'   => $task_type,
					'params' => $params,
				),
			),
		),
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

echo "\n[3] Scheduler + SystemTask propagation\n";
$scheduled_params = array(
	'root_path' => 'woocommerce',
	'dry_run'   => true,
);
$initial          = smoke_build_task_scheduler_initial_data( 'wiki_maintain', $scheduled_params );
$assert( 'TaskScheduler initial_data stores task_params', $scheduled_params === $initial['task_params'] );
$workflow = smoke_default_system_task_workflow( 'wiki_maintain', $initial['task_params'] );
$assert( 'SystemTask workflow stores params in handler_config', $scheduled_params === $workflow['steps'][0]['handler_config']['params'] );

echo "\n[4] Source tripwires\n";
$system_command = file_get_contents( __DIR__ . '/../inc/Cli/Commands/SystemCommand.php' );
$abilities      = file_get_contents( __DIR__ . '/../inc/Abilities/SystemAbilities.php' );
$registry       = file_get_contents( __DIR__ . '/../inc/Engine/Tasks/TaskRegistry.php' );
$assert( 'CLI avoids invalid --param key=value synopsis placeholder', ! str_contains( $system_command, '[--param=<key=value>]' ) );
$assert( 'CLI documents valid --param synopsis placeholder', str_contains( $system_command, '[--param=<param>]' ) );
$assert( 'CLI documents --param key=value semantics', str_contains( $system_command, 'Structured task param as key=value. Repeatable.' ) );
$assert( 'CLI forwards task_params to runTask', str_contains( $system_command, "'task_params' => $" . 'params' ) );
$assert( 'run-task ability schema accepts task_params', str_contains( $abilities, "'task_params' => array" ) );
$assert( 'run-task ability schedules merged task params', str_contains( $abilities, 'array_merge( $task_params' ) );
$assert( 'TaskRegistry exposes mutates metadata', str_contains( $registry, "'mutates'" ) );
$assert( 'TaskRegistry exposes requires_scope metadata', str_contains( $registry, "'requires_scope'" ) );

echo "\nAssertions: {$total}, Failures: {$failures}\n";
if ( $failures > 0 ) {
	exit( 1 );
}
