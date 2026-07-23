<?php
/**
 * Pure-PHP smoke coverage for pipeline AI concurrency backpressure (#1820).
 *
 * Run with: php tests/ai-step-backpressure-smoke.php
 *
 * @package DataMachine\Tests
 */

define( 'ABSPATH', __DIR__ );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

$failed = 0;
$total  = 0;

function assert_ai_backpressure_smoke( string $name, bool $cond, string $detail = '' ): void {
	global $failed, $total;
	++$total;
	if ( $cond ) {
		echo "  [PASS] $name\n";
		return;
	}

	echo "  [FAIL] $name" . ( $detail ? " - $detail" : '' ) . "\n";
	++$failed;
}

$GLOBALS['datamachine_ai_backpressure_options'] = array();
$GLOBALS['datamachine_ai_backpressure_actions'] = array();
$GLOBALS['datamachine_ai_backpressure_schedule_error'] = false;
$GLOBALS['datamachine_ai_backpressure_schedule_calls'] = 0;
$GLOBALS['datamachine_ai_backpressure_next_action_id'] = 1;
$GLOBALS['datamachine_ai_backpressure_registered_hooks'] = array();
$GLOBALS['datamachine_ai_backpressure_ability_inputs'] = array();

class DataMachineAIBackpressureSmokeAction {
	public function __construct( private array $action ) {}

	public function get_args(): array {
		return $this->action['args'] ?? array();
	}

	public function get_field( string $field ): mixed {
		return $this->action[ $field ] ?? null;
	}
}

class DataMachineAIBackpressureSmokeAbility {
	public function execute( array $input ): void {
		$GLOBALS['datamachine_ai_backpressure_ability_inputs'][] = $input;
	}
}

function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['datamachine_ai_backpressure_registered_hooks'][ $hook ] = array( $callback, $priority, $accepted_args );
}

function wp_get_ability( string $name ): ?DataMachineAIBackpressureSmokeAbility {
	return 'datamachine/execute-step' === $name ? new DataMachineAIBackpressureSmokeAbility() : null;
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( string $name, mixed $default_value = false ): mixed {
    	return array_key_exists( $name, $GLOBALS['datamachine_ai_backpressure_options'] )
    		? $GLOBALS['datamachine_ai_backpressure_options'][ $name ]
    		: $default_value;
    }
}

if ( ! function_exists( 'add_option' ) ) {
    function add_option( string $name, mixed $value = '', mixed $deprecated = '', mixed $autoload = null ): bool {
    	unset( $deprecated, $autoload );
    	if ( array_key_exists( $name, $GLOBALS['datamachine_ai_backpressure_options'] ) ) {
    		return false;
    	}

    	$GLOBALS['datamachine_ai_backpressure_options'][ $name ] = $value;
    	return true;
    }
}

if ( ! function_exists( 'delete_option' ) ) {
    function delete_option( string $name ): bool {
    	$existed = array_key_exists( $name, $GLOBALS['datamachine_ai_backpressure_options'] );
    	unset( $GLOBALS['datamachine_ai_backpressure_options'][ $name ] );
    	return $existed;
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
    	unset( $args );
    	if ( 'datamachine_pipeline_ai_concurrency_limit' === $hook ) {
    		return 1;
    	}
    	if ( 'datamachine_pipeline_ai_throttle_delay' === $hook ) {
    		return 7;
    	}
    	return $value;
    }
}

function as_get_scheduled_actions( array $query = array(), string $return_format = 'OBJECT' ): array {
	$actions = array_values(
		array_filter(
			$GLOBALS['datamachine_ai_backpressure_actions'],
			static function ( DataMachineAIBackpressureSmokeAction $action ) use ( $query ): bool {
				foreach ( array( 'hook', 'group', 'status' ) as $field ) {
					if ( isset( $query[ $field ] ) && $action->get_field( $field ) !== $query[ $field ] ) {
						return false;
					}
				}
				if ( isset( $query['args'] ) && $action->get_args() !== $query['args'] ) {
					return false;
				}

				return true;
			}
		)
	);

	if ( 'ids' === strtolower( $return_format ) ) {
		return array_map( static fn( DataMachineAIBackpressureSmokeAction $action ): int => (int) $action->get_field( 'action_id' ), $actions );
	}

	return $actions;
}

