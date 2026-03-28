//! datamachine_regenerate — extracted from migrations.php.


/**
 * Regenerate SITE.md on disk from current WordPress state.
 *
 * Called by invalidation hooks when site structure changes (plugins,
 * themes, post types, taxonomies, options). Debounced via a short-lived
 * transient to avoid excessive writes during bulk operations.
 *
 * SITE.md is read-only — it is fully regenerated from live WordPress data.
 * To extend SITE.md content, use the `datamachine_site_scaffold_content` filter.
 *
 * @since 0.48.0
 * @since 0.50.0 Removed <!-- CUSTOM --> marker; SITE.md is now read-only.
 * @return void
 */
function datamachine_regenerate_site_md(): void {
	// Debounce: skip if we regenerated in the last 60 seconds.
	if ( get_transient( 'datamachine_site_md_regenerating' ) ) {
		return;
	}
	set_transient( 'datamachine_site_md_regenerating', 1, 60 );

	// Check the setting — if disabled, skip regeneration.
	if ( ! \DataMachine\Core\PluginSettings::get( 'site_context_enabled', true ) ) {
		return;
	}

	$directory_manager = new \DataMachine\Core\FilesRepository\DirectoryManager();
	$shared_dir        = $directory_manager->get_shared_directory();
	$site_md_path      = trailingslashit( $shared_dir ) . 'SITE.md';

	$fs = \DataMachine\Core\FilesRepository\FilesystemHelper::get();
	if ( ! $fs ) {
		return;
	}

	$content = datamachine_get_site_scaffold_content();

	if ( ! is_dir( $shared_dir ) ) {
		wp_mkdir_p( $shared_dir );
	}

	$fs->put_contents( $site_md_path, $content, FS_CHMOD_FILE );
	\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $site_md_path );
}

/**
 * Regenerate NETWORK.md from live WordPress multisite data.
 *
 * Same pattern as datamachine_regenerate_site_md():
 * - 60-second debounce via transient
 * - Respects site_context_enabled setting
 * - Only runs on multisite installs
 *
 * NETWORK.md is read-only — fully regenerated from live multisite data.
 * To extend NETWORK.md content, use the `datamachine_network_scaffold_content` filter.
 *
 * @since 0.49.1
 * @since 0.50.0 Removed <!-- CUSTOM --> marker; NETWORK.md is now read-only.
 * @return void
 */
function datamachine_regenerate_network_md(): void {
	if ( ! is_multisite() ) {
		return;
	}

	// Debounce: skip if we regenerated in the last 60 seconds.
	// Use a network-wide transient so subsites don't each trigger a write.
	if ( get_site_transient( 'datamachine_network_md_regenerating' ) ) {
		return;
	}
	set_site_transient( 'datamachine_network_md_regenerating', 1, 60 );

	// Check the setting — if disabled, skip regeneration.
	if ( ! \DataMachine\Core\PluginSettings::get( 'site_context_enabled', true ) ) {
		return;
	}

	$directory_manager = new \DataMachine\Core\FilesRepository\DirectoryManager();
	$network_dir       = $directory_manager->get_network_directory();
	$network_md_path   = trailingslashit( $network_dir ) . 'NETWORK.md';

	$fs = \DataMachine\Core\FilesRepository\FilesystemHelper::get();
	if ( ! $fs ) {
		return;
	}

	$content = datamachine_get_network_scaffold_content();

	if ( empty( $content ) ) {
		return;
	}

	if ( ! is_dir( $network_dir ) ) {
		wp_mkdir_p( $network_dir );
	}

	$fs->put_contents( $network_md_path, $content, FS_CHMOD_FILE );
	\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $network_md_path );
}
