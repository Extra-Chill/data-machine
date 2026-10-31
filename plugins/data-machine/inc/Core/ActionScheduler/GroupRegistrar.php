<?php
/**
 * Action Scheduler group registration helpers.
 *
 * @package DataMachine\Core\ActionScheduler
 */

namespace DataMachine\Core\ActionScheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the Data Machine Action Scheduler group present in every AS store.
 */
class GroupRegistrar {

	public const GROUP = 'data-machine';

	/**
	 * Per-site option: unix time the Data Machine group was last confirmed.
	 */
	public const GROUP_VERIFIED_OPTION = 'datamachine_as_group_verified';

	/**
	 * How long a confirmation is trusted before the probe runs again.
	 */
	public const VERIFY_TTL = 3600;

	/**
	 * Per-request entry point: ensure the group unless confirmed within the TTL.
	 */
	public static function ensureDataMachineGroupIfStale(): void {
		if ( self::recentlyVerified( self::GROUP_VERIFIED_OPTION ) ) {
			return;
		}

		if ( self::ensureCustomTableGroup( self::GROUP ) ) {
			self::ensurePostStoreGroup( self::GROUP );
			self::markVerified( self::GROUP_VERIFIED_OPTION );
			return;
		}

		self::ensurePostStoreGroup( self::GROUP );
	}

	/**
	 * Whether a verification option was written within the TTL.
	 */
	private static function recentlyVerified( string $option ): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}

		$verified_at = (int) get_option( $option, 0 );
		$age         = time() - $verified_at;

		return $verified_at > 0 && $age >= 0 && $age < self::VERIFY_TTL;
	}

	/**
	 * Record a successful verification for the current site.
	 */
	private static function markVerified( string $option ): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( $option, time(), true );
		}
	}

	/**
	 * Ensure Action Scheduler can resolve Data Machine's group for claims/runs.
	 */
	public static function ensureDataMachineGroup(): void {
		self::ensureCustomTableGroup( self::GROUP );
		self::ensurePostStoreGroup( self::GROUP );
	}

	/**
	 * Check whether the custom-table store can safely answer queue queries.
	 */
	public static function tablesExist(): bool {
		if ( ! class_exists( '\ActionScheduler_StoreSchema' ) || ! class_exists( '\ActionScheduler_LoggerSchema' ) ) {
			return false;
		}

		$store_schema  = new \ActionScheduler_StoreSchema();
		$logger_schema = new \ActionScheduler_LoggerSchema();

		return $store_schema->tables_exist() && $logger_schema->tables_exist();
	}

	/**
	 * Ensure the custom-table data store has the group row.
	 *
	 * @return bool Whether the group row is confirmed present.
	 */
	private static function ensureCustomTableGroup( string $group ): bool {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return false;
		}

		$table = isset( $wpdb->actionscheduler_groups )
			? (string) $wpdb->actionscheduler_groups
			: $wpdb->prefix . 'actionscheduler_groups';
		if ( '' === $table || ! self::tableExists( $table ) ) {
			return false;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Action Scheduler validates this table before claiming.
		$group_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT group_id FROM %i WHERE slug = %s LIMIT 1', $table, $group ) );
		if ( $group_id > 0 ) {
			return true;
		}

		$inserted = $wpdb->insert( $table, array( 'slug' => $group ), array( '%s' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return false !== $inserted && (bool) $inserted;
	}

	/**
	 * Ensure the legacy post-store taxonomy has the group term.
	 */
	private static function ensurePostStoreGroup( string $group ): void {
		$has_post_store = class_exists( '\ActionScheduler_wpPostStore', false )
			&& defined( 'ActionScheduler_wpPostStore::GROUP_TAXONOMY' );
		$taxonomy       = $has_post_store
			? \ActionScheduler_wpPostStore::GROUP_TAXONOMY
			: 'action-group';
		if ( ! function_exists( 'taxonomy_exists' ) || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		if ( function_exists( 'term_exists' ) && term_exists( $group, $taxonomy ) ) {
			return;
		}

		if ( function_exists( 'wp_insert_term' ) ) {
			wp_insert_term( $group, $taxonomy, array( 'slug' => $group ) );
		}
	}

	/**
	 * Check whether a table exists before touching it.
	 */
	private static function tableExists( string $table ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Guarding optional Action Scheduler tables.
		return $table === (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