function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '', bool $unique = false ): int {
	++$GLOBALS['datamachine_ai_backpressure_schedule_calls'];
	if ( true === $GLOBALS['datamachine_ai_backpressure_schedule_error'] ) {
		return 0;
	}
	if ( is_int( $GLOBALS['datamachine_ai_backpressure_schedule_error'] ) && $GLOBALS['datamachine_ai_backpressure_schedule_error'] > 0 ) {
		--$GLOBALS['datamachine_ai_backpressure_schedule_error'];
		return 0;
	}
	if ( $unique ) {
		foreach ( array( 'pending', 'in-progress' ) as $blocking_status ) {
			if ( ! empty( as_get_scheduled_actions( array( 'hook' => $hook, 'group' => $group, 'status' => $blocking_status ) ) ) ) {
				return 0;
			}
		}
	}

	$action_id = $GLOBALS['datamachine_ai_backpressure_next_action_id']++;
	$GLOBALS['datamachine_ai_backpressure_actions'][] = new DataMachineAIBackpressureSmokeAction(
		array(
			'action_id' => $action_id,
			'hook'      => $hook,
			'args'      => $args,
			'group'     => $group,
			'status'    => 'pending',
			'timestamp' => $timestamp,
		)
	);
	return $action_id;
}

function did_action( string $hook ): int {
	return 'action_scheduler_init' === $hook ? 1 : 0;
}

if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( string $key ): string {
    	return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) ?? '' );
    }
}

require_once __DIR__ . '/../inc/Core/NetworkSettings.php';
require_once __DIR__ . '/../inc/Core/PluginSettings.php';
require_once __DIR__ . '/../inc/Core/OptionLeaseStore.php';
require_once __DIR__ . '/../inc/Core/ActionScheduler/GroupRegistrar.php';
require_once __DIR__ . '/../inc/Engine/Tasks/ScheduleActionIdentity.php';
require_once __DIR__ . '/../inc/Engine/AI/PipelineAIConcurrencyLease.php';
require_once __DIR__ . '/../inc/Engine/AI/PipelineAIConcurrencyLimiter.php';
require_once __DIR__ . '/../inc/Engine/AI/AIConcurrencyBackpressure.php';
require_once __DIR__ . '/../inc/Engine/Actions/Engine.php';

use DataMachine\Core\ActionScheduler\GroupRegistrar;
use DataMachine\Engine\AI\PipelineAIConcurrencyLease;
use DataMachine\Engine\AI\PipelineAIConcurrencyLimiter;
use DataMachine\Engine\AI\AIConcurrencyBackpressure;

echo "Case 1: first AI step acquires the single site slot\n";
$first = PipelineAIConcurrencyLimiter::acquire( 'openai', array( 'job_id' => 101, 'flow_step_id' => 'ai-1' ) );
assert_ai_backpressure_smoke( 'first acquire succeeds', true === $first['acquired'] );
assert_ai_backpressure_smoke( 'first acquire returns lease', ( $first['lease'] ?? null ) instanceof PipelineAIConcurrencyLease );

echo "Case 2: second concurrent AI step is throttled\n";
$second = PipelineAIConcurrencyLimiter::acquire( 'openai', array( 'job_id' => 102, 'flow_step_id' => 'ai-1' ) );
assert_ai_backpressure_smoke( 'second acquire is denied', false === $second['acquired'] );
assert_ai_backpressure_smoke( 'throttle reason is distinct', 'ai_concurrency_limit' === ( $second['reason'] ?? '' ) );
assert_ai_backpressure_smoke( 'throttle delay is filterable metadata', 7 === ( $second['delay'] ?? 0 ) );
assert_ai_backpressure_smoke( 'active count is reported', 1 === ( $second['active'] ?? 0 ) );

echo "Case 3: release happens on exception/finally paths\n";
try {
	throw new RuntimeException( 'synthetic failure after slot acquisition' );
} catch ( RuntimeException ) {
	// Mirrors AIStep's outer finally: release is independent of model outcome.
} finally {
	$first['lease']->release();
}

$after_release = PipelineAIConcurrencyLimiter::acquire( 'openai', array( 'job_id' => 103, 'flow_step_id' => 'ai-1' ) );
assert_ai_backpressure_smoke( 'slot can be acquired after release', true === $after_release['acquired'] );
$after_release['lease']->release();

