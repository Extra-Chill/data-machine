<?php
/**
 * Data Machine — Migrations, scaffolding, and activation helpers.
 *
 * Extracted from data-machine.php to keep the main plugin file clean.
 * All functions are prefixed with datamachine_ and called from the
 * plugin bootstrap and activation hooks.
 *
 * @package DataMachine
 * @since 0.38.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Migrate flow_config JSON from legacy singular handler keys to plural.
 *
 * Converts handler_slug → handler_slugs and handler_config → handler_configs
 * in every step of every flow's flow_config JSON. Idempotent: skips rows
 * that already use plural keys.
 *
 * @since 0.39.0
 */
function datamachine_migrate_handler_keys_to_plural() {
	$already_done = get_option( 'datamachine_handler_keys_migrated', false );
	if ( $already_done ) {
		return;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'datamachine_flows';

	// Check table exists (fresh installs won't have legacy data).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix.
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	// phpcs:enable WordPress.DB.PreparedSQL
	if ( ! $table_exists ) {
		update_option( 'datamachine_handler_keys_migrated', true, true );
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix.
	$rows = $wpdb->get_results( "SELECT flow_id, flow_config FROM {$table}", ARRAY_A );
	// phpcs:enable WordPress.DB.PreparedSQL

	if ( empty( $rows ) ) {
		update_option( 'datamachine_handler_keys_migrated', true, true );
		return;
	}

	$migrated = 0;
	foreach ( $rows as $row ) {
		$flow_config = json_decode( $row['flow_config'], true );
		if ( ! is_array( $flow_config ) ) {
			continue;
		}

		$changed = false;
		foreach ( $flow_config as $step_id => &$step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			// Skip flow-level metadata keys.
			if ( 'memory_files' === $step_id ) {
				continue;
			}

			// Already has plural keys — check if singular leftovers need cleanup.
			if ( isset( $step['handler_slugs'] ) && is_array( $step['handler_slugs'] ) ) {
				// Ensure handler_configs exists when handler_slugs does.
				if ( ! isset( $step['handler_configs'] ) || ! is_array( $step['handler_configs'] ) ) {
					$primary                 = $step['handler_slugs'][0] ?? '';
					$config                  = $step['handler_config'] ?? array();
					$step['handler_configs'] = ! empty( $primary ) ? array( $primary => $config ) : array();
					$changed                 = true;
				}
				// Remove any leftover singular keys.
				if ( isset( $step['handler_slug'] ) ) {
					unset( $step['handler_slug'] );
					$changed = true;
				}
				if ( isset( $step['handler_config'] ) ) {
					unset( $step['handler_config'] );
					$changed = true;
				}
				continue;
			}

			// Convert singular to plural.
			$slug   = $step['handler_slug'] ?? '';
			$config = $step['handler_config'] ?? array();

			if ( ! empty( $slug ) ) {
				$step['handler_slugs']   = array( $slug );
				$step['handler_configs'] = array( $slug => $config );
			} else {
				// Self-configuring steps (agent_ping, webhook_gate, system_task).
				$step_type = $step['step_type'] ?? '';
				if ( ! empty( $step_type ) && ! empty( $config ) ) {
					$step['handler_slugs']   = array( $step_type );
					$step['handler_configs'] = array( $step_type => $config );
				} else {
					$step['handler_slugs']   = array();
					$step['handler_configs'] = array();
				}
			}

			unset( $step['handler_slug'], $step['handler_config'] );
			$changed = true;
		}
		unset( $step );

		if ( $changed ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array( 'flow_config' => wp_json_encode( $flow_config ) ),
				array( 'flow_id' => $row['flow_id'] ),
				array( '%s' ),
				array( '%d' )
			);
			++$migrated;
		}
	}

	update_option( 'datamachine_handler_keys_migrated', true, true );

	if ( $migrated > 0 ) {
		do_action(
			'datamachine_log',
			'info',
			'Migrated flow_config handler keys from singular to plural',
			array( 'flows_updated' => $migrated )
		);
	}
}

/**
 * Auto-run DB migrations when code version is ahead of stored DB version.
 *
 * Deploys via rsync/homeboy don't trigger activation hooks, so new columns
 * are silently missing until someone manually reactivates. This check runs
 * on every request and calls the idempotent activation function when the
 * deployed code version exceeds the stored DB schema version.
 *
 * Pattern used by WooCommerce, bbPress, and most plugins with custom tables.
 *
 * @since 0.35.0
 */
function datamachine_maybe_run_migrations() {
	$db_version = get_option( 'datamachine_db_version', '0.0.0' );

	if ( version_compare( $db_version, DATAMACHINE_VERSION, '>=' ) ) {
		return;
	}

	datamachine_activate_for_site();
	update_option( 'datamachine_db_version', DATAMACHINE_VERSION, true );
}
add_action( 'init', 'datamachine_maybe_run_migrations', 5 );

/**
 * Build scaffold defaults for agent memory files using WordPress site data.
 *
 * Gathers site metadata, admin info, active plugins, content types, and
 * environment details to populate agent files with useful context instead
 * of empty placeholder comments.
 *
 * @since 0.32.0
 * @since 0.51.0 Accepts optional $agent_name for identity-aware SOUL.md scaffolding.
 *
 * @param string $agent_name Optional agent display name to include in SOUL.md identity.
 * @return array<string, string> Filename => content map for SOUL.md, USER.md, MEMORY.md.
 */
function datamachine_get_scaffold_defaults( string $agent_name = '' ): array {
	// --- Site metadata ---
	$site_name    = get_bloginfo( 'name' ) ? get_bloginfo( 'name' ) : 'WordPress Site';
	$site_tagline = get_bloginfo( 'description' );
	$site_url     = home_url();
	$timezone     = wp_timezone_string();

	// --- Active theme ---
	$theme      = wp_get_theme();
	$theme_name = $theme->get( 'Name' ) ? $theme->get( 'Name' ) : 'Unknown';

	// --- Active plugins (exclude Data Machine itself) ---
	$active_plugins = get_option( 'active_plugins', array() );

	// On multisite, include network-activated plugins too.
	if ( is_multisite() ) {
		$network_plugins = array_keys( get_site_option( 'active_sitewide_plugins', array() ) );
		$active_plugins  = array_unique( array_merge( $active_plugins, $network_plugins ) );
	}

	$plugin_names = array();

	foreach ( $active_plugins as $plugin_file ) {
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

	// --- Content types with counts ---
	$content_lines = array();
	$post_types    = get_post_types( array( 'public' => true ), 'objects' );

	foreach ( $post_types as $pt ) {
		$count     = wp_count_posts( $pt->name );
		$published = isset( $count->publish ) ? (int) $count->publish : 0;

		if ( $published > 0 || in_array( $pt->name, array( 'post', 'page' ), true ) ) {
			$content_lines[] = sprintf( '%s: %d published', $pt->label, $published );
		}
	}

	// --- Multisite ---
	$multisite_line = '';
	if ( is_multisite() ) {
		$site_count     = get_blog_count();
		$multisite_line = sprintf(
			"\n- **Network:** WordPress Multisite with %d site%s",
			$site_count,
			1 === $site_count ? '' : 's'
		);
	}

	// --- Admin user ---
	$admin_email = get_option( 'admin_email', '' );
	$admin_user  = $admin_email ? get_user_by( 'email', $admin_email ) : null;
	$admin_name  = $admin_user ? $admin_user->display_name : '';

	// --- Versions ---
	$wp_version  = get_bloginfo( 'version' );
	$php_version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
	$dm_version  = defined( 'DATAMACHINE_VERSION' ) ? DATAMACHINE_VERSION : 'unknown';
	$created     = wp_date( 'Y-m-d' );

	// --- Build SOUL.md context lines ---
	$context_items   = array();
	$context_items[] = sprintf( '- **Site:** %s', $site_name );

	if ( $site_tagline ) {
		$context_items[] = sprintf( '- **Tagline:** %s', $site_tagline );
	}

	$context_items[] = sprintf( '- **URL:** %s', $site_url );
	$context_items[] = sprintf( '- **Theme:** %s', $theme_name );

	if ( $plugin_names ) {
		$context_items[] = sprintf( '- **Plugins:** %s', implode( ', ', $plugin_names ) );
	}

	if ( $content_lines ) {
		$context_items[] = sprintf( '- **Content:** %s', implode( ' · ', $content_lines ) );
	}

	$context_items[] = sprintf( '- **Timezone:** %s', $timezone );

	$soul_context = implode( "\n", $context_items ) . $multisite_line;

	// --- SOUL.md ---
	$identity_line = ! empty( $agent_name )
		? "You are **{$agent_name}**, an AI assistant managing {$site_name}."
		: "You are an AI assistant managing {$site_name}.";

	$identity_meta = '';
	if ( ! empty( $agent_name ) ) {
		$identity_meta = "\n- **Name:** {$agent_name}";
	}

	$soul = <<<MD
# Agent Soul — {$site_name}

## Identity
{$identity_line}
{$identity_meta}

## Voice & Tone
Write in a clear, helpful tone.

## Rules
- Follow the site's content guidelines
- Ask for clarification when instructions are ambiguous

## Context
{$soul_context}

## Continuity
SOUL.md (this file) defines who you are. USER.md profiles your human. MEMORY.md tracks persistent knowledge. Daily memory files (daily/YYYY/MM/DD.md) capture session activity — the system generates daily summaries automatically. Keep MEMORY.md lean: persistent facts only, not session logs.
MD;

	// --- USER.md ---
	$user_lines = array();
	if ( $admin_name ) {
		$user_lines[] = sprintf( '- **Name:** %s', $admin_name );
	}
	if ( $admin_email ) {
		$user_lines[] = sprintf( '- **Email:** %s', $admin_email );
	}
	$user_lines[] = '- **Role:** Site Administrator';
	$user_about   = implode( "\n", $user_lines );

	$user = <<<MD
# User Profile

## About
{$user_about}

## Preferences
<!-- Communication style, formatting preferences, things to remember -->

## Goals
<!-- What you're working toward with this site or project -->
MD;

	// --- MEMORY.md ---
	$memory = <<<MD
# Agent Memory

## State
- Data Machine v{$dm_version} activated on {$created}
- WordPress {$wp_version}, PHP {$php_version}

## Lessons Learned
<!-- What worked, what didn't, patterns to remember -->

## Context
<!-- Accumulated knowledge about the site, audience, domain -->
MD;

	return array(
		'SOUL.md'   => $soul,
		'USER.md'   => $user,
		'MEMORY.md' => $memory,
	);
}

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
		$count     = wp_count_posts( $pt->name );
		$published = isset( $count->publish ) ? (int) $count->publish : 0;
		$hier      = $pt->hierarchical ? 'hierarchical' : 'flat';
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
		$hier            = $tax->hierarchical ? 'hierarchical' : 'flat';
		$associated      = implode( ', ', $tax->object_type ?? array() );
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
	$wp_roles       = wp_roles();
	$role_names     = $wp_roles->get_names();
	$default_roles  = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );
	$custom_roles   = array_diff( array_keys( $role_names ), $default_roles );
	$role_lines     = array();

	foreach ( $role_names as $slug => $name ) {
		$user_count   = count( get_users( array( 'role' => $slug, 'fields' => 'ID', 'number' => 1 ) ) );
		$is_custom    = in_array( $slug, $custom_roles, true ) ? ' (custom)' : '';
		$role_lines[] = sprintf( '- %s (`%s`)%s', translate_user_role( $name ), $slug, $is_custom );
	}

	// --- REST API namespaces (custom only) ---
	$rest_namespaces    = array();
	$builtin_prefixes   = array( 'wp/', 'oembed/', 'wp-site-health/' );

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

/**
 * Migrate existing user_id-scoped agent files to layered architecture.
 *
 * Idempotent migration that:
 * - Creates shared/ SITE.md
 * - Creates agents/{slug}/ and users/{user_id}/
 * - Copies SOUL.md + MEMORY.md to agent layer
 * - Copies USER.md to user layer
 * - Creates datamachine_agents rows (one per user-owned legacy agent dir)
 * - Backfills chat_sessions.agent_id
 *
 * @since 0.36.1
 * @return void
 */
function datamachine_migrate_to_layered_architecture(): void {
	if ( get_option( 'datamachine_layered_arch_migrated', false ) ) {
		return;
	}

	$directory_manager = new \DataMachine\Core\FilesRepository\DirectoryManager();
	$fs                = \DataMachine\Core\FilesRepository\FilesystemHelper::get();

	if ( ! $fs ) {
		return;
	}

	$legacy_agent_base = $directory_manager->get_agent_directory(); // .../datamachine-files/agent
	$shared_dir        = $directory_manager->get_shared_directory();

	update_option(
		'datamachine_layered_arch_migration_backup',
		array(
			'legacy_agent_base' => $legacy_agent_base,
			'migrated_at'       => current_time( 'mysql', true ),
		),
		false
	);

	if ( ! is_dir( $shared_dir ) ) {
		wp_mkdir_p( $shared_dir );
	}

	$site_md = trailingslashit( $shared_dir ) . 'SITE.md';
	if ( ! file_exists( $site_md ) ) {
		$fs->put_contents( $site_md, datamachine_get_site_scaffold_content(), FS_CHMOD_FILE );
		\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $site_md );
	}

	$index_file = trailingslashit( $shared_dir ) . 'index.php';
	if ( ! file_exists( $index_file ) ) {
		$fs->put_contents( $index_file, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
		\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $index_file );
	}

	$agents_repo = new \DataMachine\Core\Database\Agents\Agents();
	$chat_db     = new \DataMachine\Core\Database\Chat\Chat();

	$legacy_user_dirs = glob( trailingslashit( $legacy_agent_base ) . '*', GLOB_ONLYDIR );

	if ( ! empty( $legacy_user_dirs ) ) {
		foreach ( $legacy_user_dirs as $legacy_dir ) {
			$basename = basename( $legacy_dir );

			if ( ! preg_match( '/^\d+$/', $basename ) ) {
				continue;
			}

			$user_id = (int) $basename;
			if ( $user_id <= 0 ) {
				continue;
			}

			$user        = get_user_by( 'id', $user_id );
			$agent_slug  = $user ? sanitize_title( $user->user_login ) : 'user-' . $user_id;
			$agent_name  = $user ? $user->display_name : 'User ' . $user_id;
			$agent_model = \DataMachine\Core\PluginSettings::getContextModel( 'chat' );

			$agent_id = $agents_repo->create_if_missing(
				$agent_slug,
				$agent_name,
				$user_id,
				array(
					'model' => array(
						'default' => $agent_model,
					),
				)
			);

			$agent_identity_dir = $directory_manager->get_agent_identity_directory( $agent_slug );
			$user_dir           = $directory_manager->get_user_directory( $user_id );

			if ( ! is_dir( $agent_identity_dir ) ) {
				wp_mkdir_p( $agent_identity_dir );
			}
			if ( ! is_dir( $user_dir ) ) {
				wp_mkdir_p( $user_dir );
			}

			$agent_index = trailingslashit( $agent_identity_dir ) . 'index.php';
			if ( ! file_exists( $agent_index ) ) {
				$fs->put_contents( $agent_index, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
				\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $agent_index );
			}

			$user_index = trailingslashit( $user_dir ) . 'index.php';
			if ( ! file_exists( $user_index ) ) {
				$fs->put_contents( $user_index, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
				\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $user_index );
			}

			$legacy_soul   = trailingslashit( $legacy_dir ) . 'SOUL.md';
			$legacy_memory = trailingslashit( $legacy_dir ) . 'MEMORY.md';
			$legacy_user   = trailingslashit( $legacy_dir ) . 'USER.md';
			$legacy_daily  = trailingslashit( $legacy_dir ) . 'daily';

			$new_soul   = trailingslashit( $agent_identity_dir ) . 'SOUL.md';
			$new_memory = trailingslashit( $agent_identity_dir ) . 'MEMORY.md';
			$new_daily  = trailingslashit( $agent_identity_dir ) . 'daily';
			$new_user   = trailingslashit( $user_dir ) . 'USER.md';

			if ( file_exists( $legacy_soul ) && ! file_exists( $new_soul ) ) {
				$fs->copy( $legacy_soul, $new_soul, true, FS_CHMOD_FILE );
				\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $new_soul );
			}
			if ( file_exists( $legacy_memory ) && ! file_exists( $new_memory ) ) {
				$fs->copy( $legacy_memory, $new_memory, true, FS_CHMOD_FILE );
				\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $new_memory );
			}
			if ( file_exists( $legacy_user ) && ! file_exists( $new_user ) ) {
				$fs->copy( $legacy_user, $new_user, true, FS_CHMOD_FILE );
				\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $new_user );
			} elseif ( ! file_exists( $new_user ) ) {
				$user_profile_lines   = array();
				$user_profile_lines[] = '# User Profile';
				$user_profile_lines[] = '';
				$user_profile_lines[] = '## About';
				$user_profile_lines[] = '- **Name:** ' . ( $user ? $user->display_name : 'User ' . $user_id );
				if ( $user && ! empty( $user->user_email ) ) {
					$user_profile_lines[] = '- **Email:** ' . $user->user_email;
				}
				$user_profile_lines[] = '- **User ID:** ' . $user_id;
				$user_profile_lines[] = '';
				$user_profile_lines[] = '## Preferences';
				$user_profile_lines[] = '<!-- Add user-specific preferences here -->';

				$fs->put_contents( $new_user, implode( "\n", $user_profile_lines ) . "\n", FS_CHMOD_FILE );
				\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $new_user );
			}

			if ( is_dir( $legacy_daily ) && ! is_dir( $new_daily ) ) {
				datamachine_copy_directory_recursive( $legacy_daily, $new_daily );
			}

			// Backfill chat sessions for this user.
			global $wpdb;
			$chat_table = $chat_db->get_table_name();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET agent_id = %d WHERE user_id = %d AND (agent_id IS NULL OR agent_id = 0)',
					$chat_table,
					$agent_id,
					$user_id
				)
			);
		}
	}

	// Single-agent case: .md files live directly in agent/ with no numeric subdirs.
	// This is the most common layout for sites that never had multi-user partitioning.
	$legacy_md_files = glob( trailingslashit( $legacy_agent_base ) . '*.md' );

	if ( ! empty( $legacy_md_files ) ) {
		$default_user_id = \DataMachine\Core\FilesRepository\DirectoryManager::get_default_agent_user_id();
		$default_user    = get_user_by( 'id', $default_user_id );
		$default_slug    = $default_user ? sanitize_title( $default_user->user_login ) : 'user-' . $default_user_id;
		$default_name    = $default_user ? $default_user->display_name : 'User ' . $default_user_id;
		$default_model   = \DataMachine\Core\PluginSettings::getContextModel( 'chat' );

		$agents_repo->create_if_missing(
			$default_slug,
			$default_name,
			$default_user_id,
			array(
				'model' => array(
					'default' => $default_model,
				),
			)
		);

		$default_identity_dir = $directory_manager->get_agent_identity_directory( $default_slug );
		$default_user_dir     = $directory_manager->get_user_directory( $default_user_id );

		if ( ! is_dir( $default_identity_dir ) ) {
			wp_mkdir_p( $default_identity_dir );
		}
		if ( ! is_dir( $default_user_dir ) ) {
			wp_mkdir_p( $default_user_dir );
		}

		$default_agent_index = trailingslashit( $default_identity_dir ) . 'index.php';
		if ( ! file_exists( $default_agent_index ) ) {
			$fs->put_contents( $default_agent_index, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
			\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $default_agent_index );
		}

		$default_user_index = trailingslashit( $default_user_dir ) . 'index.php';
		if ( ! file_exists( $default_user_index ) ) {
			$fs->put_contents( $default_user_index, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
			\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $default_user_index );
		}

		foreach ( $legacy_md_files as $legacy_file ) {
			$filename = basename( $legacy_file );

			// USER.md goes to user layer; everything else to agent identity.
			if ( 'USER.md' === $filename ) {
				$dest = trailingslashit( $default_user_dir ) . $filename;
			} else {
				$dest = trailingslashit( $default_identity_dir ) . $filename;
			}

			if ( ! file_exists( $dest ) ) {
				$fs->copy( $legacy_file, $dest, true, FS_CHMOD_FILE );
				\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $dest );
			}
		}

		// Migrate daily memory directory.
		$legacy_daily = trailingslashit( $legacy_agent_base ) . 'daily';
		$new_daily    = trailingslashit( $default_identity_dir ) . 'daily';

		if ( is_dir( $legacy_daily ) && ! is_dir( $new_daily ) ) {
			datamachine_copy_directory_recursive( $legacy_daily, $new_daily );
		}
	}

	update_option( 'datamachine_layered_arch_migrated', 1, false );
}

