<?php
/**
 * Flow schedule coverage reconciliation.
 *
 * @package DataMachine\Api\Flows
 */

namespace DataMachine\Api\Flows;

use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Engine\Tasks\RecurringScheduler;

defined( 'ABSPATH' ) || exit;

class FlowScheduleReconciler {

	private const LARGE_FLEET_THRESHOLD = 25;
	private const MAX_DETAILS           = 100;
	private const MAX_COVERAGE_ROWS     = 5000;
	private const RUNNING_STALE_AFTER   = 7200;

	private Flows $flows;

	public function __construct( ?Flows $flows = null ) {
		$this->flows = $flows ?? new Flows();
	}

	/**
	 * Audit and optionally restore recurring flow schedule coverage.
	 *
	 * @param bool     $apply        Whether to repair missing schedules.
	 * @param int|null $spread_hours Optional explicit fleet spread (1-24 hours).
	 * @return array Reconciliation report.
	 */
	public function reconcile( bool $apply = false, ?int $spread_hours = null ): array {
		if ( ! RecurringScheduler::isReady() ) {
			return $this->failure( 'Action Scheduler is not initialized.', true );
		}

		if ( null !== $spread_hours && ( $spread_hours < 1 || $spread_hours > 24 ) ) {
			return $this->failure( 'spread_hours must be between 1 and 24.' );
		}

		$lock_token = null;
		if ( $apply ) {
			$lock_token = FlowScheduleReconciliationLock::acquire();
			if ( is_wp_error( $lock_token ) ) {
				return $this->failure( $lock_token->get_error_message(), true, $lock_token->get_error_code() );
			}
		}

		try {
			return $this->reconcileUnlocked( $apply, $spread_hours, $lock_token );
		} finally {
			if ( null !== $lock_token ) {
				FlowScheduleReconciliationLock::release( $lock_token );
			}
		}
	}