echo "Case 4: advanced owner step clears stale live lease\n";
$stale_owner = PipelineAIConcurrencyLimiter::acquire( 'openai', array( 'job_id' => 201, 'flow_step_id' => 'ai-old' ) );
assert_ai_backpressure_smoke( 'stale owner initially acquires slot', true === $stale_owner['acquired'] );
$GLOBALS['datamachine_ai_backpressure_actions'][] = new DataMachineAIBackpressureSmokeAction(
	array(
		'hook'   => 'datamachine_execute_step',
		'group'  => GroupRegistrar::GROUP,
		'status' => 'pending',
		'job_id' => 201,
		'args'   => array(
			'job_id'       => 201,
			'flow_step_id' => 'publish-next',
		),
	)
);
$after_advanced_owner = PipelineAIConcurrencyLimiter::acquire( 'openai', array( 'job_id' => 202, 'flow_step_id' => 'ai-new' ) );
assert_ai_backpressure_smoke( 'advanced owner lease does not block new AI work', true === $after_advanced_owner['acquired'] );
$after_advanced_owner['lease']->release();
$GLOBALS['datamachine_ai_backpressure_actions'] = array();

echo "Case 5: production wiring preserves pipeline semantics\n";
datamachine_register_execution_engine();
$ai_src       = file_get_contents( __DIR__ . '/../inc/Core/Steps/AI/AIStep.php' ) ?: '';
$engine_src   = file_get_contents( __DIR__ . '/../inc/Abilities/Engine/ExecuteStepAbility.php' ) ?: '';
$retry_src    = file_get_contents( __DIR__ . '/../inc/Core/JobRetryPolicy.php' ) ?: '';
$backpressure_src = file_get_contents( __DIR__ . '/../inc/Engine/AI/AIConcurrencyBackpressure.php' ) ?: '';
$schedule_failure_src = file_get_contents( __DIR__ . '/../inc/Engine/AI/AIConcurrencyScheduleFailure.php' ) ?: '';
$reconciler_src = file_get_contents( __DIR__ . '/../inc/Core/Database/Jobs/LegacyAIConcurrencyReconciler.php' ) ?: '';
$fetch_src    = file_get_contents( __DIR__ . '/../inc/Core/Steps/Fetch/FetchStep.php' ) ?: '';
$upsert_files = glob( __DIR__ . '/../inc/Core/Steps/Upsert/*.php' ) ?: array();
$upsert_src   = implode( "\n", array_map( static fn( string $path ): string => file_get_contents( $path ) ?: '', $upsert_files ) );

assert_ai_backpressure_smoke( 'AIStep acquires before prompt queue consumption', strpos( $ai_src, 'PipelineAIConcurrencyLimiter::acquire' ) < strpos( $ai_src, 'consumeFromPromptQueue' ) );
assert_ai_backpressure_smoke( 'AIStep throttles by rescheduling same step', str_contains( $ai_src, 'deferForAIConcurrency' ) && str_contains( $backpressure_src, 'datamachine_resume_ai_step' ) );
assert_ai_backpressure_smoke( 'executor treats AI throttle as routed pending work', str_contains( $engine_src, 'hasPendingAIConcurrencyThrottle' ) && str_contains( $engine_src, 'ai_concurrency_throttle' ) );
assert_ai_backpressure_smoke( 'blocked AI handoff bypasses empty-packet failure routing', str_contains( $ai_src, "'status'  => 'blocked'" ) && str_contains( $engine_src, "'blocked' === ( \$execution_result['status'] ?? '' )" ) );
assert_ai_backpressure_smoke( 'nonterminal blocked handoff is routed before pending retry fallback', strpos( $engine_src, "'blocked' === ( \$execution_result['status'] ?? '' )" ) < strpos( $engine_src, '$prior_status = $this->getPriorTerminalStatus' ) && str_contains( $engine_src, 'JobStatus::isStatusFinal( $current_status )' ) );
assert_ai_backpressure_smoke( 'fetch steps do not use AI limiter', ! str_contains( $fetch_src, 'PipelineAIConcurrencyLimiter' ) );
assert_ai_backpressure_smoke( 'upsert steps do not use AI limiter', ! str_contains( $upsert_src, 'PipelineAIConcurrencyLimiter' ) );
assert_ai_backpressure_smoke( 'existing transport retry classifier remains intact', str_contains( $retry_src, 'transport_connect_timeout' ) && str_contains( $retry_src, 'AI_TRANSPORT_BASE_DELAY' ) );
assert_ai_backpressure_smoke( 'throttle log includes requested metadata', str_contains( $ai_src, 'rescheduled_for_seconds' ) && str_contains( $ai_src, "'active'" ) && str_contains( $ai_src, "'limit'" ) );
assert_ai_backpressure_smoke( 'limiter checks for advanced owner leases generically', str_contains( file_get_contents( __DIR__ . '/../inc/Engine/AI/PipelineAIConcurrencyLimiter.php' ) ?: '', 'isAdvancedOwnerLease' ) );
$execute_bridge = $GLOBALS['datamachine_ai_backpressure_registered_hooks']['datamachine_execute_step'][0] ?? null;
$resume_bridge  = $GLOBALS['datamachine_ai_backpressure_registered_hooks']['datamachine_resume_ai_step'][0] ?? null;
assert_ai_backpressure_smoke( 'execute and resume hooks register canonical and guarded bridges', is_callable( $execute_bridge ) && is_callable( $resume_bridge ) && $execute_bridge !== $resume_bridge );
call_user_func( $execute_bridge, 501, 'ai-resume', 3, 'claim-token', 7 );
$resume_input = $GLOBALS['datamachine_ai_backpressure_ability_inputs'][0] ?? array();
assert_ai_backpressure_smoke( 'canonical bridge preserves resume generation with job and step', 501 === $resume_input['job_id'] && 'ai-resume' === $resume_input['flow_step_id'] && 3 === $resume_input['operation_generation'] && 'claim-token' === $resume_input['operation_claim_token'] && 7 === $resume_input['ai_resume_generation'] );

