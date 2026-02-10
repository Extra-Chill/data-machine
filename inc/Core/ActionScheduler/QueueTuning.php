<?php
/**
 * Action Scheduler Queue Tuning
 *
 * Applies user-configurable tuning settings to Action Scheduler's queue runner.
 * Enables faster parallel execution by allowing multiple concurrent batches.
 *
 * Settings:
 * - concurrent_batches: Number of batches that can run simultaneously (default: 1)
 * - batch_size: Number of actions claimed per batch (default: 25)
 * - time_limit: Maximum seconds per batch execution (default: 30)
 *
 * @package DataMachine\Core\ActionScheduler
 * @since 0.21.0
 */

namespace DataMachine\Core\ActionScheduler;

use DataMachine\Core\PluginSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Get a queue tuning setting with fallback to default.
 *
 * @param string $key     Setting key (concurrent_batches, batch_size, time_limit).
 * @param int    $default Default value if not set.
 * @return int
 */
function datamachine_get_queue_tuning( string $key, int $default ): int {
	$tuning = PluginSettings::get( 'queue_tuning', array() );
	return isset( $tuning[ $key ] ) ? absint( $tuning[ $key ] ) : $default;
}

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
 * Filter: Number of concurrent batches allowed.
 *
 * Higher values = more parallel execution, but higher server load.
 * Recommended: 2-5 depending on server resources.
 */
add_filter(
	'action_scheduler_queue_runner_concurrent_batches',
	function ( $default ) {
		$defaults = datamachine_get_queue_tuning_defaults();
		return datamachine_get_queue_tuning( 'concurrent_batches', $defaults['concurrent_batches'] );
	}
);

/**
 * Filter: Number of actions claimed per batch.
 *
 * Larger batches = fewer claim operations, but longer individual batch times.
 * For AI-heavy workloads, smaller batches with more concurrency often works better.
 */
add_filter(
	'action_scheduler_queue_runner_batch_size',
	function ( $default ) {
		$defaults = datamachine_get_queue_tuning_defaults();
		return datamachine_get_queue_tuning( 'batch_size', $defaults['batch_size'] );
	}
);

/**
 * Filter: Maximum execution time per batch in seconds.
 *
 * Should be less than PHP's max_execution_time to allow graceful completion.
 * AI steps with external API calls may need longer limits.
 */
add_filter(
	'action_scheduler_queue_runner_time_limit',
	function ( $default ) {
		$defaults = datamachine_get_queue_tuning_defaults();
		return datamachine_get_queue_tuning( 'time_limit', $defaults['time_limit'] );
	}
);

/**
 * Log queue tuning settings on init (debug level).
 */
add_action(
	'action_scheduler_init',
	function () {
		$defaults = datamachine_get_queue_tuning_defaults();
		$settings = array(
			'concurrent_batches' => datamachine_get_queue_tuning( 'concurrent_batches', $defaults['concurrent_batches'] ),
			'batch_size'         => datamachine_get_queue_tuning( 'batch_size', $defaults['batch_size'] ),
			'time_limit'         => datamachine_get_queue_tuning( 'time_limit', $defaults['time_limit'] ),
		);

		do_action(
			'datamachine_log',
			'debug',
			'Action Scheduler queue tuning applied',
			$settings
		);
	}
);
