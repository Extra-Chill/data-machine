<?php
/**
 * Uninstall Data Machine Plugin
 *
 * Handles cleanup for both single-site and multisite installations.
 * On multisite network uninstall, iterates all subsites.
 *
 * @package Data_Machine
 */

// If uninstall not called from WordPress, exit
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( is_multisite() ) {
	// Clean up every subsite on the network.
	$datamachine_sites = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $datamachine_sites as $datamachine_blog_id ) {
		switch_to_blog( $datamachine_blog_id );
		datamachine_uninstall_site();
		restore_current_blog();
	}

	// Drop network-scoped tables once (base_prefix), after every subsite is done.
	datamachine_uninstall_network_tables();

	// Clean up network-wide options (stored via get_site_option / update_site_option).
	datamachine_uninstall_network_options();
} else {
	datamachine_uninstall_site();
}

/**
 * Clean up all Data Machine data for a single site.
 *
 * Deletes options, tables, user meta, files, transients, and scheduled actions.
 */
function datamachine_uninstall_site() {
	global $wpdb;

	// --- Options ---

	// Core plugin settings.
	delete_option( 'datamachine_settings' );
	delete_option( 'datamachine_handler_defaults' );
	delete_option( 'datamachine_agent_ping_callback_token' );
	delete_option( 'datamachine_page_hook_suffixes' );

	// Unified auth data.
	delete_option( 'datamachine_auth_data' );

	// --- User meta ---

	$datamachine_pattern = 'datamachine_%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $datamachine_pattern ) );

	// --- Database tables ---

	if ( current_user_can( 'delete_plugins' ) || defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		// Drop per-site tables in reverse dependency order. The chat sessions
		// table is network-scoped (base_prefix) and is dropped once in
		// datamachine_uninstall_network_tables(), not here — dropping it per-site
		// would destroy the shared table on the first subsite uninstall.
		$datamachine_tables_to_drop = array(
			$wpdb->prefix . 'datamachine_processed_items',
			$wpdb->prefix . 'datamachine_jobs',
			$wpdb->prefix . 'datamachine_flows',
			$wpdb->prefix . 'datamachine_pipelines',
		);

		// On single-site, base_prefix === prefix, so the network table is dropped
		// here alongside the per-site tables (there is no separate network pass).
		if ( ! is_multisite() ) {
			$datamachine_tables_to_drop[] = $wpdb->base_prefix . 'datamachine_chat_sessions';
		}

		foreach ( $datamachine_tables_to_drop as $datamachine_table_name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $datamachine_table_name ) );
		}
	}

	// --- Files ---

	$datamachine_upload_dir = wp_upload_dir();

	// Agent files, pipeline files, context files.
	$datamachine_files_dir = trailingslashit( $datamachine_upload_dir['basedir'] ) . 'datamachine-files';
	if ( is_dir( $datamachine_files_dir ) ) {
		datamachine_recursive_delete( $datamachine_files_dir );
	}

	// Log files.
	$datamachine_logs_dir = trailingslashit( $datamachine_upload_dir['basedir'] ) . 'datamachine-logs';
	if ( is_dir( $datamachine_logs_dir ) ) {
		datamachine_recursive_delete( $datamachine_logs_dir );
	}

	// --- Scheduled actions ---

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'data-machine' );
	}

	// --- Transients ---

	delete_transient( 'datamachine_activation_notice' );

	// --- Cache ---

	wp_cache_flush();
}

/**
 * Drop network-scoped tables once on multisite uninstall.
 *
 * Chat sessions live on base_prefix (shared across the network, like the
 * agent identity tables), so the table must be dropped exactly once after
 * every subsite's per-site cleanup has run — never per-site, which would
 * destroy the shared table on the first subsite uninstall.
 */
function datamachine_uninstall_network_tables() {
	global $wpdb;

	if ( ! current_user_can( 'delete_plugins' ) && ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		return;
	}

	$datamachine_network_tables = array(
		$wpdb->base_prefix . 'datamachine_chat_sessions',
	);

	foreach ( $datamachine_network_tables as $datamachine_network_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $datamachine_network_table ) );
	}
}

/**
 * Clean up network-wide options on multisite.
 *
 * These are stored via get_site_option() / update_site_option() and shared
 * across all subsites on the network.
 */
function datamachine_uninstall_network_options() {
	$datamachine_network_options = array(
		'datamachine_image_generation_config',
		'datamachine_gsc_config',
		'datamachine_search_config',
		'datamachine_auth_data',
		'datamachine_network_settings',
		'datamachine_chat_sessions_network_migrated',
	);

	foreach ( $datamachine_network_options as $datamachine_option ) {
		delete_site_option( $datamachine_option );
	}
}

/**
 * Recursively delete a directory and its contents using WP_Filesystem.
 *
 * @param string $dir Directory path to delete.
 * @return bool True on success, false on failure.
 */
function datamachine_recursive_delete( $dir ) {
	global $wp_filesystem;

	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	if ( ! WP_Filesystem() ) {
		return false;
	}

	if ( ! $wp_filesystem->is_dir( $dir ) ) {
		return false;
	}

	return $wp_filesystem->delete( $dir, true );
}
