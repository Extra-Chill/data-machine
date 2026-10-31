<?php
/**
 * Merged-Bill Resolver Flow
 *
 * Action Scheduler wiring for the merged-bill resolver pipeline (issue #256).
 *
 * Architecture:
 *   1. A recurring scheduled action runs the detector daily, persisting
 *      candidate pairs to datamachine_pending_actions with kind
 *      'merged_bill_resolve'.
 *   2. The chat agent (or any orchestrator listening on
 *      `datamachine_events_merged_bill_pair_queued`) drains the queue by
 *      calling merged_bill_inspect → merged_bill_decide for each pending
 *      pair.
 *
 * We do NOT auto-merge from the cron hook. The decision step is always
 * agent-mediated per the issue's anti-pattern guidance: "the detector
 * flags; the agent decides".
 *
 * @package DataMachineEvents\Steps\MergedBills
 * @since   0.34.0
 */

namespace DataMachineEvents\Steps\MergedBills;

use DataMachineEvents\Abilities\MergedBillDetectAbilities;

defined( 'ABSPATH' ) || exit;

class MergedBillResolverFlow {

	public const SCAN_HOOK = 'datamachine_events_merged_bill_scan';

	private static bool $registered = false;

	/**
	 * Register hooks. Idempotent — safe to call multiple times.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( self::SCAN_HOOK, array( __CLASS__, 'runDetector' ), 10, 0 );

		// Schedule the recurring detector run once Action Scheduler is ready.
		// We register on init so any caller can also unschedule via the
		// standard as_unschedule_action() API if they want to take over the
		// schedule themselves.
		add_action( 'init', array( __CLASS__, 'maybeScheduleRecurring' ), 50 );
	}

	/**
	 * Ensure the recurring detector run is scheduled.
	 *
	 * Defaults to once per day. Operators can override the cadence via the
	 * `datamachine_events_merged_bill_scan_interval` filter (seconds).
	 *
	 * If Action Scheduler is unavailable we silently no-op — the CLI
	 * surface (`wp data-machine-events check merged-bills`) is still
	 * usable, the detector just won't run on its own.
	 */
	public static function maybeScheduleRecurring(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		/**
		 * Filter the detector scan cadence in seconds.
		 *
		 * Default: 86400 (1 day). Set to 0 to disable auto-scheduling.
		 *
		 * @since 0.34.0
		 *
		 * @param int $interval Seconds between scans.
		 */
		$interval = (int) apply_filters( 'datamachine_events_merged_bill_scan_interval', DAY_IN_SECONDS );

		if ( $interval <= 0 ) {
			return;
		}

		if ( false !== as_next_scheduled_action( self::SCAN_HOOK ) ) {
			return;
		}

		as_schedule_recurring_action(
			time() + 5 * MINUTE_IN_SECONDS,
			$interval,
			self::SCAN_HOOK,
			array(),
			'data-machine-events'
		);
	}

	/**
	 * Run the detector ability and announce any newly-queued pairs.
	 *
	 * Listeners on `datamachine_events_merged_bill_pair_queued` (e.g. the
	 * chat orchestrator) can pick up each new pair_id and dispatch an
	 * agent to inspect+decide.
	 */
	public static function runDetector(): void {
		/**
		 * Filter the detector parameters for the scheduled scan.
		 *
		 * @since 0.34.0
		 *
		 * @param array $params {
		 *     @type int $days_ahead Default 90.
		 *     @type int $threshold  Default 5.
		 *     @type int $limit      Default 50.
		 * }
		 */
		$params = (array) apply_filters(
			'datamachine_events_merged_bill_scan_params',
			array(
				'days_ahead' => MergedBillDetectAbilities::DEFAULT_DAYS_AHEAD,
				'threshold'  => MergedBillDetectAbilities::DEFAULT_THRESHOLD,
				'limit'      => MergedBillDetectAbilities::DEFAULT_LIMIT,
			)
		);

		$ability = new MergedBillDetectAbilities();
		$result  = $ability->execute( $params );

		do_action(
			'datamachine_log',
			'info',
			'Merged-bill detector scan complete.',
			array(
				'scanned_pairs'   => $result['scanned_pairs'] ?? 0,
				'queued'          => $result['queued'] ?? 0,
				'skipped_decided' => $result['skipped_decided'] ?? 0,
				'threshold'       => $result['threshold'] ?? 0,
			)
		);

		// Announce each queued pair so orchestrators can dispatch an agent.
		foreach ( ( $result['pairs'] ?? array() ) as $pair ) {
			if ( ( $pair['score'] ?? 0 ) < ( $result['threshold'] ?? PHP_INT_MAX ) ) {
				continue;
			}

			/**
			 * Fired for each merged-bill pair that has just been queued
			 * (or was already queued and re-detected this run).
			 *
			 * Listeners should resolve the pair by dispatching a chat
			 * agent or REST call to inspect+decide.
			 *
			 * @since 0.34.0
			 *
			 * @param array $pair {
			 *     @type string $pair_key
			 *     @type int    $post_a_id
			 *     @type int    $post_b_id
			 *     @type int    $venue_term_id
			 *     @type string $start_datetime
			 *     @type int    $score
			 *     @type array  $signals
			 * }
			 */
			do_action( 'datamachine_events_merged_bill_pair_queued', $pair );
		}
	}
}