echo "Case 6: sustained contention stays deferred without failure inflation\n";
$now   = strtotime( '2026-07-22T00:00:00Z' );
$state = array();
for ( $attempt = 1; $attempt <= 100; ++$attempt ) {
	$state = AIConcurrencyBackpressure::nextState( $state, 'ai-1', $now + $attempt, DAY_IN_SECONDS );
}
assert_ai_backpressure_smoke( 'ordinary sustained contention remains deferred past the old count budget', 'deferred' === $state['state'] );
assert_ai_backpressure_smoke( 'defer count remains machine readable', 100 === $state['attempts'] );
assert_ai_backpressure_smoke( 'defer age remains machine readable', 99 === $state['defer_age_seconds'] );
assert_ai_backpressure_smoke( 'ordinary contention does not call the failure path', ! str_contains( $ai_src, 'ai_concurrency_defer_exhausted' ) );

echo "Case 7: age-bounded stranded work terminates distinctly\n";
$stranded = AIConcurrencyBackpressure::nextState( $state, 'ai-1', $now + DAY_IN_SECONDS + 1, DAY_IN_SECONDS );
assert_ai_backpressure_smoke( 'maximum contention age marks work stranded', 'stranded' === $stranded['state'] );
assert_ai_backpressure_smoke( 'stranded work is cancelled instead of failed', str_contains( $reconciler_src, 'cancelled - ai_concurrency_stranded' ) && str_contains( $ai_src, 'cancelled - ai_concurrency_stranded' ) );
assert_ai_backpressure_smoke( 'maximum contention age is filterable', str_contains( $ai_src, 'datamachine_ai_concurrency_max_defer_age' ) );
assert_ai_backpressure_smoke( 'backoff remains capped', 600 === AIConcurrencyBackpressure::delaySeconds( 10, 30, 600 ) );
assert_ai_backpressure_smoke( 'only one matching future action is retained', str_contains( $backpressure_src, 'scheduleContinuation' ) );

echo "Case 8: unique scheduling closes concurrent duplicate races\n";
$GLOBALS['datamachine_ai_backpressure_actions'] = array(
	new DataMachineAIBackpressureSmokeAction(
		array(
			'action_id' => 900,
			'hook'      => 'datamachine_execute_step',
			'args'      => array( 'job_id' => 301, 'flow_step_id' => 'ai-1' ),
			'group'     => 'data-machine',
			'status'    => 'in-progress',
		)
	),
);
$args   = array( 'job_id' => 301, 'flow_step_id' => 'ai-1', 'operation_generation' => 0, 'operation_claim_token' => '', 'ai_resume_generation' => 1 );
$same_hook = as_schedule_single_action( $now + 60, 'datamachine_execute_step', $args, 'data-machine', true );
$first  = AIConcurrencyBackpressure::scheduleContinuation( $now + 60, $args );
$second = AIConcurrencyBackpressure::scheduleContinuation( $now + 60, $args );
assert_ai_backpressure_smoke( 'running action blocks same-hook same-group uniqueness regardless of args', 0 === $same_hook );
assert_ai_backpressure_smoke( 'first continuation is scheduled with a positive ID', $first['success'] && $first['action_id'] > 0 );
assert_ai_backpressure_smoke( 'dedicated resume hook is not blocked by running execute hook', 'datamachine_resume_ai_step' === $GLOBALS['datamachine_ai_backpressure_actions'][1]->get_field( 'hook' ) );
assert_ai_backpressure_smoke( 'concurrent duplicate reuses the winning action ID', $second['success'] && $second['reused'] && $first['action_id'] === $second['action_id'] );
assert_ai_backpressure_smoke( 'concurrent duplicate executions produce one resume action', 2 === count( $GLOBALS['datamachine_ai_backpressure_actions'] ) );

