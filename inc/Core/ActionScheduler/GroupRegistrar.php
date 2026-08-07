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
	 * Ensure Action Scheduler can resolve Data Machine's group for claims/runs.
	 */
	public static function ensureDataMachineGroup(): void {
		self::ensureCustomTableGroup( self::GROUP );
		self::ensurePostStoreGroup( self::GROUP );
	}

	/**
	 * Ensure the custom-table data store has the group row.
	 */
	private static function ensureCustomTableGroup( string $group ): void {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return;
		}

		$table = isset( $wpdb->actionscheduler_groups )
			? (string) $wpdb->actionscheduler_groups
			: $wpdb->prefix . 'actionscheduler_groups';
		if ( '' === $table || ! self::tableExists( $table ) ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Action Scheduler validates this table before claiming.
		$group_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT group_id FROM %i WHERE slug = %s LIMIT 1', $table, $group ) );
		if ( $group_id > 0 ) {
			return;
		}

		$wpdb->insert( $table, array( 'slug' => $group ), array( '%s' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
