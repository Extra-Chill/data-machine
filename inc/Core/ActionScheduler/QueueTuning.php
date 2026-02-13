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
	return array(
		'concurrent_batches' => 3,  // AS default: 1
		'batch_size'         => 25, // AS default: 25 (keep same)
		'time_limit'         => 60, // AS default: 30
	);
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
	}
);
