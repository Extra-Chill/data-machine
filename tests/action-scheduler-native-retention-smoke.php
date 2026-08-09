<?php
/**
 * Smoke coverage for Action Scheduler's native retention backstop (#2792).
 *
 * Run with: php tests/action-scheduler-native-retention-smoke.php
 */

declare( strict_types=1 );

namespace DataMachine\Engine\Tasks {
	class TaskScheduler {
		public static function schedule( string $task_type, array $params ) {
			return false;
		}
	}
}

namespace DataMachine\Engine\AI\System\Tasks\Retention {
	abstract class RetentionTask {}

	class RetentionCleanup {
		public const TASK_AS_ACTIONS = 'retention_as_actions';

		public static function actionSchedulerMaxAgeDays(): int {
			return 7;
		}

		public static function cleanupActionSchedulerActions(): array {
			return array( 'deleted' => 0 );
		}
	}
}

namespace {
	use DataMachine\Engine\AI\System\Tasks\Retention\RetentionActionSchedulerTask;

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/../' );
	}
	if ( ! defined( 'DAY_IN_SECONDS' ) ) {
		define( 'DAY_IN_SECONDS', 86400 );
	}

	$GLOBALS['__as_retention_blog_id']  = 1;
	$GLOBALS['__as_retention_filters']  = array();
	$GLOBALS['__as_retention_options']  = array();
	$GLOBALS['__as_registered_filters'] = array();

	function add_filter( string $hook, callable $callback, int $priority = 10 ): void {
		$GLOBALS['__as_registered_filters'][ $hook ] = array(
			'callback' => $callback,
			'priority' => $priority,
		);
	}

	function apply_filters( string $hook, $value ) {
		foreach ( $GLOBALS['__as_retention_filters'][ $hook ] ?? array() as $callback ) {
			$value = $callback( $value );
		}
		return $value;
	}

	function get_option( string $name, $default = false ) {
		$blog_id = $GLOBALS['__as_retention_blog_id'];
		return $GLOBALS['__as_retention_options'][ $blog_id ][ $name ] ?? $default;
	}

	function do_action( ...$args ): void {}

	$root     = dirname( __DIR__ );
	$provider = file_get_contents( $root . '/inc/Engine/AI/System/SystemAgentServiceProvider.php' ) ?: '';
	$task     = file_get_contents( $root . '/inc/Engine/AI/System/Tasks/Retention/RetentionActionSchedulerTask.php' ) ?: '';
	$failed   = 0;
	$total    = 0;

	$assert = static function ( bool $condition, string $message ) use ( &$failed, &$total ): void {
		++$total;
		if ( $condition ) {
			echo "  [PASS] {$message}\n";
			return;
		}

		++$failed;
		echo "  [FAIL] {$message}\n";
	};

	echo "=== action-scheduler-native-retention-smoke ===\n";

	require_once $root . '/inc/Engine/AI/System/Tasks/Retention/RetentionActionSchedulerTask.php';

	$assert(
		str_contains( $provider, 'RetentionActionSchedulerTask::registerNativeRetention();' ),
		'provider registers the native retention backstop in each site context'
	);

	RetentionActionSchedulerTask::registerNativeRetention();
	$period_filter = $GLOBALS['__as_registered_filters']['action_scheduler_retention_period'] ?? array();
	$batch_filter  = $GLOBALS['__as_registered_filters']['action_scheduler_cleanup_batch_size'] ?? array();
	$assert(
		PHP_INT_MAX === ( $period_filter['priority'] ?? null )
			&& PHP_INT_MAX === ( $batch_filter['priority'] ?? null ),
		'native retention filters enforce final safety bounds'
	);

	$GLOBALS['__as_retention_blog_id'] = 7;
	$assert(
		7 * DAY_IN_SECONDS === RetentionActionSchedulerTask::filterNativeRetentionPeriod( 2678400 ),
		'blog 7 shortens Action Scheduler native retention to seven days'
	);
	$assert(
		DAY_IN_SECONDS === RetentionActionSchedulerTask::filterNativeRetentionPeriod( DAY_IN_SECONDS ),
		'a shorter retention period from another integration is preserved'
	);
	$assert(
		7 * DAY_IN_SECONDS === RetentionActionSchedulerTask::filterNativeRetentionPeriod( 0 ),
		'non-positive retention is restored instead of being treated as disabled'
	);
	$assert(
		250 === RetentionActionSchedulerTask::filterNativeCleanupBatchSize( 20 )
			&& 1000 === RetentionActionSchedulerTask::filterNativeCleanupBatchSize( 5000 ),
		'native cleanup throughput has a bounded 250-to-1000 row per-phase range'
	);
	$queue_cleaner = file_get_contents( $root . '/vendor/woocommerce/action-scheduler/classes/ActionScheduler_QueueCleaner.php' ) ?: '';
	$assert(
		str_contains( $queue_cleaner, 'foreach ( $statuses_to_purge as $status )' )
			&& str_contains( $queue_cleaner, "apply_filters( 'action_scheduler_cleanup_batch_size'" ),
		'test evidence pins Action Scheduler batch scope to each cleanup status and maintenance phase'
	);

	$GLOBALS['__as_retention_blog_id'] = 2;
	$GLOBALS['__as_retention_options'][2]['datamachine_settings'] = array( 'retention_as_actions_enabled' => false );
	$assert(
		2678400 === RetentionActionSchedulerTask::filterNativeRetentionPeriod( 2678400 )
			&& 20 === RetentionActionSchedulerTask::filterNativeCleanupBatchSize( 20 ),
		'a site-scoped disabled setting leaves Action Scheduler defaults untouched'
	);

	$GLOBALS['__as_retention_blog_id'] = 1;
	$assert(
		7 * DAY_IN_SECONDS === RetentionActionSchedulerTask::filterNativeRetentionPeriod( 2678400 ),
		'main-site settings do not leak from another multisite blog'
	);
	$assert(
		str_contains( $task, '$result = RetentionCleanup::cleanupActionSchedulerActions();' )
			&& str_contains( $task, '$this->maybeScheduleCatchUp( $result );' ),
		'the full recurring cleanup and catch-up path remain unchanged'
	);

	if ( $failed > 0 ) {
		echo "\naction-scheduler-native-retention-smoke failed: {$failed}/{$total} assertions failed.\n";
		exit( 1 );
	}

	echo "\naction-scheduler-native-retention-smoke passed: {$total} assertions.\n";
}