/**
 * Copy directory contents recursively without deleting source.
 *
 * Existing destination files are preserved.
 *
 * @since 0.36.1
 * @param string $source_dir Source directory path.
 * @param string $target_dir Target directory path.
 * @return void
 */
function datamachine_copy_directory_recursive( string $source_dir, string $target_dir ): void {
	if ( ! is_dir( $source_dir ) ) {
		return;
	}

	$fs = \DataMachine\Core\FilesRepository\FilesystemHelper::get();
	if ( ! $fs ) {
		return;
	}

	if ( ! is_dir( $target_dir ) ) {
		wp_mkdir_p( $target_dir );
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $source_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $iterator as $item ) {
		$source_path = $item->getPathname();
		$relative    = ltrim( str_replace( $source_dir, '', $source_path ), DIRECTORY_SEPARATOR );
		$target_path = trailingslashit( $target_dir ) . $relative;

		if ( $item->isDir() ) {
			if ( ! is_dir( $target_path ) ) {
				wp_mkdir_p( $target_path );
			}
			continue;
		}

		if ( file_exists( $target_path ) ) {
			continue;
		}

		$parent = dirname( $target_path );
		if ( ! is_dir( $parent ) ) {
			wp_mkdir_p( $parent );
		}

		$fs->copy( $source_path, $target_path, true, FS_CHMOD_FILE );
		\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $target_path );
	}
}

