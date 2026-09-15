<?php
/**
 * Pure-PHP smoke coverage for release-side AI concurrency wake-up (#3499).
 *
 * Run with: php tests/ai-concurrency-release-wakeup-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Core {
	/**
	 * In-memory EngineData stand-in so ownership mutations run without a database.
	 */
	class EngineData {
		public static array $engines = array();

		public static function mutate( int $job_id, callable $callback, string $event_type = 'mutation', int $max_attempts = 3 ): array {
			unset( $event_type, $max_attempts );
			$current = self::$engines[ $job_id ] ?? array();
			$next    = $callback( $current );
			if ( ! is_array( $next ) ) {
				return array( 'success' => false, 'snapshot' => $current );
			}
			self::$engines[ $job_id ] = $next;
			return array( 'success' => true, 'snapshot' => $next );
		}

		public static function retrieve( int $job_id ): array {
			return self::$engines[ $job_id ] ?? array();
		}
	}
}

namespace {

	define( 'ABSPATH', __DIR__ . '/' );
	define( 'DAY_IN_SECONDS', 86400 );

	$GLOBALS['wake_actions']        = array();
	$GLOBALS['wake_schedule_calls'] = 0;
	$GLOBALS['wake_schedule_error'] = false;
	$GLOBALS['wake_next_action_id'] = 1000;
	$GLOBALS['wake_logs']           = array();
	$GLOBALS['wake_options']        = array();
	$GLOBALS['wake_canceled']       = array();
	$GLOBALS['wake_fail_cancel']    = false;
	$GLOBALS['wake_count_queries']  = 0;

	use DataMachine\Core\EngineData;
	use DataMachine\Engine\AI\AIConcurrencyBackpressure;
	use DataMachine\Engine\AI\PipelineAIConcurrencyLimiter;

	$failed = 0;
	$total  = 0;

	function assert_wake_smoke( string $name, bool $cond, string $detail = '' ): void {
		global $failed, $total;
		++$total;
		if ( $cond ) {
			echo "  [PASS] $name\n";
			return;
		}
		echo "  [FAIL] $name" . ( $detail ? " - $detail" : '' ) . "\n";
		++$failed;
	}

	function wp_json_encode( mixed $value ): string|false {
		return json_encode( $value );
	}

	function wake_reset_actions(): void {
		$GLOBALS['wake_actions']  = array();
		$GLOBALS['wake_canceled'] = array();
	}

	function wake_add_action( int $action_id, array $args, int $scheduled_at, string $status = 'pending', string $hook = 'datamachine_resume_ai_step', string $group = '' ): void {
		$GLOBALS['wake_actions'][] = array(
			'action_id' => $action_id,
			'hook'      => $hook,
			'args'      => $args,
			'group'     => $group,
			'status'    => $status,
			'timestamp' => $scheduled_at,
		);
	}

	function wake_find_action( int $action_id ): ?array {
		foreach ( $GLOBALS['wake_actions'] as $action ) {
			if ( (int) $action['action_id'] === $action_id ) {
				return $action;
			}
		}
		return null;
	}

	function wake_filter_actions( array $query ): array {
		$matched = array_values(
			array_filter(
				$GLOBALS['wake_actions'],
				static function ( array $action ) use ( $query ): bool {
					foreach ( array( 'hook', 'group', 'status' ) as $field ) {
						if ( isset( $query[ $field ] ) && $action[ $field ] !== $query[ $field ] ) {
							return false;
						}
					}
					if ( isset( $query['args'] ) && $action['args'] !== $query['args'] ) {
						return false;
					}
					if ( isset( $query['action_id'] ) && (int) $action['action_id'] !== (int) $query['action_id'] ) {
						return false;
					}
					if ( isset( $query['date'] ) && $query['date'] instanceof \DateTime ) {
						$comparator = $query['date_compare'] ?? '<=';
						$actual     = (int) $action['timestamp'];
						$value      = (int) $query['date']->format( 'U' );
						$keep       = match ( $comparator ) {
							'>'     => $actual > $value,
							'>='    => $actual >= $value,
							'<'     => $actual < $value,
							'<='    => $actual <= $value,
							default => $actual === $value,
						};
						if ( ! $keep ) {
							return false;
						}
					}
					return true;
				}
			)
		);

		usort(
			$matched,
			static fn( array $a, array $b ): int => (int) $a['timestamp'] <=> (int) $b['timestamp']
		);

		$per_page = (int) ( $query['per_page'] ?? 0 );
		if ( $per_page > 0 ) {
			$matched = array_slice( $matched, 0, $per_page );
		}

		return $matched;
	}

