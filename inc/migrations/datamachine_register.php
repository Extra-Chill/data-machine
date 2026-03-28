//! datamachine_register — extracted from migrations.php.


/**
 * Register hooks that trigger SITE.md regeneration on structural changes.
 *
 * These are the same hooks that SiteContext used for cache invalidation,
 * but now they regenerate the actual file on disk. The debounce in
 * datamachine_regenerate_site_md() prevents excessive writes.
 *
 * @since 0.48.0
 * @return void
 */
function datamachine_register_site_md_invalidation(): void {
	$callback = 'datamachine_regenerate_site_md';

	// Plugin/theme structural changes — always regenerate.
	add_action( 'switch_theme', $callback );
	add_action( 'activated_plugin', $callback );
	add_action( 'deactivated_plugin', $callback );

	// Post lifecycle — updates published counts.
	add_action( 'save_post', $callback );
	add_action( 'delete_post', $callback );
	add_action( 'wp_trash_post', $callback );
	add_action( 'untrash_post', $callback );

	// Term lifecycle — updates term counts.
	add_action( 'create_term', $callback );
	add_action( 'edit_term', $callback );
	add_action( 'delete_term', $callback );

	// Site identity and structure changes.
	add_action( 'update_option_blogname', $callback );
	add_action( 'update_option_blogdescription', $callback );
	add_action( 'update_option_home', $callback );
	add_action( 'update_option_siteurl', $callback );
	add_action( 'update_option_permalink_structure', $callback );
	add_action( 'update_option_page_on_front', $callback );
	add_action( 'update_option_page_for_posts', $callback );
	add_action( 'update_option_show_on_front', $callback );

	// Menu changes.
	add_action( 'wp_update_nav_menu', $callback );
	add_action( 'wp_delete_nav_menu', $callback );
	add_action( 'wp_update_nav_menu_item', $callback );
}

/**
 * Register hooks that trigger NETWORK.md regeneration on structural changes.
 *
 * Only registers on multisite installs. Covers site lifecycle, URL changes,
 * network plugin activations, and theme switches. The debounce in
 * datamachine_regenerate_network_md() prevents excessive writes.
 *
 * @since 0.49.1
 * @return void
 */
function datamachine_register_network_md_invalidation(): void {
	if ( ! is_multisite() ) {
		return;
	}

	$callback = 'datamachine_regenerate_network_md';

	// Site lifecycle — new sites, deleted sites.
	add_action( 'wp_initialize_site', $callback );
	add_action( 'wp_delete_site', $callback );
	add_action( 'wp_uninitialize_site', $callback );

	// Site identity changes — URL or name changes on any site.
	add_action( 'update_option_siteurl', $callback );
	add_action( 'update_option_home', $callback );
	add_action( 'update_option_blogname', $callback );

	// Plugin/theme structural changes — affects network plugin list.
	add_action( 'activated_plugin', $callback );
	add_action( 'deactivated_plugin', $callback );
	add_action( 'switch_theme', $callback );
}

/**
 * Register default content generators for datamachine/scaffold-memory-file.
 *
 * Each generator handles one filename and builds content from the
 * context array (user_id, agent_slug, etc.). Generators are composable
 * via the `datamachine_scaffold_content` filter — plugins can override
 * or extend any file's default content.
 *
 * @since 0.50.0
 */
function datamachine_register_scaffold_generators(): void {
	add_filter( 'datamachine_scaffold_content', 'datamachine_scaffold_user_content', 10, 3 );
	add_filter( 'datamachine_scaffold_content', 'datamachine_scaffold_soul_content', 10, 3 );
	add_filter( 'datamachine_scaffold_content', 'datamachine_scaffold_memory_content', 10, 3 );
	add_filter( 'datamachine_scaffold_content', 'datamachine_scaffold_daily_content', 10, 3 );
	add_filter( 'datamachine_scaffold_content', 'datamachine_scaffold_rules_content', 10, 3 );
}