/**
 * Create default agent memory files if they don't exist.
 *
 * Called on activation and lazily on any request that reads agent files
 * (via DirectoryManager::ensure_agent_files()). Existing files are never
 * overwritten — only missing files are recreated from scaffold defaults.
 *
 * Returns false when the Abilities API is unavailable (e.g. during plugin
 * activation where init callbacks haven't fired), so the caller can defer.
 *
 * @since 0.30.0
 *
 * @return bool True if scaffold ran, false if abilities were unavailable.
 */
function datamachine_ensure_default_memory_files(): bool {
	$ability = \DataMachine\Abilities\File\ScaffoldAbilities::get_ability();
	if ( ! $ability ) {
		return false;
	}

	$default_user_id = \DataMachine\Core\FilesRepository\DirectoryManager::get_default_agent_user_id();

	$ability->execute( array( 'layer' => 'agent', 'user_id' => $default_user_id ) );
	$ability->execute( array( 'layer' => 'user', 'user_id' => $default_user_id ) );

	// Scaffold default context memory files (contexts/{context}.md).
	datamachine_ensure_default_context_files( $default_user_id );

	return true;
}

/**
 * Scaffold default context memory files (contexts/{context}.md).
 *
 * Creates the contexts/ directory and writes default context files
 * for each core execution context. Existing files are never overwritten.
 *
 * @since 0.58.0
 *
 * @param int $user_id Default agent user ID.
 */
