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
	}
);

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
				// Access the async_request property via reflection since it's protected.
				$ref = new \ReflectionProperty( get_class( $runner ), 'async_request' );
				$ref->setAccessible( true );
				$async_request = $ref->getValue( $runner );
				$async_request->maybe_dispatch();
			}
		}
	);
}
