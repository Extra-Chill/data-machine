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
					$primary                = $step['handler_slugs'][0] ?? '';
					$config                 = $step['handler_config'] ?? array();
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
 *
 * @return array<string, string> Filename => content map for SOUL.md, USER.md, MEMORY.md.
 */
function datamachine_get_scaffold_defaults(): array {
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
	$soul = <<<MD
# Agent Soul

## Identity
You are an AI assistant managing {$site_name}.

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
 * Build shared SITE.md scaffold content from WordPress site data.
 *
 * @since 0.36.1
 * @return string
 */
function datamachine_get_site_scaffold_content(): string {
	$site_name        = get_bloginfo( 'name' ) ? get_bloginfo( 'name' ) : 'WordPress Site';
	$site_description = get_bloginfo( 'description' ) ? get_bloginfo( 'description' ) : '';
	$site_url         = home_url();
	$post_types       = get_post_types( array( 'public' => true ), 'names' );
	$taxonomies       = get_taxonomies( array( 'public' => true ), 'names' );
	$active_plugins   = get_option( 'active_plugins', array() );
	$theme_name       = wp_get_theme()->get( 'Name' ) ? wp_get_theme()->get( 'Name' ) : 'Unknown';

	if ( is_multisite() ) {
		$network_plugins = array_keys( get_site_option( 'active_sitewide_plugins', array() ) );
		$active_plugins  = array_unique( array_merge( $active_plugins, $network_plugins ) );
	}

	$plugin_names = array();
	foreach ( $active_plugins as $plugin_file ) {
		$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( function_exists( 'get_plugin_data' ) && file_exists( $plugin_path ) ) {
			$plugin_data = get_plugin_data( $plugin_path, false, false );
			$plugin_name = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : dirname( $plugin_file );
		} else {
			$dir         = dirname( $plugin_file );
			$plugin_name = '.' === $dir ? str_replace( '.php', '', basename( $plugin_file ) ) : $dir;
		}

		if ( 'data-machine' === strtolower( (string) $plugin_name ) || 0 === strpos( $plugin_file, 'data-machine/' ) ) {
			continue;
		}

		$plugin_names[] = $plugin_name;
	}

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
	$lines[] = '- **multisite:** ' . ( is_multisite() ? 'true' : 'false' );
	$lines[] = '';
	$lines[] = '## Content Model';
	$lines[] = '- **post_types:** ' . implode( ', ', $post_types );
	$lines[] = '- **taxonomies:** ' . implode( ', ', $taxonomies );
	$lines[] = '';
	$lines[] = '## Active Plugins';
	if ( ! empty( $plugin_names ) ) {
		$lines[] = '- ' . implode( "\n- ", $plugin_names );
	} else {
		$lines[] = '- (none)';
	}

	return implode( "\n", $lines ) . "\n";
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
 * @since 0.30.0
 */
function datamachine_ensure_default_memory_files() {
	$directory_manager = new \DataMachine\Core\FilesRepository\DirectoryManager();
	$default_user_id   = \DataMachine\Core\FilesRepository\DirectoryManager::get_default_agent_user_id();
	$agent_dir         = $directory_manager->get_agent_identity_directory_for_user( $default_user_id );
	$user_dir          = $directory_manager->get_user_directory( $default_user_id );

	// USER.md belongs in the user layer; everything else in the agent identity layer.
	$user_layer_files = array( 'USER.md' );

	if ( ! $directory_manager->ensure_directory_exists( $agent_dir ) ) {
		return;
	}
	if ( ! $directory_manager->ensure_directory_exists( $user_dir ) ) {
		return;
	}

	$fs = \DataMachine\Core\FilesRepository\FilesystemHelper::get();
	if ( ! $fs ) {
		return;
	}

	$defaults = datamachine_get_scaffold_defaults();

	foreach ( $defaults as $filename => $content ) {
		$target_dir = in_array( $filename, $user_layer_files, true ) ? $user_dir : $agent_dir;
		$filepath   = "{$target_dir}/{$filename}";

		if ( file_exists( $filepath ) ) {
			continue;
		}

		$fs->put_contents( $filepath, $content . "\n", FS_CHMOD_FILE );
		\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $filepath );

		do_action(
			'datamachine_log',
			'info',
			sprintf( 'Self-healing: created missing agent file %s with scaffold defaults.', $filename ),
			array( 'filename' => $filename )
		);
	}
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
	$agent_map = array();
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
					$created_id = datamachine_resolve_or_create_agent_id( $user_id );
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
		$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'datamachine_agents' )
	);

	if ( 1 !== $agent_count ) {
		// 0 agents: nothing to assign to. >1 agents: ambiguous, skip.
		update_option( 'datamachine_orphaned_resources_assigned', true, true );
		return;
	}

	// Get the sole agent's ID.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$agent_id = (int) $wpdb->get_var(
		$wpdb->prepare( 'SELECT agent_id FROM %i LIMIT 1', $wpdb->prefix . 'datamachine_agents' )
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