function datamachine_ensure_default_context_files( int $user_id ): void {
	$dm          = new \DataMachine\Core\FilesRepository\DirectoryManager();
	$contexts_dir = $dm->get_contexts_directory( array( 'user_id' => $user_id ) );

	if ( ! $dm->ensure_directory_exists( $contexts_dir ) ) {
		return;
	}

	global $wp_filesystem;
	if ( ! $wp_filesystem ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		\WP_Filesystem();
	}

	$defaults = datamachine_get_default_context_files();

	foreach ( $defaults as $slug => $content ) {
		$filepath = trailingslashit( $contexts_dir ) . $slug . '.md';
		if ( file_exists( $filepath ) ) {
			continue;
		}
		$wp_filesystem->put_contents( $filepath, $content, FS_CHMOD_FILE );
	}
}

/**
 * Get default context file contents.
 *
 * Each key is the context slug (filename without .md extension).
 * These replace the former hardcoded ChatContextDirective,
 * PipelineContextDirective, and SystemContextDirective PHP classes.
 *
 * @since 0.58.0
 * @return array<string, string> Context slug => markdown content.
 */
function datamachine_get_default_context_files(): array {
	$defaults = array(
		'chat'     => datamachine_default_chat_context(),
		'pipeline' => datamachine_default_pipeline_context(),
		'system'   => datamachine_default_system_context(),
	);

	/**
	 * Filter the default context file contents.
	 *
	 * Extensions can add their own context defaults (e.g. 'editor')
	 * or modify the core defaults before scaffolding.
	 *
	 * @since 0.58.0
	 *
	 * @param array<string, string> $defaults Context slug => markdown content.
	 */
	return apply_filters( 'datamachine_default_context_files', $defaults );
}

/**
 * Default chat context (replaces ChatContextDirective).
 *
 * @since 0.58.0
 */
function datamachine_default_chat_context(): string {
	return <<<'MD'
# Chat Session Context

This is a live chat session with a user in the Data Machine admin UI. You have tools to configure and manage workflows. Your identity, voice, and knowledge come from your memory files above.

## Data Machine Architecture

HANDLERS are the core intelligence. Fetch handlers extract and structure source data. Update/publish handlers apply changes with schema defaults for unconfigured fields. Each handler has a settings schema — only use documented fields.

PIPELINES define workflow structure: step types in sequence (e.g., event_import → ai → upsert). The pipeline system_prompt defines AI behavior shared by all flows.

FLOWS are configured pipeline instances. Each step needs a handler_slug and handler_config. When creating flows, match handler configurations from existing flows on the same pipeline.

AI STEPS process data that handlers cannot automatically handle. Flow user_message is rarely needed; only for minimal source-specific overrides.

## Discovery

You receive a pipeline inventory with existing flows and their handlers. Use `api_query` for detailed configuration. Query existing flows before creating new ones to learn established patterns.

## Configuration Rules

- Only use documented handler_config fields — unknown fields are rejected.
- Use pipeline_step_id from the inventory to target steps.
- Unconfigured handler fields use schema defaults automatically.
- Act first — if the user gives executable instructions, execute them.

## Scheduling

- Scheduling uses intervals only (daily, hourly, etc.), not specific times of day.
- Valid intervals are provided in the tool definitions. Use update_flow to change schedules.

## Execution Protocol

- Only confirm task completion after a successful tool result. Never claim success on error.
- Check error_type on failure: not_found/permission → report, validation → fix and retry, system → retry once.
- If a tool rejects unknown fields, retry with only the valid fields listed in the error.
- Act decisively — execute tools directly for routine configuration.
- If uncertain about a value, use sensible defaults and note the assumption.
MD;
}

/**
 * Default pipeline context (replaces PipelineContextDirective).
 *
 * @since 0.58.0
 */
function datamachine_default_pipeline_context(): string {
	return <<<'MD'
# Pipeline Execution Context

This is an automated pipeline step — not a chat session. You're processing data through a multi-step workflow. Your identity and knowledge come from your memory files above. Apply that context to the content you process.

## How Pipelines Work

- Each pipeline step has a specific purpose within the overall workflow
- Handler tools produce final results — execute once per workflow objective
- Analyze available data and context before taking action

## Data Packet Structure

You receive content as JSON data packets with these guaranteed fields:
- type: The step type that created this packet
- timestamp: When the packet was created

Additional fields may include data, metadata, content, and handler-specific information.
MD;
}

/**
 * Default system context (replaces SystemContextDirective).
 *
 * @since 0.58.0
 */
function datamachine_default_system_context(): string {
	return <<<'MD'
# System Task Context

This is a background system task — not a chat session. You are the internal agent responsible for automated housekeeping: generating session titles, summarizing content, and other system-level operations.

Your identity and knowledge are already loaded from your memory files above. Use that context.

## Task Behavior

- Execute the task described in the user message below.
- Return exactly what the task asks for — no extra commentary, no meta-discussion.
- Apply your knowledge of this site, its voice, and its conventions from your memory files.

## Session Title Generation

When asked to generate a chat session title: create a concise, descriptive title (3-6 words) capturing the discussion essence. Return ONLY the title text, under 100 characters.
MD;
}

