<?php
/**
 * Data Machine — SITE.md and NETWORK.md scaffolding and invalidation.
 *
 * Generates and regenerates site and network context files from live
 * WordPress data. Registers invalidation hooks for structural changes.
 *
 * @package DataMachine
 * @since 0.60.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build shared SITE.md content from WordPress site data.
 *
 * This is the single source of truth for site context injected into AI calls.
 * Replaces the former SiteContext class + SiteContextDirective which injected
 * a duplicate JSON blob at priority 80. Now SITE.md contains all the same
 * data in markdown format, injected once via CoreMemoryFilesDirective.
 *
 * @since 0.36.1
 * @since 0.48.0 Enriched with post counts, taxonomy details, language, timezone,
 *               site structure, user roles, plugin descriptions, REST namespaces.
 * @return string
 */
function datamachine_get_site_scaffold_content(): string {
	$site_name        = get_bloginfo( 'name' ) ? get_bloginfo( 'name' ) : 'WordPress Site';
	$site_description = get_bloginfo( 'description' ) ? get_bloginfo( 'description' ) : '';
	$site_url         = home_url();
	$language         = get_locale();
	$timezone         = wp_timezone_string();
	$theme_name       = wp_get_theme()->get( 'Name' ) ? wp_get_theme()->get( 'Name' ) : 'Unknown';
	$permalink        = get_option( 'permalink_structure', '' );

	// --- Active plugins with descriptions (exclude Data Machine) ---
	$active_plugins = get_option( 'active_plugins', array() );

	if ( is_multisite() ) {
		$network_plugins = array_keys( get_site_option( 'active_sitewide_plugins', array() ) );
		$active_plugins  = array_unique( array_merge( $active_plugins, $network_plugins ) );
	}

	$plugin_entries = array();
	foreach ( $active_plugins as $plugin_file ) {
		$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( function_exists( 'get_plugin_data' ) && file_exists( $plugin_path ) ) {
			$plugin_data = get_plugin_data( $plugin_path, false, false );
			$plugin_name = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : dirname( $plugin_file );
			$plugin_desc = ! empty( $plugin_data['Description'] ) ? $plugin_data['Description'] : '';
		} else {
			$dir         = dirname( $plugin_file );
			$plugin_name = '.' === $dir ? str_replace( '.php', '', basename( $plugin_file ) ) : $dir;
			$plugin_desc = '';
		}

		if ( 'data-machine' === strtolower( (string) $plugin_name ) || 0 === strpos( $plugin_file, 'data-machine/' ) ) {
			continue;
		}

		$plugin_entries[] = array(
			'name' => $plugin_name,
			'desc' => $plugin_desc,
		);
	}

	// --- Post types with counts ---
	$post_types      = get_post_types( array( 'public' => true ), 'objects' );
	$post_type_lines = array();
	foreach ( $post_types as $pt ) {
		$count             = wp_count_posts( $pt->name );
		$published         = isset( $count->publish ) ? (int) $count->publish : 0;
		$hier              = $pt->hierarchical ? 'hierarchical' : 'flat';
		$post_type_lines[] = sprintf( '| %s | %s | %d | %s |', $pt->label, $pt->name, $published, $hier );
	}

	// --- Taxonomies with term counts ---
	$taxonomies     = get_taxonomies( array( 'public' => true ), 'objects' );
	$taxonomy_lines = array();
	foreach ( $taxonomies as $tax ) {
		$term_count = wp_count_terms( array(
			'taxonomy'   => $tax->name,
			'hide_empty' => false,
		) );
		if ( is_wp_error( $term_count ) ) {
			$term_count = 0;
		}
		$hier             = $tax->hierarchical ? 'hierarchical' : 'flat';
		$associated       = implode( ', ', $tax->object_type ?? array() );
		$taxonomy_lines[] = sprintf( '| %s | %s | %d | %s | %s |', $tax->label, $tax->name, (int) $term_count, $hier, $associated );
	}

	// --- Key pages ---
	$key_pages = array();

	$front_page_id = (int) get_option( 'page_on_front', 0 );
	if ( $front_page_id > 0 ) {
		$key_pages[] = sprintf( '- **Front page:** %s (ID %d)', get_the_title( $front_page_id ), $front_page_id );
	}

	$blog_page_id = (int) get_option( 'page_for_posts', 0 );
	if ( $blog_page_id > 0 ) {
		$key_pages[] = sprintf( '- **Blog page:** %s (ID %d)', get_the_title( $blog_page_id ), $blog_page_id );
	}

	$privacy_page_id = (int) get_option( 'wp_page_for_privacy_policy', 0 );
	if ( $privacy_page_id > 0 ) {
		$key_pages[] = sprintf( '- **Privacy page:** %s (ID %d)', get_the_title( $privacy_page_id ), $privacy_page_id );
	}

	$show_on_front = get_option( 'show_on_front', 'posts' );
	$key_pages[]   = '- **Homepage displays:** ' . ( 'page' === $show_on_front ? 'static page' : 'latest posts' );

	// --- Menus ---
	$registered_menus = get_registered_nav_menus();
	$menu_locations   = get_nav_menu_locations();
	$menu_lines       = array();

	foreach ( $registered_menus as $location => $description ) {
		$assigned = 'unassigned';
		if ( ! empty( $menu_locations[ $location ] ) ) {
			$menu_obj = wp_get_nav_menu_object( $menu_locations[ $location ] );
			$assigned = $menu_obj ? $menu_obj->name : 'unassigned';
		}
		$menu_lines[] = sprintf( '- **%s** (%s): %s', $description, $location, $assigned );
	}

	// --- User roles ---
	$wp_roles      = wp_roles();
	$role_names    = $wp_roles->get_names();
	$default_roles = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );
	$custom_roles  = array_diff( array_keys( $role_names ), $default_roles );
	$role_lines    = array();

	foreach ( $role_names as $slug => $name ) {
		$user_count   = count( get_users( array(
			'role'   => $slug,
			'fields' => 'ID',
			'number' => 1,
		) ) );
		$is_custom    = in_array( $slug, $custom_roles, true ) ? ' (custom)' : '';
		$role_lines[] = sprintf( '- %s (`%s`)%s', translate_user_role( $name ), $slug, $is_custom );
	}

	// --- REST API namespaces (custom only) ---
	$rest_namespaces  = array();
	$builtin_prefixes = array( 'wp/', 'oembed/', 'wp-site-health/' );

	if ( function_exists( 'rest_get_server' ) && did_action( 'rest_api_init' ) ) {
		$routes = rest_get_server()->get_namespaces();
		foreach ( $routes as $namespace ) {
			$is_builtin = false;
			foreach ( $builtin_prefixes as $prefix ) {
				if ( 0 === strpos( $namespace . '/', $prefix ) || 'wp' === $namespace ) {
					$is_builtin = true;
					break;
				}
			}
			if ( ! $is_builtin ) {
				$rest_namespaces[] = $namespace;
			}
		}
	}

	// --- Build SITE.md ---
	$lines   = array();
	$lines[] = '# SITE';
	$lines[] = '';
	$lines[] = '## Identity';
	$lines[] = '- **name:** ' . $site_name;
	if ( ! empty( $site_description ) ) {
		$lines[] = '- **description:** ' . $site_description;
	}
	$lines[] = '- **url:** ' . $site_url;
	$lines[] = '- **theme:** ' . $theme_name;
	$lines[] = '- **language:** ' . $language;
	$lines[] = '- **timezone:** ' . $timezone;
	if ( ! empty( $permalink ) ) {
		$lines[] = '- **permalinks:** ' . $permalink;
	}
	$lines[] = '- **multisite:** ' . ( is_multisite() ? 'true' : 'false' );
	$lines[] = '';

	// --- Site Structure ---
	$lines[] = '## Site Structure';

	foreach ( $key_pages as $page_line ) {
		$lines[] = $page_line;
	}
	$lines[] = '';

	if ( ! empty( $menu_lines ) ) {
		$lines[] = '### Menus';
		foreach ( $menu_lines as $menu_line ) {
			$lines[] = $menu_line;
		}
		$lines[] = '';
	}

	// --- Content Model ---
	$lines[] = '## Post Types';
	$lines[] = '| Label | Slug | Published | Type |';
	$lines[] = '|-------|------|-----------|------|';
	foreach ( $post_type_lines as $line ) {
		$lines[] = $line;
	}
	$lines[] = '';

	$lines[] = '## Taxonomies';
	$lines[] = '| Label | Slug | Terms | Type | Post Types |';
	$lines[] = '|-------|------|-------|------|------------|';
	foreach ( $taxonomy_lines as $line ) {
		$lines[] = $line;
	}
	$lines[] = '';

	// --- User Roles ---
	if ( ! empty( $custom_roles ) ) {
		$lines[] = '## User Roles';
		foreach ( $role_lines as $role_line ) {
			$lines[] = $role_line;
		}
		$lines[] = '';
	}

	// --- Active Plugins with descriptions ---
	$lines[] = '## Active Plugins';
	if ( ! empty( $plugin_entries ) ) {
		foreach ( $plugin_entries as $entry ) {
			$desc_suffix = '';
			if ( ! empty( $entry['desc'] ) ) {
				// Truncate long descriptions to keep SITE.md scannable.
				$desc = wp_strip_all_tags( $entry['desc'] );
				if ( strlen( $desc ) > 120 ) {
					$desc = substr( $desc, 0, 117 ) . '...';
				}
				$desc_suffix = ' — ' . $desc;
			}
			$lines[] = '- **' . $entry['name'] . '**' . $desc_suffix;
		}
	} else {
		$lines[] = '- (none)';
	}

	// --- Must-Use Plugins ---
	if ( function_exists( 'get_mu_plugins' ) ) {
		$mu_plugins = get_mu_plugins();
		if ( ! empty( $mu_plugins ) ) {
			$lines[] = '';
			$lines[] = '## Must-Use Plugins';
			foreach ( $mu_plugins as $mu_file => $mu_data ) {
				$mu_name = ! empty( $mu_data['Name'] ) ? $mu_data['Name'] : basename( $mu_file, '.php' );

				if ( 'data-machine' === strtolower( $mu_name ) ) {
					continue;
				}

				$mu_desc_suffix = '';
				if ( ! empty( $mu_data['Description'] ) ) {
					$mu_desc = wp_strip_all_tags( $mu_data['Description'] );
					if ( strlen( $mu_desc ) > 120 ) {
						$mu_desc = substr( $mu_desc, 0, 117 ) . '...';
					}
					$mu_desc_suffix = ' — ' . $mu_desc;
				}
				$lines[] = '- **' . $mu_name . '**' . $mu_desc_suffix;
			}
		}
	}

	// --- Drop-ins ---
	if ( function_exists( 'get_dropins' ) ) {
		$dropins = get_dropins();
		if ( ! empty( $dropins ) ) {
			$lines[] = '';
			$lines[] = '## Drop-ins';
			foreach ( $dropins as $dropin_file => $dropin_data ) {
				$dropin_name = ! empty( $dropin_data['Name'] ) ? $dropin_data['Name'] : $dropin_file;

				$dropin_desc_suffix = '';
				if ( ! empty( $dropin_data['Description'] ) ) {
					$dropin_desc = wp_strip_all_tags( $dropin_data['Description'] );
					if ( strlen( $dropin_desc ) > 120 ) {
						$dropin_desc = substr( $dropin_desc, 0, 117 ) . '...';
					}
					$dropin_desc_suffix = ' — ' . $dropin_desc;
				}
				$lines[] = '- **' . $dropin_name . '** (`' . $dropin_file . '`)' . $dropin_desc_suffix;
			}
		}
	}

	// --- REST API namespaces ---
	if ( ! empty( $rest_namespaces ) ) {
		$lines[] = '';
		$lines[] = '## REST API';
		$lines[] = '- **Custom namespaces:** ' . implode( ', ', $rest_namespaces );
	}

	$content = implode( "\n", $lines ) . "\n";

	/**
	 * Filter the auto-generated SITE.md content.
	 *
	 * Allows plugins and themes to append or modify the site context
	 * that is injected into AI agent calls. SITE.md is read-only in the
	 * admin UI; this filter is the only extension point.
	 *
	 * @since 0.50.0
	 *
	 * @param string $content The generated SITE.md markdown content.
	 */
	return apply_filters( 'datamachine_site_scaffold_content', $content );
}

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
 * Register hooks that trigger SITE.md regeneration on structural changes.
 *
 * Only hooks into events that change the actual structure of the site:
 * plugin/theme changes, site identity options, and menu changes.
 * Post and term lifecycle hooks were intentionally removed — stale
 * published counts and term counts are not meaningful to AI agents,
 * and those hooks (especially save_post) fire far too frequently.
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
 * Build NETWORK.md scaffold content from WordPress multisite data.
 *
 * Generates a markdown summary of the multisite network topology
 * including all sites, network-activated plugins, and shared resources.
 * Returns empty string on single-site installs.
 *
 * @since 0.48.0
 * @return string NETWORK.md content, or empty string if not multisite.
 */