	/**
	 * Reconcile after the apply lock has been acquired.
	 *
	 * @param bool     $apply        Whether to repair missing schedules.
	 * @param int|null $spread_hours Optional explicit fleet spread.
	 * @param string|null $lock_token Apply lock token.
	 * @return array Reconciliation report.
	 */
	private function reconcileUnlocked( bool $apply, ?int $spread_hours, ?string $lock_token ): array {
		$eligible       = array();
		$invalid        = array();
		$drift_cleanup  = array();
		$excluded_count = 0;

		foreach ( $this->flows->get_flow_schedules() as $flow ) {
			$scheduling = $flow['scheduling_config'] ?? array();
			$interval   = $scheduling['interval'] ?? null;

			$has_drift = ! empty( $scheduling['schedule_reconciliation'] );
			if ( ! Flows::is_flow_enabled( $scheduling ) || empty( $interval ) || in_array( $interval, array( 'manual', 'one_time' ), true ) ) {
				if ( $has_drift ) {
					$flow['force_reconcile'] = true;
					$drift_cleanup[]         = $flow;
					continue;
				}
				++$excluded_count;
				continue;
			}

			$expected = $this->expectedRecurrence( $scheduling );
			if ( is_wp_error( $expected ) ) {
				$flow['definition_error'] = $expected->get_error_message();
				$invalid[]                = $flow;
				continue;
			}

			$flow['expected_recurrence'] = $expected;
			$flow['force_reconcile']     = $has_drift;
			$eligible[]                  = $flow;
		}

		$coverage_rows = $this->getCoverageRows();
		if ( is_wp_error( $coverage_rows ) ) {
			return $this->failure( $coverage_rows->get_error_message(), true, $coverage_rows->get_error_code() );
		}

		$coverage = $this->classifyCoverageRows( $coverage_rows, $eligible );
		$missing  = array();
		$blocked  = array();
		foreach ( $eligible as $flow ) {
			$flow_id = (int) $flow['flow_id'];
			if ( isset( $coverage['blocked'][ $flow_id ] ) ) {
				$flow['blocked_reason'] = $coverage['blocked'][ $flow_id ];
				$blocked[]              = $flow;
			} elseif ( ! isset( $coverage['covered'][ $flow_id ] ) || ! empty( $flow['force_reconcile'] ) ) {
				$missing[] = $flow;
			}
		}
		$missing = array_merge( $missing, $drift_cleanup );

		$missing_count = count( $missing );
		$window_hours  = $spread_hours;
		if ( null === $window_hours && $missing_count >= self::LARGE_FLEET_THRESHOLD ) {
			$window_hours = 24;
		}

		$repaired = 0;
		$failed   = 0;
		$details  = array();
		foreach ( $invalid as $flow ) {
			$this->appendDetail(
				$details,
				$flow,
				'invalid',
				(string) $flow['definition_error']
			);
		}
		foreach ( $blocked as $flow ) {
			$this->appendDetail( $details, $flow, 'blocked', (string) $flow['blocked_reason'] );
		}

		foreach ( $missing as $index => $flow ) {
			$flow_id    = (int) $flow['flow_id'];
			$scheduling = $flow['scheduling_config'];
			$status     = 'missing';
			$error      = '';

			if ( $apply ) {
				if ( 0 === $index % 10 && ( null === $lock_token || ! FlowScheduleReconciliationLock::refresh( $lock_token ) ) ) {
					return $this->failure( 'Flow schedule reconciliation lost its apply lock.', true, 'flow_schedule_reconciliation_lock_lost' );
				}

				$options = array(
					'stagger_seed'              => $flow_id,
					'generation_argument_index' => FlowScheduling::GENERATION_ARGUMENT_INDEX,
				);
				if ( null !== $window_hours ) {
					$options['distribution_window_seconds'] = $window_hours * HOUR_IN_SECONDS;
				}
				if ( 'cron' === $scheduling['interval'] ) {
					$options['cron_expression'] = $scheduling['cron_expression'];
				}

				if ( ! empty( $flow['force_reconcile'] ) ) {
					$result = FlowScheduling::handle_scheduling_update( $flow_id, $scheduling, true );
				} else {
					$result = RecurringScheduler::ensureSchedule(
						FlowScheduling::FLOW_HOOK,
						array( $flow_id ),
						(string) $scheduling['interval'],
						$options
					);
				}

				if ( is_wp_error( $result ) ) {
					++$failed;
					$status = 'error';
					$error  = $result->get_error_message();
				} else {
					++$repaired;
					$status = 'repaired';
				}
			}

			$this->appendDetail( $details, $flow, $status, $error );
		}

		return array(
			'success'                   => 0 === $failed && empty( $blocked ),
			'transient'                 => $failed > 0 || ! empty( $blocked ),
			'applied'                   => $apply,
			'total'                     => count( $eligible ) + count( $invalid ) + count( $drift_cleanup ) + $excluded_count,
			'eligible'                  => count( $eligible ),
			'covered'                   => count( $coverage['covered'] ),
			'missing'                   => $missing_count,
			'blocked'                   => count( $blocked ),
			'repaired'                  => $repaired,
			'failed'                    => $failed,
			'remaining_missing'         => ( $apply ? $failed : $missing_count ) + count( $blocked ),
			'invalid'                   => count( $invalid ),
			'excluded'                  => $excluded_count,
			'distribution_window_hours' => $window_hours ?? 1,
			'details'                   => $details,
			'details_truncated'         => count( $invalid ) + count( $blocked ) + $missing_count > self::MAX_DETAILS,
		);
	}