/**
 * Resolve agent display name from scaffolding context.
 *
 * Looks up the agent record from the provided context identifiers
 * (agent_slug, agent_id, or user_id) and returns the display name.
 * Returns empty string when no agent can be resolved.
 *
 * @since 0.51.0
 *
 * @param array $context Scaffolding context with agent_slug, agent_id, or user_id.
 * @return string Agent display name, or empty string.
 */
function datamachine_resolve_agent_name_from_context( array $context ): string {
	if ( ! class_exists( '\\DataMachine\\Core\\Database\\Agents\\Agents' ) ) {
		return '';
	}

	$agents_repo = new \DataMachine\Core\Database\Agents\Agents();

	// 1) Explicit agent_slug.
	if ( ! empty( $context['agent_slug'] ) ) {
		$agent = $agents_repo->get_by_slug( sanitize_title( (string) $context['agent_slug'] ) );
		if ( ! empty( $agent['agent_name'] ) ) {
			return (string) $agent['agent_name'];
		}
	}

	// 2) Agent ID.
	$agent_id = (int) ( $context['agent_id'] ?? 0 );
	if ( $agent_id > 0 ) {
		$agent = $agents_repo->get_agent( $agent_id );
		if ( ! empty( $agent['agent_name'] ) ) {
			return (string) $agent['agent_name'];
		}
	}

	// 3) User ID → owner lookup.
	$user_id = (int) ( $context['user_id'] ?? 0 );
	if ( $user_id > 0 ) {
		$agent = $agents_repo->get_by_owner_id( $user_id );
		if ( ! empty( $agent['agent_name'] ) ) {
			return (string) $agent['agent_name'];
		}
	}

	return '';
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
add_action( 'plugins_loaded', 'datamachine_register_scaffold_generators', 5 );

/**
 * Generate USER.md content from WordPress user profile data.
 *
 * @since 0.50.0
 *
 * @param string $content  Current content (empty if no prior generator).
 * @param string $filename Filename being scaffolded.
 * @param array  $context  Scaffolding context with user_id.
 * @return string
 */
function datamachine_scaffold_user_content( string $content, string $filename, array $context ): string {
	if ( 'USER.md' !== $filename || '' !== $content ) {
		return $content;
	}

	$user_id = (int) ( $context['user_id'] ?? 0 );
	if ( $user_id <= 0 ) {
		return $content;
	}

	$user = get_user_by( 'id', $user_id );
	if ( ! $user ) {
		return $content;
	}

	$about_lines   = array();
	$about_lines[] = sprintf( '- **Name:** %s', $user->display_name );
	$about_lines[] = sprintf( '- **Username:** %s', $user->user_login );

	$roles = $user->roles;
	if ( ! empty( $roles ) ) {
		$role_name     = ucfirst( reset( $roles ) );
		$about_lines[] = sprintf( '- **Role:** %s', $role_name );
	}

	if ( ! empty( $user->user_registered ) ) {
		$registered    = wp_date( 'F Y', strtotime( $user->user_registered ) );
		$about_lines[] = sprintf( '- **Member since:** %s', $registered );
	}

	$post_count = count_user_posts( $user_id, 'post', true );
	if ( $post_count > 0 ) {
		$about_lines[] = sprintf( '- **Published posts:** %d', $post_count );
	}

	$description = get_user_meta( $user_id, 'description', true );
	if ( ! empty( $description ) ) {
		$clean_bio     = wp_strip_all_tags( $description );
		$about_lines[] = sprintf( "\n%s", $clean_bio );
	}

	$about = implode( "\n", $about_lines );

	return <<<MD
# User Profile

## About
{$about}

## Preferences
<!-- Communication style, topics of interest, working hours, things to remember -->

## Goals
<!-- What are you working toward? Projects, content themes, skills to develop -->
MD;
}

/**
 * Generate SOUL.md content from site and agent context.
 *
 * Uses scaffolding context (agent_slug, agent_id) to resolve the agent's
 * display name from the database and embed it in the identity section.
 * Falls back to the generic template when no agent context is available.
 *
 * @since 0.50.0
 * @since 0.51.0 Resolves agent_name from context for identity-aware scaffolding.
 *
 * @param string $content  Current content.
 * @param string $filename Filename being scaffolded.
 * @param array  $context  Scaffolding context with agent_slug, agent_id, or user_id.
 * @return string
 */
function datamachine_scaffold_soul_content( string $content, string $filename, array $context ): string {
	if ( 'SOUL.md' !== $filename || '' !== $content ) {
		return $content;
	}

	// Resolve agent identity from context.
	$agent_name = datamachine_resolve_agent_name_from_context( $context );

	$defaults = datamachine_get_scaffold_defaults( $agent_name );
	return $defaults['SOUL.md'] ?? '';
}

/**
 * Generate MEMORY.md content from site context.
 *
 * @since 0.50.0
 *
 * @param string $content  Current content.
 * @param string $filename Filename being scaffolded.
 * @param array  $context  Scaffolding context.
 * @return string
 */
function datamachine_scaffold_memory_content( string $content, string $filename, array $context ): string {
	if ( 'MEMORY.md' !== $filename || '' !== $content ) {
		return $content;
	}

	$defaults = datamachine_get_scaffold_defaults();
	return $defaults['MEMORY.md'] ?? '';
}

/**
 * Generate RULES.md scaffold content.
 *
 * Creates a starter template for site-wide behavioral constraints.
 * RULES.md is admin-editable and applies to every agent on the site.
 *
 * @since 0.50.0
 *
 * @param string $content  Current content.
 * @param string $filename Filename being scaffolded.
 * @param array  $context  Scaffolding context.
 * @return string
 */
function datamachine_scaffold_rules_content( string $content, string $filename, array $context ): string {
	if ( 'RULES.md' !== $filename || '' !== $content ) {
		return $content;
	}

	$site_name = get_bloginfo( 'name' ) ?: 'this site';

	return <<<MD
# Site Rules

Behavioral constraints that apply to every agent on {$site_name}.

## General
- Be helpful, accurate, and concise.
- Follow the site's voice and tone.
- Do not make up facts or hallucinate information.

## Safety
- Never expose private user data.
- Never run destructive operations without confirmation.
- When in doubt, ask before acting.

## Content
- Respect the site's content guidelines.
- Do not publish or modify content without authorization.
MD;
}

/**
 * Generate daily memory file content with a date header.
 *
 * Matches filenames like 'daily/2026/03/20.md'. The context must
 * include a 'date' key in YYYY-MM-DD format for the header.
 *
 * @since 0.50.0
 *
 * @param string $content  Current content.
 * @param string $filename Logical filename (e.g. 'daily/2026/03/20.md').
 * @param array  $context  Scaffolding context with 'date'.
 * @return string
 */
function datamachine_scaffold_daily_content( string $content, string $filename, array $context ): string {
	if ( '' !== $content ) {
		return $content;
	}

	// Match daily file pattern: daily/YYYY/MM/DD.md.
	if ( ! preg_match( '#^daily/\d{4}/\d{2}/\d{2}\.md$#', $filename ) ) {
		return $content;
	}

	$date = $context['date'] ?? '';
	if ( empty( $date ) ) {
		// Extract date from filename path.
		if ( preg_match( '#^daily/(\d{4})/(\d{2})/(\d{2})\.md$#', $filename, $m ) ) {
			$date = "{$m[1]}-{$m[2]}-{$m[3]}";
		}
	}

	if ( empty( $date ) ) {
		return $content;
	}

	return "# {$date}";
}

/**
 * Backfill agent_id on pipelines, flows, and jobs from user_id → owner_id mapping.
 *
 * For existing rows that have user_id > 0 but no agent_id, looks up the agent
 * via Agents::get_by_owner_id() and sets agent_id. Also bootstraps agent_access
 * rows so owners have admin access to their agents.
 *
 * Idempotent: only processes rows where agent_id IS NULL and user_id > 0.
 * Skipped entirely on fresh installs (no rows to backfill).
 *
 * @since 0.41.0
 */
function datamachine_backfill_agent_ids(): void {
	if ( get_option( 'datamachine_agent_ids_backfilled', false ) ) {
		return;
	}

	global $wpdb;

	$agents_repo = new \DataMachine\Core\Database\Agents\Agents();
	$access_repo = new \DataMachine\Core\Database\Agents\AgentAccess();

	$tables = array(
		$wpdb->prefix . 'datamachine_pipelines',
		$wpdb->prefix . 'datamachine_flows',
		$wpdb->prefix . 'datamachine_jobs',
	);

	// Cache of user_id → agent_id to avoid repeated lookups.
	$agent_map  = array();
	$backfilled = 0;

	foreach ( $tables as $table ) {
		// Check table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $table_exists ) {
			continue;
		}

		// Check agent_id column exists (migration may not have run yet).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$col = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'agent_id'",
				DB_NAME,
				$table
			)
		);
		if ( null === $col ) {
			continue;
		}

		// Get distinct user_ids that need backfill.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$user_ids = $wpdb->get_col(
			"SELECT DISTINCT user_id FROM {$table} WHERE user_id > 0 AND agent_id IS NULL"
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( empty( $user_ids ) ) {
			continue;
		}

		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;

			if ( ! isset( $agent_map[ $user_id ] ) ) {
				$agent = $agents_repo->get_by_owner_id( $user_id );
				if ( $agent ) {
					$agent_map[ $user_id ] = (int) $agent['agent_id'];

					// Bootstrap agent_access for owner.
					$access_repo->bootstrap_owner_access( (int) $agent['agent_id'], $user_id );
				} else {
					// Try to create agent for this user.
					$created_id            = datamachine_resolve_or_create_agent_id( $user_id );
					$agent_map[ $user_id ] = $created_id;

					if ( $created_id > 0 ) {
						$access_repo->bootstrap_owner_access( $created_id, $user_id );
					}
				}
			}

			$agent_id = $agent_map[ $user_id ];
			if ( $agent_id <= 0 ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET agent_id = %d WHERE user_id = %d AND agent_id IS NULL",
					$agent_id,
					$user_id
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL

			if ( false !== $updated ) {
				$backfilled += $updated;
			}
		}
	}

	update_option( 'datamachine_agent_ids_backfilled', true, true );

	if ( $backfilled > 0 ) {
		do_action(
			'datamachine_log',
			'info',
			'Backfilled agent_id on existing pipelines, flows, and jobs',
			array(
				'rows_updated' => $backfilled,
				'agent_map'    => $agent_map,
			)
		);
	}
}