function datamachine_get_network_scaffold_content(): string {
	if ( ! is_multisite() ) {
		return '';
	}

	$network      = get_network();
	$network_name = $network ? $network->site_name : 'WordPress Network';
	$main_site_id = get_main_site_id();
	$main_site    = get_site( $main_site_id );
	$main_url     = $main_site ? $main_site->domain . $main_site->path : home_url();

	// --- Sites ---
	$sites      = get_sites( array( 'number' => 100 ) );
	$site_count = get_blog_count();

	$site_lines = array();
	foreach ( $sites as $site ) {
		$blog_id = (int) $site->blog_id;

		switch_to_blog( $blog_id );
		$name  = get_bloginfo( 'name' ) ? get_bloginfo( 'name' ) : 'Site ' . $blog_id;
		$url   = home_url();
		$theme = wp_get_theme()->get( 'Name' ) ? wp_get_theme()->get( 'Name' ) : 'Unknown';
		restore_current_blog();

		$is_main      = ( $blog_id === $main_site_id ) ? ' (main)' : '';
		$site_lines[] = sprintf( '| %s%s | %s | %s |', $name, $is_main, $url, $theme );
	}

	// --- Network-activated plugins ---
	$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
	$plugin_names    = array();

	foreach ( array_keys( $network_plugins ) as $plugin_file ) {
		if ( 0 === strpos( $plugin_file, 'data-machine/' ) ) {
			continue;
		}

		$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( function_exists( 'get_plugin_data' ) && file_exists( $plugin_path ) ) {
			$plugin_data    = get_plugin_data( $plugin_path, false, false );
			$plugin_names[] = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : dirname( $plugin_file );
		} else {
			$dir            = dirname( $plugin_file );
			$plugin_names[] = '.' === $dir ? str_replace( '.php', '', basename( $plugin_file ) ) : $dir;
		}
	}

	// --- Build content ---
	$lines   = array();
	$lines[] = '# Network';
	$lines[] = '';
	$lines[] = '## Identity';
	$lines[] = '- **network_name:** ' . $network_name;
	$lines[] = '- **primary_site:** ' . $main_url;
	$lines[] = '- **sites_count:** ' . $site_count;
	$lines[] = '';
	$lines[] = '## Sites';
	$lines[] = '| Site | URL | Theme |';
	$lines[] = '|------|-----|-------|';

	foreach ( $site_lines as $line ) {
		$lines[] = $line;
	}

	$lines[] = '';
	$lines[] = '## Network Plugins';
	if ( ! empty( $plugin_names ) ) {
		foreach ( $plugin_names as $name ) {
			$lines[] = '- ' . $name;
		}
	} else {
		$lines[] = '- (none)';
	}

	$lines[] = '';
	$lines[] = '## Shared Resources';
	$lines[] = '- **Users:** network-wide (see USER.md)';
	$lines[] = '- **Media:** per-site uploads';

	$content = implode( "\n", $lines ) . "\n";

	/**
	 * Filter the auto-generated NETWORK.md content.
	 *
	 * Allows plugins and themes to append or modify the network context
	 * that is injected into AI agent calls. NETWORK.md is read-only in the
	 * admin UI; this filter is the only extension point.
	 *
	 * @since 0.50.0
	 *
	 * @param string $content The generated NETWORK.md markdown content.
	 */
	return apply_filters( 'datamachine_network_scaffold_content', $content );
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