	/**
	 * Resolve and validate the persisted recurrence semantics.
	 *
	 * @param array $scheduling Persisted scheduling configuration.
	 * @return int|string|\WP_Error Expected interval seconds or cron expression.
	 */
	private function expectedRecurrence( array $scheduling ) {
		$interval = (string) ( $scheduling['interval'] ?? '' );
		if ( 'cron' === $interval ) {
			$expression = (string) ( $scheduling['cron_expression'] ?? '' );
			if ( '' === $expression || ! RecurringScheduler::isValidCronExpression( $expression ) ) {
				return new \WP_Error( 'invalid_cron_definition', 'Cron schedule is missing a valid cron_expression.' );
			}
			return $expression;
		}

		if ( RecurringScheduler::looksLikeCronExpression( $interval ) ) {
			return RecurringScheduler::isValidCronExpression( $interval )
				? $interval
				: new \WP_Error( 'invalid_cron_definition', 'Flow schedule contains an invalid cron expression.' );
		}

		$resolved  = RecurringScheduler::resolveIntervalAlias( $interval );
		$intervals = apply_filters( 'datamachine_scheduler_intervals', array() );
		$seconds   = (int) ( $intervals[ $resolved ]['seconds'] ?? 0 );
		if ( $seconds <= 0 ) {
			return new \WP_Error( 'unknown_interval_definition', sprintf( 'Unknown recurring interval: %s.', $interval ) );
		}

		return $seconds;
	}

	/**
	 * Read lightweight coverage rows, preferring a direct bounded table query.
	 *
	 * @return array|\WP_Error Coverage rows or a transient query error.
	 */
	private function getCoverageRows() {
		$rows = $this->getDirectCoverageRows();
		if ( null === $rows ) {
			$rows = $this->getApiCoverageRows();
		}

		if ( ! is_array( $rows ) ) {
			return new \WP_Error( 'flow_schedule_coverage_unavailable', 'Unable to query Action Scheduler flow coverage.' );
		}

		if ( count( $rows ) > self::MAX_COVERAGE_ROWS ) {
			return new \WP_Error( 'flow_schedule_coverage_too_large', 'Flow schedule coverage exceeded the bounded audit row limit; clean duplicate Action Scheduler rows before retrying.' );
		}

		return $rows;
	}

	/**
	 * Query Action Scheduler's owned tables without hydrating action objects.
	 *
	 * @return array|null Rows, or null when direct querying is unavailable.
	 */
	private function getDirectCoverageRows(): ?array {
		global $wpdb;
		if ( ! class_exists( '\ActionScheduler' ) || ! class_exists( '\ActionScheduler_DBStore' ) ) {
			return null;
		}

		$store = \ActionScheduler::store();
		if ( ! is_object( $store ) || 'ActionScheduler_DBStore' !== get_class( $store ) ) {
			return null;
		}

		if ( empty( $wpdb->actionscheduler_actions ) || empty( $wpdb->actionscheduler_groups ) || empty( $wpdb->actionscheduler_claims ) ) {
			return null;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Read-only bounded diagnostics against Action Scheduler-owned tables.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.action_id, a.status, a.args, a.extended_args, a.schedule, a.last_attempt_gmt, c.date_created_gmt AS claim_created_gmt
				FROM %i a
				INNER JOIN %i g ON g.group_id = a.group_id
				LEFT JOIN %i c ON c.claim_id = a.claim_id
				WHERE a.hook = %s AND g.slug = %s AND a.status IN (%s, %s)
				ORDER BY a.action_id DESC LIMIT %d',
				$wpdb->actionscheduler_actions,
				$wpdb->actionscheduler_groups,
				$wpdb->actionscheduler_claims,
				FlowScheduling::FLOW_HOOK,
				RecurringScheduler::GROUP,
				'pending',
				'in-progress',
				self::MAX_COVERAGE_ROWS + 1
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		if ( is_array( $rows ) ) {
			foreach ( $rows as &$row ) {
				$row['timing_authoritative'] = true;
			}
		}

		return is_array( $rows ) ? $rows : null;
	}

	/**
	 * Portable bounded fallback through Action Scheduler's public query API.
	 *
	 * @return array|null Coverage rows, or null on failure.
	 */
	private function getApiCoverageRows(): ?array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( '\ActionScheduler' ) ) {
			return null;
		}