	function wake_action_object( array $action ): object {
		return new class( $action ) {
			private array $action;

			public function __construct( array $action ) {
				$this->action = $action;
			}

			public function get_id(): int {
				return (int) $this->action['action_id'];
			}

			public function get_args(): array {
				return $this->action['args'];
			}
		};
	}

	function as_get_scheduled_actions( array $query = array(), string $return_format = 'OBJECT' ): array {
		$matched = wake_filter_actions( $query );
		if ( 'ids' === strtolower( $return_format ) ) {
			return array_map( static fn( array $action ): int => (int) $action['action_id'], $matched );
		}
		return array_map( 'wake_action_object', $matched );
	}

	function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '', bool $unique = false ): int {
		++$GLOBALS['wake_schedule_calls'];
		if ( ! empty( $GLOBALS['wake_schedule_error'] ) ) {
			return 0;
		}
		if ( $unique ) {
			foreach ( array( 'pending', 'in-progress' ) as $blocking_status ) {
				if ( ! empty(
					wake_filter_actions(
						array(
							'hook'   => $hook,
							'group'  => $group,
							'status' => $blocking_status,
						)
					)
				) ) {
					return 0;
				}
			}
		}

		$action_id = $GLOBALS['wake_next_action_id']++;
		wake_add_action( $action_id, $args, $timestamp, 'pending', $hook, $group );
		return $action_id;
	}

	class ActionScheduler_Store {
		public function cancel_action( int $action_id ): void {
			if ( $GLOBALS['wake_fail_cancel'] ) {
				throw new \RuntimeException( 'synthetic cancel failure' );
			}
			$GLOBALS['wake_canceled'][] = $action_id;
			foreach ( $GLOBALS['wake_actions'] as $index => $action ) {
				if ( (int) $action['action_id'] === $action_id ) {
					$GLOBALS['wake_actions'][ $index ]['status'] = 'canceled';
				}
			}
		}

		public function query_actions( array $query = array(), string $query_type = 'select' ) {
			if ( 'count' === $query_type ) {
				++$GLOBALS['wake_count_queries'];
				return (string) count( wake_filter_actions( $query ) );
			}
			return array_map( static fn( array $action ): int => (int) $action['action_id'], wake_filter_actions( $query ) );
		}

		public static function instance(): self {
			return new self();
		}
	}

	function did_action( string $hook ): int {
		return 'action_scheduler_init' === $hook ? 1 : 0;
	}

	function do_action( string $hook, mixed ...$args ): void {
		if ( 'datamachine_log' === $hook ) {
			$GLOBALS['wake_logs'][] = $args;
		}
	}

	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		unset( $hook, $args );
		return $value;
	}

	function get_option( string $name, mixed $default_value = false ): mixed {
		return array_key_exists( $name, $GLOBALS['wake_options'] ) ? $GLOBALS['wake_options'][ $name ] : $default_value;
	}

	function add_option( string $name, mixed $value = '', mixed $deprecated = '', mixed $autoload = null ): bool {
		unset( $deprecated, $autoload );
		if ( array_key_exists( $name, $GLOBALS['wake_options'] ) ) {
			return false;
		}
		$GLOBALS['wake_options'][ $name ] = $value;
		return true;
	}

	function update_option( string $name, mixed $value, mixed $autoload = null ): bool {
		unset( $autoload );
		$GLOBALS['wake_options'][ $name ] = $value;
		return true;
	}

	function delete_option( string $name ): bool {
		$existed = array_key_exists( $name, $GLOBALS['wake_options'] );
		unset( $GLOBALS['wake_options'][ $name ] );
		return $existed;
	}

	function sanitize_key( string $key ): string {
		return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) ?? '' );
	}

	require_once __DIR__ . '/../inc/Core/NetworkSettings.php';
	require_once __DIR__ . '/../inc/Core/PluginSettings.php';
	require_once __DIR__ . '/../inc/Core/OptionLeaseStore.php';
	require_once __DIR__ . '/../inc/Engine/AI/PipelineAIConcurrencyLease.php';
	require_once __DIR__ . '/../inc/Engine/AI/PipelineAIConcurrencyLimiter.php';
	require_once __DIR__ . '/../inc/Engine/AI/AIConcurrencyBackpressure.php';

	$now          = strtotime( '2026-09-15T12:00:00Z' );
	$resume_args1 = array( 'job_id' => 501, 'flow_step_id' => 'ai-1', 'operation_generation' => 0, 'operation_claim_token' => '', 'ai_resume_generation' => 8 );
	$resume_args2 = array( 'job_id' => 502, 'flow_step_id' => 'ai-2', 'operation_generation' => 0, 'operation_claim_token' => '', 'ai_resume_generation' => 11 );
	$resume_args3 = array( 'job_id' => 503, 'flow_step_id' => 'ai-3', 'operation_generation' => 0, 'operation_claim_token' => '', 'ai_resume_generation' => 10 );

	echo "Case 1: wake-up selects the earliest future-scheduled waiter and only one\n";
	wake_reset_actions();
	wake_add_action( 11, $resume_args2, $now + 600, 'pending', AIConcurrencyBackpressure::RESUME_HOOK, AIConcurrencyBackpressure::continuationGroup( $resume_args2 ) );
	wake_add_action( 12, $resume_args1, $now + 300, 'pending', AIConcurrencyBackpressure::RESUME_HOOK, AIConcurrencyBackpressure::continuationGroup( $resume_args1 ) );
	wake_add_action( 13, $resume_args3, $now - 5, 'pending', AIConcurrencyBackpressure::RESUME_HOOK, AIConcurrencyBackpressure::continuationGroup( $resume_args3 ) );

	$wake = AIConcurrencyBackpressure::wakeEarliestDeferred( $now );
	assert_wake_smoke( 'wake targets the earliest future-scheduled waiter', $wake['woke'] && 12 === $wake['previous_action_id'], (string) wp_json_encode( $wake ) );
	assert_wake_smoke( 'wake preserves waiter identity', 501 === $wake['job_id'] && 'ai-1' === $wake['flow_step_id'] && 8 === $wake['generation'] );
	assert_wake_smoke( 'wake schedules exactly one replacement action', 1 === $GLOBALS['wake_schedule_calls'] && $wake['action_id'] > 0 );
	$replacement = wake_find_action( $wake['action_id'] );
	assert_wake_smoke( 'replacement runs immediately instead of on backoff', null !== $replacement && $now === (int) $replacement['timestamp'] );
	assert_wake_smoke( 'predecessor is canceled after replacement exists', array( 12 ) === $GLOBALS['wake_canceled'] );
	assert_wake_smoke( 'replacement reuses the same continuation group', AIConcurrencyBackpressure::continuationGroup( $resume_args1 ) === (string) ( $replacement['group'] ?? '' ) );
	assert_wake_smoke( 'exactly one pending continuation remains for the woken generation', 1 === count( wake_filter_actions( array( 'hook' => AIConcurrencyBackpressure::RESUME_HOOK, 'group' => AIConcurrencyBackpressure::continuationGroup( $resume_args1 ), 'status' => 'pending' ) ) ) );
	assert_wake_smoke( 'due-now waiters are never selected as wake targets', 503 !== $wake['job_id'] );

	echo "Case 2: wake-up is a no-op without a future waiter\n";
	wake_reset_actions();
	$GLOBALS['wake_schedule_calls'] = 0;
	$empty_wake                     = AIConcurrencyBackpressure::wakeEarliestDeferred( $now );
	assert_wake_smoke( 'no waiter means no wake', ! $empty_wake['woke'] && 'no_deferred_waiter' === $empty_wake['reason'] );
	assert_wake_smoke( 'no-op wake schedules nothing', 0 === $GLOBALS['wake_schedule_calls'] );

	wake_add_action( 14, $resume_args3, $now - 30, 'pending', AIConcurrencyBackpressure::RESUME_HOOK, AIConcurrencyBackpressure::continuationGroup( $resume_args3 ) );
	$due_only = AIConcurrencyBackpressure::wakeEarliestDeferred( $now );
	assert_wake_smoke( 'only due-now pending actions is also a no-op', ! $due_only['woke'] && 0 === $GLOBALS['wake_schedule_calls'] );

	echo "Case 3: wake-up honors generation ownership\n";
	wake_reset_actions();
	EngineData::$engines = array();
	wake_add_action( 21, $resume_args2, $now + 120, 'pending', AIConcurrencyBackpressure::RESUME_HOOK, AIConcurrencyBackpressure::continuationGroup( $resume_args2 ) );
	EngineData::$engines[502] = array(
		'ai_concurrency_resume_ownership' => array(
			'flow_step_id'      => 'ai-2',
			'generation'        => 11,
			'source_generation' => 10,
			'status'            => 'scheduled',
			'token'             => 'token-502',
			'action_id'         => 21,
			'claimed_at'        => gmdate( 'c', $now ),
		),
	);

	$ownership_wake = AIConcurrencyBackpressure::wakeEarliestDeferred( $now );
	assert_wake_smoke( 'wake succeeds under live ownership', $ownership_wake['woke'] && 21 === $ownership_wake['previous_action_id'], (string) wp_json_encode( $ownership_wake ) );

	$ownership = EngineData::retrieve( 502 )['ai_concurrency_resume_ownership'] ?? array();
	assert_wake_smoke( 'ownership action_id is repointed to the replacement', (int) ( $ownership['action_id'] ?? 0 ) === $ownership_wake['action_id'], (string) wp_json_encode( $ownership ) );
	assert_wake_smoke( 'ownership keeps the original claim token and generation', 'token-502' === (string) ( $ownership['token'] ?? '' ) && 11 === (int) ( $ownership['generation'] ?? 0 ) && 'scheduled' === (string) ( $ownership['status'] ?? '' ) );
	assert_wake_smoke( 'replaced generation begins exactly once after wake', AIConcurrencyBackpressure::beginGeneration( 502, 'ai-2', 11, $now + 1 ) );
	assert_wake_smoke( 'duplicate execution of the replacement is fenced', ! AIConcurrencyBackpressure::beginGeneration( 502, 'ai-2', 11, $now + 2 ) );

	wake_reset_actions();
	EngineData::$engines = array();
	wake_add_action( 31, $resume_args1, $now + 60, 'pending', AIConcurrencyBackpressure::RESUME_HOOK, AIConcurrencyBackpressure::continuationGroup( $resume_args1 ) );
	$mismatched_wake = AIConcurrencyBackpressure::wakeEarliestDeferred( $now );
	assert_wake_smoke( 'wake without recorded ownership still wakes the waiter', $mismatched_wake['woke'] && 31 === $mismatched_wake['previous_action_id'] );

	echo "Case 4: wake-up failures never break the releasing job\n";
	wake_reset_actions();
	$GLOBALS['wake_schedule_error'] = true;
	$GLOBALS['wake_schedule_calls'] = 0;
	wake_add_action( 41, $resume_args2, $now + 90, 'pending', AIConcurrencyBackpressure::RESUME_HOOK, AIConcurrencyBackpressure::continuationGroup( $resume_args2 ) );
	$schedule_fail_wake             = AIConcurrencyBackpressure::wakeEarliestDeferred( $now );
	$GLOBALS['wake_schedule_error'] = false;
	assert_wake_smoke( 'reschedule failure reports without waking', ! $schedule_fail_wake['woke'] && 'reschedule_failed' === $schedule_fail_wake['reason'] );
	assert_wake_smoke( 'failed wake leaves the waiter untouched', null !== wake_find_action( 41 ) && 'pending' === wake_find_action( 41 )['status'] && array() === $GLOBALS['wake_canceled'] );

	wake_reset_actions();
	wake_add_action( 42, $resume_args2, $now + 90, 'pending', AIConcurrencyBackpressure::RESUME_HOOK, AIConcurrencyBackpressure::continuationGroup( $resume_args2 ) );
	$GLOBALS['wake_fail_cancel'] = true;
	$cancel_fail_wake            = AIConcurrencyBackpressure::wakeEarliestDeferred( $now );
	$GLOBALS['wake_fail_cancel'] = false;
	assert_wake_smoke( 'cancel failure is contained and reported', ! $cancel_fail_wake['woke'] && str_starts_with( (string) $cancel_fail_wake['reason'], 'wake_failed:' ), (string) wp_json_encode( $cancel_fail_wake ) );

	echo "Case 5: deferred snapshot reports count, median and max generation\n";
	wake_reset_actions();
	$GLOBALS['wake_count_queries'] = 0;
	wake_add_action( 51, $resume_args1, $now + 300, 'pending', AIConcurrencyBackpressure::RESUME_HOOK );
	wake_add_action( 52, $resume_args2, $now + 600, 'pending', AIConcurrencyBackpressure::RESUME_HOOK );
	wake_add_action( 53, $resume_args3, $now + 900, 'pending', AIConcurrencyBackpressure::RESUME_HOOK );
	$snapshot = AIConcurrencyBackpressure::deferredSnapshot();
	assert_wake_smoke( 'snapshot counts pending resume actions', $snapshot['available'] && 3 === $snapshot['pending'], (string) wp_json_encode( $snapshot ) );
	assert_wake_smoke( 'snapshot sample covers every pending action', 3 === $snapshot['sampled'] && ! $snapshot['sample_capped'] );
	assert_wake_smoke( 'snapshot reports median generation', 10.0 === $snapshot['generation_median'], (string) $snapshot['generation_median'] );
	assert_wake_smoke( 'snapshot reports max generation', 11 === $snapshot['generation_max'] );
	assert_wake_smoke( 'snapshot uses one bounded count query', 1 === $GLOBALS['wake_count_queries'] );

	wake_add_action( 54, $resume_args1, $now + 1200, 'pending', AIConcurrencyBackpressure::RESUME_HOOK );
	$snapshot2 = AIConcurrencyBackpressure::deferredSnapshot( 3 );
	assert_wake_smoke( 'snapshot flags a capped sample', 4 === $snapshot2['pending'] && 3 === $snapshot2['sampled'] && $snapshot2['sample_capped'] );

	wake_reset_actions();
	$empty_snapshot = AIConcurrencyBackpressure::deferredSnapshot();
	assert_wake_smoke( 'empty queue reports zero without generations', $empty_snapshot['available'] && 0 === $empty_snapshot['pending'] && null === $empty_snapshot['generation_median'] && null === $empty_snapshot['generation_max'] );

	echo "Case 6: lease release and limiter utilization stay observable\n";
	$GLOBALS['wake_options']['datamachine_settings'] = array( 'pipeline_ai_concurrency_limit' => 2 );
	\DataMachine\Core\PluginSettings::clearCache();

	$acquired = PipelineAIConcurrencyLimiter::acquire( 'openai', array( 'job_id' => 601, 'flow_step_id' => 'ai-1' ) );
	assert_wake_smoke( 'first acquire takes one of two site slots', $acquired['acquired'] && 1 === $acquired['active'] );
	$utilization = PipelineAIConcurrencyLimiter::utilization();
	assert_wake_smoke( 'utilization reports the configured site limit', 2 === $utilization['site']['limit'] );
	assert_wake_smoke( 'utilization counts the held slot', 1 === $utilization['site']['held'] );
	assert_wake_smoke( 'utilization reports no provider scopes without configuration', array() === $utilization['providers'] );

	$GLOBALS['wake_options']['datamachine_settings'] = array(
		'pipeline_ai_concurrency_limit'           => 2,
		'pipeline_ai_provider_concurrency_limits' => array( 'openai' => 1 ),
	);
	\DataMachine\Core\PluginSettings::clearCache();
	$provider_utilization = PipelineAIConcurrencyLimiter::utilization();
	assert_wake_smoke( 'provider scope reports held over limit', array( 'openai' => '0/1' ) === array_map( static fn( array $scope ): string => (int) $scope['held'] . '/' . (int) $scope['limit'], $provider_utilization['providers'] ) );

	$acquired['lease']->release();
	$released_utilization = PipelineAIConcurrencyLimiter::utilization();
	assert_wake_smoke( 'release empties the site slot count', 0 === $released_utilization['site']['held'] );

	echo "Case 7: production wiring keeps the release-side hook and the 120s cap\n";
	$ai_src           = file_get_contents( __DIR__ . '/../inc/Core/Steps/AI/AIStep.php' ) ?: '';
	$backpressure_src = file_get_contents( __DIR__ . '/../inc/Engine/AI/AIConcurrencyBackpressure.php' ) ?: '';
	$limiter_src      = file_get_contents( __DIR__ . '/../inc/Engine/AI/PipelineAIConcurrencyLimiter.php' ) ?: '';
	$worker_src       = file_get_contents( __DIR__ . '/../inc/Cli/Commands/WorkerCommand.php' ) ?: '';

	assert_wake_smoke( 'AIStep wakes a waiter after the lease release', strpos( $ai_src, '$ai_concurrency_lease->release();' ) < strpos( $ai_src, 'AIConcurrencyBackpressure::wakeEarliestDeferred();' ) );
	assert_wake_smoke( 'backoff cap is 120 seconds', str_contains( $ai_src, 'AI_CONCURRENCY_MAX_DEFER_DELAY = 120' ) );
	assert_wake_smoke( 'backoff cap is filterable', str_contains( $ai_src, 'datamachine_ai_concurrency_max_defer_delay' ) );
	assert_wake_smoke( 'wake-up schedules before canceling the predecessor', strpos( $backpressure_src, 'as_schedule_single_action' ) < strpos( $backpressure_src, 'cancel_action' ) );
	assert_wake_smoke( 'wake-up repoints ownership to stay generation-consistent', str_contains( $backpressure_src, 'repointScheduledAction' ) );
	assert_wake_smoke( 'wake-up never throws through the release path', str_contains( $backpressure_src, 'catch ( \Throwable $wake_error )' ) );
	assert_wake_smoke( 'limiter exposes read-only utilization', str_contains( $limiter_src, 'public static function utilization(' ) );
	assert_wake_smoke( 'worker status surfaces lease utilization and deferred resume stats', str_contains( $worker_src, 'ai_lease_site_slots_held' ) && str_contains( $worker_src, 'ai_resume_generation_median' ) );

	assert_wake_smoke( 'capped backoff uses the lowered ceiling', 120 === AIConcurrencyBackpressure::delaySeconds( 10, 30, 120 ) );
	assert_wake_smoke( 'backoff still grows exponentially below the cap', 40 === AIConcurrencyBackpressure::delaySeconds( 10, 2, 120 ) );

	echo "\nAI concurrency release wake-up smoke complete: {$total} assertions, {$failed} failures.\n";
	if ( $failed > 0 ) {
		exit( 1 );
	}
}
