<?php
/**
 * Pure-PHP behavioral smoke tests for FlowScheduleReconciler.
 *
 * Run with: php tests/flow-schedule-reconciler-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Core\Database\Flows {
	class Flows {
		public array $schedules = array();

		public function get_flow_schedules(): array {
			return $this->schedules;
		}

		public static function is_flow_enabled( array $scheduling ): bool {
			return ! isset( $scheduling['enabled'] ) || false !== $scheduling['enabled'];
		}
	}
}

namespace DataMachine\Api\Flows {
	class FlowScheduling {
		public const FLOW_HOOK = 'datamachine_run_flow_now';
		public const GENERATION_ARGUMENT_INDEX = 2;
		public static array $calls = array();

		public static function handle_scheduling_update( int $flow_id, array $scheduling, bool $force = false ) {
			self::$calls[] = compact( 'flow_id', 'scheduling', 'force' );
			$interval      = false === ( $scheduling['enabled'] ?? true )
				? 'manual'
				: (string) ( $scheduling['interval'] ?? 'manual' );
			return \DataMachine\Engine\Tasks\RecurringScheduler::ensureSchedule(
				self::FLOW_HOOK,
				array( $flow_id ),
				$interval
			);
		}
	}

	class FlowScheduleReconciliationLock {
		public static int $refresh_count = 0;

		public static function acquire(): string {
			return 'test-lock';
		}

		public static function release( string $token ): bool {
			return 'test-lock' === $token;
		}

		public static function refresh( string $token ): bool {
			++self::$refresh_count;
			return 'test-lock' === $token;
		}
	}
}

namespace DataMachine\Engine\Tasks {
	class RecurringScheduler {
		public const GROUP = 'data-machine';

		public static bool $ready = true;
		public static array $covered = array();
		public static array $recurrences = array();
		public static array $calls = array();
		public static array $fail_ids = array();
		public static array $statuses = array();
		public static array $last_attempts = array();
		public static array $duplicates = array();

		public static function isReady(): bool {
			return self::$ready;
		}

		public static function hasCoverage( string $hook, array $args ): bool {
			unset( $hook );
			return ! empty( self::$covered[ (int) $args[0] ] );
		}

		public static function isValidCronExpression( string $expression ): bool {
			return str_contains( $expression, ' ' );
		}

		public static function looksLikeCronExpression( string $value ): bool {
			return substr_count( trim( $value ), ' ' ) >= 4;
		}

		public static function resolveIntervalAlias( string $interval ): string {
			return $interval;
		}

		public static function ensureSchedule( string $hook, array $args, ?string $interval, array $options = array() ) {
			$flow_id            = (int) $args[0];
			if ( ! empty( self::$fail_ids[ $flow_id ] ) ) {
				return new \WP_Error( 'schedule_failed', 'Synthetic schedule failure.' );
			}
			self::$covered[ $flow_id ] = true;
			self::$recurrences[ $flow_id ] = 'cron' === $interval
				? (string) ( $options['cron_expression'] ?? '' )
				: 3600;
			self::$statuses[ $flow_id ] = 'pending';
			self::$duplicates[ $flow_id ] = array();
			self::$calls[]      = compact( 'hook', 'args', 'interval', 'options' );
			return array(
				'interval'  => $interval,
				'scheduled' => true,
			);
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'ARRAY_A', 'ARRAY_A' );

	class WP_Error {
		public function __construct( private string $code = '', private string $message = '' ) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}

	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}

	function apply_filters( string $hook, array $value ): array {
		if ( 'datamachine_scheduler_intervals' === $hook ) {
			return array(
				'hourly' => array( 'seconds' => 3600 ),
				'daily'  => array( 'seconds' => 86400 ),
			);
		}
		return $value;
	}

	function maybe_unserialize( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		$decoded = @unserialize( $value );
		return false === $decoded ? $value : $decoded;
	}

	class DatamachineFakeSchedule {
		public function __construct( private int|string $recurrence ) {}

		public function is_recurring(): bool {
			return true;
		}

		public function get_recurrence(): int|string {
			return $this->recurrence;
		}
	}

	class DatamachineFakeAction {
		public function __construct( private int $flow_id, private int|string $recurrence ) {}

		public function get_args(): array {
			return array( $this->flow_id );
		}

		public function get_schedule(): DatamachineFakeSchedule {
			return new DatamachineFakeSchedule( $this->recurrence );
		}
	}

	class DatamachineCoverageWpdb {
		public string $actionscheduler_actions = 'wp_actionscheduler_actions';
		public string $actionscheduler_groups  = 'wp_actionscheduler_groups';
		public string $actionscheduler_claims  = 'wp_actionscheduler_claims';

		public function prepare( string $query, ...$args ): array {
			return array( $query, $args );
		}

		public function get_results( array $prepared, string $format ): array {
			unset( $prepared, $format );
			$rows = array();
			foreach ( \DataMachine\Engine\Tasks\RecurringScheduler::$covered as $flow_id => $covered ) {
				if ( ! $covered ) {
					continue;
				}
				$rows[] = array(
					'action_id'        => $flow_id,
					'status'           => \DataMachine\Engine\Tasks\RecurringScheduler::$statuses[ $flow_id ] ?? 'pending',
					'args'             => json_encode( array( $flow_id ) ),
					'extended_args'    => null,
					'schedule'         => serialize( new DatamachineFakeSchedule( \DataMachine\Engine\Tasks\RecurringScheduler::$recurrences[ $flow_id ] ?? 3600 ) ),
					'last_attempt_gmt' => \DataMachine\Engine\Tasks\RecurringScheduler::$last_attempts[ $flow_id ] ?? null,
					'claim_created_gmt' => null,
				);
				foreach ( \DataMachine\Engine\Tasks\RecurringScheduler::$duplicates[ $flow_id ] ?? array() as $duplicate ) {
					$rows[] = array(
						'action_id'         => $flow_id + count( $rows ) + 1000,
						'status'            => $duplicate['status'] ?? 'pending',
						'args'              => json_encode( array( $flow_id ) ),
						'extended_args'     => null,
						'schedule'          => serialize( new DatamachineFakeSchedule( $duplicate['recurrence'] ?? 3600 ) ),
						'last_attempt_gmt'  => $duplicate['last_attempt_gmt'] ?? null,
						'claim_created_gmt' => null,
					);
				}
			}
			return $rows;
		}
	}

	class ActionScheduler_DBStore {
		public function fetch_action( int $action_id ): DatamachineFakeAction {
			return new DatamachineFakeAction(
				$action_id,
				\DataMachine\Engine\Tasks\RecurringScheduler::$recurrences[ $action_id ] ?? 3600
			);
		}

		public function get_date( int $action_id ): ?DateTimeInterface {
			$date = \DataMachine\Engine\Tasks\RecurringScheduler::$last_attempts[ $action_id ] ?? null;
			return $date ? new DateTimeImmutable( $date, new DateTimeZone( 'UTC' ) ) : null;
		}
	}

	class DatamachineFallbackStore extends ActionScheduler_DBStore {}

	class ActionScheduler {
		public static object $store;

		public static function store(): object {
			return self::$store;
		}
	}

	$wpdb = new DatamachineCoverageWpdb();
	ActionScheduler::$store = new ActionScheduler_DBStore();

	function as_get_scheduled_actions( array $query, string $return_format ): array {
		$actions = array();
		foreach ( \DataMachine\Engine\Tasks\RecurringScheduler::$covered as $flow_id => $covered ) {
			if ( $covered && ( $query['status'] ?? '' ) === ( \DataMachine\Engine\Tasks\RecurringScheduler::$statuses[ $flow_id ] ?? 'pending' ) ) {
				$actions[] = 'ids' === $return_format
					? (int) $flow_id
					: new DatamachineFakeAction(
					(int) $flow_id,
					\DataMachine\Engine\Tasks\RecurringScheduler::$recurrences[ $flow_id ] ?? 3600
				);
			}
		}
		return $actions;
	}

	require_once __DIR__ . '/../inc/Api/Flows/FlowScheduleReconciler.php';

	use DataMachine\Api\Flows\FlowScheduleReconciler;
	use DataMachine\Core\Database\Flows\Flows;
	use DataMachine\Engine\Tasks\RecurringScheduler;

	function datamachine_reconciler_assert( bool $condition, string $message ): void {
		if ( $condition ) {
			echo "  [PASS] {$message}\n";
			return;
		}

		echo "  [FAIL] {$message}\n";
		exit( 1 );
	}

	function datamachine_reconciler_flow( int $id, array $scheduling ): array {
		return array(
			'flow_id'           => $id,
			'flow_name'         => 'Flow ' . $id,
			'scheduling_config' => $scheduling,
		);
	}

	echo "=== flow-schedule-reconciler-smoke ===\n";

	$flows = new Flows();
	for ( $id = 1; $id <= 30; $id++ ) {
		$flows->schedules[] = datamachine_reconciler_flow( $id, array( 'interval' => 'hourly' ) );
	}
	$flows->schedules[] = datamachine_reconciler_flow( 31, array( 'interval' => 'manual' ) );
	$flows->schedules[] = datamachine_reconciler_flow( 32, array( 'interval' => 'daily', 'enabled' => false ) );
	$flows->schedules[] = datamachine_reconciler_flow( 33, array( 'interval' => 'one_time' ) );
	$flows->schedules[] = datamachine_reconciler_flow( 34, array() );
	RecurringScheduler::$covered[1] = true; // Models pending or in-progress matching coverage.
	RecurringScheduler::$recurrences[1] = 3600;
	RecurringScheduler::$statuses[1] = 'pending';

	$reconciler = new FlowScheduleReconciler( $flows );
	$dry_run    = $reconciler->reconcile();
	datamachine_reconciler_assert( 30 === $dry_run['eligible'], 'only recurring and cron definitions are eligible' );
	datamachine_reconciler_assert( 4 === $dry_run['excluded'], 'manual, paused, one-time, and missing schedules are excluded' );
	datamachine_reconciler_assert( 1 === $dry_run['covered'] && 29 === $dry_run['missing'], 'existing matching work counts as coverage' );
	datamachine_reconciler_assert( 24 === $dry_run['distribution_window_hours'], 'large automatic reconciliation selects 24-hour spread' );
	datamachine_reconciler_assert( empty( RecurringScheduler::$calls ), 'dry-run does not schedule actions' );

	$applied = $reconciler->reconcile( true );
	datamachine_reconciler_assert( 29 === $applied['repaired'] && 0 === $applied['remaining_missing'], 'apply repairs every missing schedule' );
	datamachine_reconciler_assert( 29 === count( RecurringScheduler::$calls ), 'apply schedules each missing definition exactly once' );
	datamachine_reconciler_assert( 3 === \DataMachine\Api\Flows\FlowScheduleReconciliationLock::$refresh_count, 'large apply refreshes its lock lease throughout the loop' );
	datamachine_reconciler_assert(
		86400 === RecurringScheduler::$calls[0]['options']['distribution_window_seconds'],
		'large repair passes an explicit 24-hour fleet window'
	);

	$second = $reconciler->reconcile( true );
	datamachine_reconciler_assert( 0 === $second['missing'] && 0 === $second['repaired'], 'second apply is idempotent' );
	datamachine_reconciler_assert( 29 === count( RecurringScheduler::$calls ), 'idempotent apply creates no duplicate actions' );

	$small             = new Flows();
	$small->schedules  = array( datamachine_reconciler_flow( 100, array( 'interval' => 'daily' ) ) );
	$small_reconciler  = new FlowScheduleReconciler( $small );
	$small_result      = $small_reconciler->reconcile( true );
	$small_call        = RecurringScheduler::$calls[ count( RecurringScheduler::$calls ) - 1 ];
	datamachine_reconciler_assert( 1 === $small_result['distribution_window_hours'], 'small repair reports ordinary one-hour staggering' );
	datamachine_reconciler_assert( ! isset( $small_call['options']['distribution_window_seconds'] ), 'small repair leaves ordinary scheduler behavior untouched' );

	$invalid = $small_reconciler->reconcile( false, 25 );
	datamachine_reconciler_assert( false === $invalid['success'], 'explicit spread is bounded to 24 hours' );

	$malformed            = new Flows();
	$malformed->schedules = array(
		datamachine_reconciler_flow( 200, array( 'interval' => 'cron' ) ),
		datamachine_reconciler_flow( 201, array( 'interval' => 'sometimes' ) ),
	);
	$malformed_result = ( new FlowScheduleReconciler( $malformed ) )->reconcile( true );
	datamachine_reconciler_assert( true === $malformed_result['success'], 'malformed definitions do not create a permanent apply failure' );
	datamachine_reconciler_assert( 2 === $malformed_result['invalid'] && 0 === $malformed_result['eligible'], 'malformed definitions are reported separately' );

	$partial            = new Flows();
	$partial->schedules = array(
		datamachine_reconciler_flow( 300, array( 'interval' => 'hourly' ) ),
		datamachine_reconciler_flow( 301, array( 'interval' => 'hourly' ) ),
	);
	RecurringScheduler::$fail_ids[300] = true;
	$partial_result = ( new FlowScheduleReconciler( $partial ) )->reconcile( true );
	datamachine_reconciler_assert( false === $partial_result['success'], 'partial schedule failure marks reconciliation unsuccessful' );
	datamachine_reconciler_assert( 1 === $partial_result['failed'] && 1 === $partial_result['repaired'], 'partial failure preserves exact repair counts' );
	datamachine_reconciler_assert( true === $partial_result['transient'], 'partial schedule failure remains retryable for deferred repair' );

	$fresh_running            = new Flows();
	$fresh_running->schedules = array( datamachine_reconciler_flow( 400, array( 'interval' => 'hourly' ) ) );
	RecurringScheduler::$covered[400]      = true;
	RecurringScheduler::$recurrences[400]  = 3600;
	RecurringScheduler::$statuses[400]     = 'in-progress';
	RecurringScheduler::$last_attempts[400] = gmdate( 'Y-m-d H:i:s' );
	$fresh_result = ( new FlowScheduleReconciler( $fresh_running ) )->reconcile();
	datamachine_reconciler_assert( 1 === $fresh_result['covered'], 'fresh in-progress action covers its matching schedule' );

	RecurringScheduler::$last_attempts[400] = gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS );
	$stale_result = ( new FlowScheduleReconciler( $fresh_running ) )->reconcile();
	datamachine_reconciler_assert( 1 === $stale_result['blocked'] && false === $stale_result['success'], 'stale DBStore in-progress action blocks reconciliation' );
	$calls_before_blocked_apply = count( RecurringScheduler::$calls );
	$stale_apply = ( new FlowScheduleReconciler( $fresh_running ) )->reconcile( true );
	datamachine_reconciler_assert( 1 === $stale_apply['blocked'] && true === $stale_apply['transient'], 'blocked apply remains a transient failure' );
	datamachine_reconciler_assert( 1 === $stale_apply['remaining_missing'] && 0 === $stale_apply['repaired'], 'blocked apply cannot report complete repair' );
	datamachine_reconciler_assert( $calls_before_blocked_apply === count( RecurringScheduler::$calls ), 'blocked apply does not schedule a successor' );
	$stale_second = ( new FlowScheduleReconciler( $fresh_running ) )->reconcile();
	datamachine_reconciler_assert( 1 === $stale_second['blocked'], 'second audit remains blocked while ownership persists' );

	$mismatch            = new Flows();
	$mismatch->schedules = array( datamachine_reconciler_flow( 401, array( 'interval' => 'daily' ) ) );
	RecurringScheduler::$covered[401]     = true;
	RecurringScheduler::$recurrences[401] = 3600;
	RecurringScheduler::$statuses[401]    = 'pending';
	$mismatch_result = ( new FlowScheduleReconciler( $mismatch ) )->reconcile();
	datamachine_reconciler_assert( 1 === $mismatch_result['missing'], 'pending action with mismatched recurrence is not coverage' );

	$duplicate            = new Flows();
	$duplicate->schedules = array( datamachine_reconciler_flow( 402, array( 'interval' => 'hourly' ) ) );
	RecurringScheduler::$covered[402]     = true;
	RecurringScheduler::$recurrences[402] = 3600;
	RecurringScheduler::$statuses[402]    = 'pending';
	RecurringScheduler::$duplicates[402]  = array( array( 'recurrence' => 3600 ) );
	$duplicate_result = ( new FlowScheduleReconciler( $duplicate ) )->reconcile();
	datamachine_reconciler_assert( 1 === $duplicate_result['missing'], 'duplicate matching actions are unhealthy coverage' );
	$duplicate_apply = ( new FlowScheduleReconciler( $duplicate ) )->reconcile( true );
	datamachine_reconciler_assert( 1 === $duplicate_apply['repaired'] && empty( RecurringScheduler::$duplicates[402] ), 'apply delegates duplicate collapse to ensureSchedule' );
	$duplicate_verified = ( new FlowScheduleReconciler( $duplicate ) )->reconcile();
	datamachine_reconciler_assert( 1 === $duplicate_verified['covered'], 'deduplicated pending schedule becomes healthy coverage' );

	$mixed            = new Flows();
	$mixed->schedules = array( datamachine_reconciler_flow( 405, array( 'interval' => 'hourly' ) ) );
	RecurringScheduler::$covered[405]     = true;
	RecurringScheduler::$recurrences[405] = 3600;
	RecurringScheduler::$statuses[405]    = 'pending';
	RecurringScheduler::$duplicates[405]  = array(
		array(
			'recurrence'      => 3600,
			'status'          => 'in-progress',
			'last_attempt_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS ),
		),
	);
	$mixed_result = ( new FlowScheduleReconciler( $mixed ) )->reconcile( true );
	datamachine_reconciler_assert( 1 === $mixed_result['blocked'] && 0 === $mixed_result['repaired'], 'mixed rows with stale in-progress ownership are blocked' );

	ActionScheduler::$store = new DatamachineFallbackStore();
	$fallback            = new Flows();
	$fallback->schedules = array( datamachine_reconciler_flow( 403, array( 'interval' => 'hourly' ) ) );
	RecurringScheduler::$covered[403]       = true;
	RecurringScheduler::$recurrences[403]   = 3600;
	RecurringScheduler::$statuses[403]      = 'in-progress';
	RecurringScheduler::$last_attempts[403] = gmdate( 'Y-m-d H:i:s' );
	$fallback_fresh = ( new FlowScheduleReconciler( $fallback ) )->reconcile();
	datamachine_reconciler_assert( 1 === $fallback_fresh['blocked'], 'fallback in-progress action is blocked regardless of scheduled date' );
	RecurringScheduler::$last_attempts[403] = gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS );
	$fallback_stale = ( new FlowScheduleReconciler( $fallback ) )->reconcile();
	datamachine_reconciler_assert( 1 === $fallback_stale['blocked'], 'fallback in-progress remains blocked for stale-looking dates' );
	RecurringScheduler::$last_attempts[403] = null;
	$fallback_unknown = ( new FlowScheduleReconciler( $fallback ) )->reconcile();
	datamachine_reconciler_assert( 1 === $fallback_unknown['blocked'], 'fallback in-progress remains blocked when timing is unavailable' );

	$fallback_pending            = new Flows();
	$fallback_pending->schedules = array( datamachine_reconciler_flow( 404, array( 'interval' => 'hourly' ) ) );
	RecurringScheduler::$covered[404]     = true;
	RecurringScheduler::$recurrences[404] = 3600;
	RecurringScheduler::$statuses[404]    = 'pending';
	$fallback_pending_result = ( new FlowScheduleReconciler( $fallback_pending ) )->reconcile();
	datamachine_reconciler_assert( 1 === $fallback_pending_result['covered'], 'pending fallback action still provides semantic coverage' );

	ActionScheduler::$store = new ActionScheduler_DBStore();
	$fresh_mismatch            = new Flows();
	$fresh_mismatch->schedules = array( datamachine_reconciler_flow( 406, array( 'interval' => 'daily' ) ) );
	RecurringScheduler::$covered[406]       = true;
	RecurringScheduler::$recurrences[406]   = 3600;
	RecurringScheduler::$statuses[406]      = 'in-progress';
	RecurringScheduler::$last_attempts[406] = gmdate( 'Y-m-d H:i:s' );
	$calls_before_fresh_mismatch = count( RecurringScheduler::$calls );
	$fresh_mismatch_result = ( new FlowScheduleReconciler( $fresh_mismatch ) )->reconcile( true );
	datamachine_reconciler_assert( 1 === $fresh_mismatch_result['blocked'] && true === $fresh_mismatch_result['transient'], 'fresh mismatched in-progress ownership blocks apply' );
	datamachine_reconciler_assert( $calls_before_fresh_mismatch === count( RecurringScheduler::$calls ), 'fresh mismatched in-progress apply schedules nothing' );

	$fresh_mixed            = new Flows();
	$fresh_mixed->schedules = array( datamachine_reconciler_flow( 407, array( 'interval' => 'hourly' ) ) );
	RecurringScheduler::$covered[407]       = true;
	RecurringScheduler::$recurrences[407]   = 3600;
	RecurringScheduler::$statuses[407]      = 'in-progress';
	RecurringScheduler::$last_attempts[407] = gmdate( 'Y-m-d H:i:s' );
	RecurringScheduler::$duplicates[407]    = array( array( 'recurrence' => 3600, 'status' => 'pending' ) );
	$calls_before_fresh_mixed = count( RecurringScheduler::$calls );
	$fresh_mixed_result = ( new FlowScheduleReconciler( $fresh_mixed ) )->reconcile( true );
	datamachine_reconciler_assert( 1 === $fresh_mixed_result['blocked'] && true === $fresh_mixed_result['transient'], 'fresh in-progress plus pending mixed ownership blocks apply' );
	datamachine_reconciler_assert( $calls_before_fresh_mixed === count( RecurringScheduler::$calls ), 'mixed fresh in-progress apply schedules nothing' );

	$paused_drift            = new Flows();
	$paused_drift->schedules = array(
		datamachine_reconciler_flow(
			408,
			array(
				'interval'                => 'hourly',
				'enabled'                 => false,
				'schedule_reconciliation' => array( 'status' => 'drift' ),
			)
		),
	);
	$paused_drift_audit = ( new FlowScheduleReconciler( $paused_drift ) )->reconcile();
	datamachine_reconciler_assert( 1 === $paused_drift_audit['missing'], 'paused desired-state drift remains visible to reconciliation' );
	$paused_drift_apply = ( new FlowScheduleReconciler( $paused_drift ) )->reconcile( true );
	$drift_call         = \DataMachine\Api\Flows\FlowScheduling::$calls[ count( \DataMachine\Api\Flows\FlowScheduling::$calls ) - 1 ];
	datamachine_reconciler_assert( 1 === $paused_drift_apply['repaired'], 'reconciler retries paused schedule cleanup' );
	datamachine_reconciler_assert( true === $drift_call['force'] && false === $drift_call['scheduling']['enabled'], 'drift repair replays authoritative persisted intent' );

	echo "\nAll flow schedule reconciler assertions passed.\n";
}