		$store = \ActionScheduler::store();
		if ( ! is_object( $store ) || ! method_exists( $store, 'fetch_action' ) ) {
			return null;
		}

		$rows = array();
		foreach ( array( 'pending', 'in-progress' ) as $status ) {
			$action_ids = as_get_scheduled_actions(
				array(
					'hook'     => FlowScheduling::FLOW_HOOK,
					'group'    => RecurringScheduler::GROUP,
					'status'   => $status,
					'per_page' => self::MAX_COVERAGE_ROWS + 1 - count( $rows ),
				),
				'ids'
			);
			if ( ! is_array( $action_ids ) ) {
				return null;
			}

			foreach ( $action_ids as $action_id ) {
				try {
					$action = $store->fetch_action( $action_id );
				} catch ( \Throwable $throwable ) {
					unset( $throwable );
					return null;
				}
				if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) || ! method_exists( $action, 'get_schedule' ) ) {
					return null;
				}

				$rows[] = array(
					'status'               => $status,
					'action_args'          => $action->get_args(),
					'schedule_object'      => $action->get_schedule(),
					'timing_authoritative' => false,
				);
			}
			if ( count( $rows ) > self::MAX_COVERAGE_ROWS ) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * Classify flow coverage by action ownership, freshness, and recurrence.
	 *
	 * @param array $rows Coverage rows.
	 * @param array $eligible Eligible flow definitions.
	 * @return array{covered:array<int,true>,blocked:array<int,string>} Coverage classification.
	 */
	private function classifyCoverageRows( array $rows, array $eligible ): array {
		$expected = array();
		foreach ( $eligible as $flow ) {
			$expected[ (int) $flow['flow_id'] ] = $flow['expected_recurrence'];
		}

		$coverage = array();
		foreach ( $rows as $row ) {
			$args    = $row['action_args'] ?? $this->decodeActionArgs( $row );
			$flow_id = is_array( $args ) && isset( $args[0] ) ? (int) $args[0] : 0;
			if ( $flow_id <= 0 || ! array_key_exists( $flow_id, $expected ) ) {
				continue;
			}
			if ( ! isset( $coverage[ $flow_id ] ) ) {
				$coverage[ $flow_id ] = array(
					'total'       => 0,
					'valid'       => 0,
					'in_progress' => 0,
				);
			}
			++$coverage[ $flow_id ]['total'];
			if ( isset( $args[ FlowScheduling::GENERATION_ARGUMENT_INDEX ] )
				&& ! RecurringScheduler::isActionGenerationCurrent(
					FlowScheduling::FLOW_HOOK,
					array( $flow_id ),
					RecurringScheduler::GROUP,
					$args[ FlowScheduling::GENERATION_ARGUMENT_INDEX ]
				) ) {
				continue;
			}

			if ( 'in-progress' === ( $row['status'] ?? '' ) ) {
				++$coverage[ $flow_id ]['in_progress'];
				if ( $this->isStaleInProgress( $row ) ) {
					$coverage[ $flow_id ]['blocked'] = empty( $row['timing_authoritative'] )
						? 'In-progress Action Scheduler ownership timing is unavailable; recover the action before reconciling schedules.'
						: 'A stale in-progress Action Scheduler action still owns this flow; recover the action before reconciling schedules.';
					continue;
				}
			}

			$schedule = $row['schedule_object'] ?? maybe_unserialize( $row['schedule'] ?? null );
			if ( $this->scheduleMatches( $schedule, $expected[ $flow_id ] ) ) {
				++$coverage[ $flow_id ]['valid'];
			}
		}

		$covered = array();
		$blocked = array();
		foreach ( $coverage as $flow_id => $counts ) {
			if ( ! empty( $counts['blocked'] ) ) {
				$blocked[ $flow_id ] = $counts['blocked'];
			} elseif ( $counts['in_progress'] > 0 && ( 1 !== $counts['total'] || 1 !== $counts['in_progress'] || 1 !== $counts['valid'] ) ) {
				$blocked[ $flow_id ] = 'In-progress Action Scheduler ownership is mixed or does not match the persisted schedule; recover the action before reconciling schedules.';
			} elseif ( 1 === $counts['total'] && 1 === $counts['valid'] ) {
				$covered[ $flow_id ] = true;
			}
		}

		return array(
			'covered' => $covered,
			'blocked' => $blocked,
		);
	}

	/**
	 * Decode Action Scheduler's compact or extended JSON args.
	 *
	 * @param array $row Action row.
	 * @return array|null Decoded args.
	 */
	private function decodeActionArgs( array $row ): ?array {
		$json = ! empty( $row['extended_args'] ) ? $row['extended_args'] : ( $row['args'] ?? '' );
		$args = json_decode( (string) $json, true );
		return is_array( $args ) ? $args : null;
	}

	/**
	 * Determine whether an in-progress action is older than two hours.
	 *
	 * Missing timing is treated as stale so a custom-store action cannot suppress
	 * repair forever when authoritative timing is unavailable.
	 *
	 * @param array $row Action row.
	 * @return bool True when available timing proves the action is stale.
	 */
	private function isStaleInProgress( array $row ): bool {
		if ( empty( $row['timing_authoritative'] ) ) {
			return true;
		}

		$timestamps = array_filter(
			array(
				(int) ( $row['last_attempt_timestamp'] ?? 0 ),
				strtotime( (string) ( $row['last_attempt_gmt'] ?? '' ) ),
				strtotime( (string) ( $row['claim_created_gmt'] ?? '' ) ),
			)
		);
		if ( empty( $timestamps ) ) {
			return true;
		}

		return max( $timestamps ) < time() - self::RUNNING_STALE_AFTER;
	}

	/**
	 * Compare a stored Action Scheduler schedule with persisted semantics.
	 *
	 * @param mixed      $schedule Stored Action Scheduler schedule object.
	 * @param int|string $expected Expected seconds or cron expression.
	 * @return bool True when recurrence semantics match.
	 */
	private function scheduleMatches( $schedule, $expected ): bool {
		if ( ! is_object( $schedule ) || ! method_exists( $schedule, 'is_recurring' ) || ! $schedule->is_recurring() || ! method_exists( $schedule, 'get_recurrence' ) ) {
			return false;
		}

		$recurrence = $schedule->get_recurrence();
		return is_int( $expected )
			? (int) $recurrence === $expected
			: (string) $recurrence === $expected;
	}

	/**
	 * Append one bounded detail row.
	 *
	 * @param array  $details Detail accumulator.
	 * @param array  $flow Flow row.
	 * @param string $status Detail status.
	 * @param string $error Optional error.
	 * @return void
	 */
	private function appendDetail( array &$details, array $flow, string $status, string $error = '' ): void {
		if ( count( $details ) >= self::MAX_DETAILS ) {
			return;
		}

		$details[] = array(
			'flow_id'   => (int) $flow['flow_id'],
			'flow_name' => (string) ( $flow['flow_name'] ?? '' ),
			'interval'  => (string) ( $flow['scheduling_config']['interval'] ?? '' ),
			'status'    => $status,
			'error'     => $error,
		);
	}

	/**
	 * Build a consistent failure response.
	 *
	 * @param string $error     Failure message.
	 * @param bool   $transient Whether deferred reconciliation should retry.
	 * @param string $code      Machine-readable failure code.
	 * @return array Failure report.
	 */
	private function failure( string $error, bool $transient = false, string $code = 'invalid_request' ): array {
		return array(
			'success'   => false,
			'transient' => $transient,
			'code'      => $code,
			'error'     => $error,
			'details'   => array(),
		);
	}
}
