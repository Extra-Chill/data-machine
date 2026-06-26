<?php
/**
 * Action Scheduler Queue Tuning
 *
 * Applies user-configurable tuning settings to Action Scheduler's queue runner.
 * Enables faster parallel execution by allowing multiple concurrent batches.
 *
 * Settings:
 * - concurrent_batches: Number of batches that can run simultaneously (default: 3)
 * - batch_size: Number of actions claimed per batch (default: 25)
 * - time_limit: Maximum seconds per batch execution (default: 60)
 *
 * @package DataMachine\Core\ActionScheduler
 * @since 0.21.0
 */

namespace DataMachine\Core\ActionScheduler;

use DataMachine\Core\PluginSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Get default queue tuning values.
 *
 * These defaults are more aggressive than Action Scheduler's ultra-conservative
 * defaults (1 batch, 25 size, 30s limit) but still safe for most environments.
 *
 * @return array
 */
function datamachine_get_queue_tuning_defaults(): array {
	return PluginSettings::getDefaultQueueTuning();
}

/**
 * Apply queue tuning filters.
 *
 * Reads settings once and applies all three filters from the cached values.
 * Avoids repeated get_option() calls on every page load.
 */
add_action(
	'action_scheduler_init',
	function () {
		$defaults = datamachine_get_queue_tuning_defaults();
		$tuning   = \DataMachine\Core\PluginSettings::get( 'queue_tuning', array() );

		$concurrent = isset( $tuning['concurrent_batches'] ) ? absint( $tuning['concurrent_batches'] ) : $defaults['concurrent_batches'];
		$batch_size = isset( $tuning['batch_size'] ) ? absint( $tuning['batch_size'] ) : $defaults['batch_size'];
		$time_limit = isset( $tuning['time_limit'] ) ? absint( $tuning['time_limit'] ) : $defaults['time_limit'];

		add_filter( 'action_scheduler_queue_runner_concurrent_batches', function () use ( $concurrent ) {
			return $concurrent;
		} );

		add_filter( 'action_scheduler_queue_runner_batch_size', function () use ( $batch_size ) {
			return $batch_size;
		} );

		add_filter( 'action_scheduler_queue_runner_time_limit', function () use ( $time_limit ) {
			return $time_limit;
		} );

		datamachine_enable_cron_async_dispatch();
		datamachine_install_deadlock_resilient_runner();
	}
);

/**
 * Make the Action Scheduler queue runner resilient to transient claim deadlocks.
 *
 * DM runs the queue runner with concurrent batches enabled (default 3) and also
 * drops Action Scheduler's is_admin() gate on the async dispatch chain (see
 * datamachine_enable_cron_async_dispatch()), so multiple runner processes can
 * call ActionScheduler_DBStore::claim_actions() at the same time. That method
 * issues an `UPDATE ... JOIN` against the actions table; when several of those
 * run concurrently against the same hot rows, MariaDB/MySQL can return a
 * deadlock ("Deadlock found when trying to get lock; try restarting transaction").
 *
 * Action Scheduler treats that as fatal: claim_actions() throws an uncaught
 * RuntimeException from ActionScheduler_DBStore.php, which bubbles out of
 * ActionScheduler_QueueRunner::run() and crashes the request with a PHP fatal.
 *
 * A deadlock is transient and retryable by design — the database itself tells us
 * to restart the transaction. So instead of letting it become a fatal, we replace
 * the default queue-runner callback with one that retries run() with an
 * exponential, jittered backoff when the failure is a transient deadlock, and
 * re-throws anything else unchanged. We cannot patch the vendored Action Scheduler
 * library, so this guard lives in DM where it owns the runner wiring (mirroring how
 * datamachine_enable_cron_async_dispatch() already swaps an AS shutdown callback).
 *
 * Two correctness details this function has to get right:
 *
 *  1. Idempotency. `action_scheduler_init` can fire more than once in a request
 *     (notably on multisite, where AS may initialize across switch_to_blog()
 *     contexts). Without a guard, each fire would stack another wrapper closure on
 *     `action_scheduler_run_queue` — the queue would then run two-plus times per
 *     dispatch, multiplying the concurrent claim_actions() pressure that causes the
 *     deadlock in the first place. A static guard makes the swap happen once per
 *     request.
 *
 *  2. Sufficient retry budget. Under concurrent batches (default 3) the same hot
 *     rows can deadlock several times in quick succession, so a 3-attempt /
 *     ~150ms-ceiling budget can still exhaust and surface the fatal. We widen the
 *     budget to 5 attempts with exponential backoff (up to ~800ms + jitter), which
 *     stays well inside the batch time limit while giving competing transactions
 *     room to commit and release their locks.
 *
 * @since 0.153.6
 */