$other_job = AIConcurrencyBackpressure::scheduleContinuation( $now + 60, array( 'job_id' => 303, 'flow_step_id' => 'ai-1', 'operation_generation' => 0, 'operation_claim_token' => '', 'ai_resume_generation' => 1 ) );
assert_ai_backpressure_smoke( 'another job uses a separate uniqueness group', $other_job['success'] && 3 === count( $GLOBALS['datamachine_ai_backpressure_actions'] ) );

$GLOBALS['datamachine_ai_backpressure_schedule_error'] = true;
$schedule_error = AIConcurrencyBackpressure::scheduleContinuation( $now + 60, array( 'job_id' => 302, 'flow_step_id' => 'ai-1' ) );
$GLOBALS['datamachine_ai_backpressure_schedule_error'] = false;
assert_ai_backpressure_smoke( 'zero without a matching pending action is a retryable scheduling failure', ! $schedule_error['success'] && 0 === $schedule_error['action_id'] && 3 === $schedule_error['attempts'] && $schedule_error['retryable'] );
assert_ai_backpressure_smoke( 'scheduler call requests native uniqueness', 1 === preg_match( '/\$group,\s+true/', $backpressure_src ) );

$GLOBALS['datamachine_ai_backpressure_schedule_error'] = 1;
$transient_schedule = AIConcurrencyBackpressure::scheduleContinuation( $now + 60, array( 'job_id' => 304, 'flow_step_id' => 'ai-1' ) );
assert_ai_backpressure_smoke( 'transient scheduler failure succeeds on a bounded retry', $transient_schedule['success'] && 2 === $transient_schedule['attempts'] && ! $transient_schedule['reused'] );

$running_args = array( 'job_id' => 305, 'flow_step_id' => 'ai-1', 'ai_resume_generation' => 1 );
$GLOBALS['datamachine_ai_backpressure_actions'][] = new DataMachineAIBackpressureSmokeAction(
	array(
		'action_id' => 905,
		'hook'      => AIConcurrencyBackpressure::RESUME_HOOK,
		'args'      => $running_args,
		'group'     => AIConcurrencyBackpressure::continuationGroup( $running_args ),
		'status'    => 'in-progress',
	)
);
$running_schedule = AIConcurrencyBackpressure::scheduleContinuation( $now + 60, $running_args );
assert_ai_backpressure_smoke( 'equivalent in-progress continuation is reused without pending handoff', $running_schedule['success'] && $running_schedule['reused'] && 905 === $running_schedule['action_id'] && 'in-progress' === $running_schedule['action_status'] );

echo "Case 9: slot recovery archives and clears active contention\n";
$resolved = AIConcurrencyBackpressure::resolvedState( $state, $now + 120 );
assert_ai_backpressure_smoke( 'resolved history preserves defer count', 100 === $resolved['defer_count'] );
assert_ai_backpressure_smoke( 'resolved history preserves contention duration', 119 === $resolved['defer_age_seconds'] );
assert_ai_backpressure_smoke( 'AIStep clears active contention after acquisition', str_contains( $ai_src, 'resolveAIConcurrencyContention' ) && str_contains( $ai_src, "unset( \$engine['ai_concurrency_throttle'] )" ) );

echo "Case 10: resume generations advance ownership without running collisions\n";
$generation_one = AIConcurrencyBackpressure::nextGenerationState( array(), 'ai-1', 0, 'token-1', $now );
$running_one    = AIConcurrencyBackpressure::beginGenerationState( $generation_one, 'ai-1', 1, $now + 1 );
$generation_two = AIConcurrencyBackpressure::nextGenerationState( $running_one, 'ai-1', 1, 'token-2', $now + 2 );
$duplicate_two  = AIConcurrencyBackpressure::nextGenerationState( $generation_two, 'ai-1', 1, 'token-other', $now + 2 );
assert_ai_backpressure_smoke( 'initial execution mints resume generation one', 1 === $generation_one['generation'] && 0 === $generation_one['source_generation'] );
assert_ai_backpressure_smoke( 'running generation one atomically mints generation two', 2 === $generation_two['generation'] && 1 === $generation_two['source_generation'] );
assert_ai_backpressure_smoke( 'duplicate generation-two claim reuses original owner token', 'token-2' === $duplicate_two['token'] );