/**
 * Assign orphaned resources to the sole agent on single-agent installs.
 *
 * Handles the case where pipelines, flows, and jobs were created before
 * agent scoping existed (user_id=0, agent_id=NULL). If exactly one agent
 * exists, assigns all unowned resources to it.
 *
 * Idempotent: runs once per install, skipped if multi-agent (>1 agent).
 *
 * @since 0.41.0
 */
function datamachine_assign_orphaned_resources_to_sole_agent(): void {
	if ( get_option( 'datamachine_orphaned_resources_assigned', false ) ) {
		return;
	}

	global $wpdb;

	$agents_repo = new \DataMachine\Core\Database\Agents\Agents();

	// Only proceed for single-agent installs.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$agent_count = (int) $wpdb->get_var(
		$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->base_prefix . 'datamachine_agents' )
	);

	if ( 1 !== $agent_count ) {
		// 0 agents: nothing to assign to. >1 agents: ambiguous, skip.
		update_option( 'datamachine_orphaned_resources_assigned', true, true );
		return;
	}

	// Get the sole agent's ID.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$agent_id = (int) $wpdb->get_var(
		$wpdb->prepare( 'SELECT agent_id FROM %i LIMIT 1', $wpdb->base_prefix . 'datamachine_agents' )
	);

	if ( $agent_id <= 0 ) {
		update_option( 'datamachine_orphaned_resources_assigned', true, true );
		return;
	}

	$tables = array(
		$wpdb->prefix . 'datamachine_pipelines',
		$wpdb->prefix . 'datamachine_flows',
		$wpdb->prefix . 'datamachine_jobs',
	);

	$total_assigned = 0;

	foreach ( $tables as $table ) {
		// Check table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $table_exists ) {
			continue;
		}

		// Check agent_id column exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$col = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'agent_id'",
				DB_NAME,
				$table
			)
		);
		if ( null === $col ) {
			continue;
		}

		// Assign orphaned rows (agent_id IS NULL) to the sole agent.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET agent_id = %d WHERE agent_id IS NULL",
				$agent_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( false !== $updated ) {
			$total_assigned += $updated;
		}
	}

	update_option( 'datamachine_orphaned_resources_assigned', true, true );

	if ( $total_assigned > 0 ) {
		do_action(
			'datamachine_log',
			'info',
			'Assigned orphaned resources to sole agent',
			array(
				'agent_id'     => $agent_id,
				'rows_updated' => $total_assigned,
			)
		);
	}
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
	 * that is injected into AI agent calls. NETWORK.md is read-only in
	 * the admin UI; this filter is the only extension point.
	 *
	 * @since 0.50.0
	 *
	 * @param string $content The generated NETWORK.md markdown content.
	 */
	return apply_filters( 'datamachine_network_scaffold_content', $content );
}

/**
 * Migrate USER.md from site-scoped to network-scoped paths on multisite.
 *
 * On multisite, USER.md was previously stored per-site (under each site's
 * upload dir). Since WordPress users are network-wide, USER.md should live
 * in the main site's uploads directory.
 *
 * This migration finds the richest (largest) USER.md across all subsites
 * and copies it to the new network-scoped location. Also creates NETWORK.md
 * if it doesn't exist.
 *
 * Idempotent. Skipped on single-site installs.
 *
 * @since 0.48.0
 * @return void
 */
