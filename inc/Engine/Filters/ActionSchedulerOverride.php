<?php
/**
 * Action Scheduler Override
 *
 * Forces Action Scheduler to use DBStore directly, bypassing the legacy
 * HybridStore migration wrapper that causes duplicate database queries.
 *
 * @package DataMachine\Engine\Filters
 * @since 0.20.4
 */

namespace DataMachine\Engine\Filters;

defined( 'ABSPATH' ) || exit;

/**
 * Override Action Scheduler to always use DBStore.
 *
 * The HybridStore exists to support migration from the old post-based
 * action storage to custom DB tables. This migration is complete and
 * the wrapper only adds overhead (duplicate queries on every fetch).
 *
 * This filter runs at priority 200 to override Action Scheduler's
 * internal migration controller (priority 100).
 */
class ActionSchedulerOverride {

	/**
	 * Initialize the override.
	 */
	public static function init(): void {
		// Force DBStore, bypassing HybridStore wrapper.
		add_filter( 'action_scheduler_store_class', array( __CLASS__, 'force_db_store' ), 200 );

		// Mark migration as complete to prevent any migration scheduling.
		add_filter( 'action_scheduler_migration_dependencies_met', '__return_false', 200 );
	}

	/**
	 * Force Action Scheduler to use DBStore directly.
	 *
	 * @param string $class The store class name.
	 * @return string Always returns DBStore class.
	 */
	public static function force_db_store( string $class ): string {
		return 'ActionScheduler_DBStore';
	}
}

// Initialize on plugins_loaded before Action Scheduler hooks in.
add_action( 'plugins_loaded', array( ActionSchedulerOverride::class, 'init' ), 0 );
