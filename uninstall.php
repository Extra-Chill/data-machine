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

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/inc/Abilities/Media/ImageGenerationAbilities.php';
require_once __DIR__ . '/inc/Abilities/SettingsAbilities.php';
require_once __DIR__ . '/inc/Core/ActionScheduler/GroupRegistrar.php';
require_once __DIR__ . '/inc/Core/NetworkSettings.php';

if ( is_multisite() ) {
	// Clean up every subsite on the network.
	$datamachine_sites = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $datamachine_sites as $datamachine_blog_id ) {
		switch_to_blog( $datamachine_blog_id );
		try {
			datamachine_uninstall_site();
		} finally {
			restore_current_blog();
		}
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

	// --- Site options ---

	// Core plugin settings.
	delete_option( 'datamachine_settings' );
	delete_option( \DataMachine\Abilities\SettingsAbilities::HANDLER_DEFAULTS_OPTION );
	delete_option( 'datamachine_agent_ping_callback_token' );
	delete_option( 'datamachine_page_hook_suffixes' );
	delete_option( 'datamachine_post_identity_reservations_schema' );

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
			\DataMachine\Engine\AI\Actions\PendingActionStore::get_table_name(),
			$wpdb->prefix . \DataMachine\Core\Database\RunMetadata\RunMetadata::TABLE_NAME,
			$wpdb->prefix . \DataMachine\Core\Database\BundleArtifacts\InstalledBundleArtifacts::TABLE_NAME,
			$wpdb->prefix . \DataMachine\Core\Database\PostIdentityReservations\PostIdentityReservations::TABLE_NAME,
			$wpdb->prefix . \DataMachine\Core\Database\PostIdentityIndex\PostIdentityIndex::TABLE_NAME,
			$wpdb->prefix . \DataMachine\Core\Database\TrackedItems\TrackedItems::TABLE_NAME,
			$wpdb->prefix . \DataMachine\Core\Database\BatchItems\BatchItems::TABLE_NAME,
			$wpdb->prefix . \DataMachine\Core\Database\ProcessedItems\ProcessedItems::TABLE_NAME,
			$wpdb->prefix . \DataMachine\Core\Database\Jobs\Jobs::TABLE_NAME,
			$wpdb->prefix . \DataMachine\Core\Database\Flows\Flows::TABLE_NAME,
			$wpdb->prefix . \DataMachine\Core\Database\Pipelines\Pipelines::TABLE_NAME,
			$wpdb->prefix . \DataMachine\Core\Database\Logs\LogRepository::TABLE_NAME,
		);

		// On single-site, base_prefix === prefix, so the network table is dropped
		// here alongside the per-site tables (there is no separate network pass).
		if ( ! is_multisite() ) {
			$datamachine_tables_to_drop = array_merge( $datamachine_tables_to_drop, datamachine_get_network_table_names() );
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
		as_unschedule_all_actions( '', array(), \DataMachine\Core\ActionScheduler\GroupRegistrar::GROUP );
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
	if ( ! current_user_can( 'delete_plugins' ) && ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		return;
	}

	foreach ( datamachine_get_network_table_names() as $datamachine_network_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'DROP TABLE IF EXISTS %i', $datamachine_network_table ) );
	}
}

/**
 * Get the network-scoped tables created by the current schema setup.
 *
 * The repository constants are the canonical unprefixed names used by each
 * create_table() implementation. Order is reversed from dependencies so agent
 * credentials and grants are removed before agent identities.
 *
 * @return string[] Full network table names.
 */
function datamachine_get_network_table_names() {
	global $wpdb;

	return array(
		\DataMachine\Core\Database\Chat\Chat::get_prefixed_table_name(),
		$wpdb->base_prefix . \DataMachine\Core\Database\Agents\AgentTokens::TABLE_NAME,
		$wpdb->base_prefix . \DataMachine\Core\Database\Agents\AgentAccess::PRINCIPAL_TABLE_NAME,
		$wpdb->base_prefix . \DataMachine\Core\Database\Agents\AgentAccess::TABLE_NAME,
		$wpdb->base_prefix . \DataMachine\Core\Database\Agents\Agents::TABLE_NAME,
	);
}

/**
 * Clean up network-wide options on multisite.
 *
 * These are stored via get_site_option() / update_site_option() and shared
 * across all subsites on the network.
 */
function datamachine_uninstall_network_options() {
	$datamachine_network_options = array(
		\DataMachine\Abilities\Media\ImageGenerationAbilities::CONFIG_OPTION,
		'datamachine_gsc_config',
		'datamachine_search_config',
		'datamachine_auth_data',
		\DataMachine\Core\NetworkSettings::OPTION_NAME,
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