function datamachine_migrate_user_md_to_network_scope(): void {
	if ( get_option( 'datamachine_user_md_network_migrated', false ) ) {
		return;
	}

	// Single-site: nothing to migrate, just mark done.
	if ( ! is_multisite() ) {
		update_option( 'datamachine_user_md_network_migrated', true, true );
		return;
	}

	$directory_manager = new \DataMachine\Core\FilesRepository\DirectoryManager();
	$fs                = \DataMachine\Core\FilesRepository\FilesystemHelper::get();

	if ( ! $fs ) {
		return;
	}

	// get_user_directory() now returns the network-scoped path.
	// We need to find USER.md files in old per-site locations and consolidate.
	$sites = get_sites( array( 'number' => 100 ) );

	// Get all WordPress users to check for USER.md across sites.
	$users = get_users( array(
		'fields' => 'ID',
		'number' => 100,
	) );

	$migrated_users = 0;

	foreach ( $users as $user_id ) {
		$user_id = absint( $user_id );

		// New network-scoped destination (from updated get_user_directory).
		$network_user_dir  = $directory_manager->get_user_directory( $user_id );
		$network_user_file = trailingslashit( $network_user_dir ) . 'USER.md';

		// If the file already exists at the network location, skip.
		if ( file_exists( $network_user_file ) ) {
			continue;
		}

		// Search all subsites for the richest USER.md for this user.
		$best_content = '';
		$best_size    = 0;

		foreach ( $sites as $site ) {
			$blog_id = (int) $site->blog_id;

			switch_to_blog( $blog_id );
			$site_upload_dir = wp_upload_dir();
			restore_current_blog();

			$site_user_file = trailingslashit( $site_upload_dir['basedir'] )
				. 'datamachine-files/users/' . $user_id . '/USER.md';

			if ( file_exists( $site_user_file ) ) {
				$size = filesize( $site_user_file );
				if ( $size > $best_size ) {
					$best_size    = $size;
					$best_content = $fs->get_contents( $site_user_file );
				}
			}
		}

		if ( ! empty( $best_content ) ) {
			if ( ! is_dir( $network_user_dir ) ) {
				wp_mkdir_p( $network_user_dir );
			}

			$index_file = trailingslashit( $network_user_dir ) . 'index.php';
			if ( ! file_exists( $index_file ) ) {
				$fs->put_contents( $index_file, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
				\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $index_file );
			}

			$fs->put_contents( $network_user_file, $best_content, FS_CHMOD_FILE );
			\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $network_user_file );
			++$migrated_users;
		}
	}

	// Create NETWORK.md if it doesn't exist.
	$network_dir = $directory_manager->get_network_directory();
	if ( ! is_dir( $network_dir ) ) {
		wp_mkdir_p( $network_dir );
	}

	$network_md = trailingslashit( $network_dir ) . 'NETWORK.md';
	if ( ! file_exists( $network_md ) ) {
		$content = datamachine_get_network_scaffold_content();
		if ( ! empty( $content ) ) {
			$fs->put_contents( $network_md, $content, FS_CHMOD_FILE );
			\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $network_md );
		}
	}

	$network_index = trailingslashit( $network_dir ) . 'index.php';
	if ( ! file_exists( $network_index ) ) {
		$fs->put_contents( $network_index, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
		\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $network_index );
	}

	update_option( 'datamachine_user_md_network_migrated', true, true );

	if ( $migrated_users > 0 ) {
		do_action(
			'datamachine_log',
			'info',
			'Migrated USER.md to network-scoped paths',
			array( 'users_migrated' => $migrated_users )
		);
	}
}

/**
 * Re-schedule all flows with non-manual scheduling on plugin activation.
 *
 * Ensures scheduled flows resume after plugin reactivation.
 */
function datamachine_activate_scheduled_flows() {
	if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
		return;
	}

	global $wpdb;
	$table_name = $wpdb->prefix . 'datamachine_flows';

	// Check if table exists (fresh install won't have flows yet)
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$flows = $wpdb->get_results( $wpdb->prepare( 'SELECT flow_id, scheduling_config FROM %i', $table_name ), ARRAY_A );

	if ( empty( $flows ) ) {
		return;
	}

	$scheduled_count = 0;

	foreach ( $flows as $flow ) {
		$flow_id           = (int) $flow['flow_id'];
		$scheduling_config = json_decode( $flow['scheduling_config'], true );

		if ( empty( $scheduling_config ) || empty( $scheduling_config['interval'] ) ) {
			continue;
		}

		$interval = $scheduling_config['interval'];

		if ( 'manual' === $interval ) {
			continue;
		}

		// Delegate to FlowScheduling — single source of truth for scheduling
		// logic including stagger offsets, interval validation, and AS registration.
		$result = \DataMachine\Api\Flows\FlowScheduling::handle_scheduling_update(
			$flow_id,
			$scheduling_config
		);

		if ( ! is_wp_error( $result ) ) {
			++$scheduled_count;
		}
	}

	if ( $scheduled_count > 0 ) {
		do_action(
			'datamachine_log',
			'info',
			'Flows re-scheduled on plugin activation',
			array(
				'scheduled_count' => $scheduled_count,
			)
		);
	}
}

/**
 * Migrate per-site agent rows to the network-scoped table.
 *
 * On multisite, agent tables previously used $wpdb->prefix (per-site).
 * This migration consolidates per-site agent rows into the network table
 * ($wpdb->base_prefix) and sets site_scope to the originating blog_id.
 *
 * Deduplication: if an agent_slug already exists in the network table,
 * the per-site row is skipped (the network table wins).
 *
 * Idempotent — guarded by a network-level site option.
 *
 * @since 0.52.0
 */