$generation_one_args = array( 'job_id' => 401, 'flow_step_id' => 'ai-1', 'operation_generation' => 0, 'operation_claim_token' => '', 'ai_resume_generation' => 1 );
$generation_two_args = array( 'job_id' => 401, 'flow_step_id' => 'ai-1', 'operation_generation' => 0, 'operation_claim_token' => '', 'ai_resume_generation' => 2 );
$GLOBALS['datamachine_ai_backpressure_actions'] = array(
	new DataMachineAIBackpressureSmokeAction(
		array(
			'action_id' => 901,
			'hook'      => AIConcurrencyBackpressure::RESUME_HOOK,
			'args'      => $generation_one_args,
			'group'     => AIConcurrencyBackpressure::continuationGroup( $generation_one_args ),
			'status'    => 'in-progress',
		)
	),
);
$scheduled_two = AIConcurrencyBackpressure::scheduleContinuation( $now + 60, $generation_two_args );
$duplicate_schedule_two = AIConcurrencyBackpressure::scheduleContinuation( $now + 60, $generation_two_args );
assert_ai_backpressure_smoke( 'running generation one does not block generation two schedule', $scheduled_two['success'] && ! $scheduled_two['reused'] );
assert_ai_backpressure_smoke( 'duplicate generation two schedule collapses to one action', $duplicate_schedule_two['success'] && $duplicate_schedule_two['reused'] && 2 === count( $GLOBALS['datamachine_ai_backpressure_actions'] ) );
assert_ai_backpressure_smoke( 'stale generation one replay cannot claim newer ownership', null === AIConcurrencyBackpressure::beginGenerationState( $generation_two, 'ai-1', 1, $now + 3 ) );
$running_two = AIConcurrencyBackpressure::beginGenerationState( $generation_two, 'ai-1', 2, $now + 3 );
assert_ai_backpressure_smoke( 'exact generation two can begin once', 'running' === $running_two['status'] );
assert_ai_backpressure_smoke( 'duplicate generation two execution is rejected', null === AIConcurrencyBackpressure::beginGenerationState( $running_two, 'ai-1', 2, $now + 4 ) );

assert_ai_backpressure_smoke( 'only exact actionless scheduled generation can be released', AIConcurrencyBackpressure::isReleasableUnscheduledGeneration( $generation_two, 'ai-1', 2, 'token-2' ) );
assert_ai_backpressure_smoke( 'wrong token cannot release generation ownership', ! AIConcurrencyBackpressure::isReleasableUnscheduledGeneration( $generation_two, 'ai-1', 2, 'token-other' ) );
assert_ai_backpressure_smoke( 'running generation cannot be released as unscheduled', ! AIConcurrencyBackpressure::isReleasableUnscheduledGeneration( $running_two, 'ai-1', 2, 'token-2' ) );
assert_ai_backpressure_smoke( 'schedule exhaustion routes through retryable fail-job instead of cancellation', str_contains( $schedule_failure_src, "'datamachine_fail_job'" ) && str_contains( $schedule_failure_src, "'ai_concurrency_defer_schedule_failed'" ) && ! str_contains( $ai_src, "cancelled - ai_concurrency_defer_schedule_failed" ) );
assert_ai_backpressure_smoke( 'in-progress continuation does not regress job status to pending', str_contains( $ai_src, "'in-progress' !== (string) ( \$schedule_result['action_status'] ?? '' )" ) );
assert_ai_backpressure_smoke( 'in-progress continuation returns before stale throttle merge', str_contains( $ai_src, "} else {\n\t\t\treturn;\n\t\t}" ) );

$bounded_state = array();
for ( $generation = 1; $generation <= 20; ++$generation ) {
	$bounded_state = AIConcurrencyBackpressure::nextState( $bounded_state, 'ai-1', $now + $generation, 60 );
}
$bounded_terminal = AIConcurrencyBackpressure::nextState( $bounded_state, 'ai-1', $now + 61, 60 );
assert_ai_backpressure_smoke( 'repeated healthy generations reach age bound instead of schedule failure', 'stranded' === $bounded_terminal['state'] );

echo "\nAI step backpressure smoke complete: {$total} assertions, {$failed} failures.\n";
if ( $failed > 0 ) {
	exit( 1 );
}