function datamachine_install_deadlock_resilient_runner(): void {
	static $installed = false;

	// `action_scheduler_init` can fire multiple times per request; only swap once.
	if ( $installed ) {
		return;
	}
	$installed = true;

	$runner = \ActionScheduler::runner();

	// Swap AS's default `run` callback for a deadlock-resilient wrapper.
	remove_action( 'action_scheduler_run_queue', array( $runner, 'run' ) );

	add_action(
		'action_scheduler_run_queue',
		function ( $context = '' ) use ( $runner ) {
			$max_attempts = 5;
			$attempt      = 0;

			while ( true ) {
				$attempt++;

				try {
					return $runner->run( $context );
				} catch ( \RuntimeException $e ) {
					if ( ! datamachine_is_transient_deadlock( $e ) || $attempt >= $max_attempts ) {
						// Not retryable, or we're out of attempts — let it surface.
						throw $e;
					}

					// Transient deadlock: back off with exponential, jittered delay,
					// then retry. 50/100/200/400ms (+ up to 50ms jitter) caps total
					// backoff under ~1s — comfortably inside the batch time limit —
					// while giving the competing transaction time to commit and
					// release its locks.
					$backoff_us = ( 50000 * ( 2 ** ( $attempt - 1 ) ) ) + random_int( 0, 50000 );
					usleep( $backoff_us );
				}
			}
		},
		10,
		1
	);
}

/**
 * Determine whether an exception represents a transient, retryable DB deadlock.
 *
 * Action Scheduler surfaces the raw database error inside the RuntimeException
 * message (see ActionScheduler_DBStore::claim_actions()), so we match on the
 * deadlock / lock-wait signatures the database emits.
 *
 * @param \Throwable $e Exception thrown while claiming actions.
 * @return bool True when the failure is a transient deadlock or lock-wait timeout.
 */
function datamachine_is_transient_deadlock( \Throwable $e ): bool {
	$message = $e->getMessage();

	return false !== stripos( $message, 'Deadlock found when trying to get lock' )
		|| false !== stripos( $message, 'Lock wait timeout exceeded' );
}

/**
 * Enable async request dispatch from WP-Cron and CLI contexts.
 *
 * Action Scheduler's maybe_dispatch_async_request() requires is_admin() to be true,
 * which means the async runner chain (concurrent batches) never starts from wp-cron.php
 * or WP-CLI. Since DM uses system cron (DISABLE_WP_CRON=true) and nobody regularly
 * visits wp-admin on subsites, the queue processes ~1 action per 5-minute cron cycle
 * instead of using the configured concurrent batches.
 *
 * Fix: replace the shutdown callback with one that drops the is_admin() gate.
 * The OptionLock (60-second throttle) and has_maximum_concurrent_batches() check
 * remain in place, so this is safe on frontend requests.
 *
 * @since 0.41.0
 */
function datamachine_enable_cron_async_dispatch(): void {
	$runner = \ActionScheduler::runner();

	// Remove the original gated shutdown callback.
	remove_action( 'shutdown', array( $runner, 'maybe_dispatch_async_request' ) );

	// Add our own that skips the is_admin() check.
	add_action(
		'shutdown',
		function () use ( $runner ) {
			// The OptionLock already throttles to once per 60 seconds — this is the
			// same guard AS uses, minus the is_admin() gate that blocks cron contexts.
			if (
				! \ActionScheduler::lock()->is_locked( 'async-request-runner' )
				&& \ActionScheduler::lock()->set( 'async-request-runner' )
			) {
				$ref           = new \ReflectionProperty( get_class( $runner ), 'async_request' );
				$async_request = $ref->getValue( $runner );
				$async_request->maybe_dispatch();
			}
		}
	);
}