function datamachine_migrate_agents_to_network_scope() {
	if ( ! is_multisite() ) {
		return;
	}

	if ( get_site_option( 'datamachine_agents_network_migrated' ) ) {
		return;
	}

	global $wpdb;

	$network_agents_table  = $wpdb->base_prefix . 'datamachine_agents';
	$network_access_table  = $wpdb->base_prefix . 'datamachine_agent_access';
	$network_tokens_table  = $wpdb->base_prefix . 'datamachine_agent_tokens';
	$migrated_agents       = 0;
	$migrated_access       = 0;

	$sites = get_sites( array( 'fields' => 'ids' ) );

	foreach ( $sites as $blog_id ) {
		$site_prefix = $wpdb->get_blog_prefix( $blog_id );

		// Skip the main site — its prefix IS the base_prefix, so the table is already network-level.
		if ( $site_prefix === $wpdb->base_prefix ) {
			// Set site_scope on existing main-site agents that don't have one yet.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$network_agents_table}` SET site_scope = %d WHERE site_scope IS NULL",
					(int) $blog_id
				)
			);
			continue;
		}

		$site_agents_table = $site_prefix . 'datamachine_agents';
		$site_access_table = $site_prefix . 'datamachine_agent_access';
		$site_tokens_table = $site_prefix . 'datamachine_agent_tokens';

		// Check if per-site agents table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $site_agents_table ) );
		if ( ! $table_exists ) {
			continue;
		}

		// Get all agents from the per-site table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$site_agents = $wpdb->get_results( "SELECT * FROM `{$site_agents_table}`", ARRAY_A );

		if ( empty( $site_agents ) ) {
			continue;
		}

		foreach ( $site_agents as $agent ) {
			$old_agent_id = (int) $agent['agent_id'];

			// Check if slug already exists in network table.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT agent_id FROM `{$network_agents_table}` WHERE agent_slug = %s",
					$agent['agent_slug']
				),
				ARRAY_A
			);

			if ( $existing ) {
				// Slug already exists in network table — skip this agent.
				continue;
			}

			// Insert into network table with site_scope.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$network_agents_table,
				array(
					'agent_slug'   => $agent['agent_slug'],
					'agent_name'   => $agent['agent_name'],
					'owner_id'     => (int) $agent['owner_id'],
					'site_scope'   => (int) $blog_id,
					'agent_config' => $agent['agent_config'],
					'status'       => $agent['status'],
					'created_at'   => $agent['created_at'],
					'updated_at'   => $agent['updated_at'],
				),
				array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
			);

			$new_agent_id = (int) $wpdb->insert_id;

			if ( $new_agent_id <= 0 ) {
				continue;
			}

			++$migrated_agents;

			// Migrate access grants for this agent.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$site_access = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM `{$site_access_table}` WHERE agent_id = %d", $old_agent_id ),
				ARRAY_A
			);

			foreach ( $site_access as $access ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->insert(
					$network_access_table,
					array(
						'agent_id'   => $new_agent_id,
						'user_id'    => (int) $access['user_id'],
						'role'       => $access['role'],
						'granted_at' => $access['granted_at'],
					),
					array( '%d', '%d', '%s', '%s' )
				);
				++$migrated_access;
			}

			// Migrate tokens for this agent (if any).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$token_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $site_tokens_table ) );
			if ( $token_table_exists ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$site_tokens = $wpdb->get_results(
					$wpdb->prepare( "SELECT * FROM `{$site_tokens_table}` WHERE agent_id = %d", $old_agent_id ),
					ARRAY_A
				);

				foreach ( $site_tokens as $token ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$wpdb->insert(
						$network_tokens_table,
						array(
							'agent_id'     => $new_agent_id,
							'token_hash'   => $token['token_hash'],
							'token_prefix' => $token['token_prefix'],
							'label'        => $token['label'],
							'capabilities' => $token['capabilities'],
							'last_used_at' => $token['last_used_at'],
							'expires_at'   => $token['expires_at'],
							'created_at'   => $token['created_at'],
						),
						array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
					);
				}
			}
		}
	}

	update_site_option( 'datamachine_agents_network_migrated', true );

	if ( $migrated_agents > 0 || $migrated_access > 0 ) {
		do_action(
			'datamachine_log',
			'info',
			'Migrated per-site agents to network-scoped tables',
			array(
				'agents_migrated' => $migrated_agents,
				'access_migrated' => $migrated_access,
			)
		);
	}
}

/**
 * Drop orphaned per-site agent tables after network migration.
 *
 * After datamachine_migrate_agents_to_network_scope() has consolidated
 * all agent data into the network-scoped tables (base_prefix), the
 * per-site copies (e.g. c8c_7_datamachine_agents) serve no purpose.
 * They can't be queried (all repositories use base_prefix) and their
 * presence is confusing.
 *
 * This function drops the orphaned per-site agent, access, and token
 * tables for every subsite. Idempotent — safe to call multiple times.
 * Only runs on multisite after the network migration flag is set.
 *
 * @since 0.43.0
 */
function datamachine_drop_orphaned_agent_tables() {
	if ( ! is_multisite() ) {
		return;
	}

	if ( ! get_site_option( 'datamachine_agents_network_migrated' ) ) {
		return;
	}

	if ( get_site_option( 'datamachine_orphaned_agent_tables_dropped' ) ) {
		return;
	}

	global $wpdb;

	$table_suffixes = array(
		'datamachine_agents',
		'datamachine_agent_access',
		'datamachine_agent_tokens',
	);

	$sites   = get_sites( array( 'fields' => 'ids' ) );
	$dropped = 0;

	foreach ( $sites as $blog_id ) {
		$site_prefix = $wpdb->get_blog_prefix( $blog_id );

		// Skip the main site — its prefix IS the base_prefix,
		// so these are the canonical network tables.
		if ( $site_prefix === $wpdb->base_prefix ) {
			continue;
		}

		foreach ( $table_suffixes as $suffix ) {
			$table_name = $site_prefix . $suffix;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

			if ( $exists ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DROP TABLE `{$table_name}`" );
				++$dropped;
			}
		}
	}

	update_site_option( 'datamachine_orphaned_agent_tables_dropped', true );

	if ( $dropped > 0 ) {
		do_action(
			'datamachine_log',
			'info',
			'Dropped orphaned per-site agent tables after network migration',
			array( 'tables_dropped' => $dropped )
		);
	}
}

/**
 * Migrate agent_ping step types to system_task steps in flow configs.
 *
 * Converts existing agent_ping steps to system_task steps with
 * task: 'agent_ping' in handler_config. Preserves webhook_url, prompt,
 * auth settings, queue_enabled, and prompt_queue.
 *
 * Idempotent: guarded by datamachine_agent_ping_migrated option.
 *
 * @since 0.60.0
 */
function datamachine_migrate_agent_ping_to_system_task(): void {
	if ( get_option( 'datamachine_agent_ping_migrated', false ) ) {
		return;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'datamachine_flows';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix.
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	// phpcs:enable WordPress.DB.PreparedSQL
	if ( ! $table_exists ) {
		update_option( 'datamachine_agent_ping_migrated', true, true );
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix.
	$rows = $wpdb->get_results( "SELECT flow_id, flow_config FROM {$table}", ARRAY_A );
	// phpcs:enable WordPress.DB.PreparedSQL
	if ( empty( $rows ) ) {
		update_option( 'datamachine_agent_ping_migrated', true, true );
		return;
	}

	$migrated = 0;

	foreach ( $rows as $row ) {
		$flow_config = json_decode( $row['flow_config'], true );
		if ( ! is_array( $flow_config ) ) {
			continue;
		}

		$changed = false;
		foreach ( $flow_config as $step_id => &$step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			// Only convert steps with agent_ping step type.
			if ( 'agent_ping' !== ( $step['step_type'] ?? '' ) ) {
				continue;
			}

			// Extract existing agent_ping handler config.
			$old_config = $step['handler_configs']['agent_ping'] ?? array();

			// Build new system_task handler_config.
			$new_config = array(
				'task'   => 'agent_ping',
				'params' => array(
					'webhook_url'      => $old_config['webhook_url'] ?? '',
					'prompt'           => $old_config['prompt'] ?? '',
					'auth_header_name' => $old_config['auth_header_name'] ?? '',
					'auth_token'       => $old_config['auth_token'] ?? '',
					'reply_to'         => $old_config['reply_to'] ?? '',
				),
			);

			// Convert step type and handler references.
			$step['step_type']       = 'system_task';
			$step['handler_slugs']   = array( 'system_task' );
			$step['handler_configs'] = array( 'system_task' => $new_config );

			// queue_enabled and prompt_queue stay at their existing positions
			// in the step config — no changes needed, they're already there.

			$changed = true;
		}
		unset( $step );

		if ( $changed ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array( 'flow_config' => wp_json_encode( $flow_config ) ),
				array( 'flow_id' => $row['flow_id'] ),
				array( '%s' ),
				array( '%d' )
			);
			++$migrated;
		}
	}

	update_option( 'datamachine_agent_ping_migrated', true, true );

	if ( $migrated > 0 ) {
		do_action(
			'datamachine_log',
			'info',
			'Migrated agent_ping steps to system_task steps in flow configs',
			array( 'flows_updated' => $migrated )
		);
	}
}
