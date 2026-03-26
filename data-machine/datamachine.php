//! datamachine — extracted from data-machine.php.


function datamachine_activate_plugin_defaults( $network_wide = false ) {
	if ( is_multisite() && $network_wide ) {
		datamachine_for_each_site( 'datamachine_activate_defaults_for_site' );
	} else {
		datamachine_activate_defaults_for_site();
	}
}

/**
 * Set default settings for a single site.
 */
function datamachine_activate_defaults_for_site() {
	$default_settings = array(
		'disabled_tools'              => array(), // Opt-out pattern: empty = all tools enabled
		'enabled_pages'               => array(
			'pipelines' => true,
			'jobs'      => true,
			'logs'      => true,
			'settings'  => true,
		),
		'site_context_enabled'        => true,
		'cleanup_job_data_on_failure' => true,
	);

	add_option( 'datamachine_settings', $default_settings );
}

/**
 * Scan directory for PHP files and instantiate classes.
 * Classes are expected to self-register in their constructors.
 */
function datamachine_scan_and_instantiate( $directory ) {
	$files = glob( $directory . '/*.php' );

	foreach ( $files as $file ) {
		// Skip if it's a *Filters.php file (will be deleted)
		if ( strpos( basename( $file ), 'Filters.php' ) !== false ) {
			continue;
		}

		// Skip if it's a *Settings.php file
		if ( strpos( basename( $file ), 'Settings.php' ) !== false ) {
			continue;
		}

		// Include the file - classes will auto-instantiate
		include_once $file;
	}
}

function datamachine_allow_json_upload( $mimes ) {
	$mimes['json'] = 'application/json';
	return $mimes;
}

/**
 * Register Data Machine custom capabilities on roles.
 *
 * @since 0.37.0
 * @return void
 */
function datamachine_register_capabilities(): void {
	$capabilities = array(
		'datamachine_manage_agents',
		'datamachine_manage_flows',
		'datamachine_manage_settings',
		'datamachine_chat',
		'datamachine_use_tools',
		'datamachine_view_logs',
		'datamachine_create_own_agent',
	);

	$administrator = get_role( 'administrator' );
	if ( $administrator ) {
		foreach ( $capabilities as $capability ) {
			$administrator->add_cap( $capability );
		}
	}

	$editor = get_role( 'editor' );
	if ( $editor ) {
		$editor->add_cap( 'datamachine_chat' );
		$editor->add_cap( 'datamachine_use_tools' );
		$editor->add_cap( 'datamachine_view_logs' );
		$editor->add_cap( 'datamachine_create_own_agent' );
	}

	$author = get_role( 'author' );
	if ( $author ) {
		$author->add_cap( 'datamachine_chat' );
		$author->add_cap( 'datamachine_use_tools' );
		$author->add_cap( 'datamachine_create_own_agent' );
	}

	$subscriber = get_role( 'subscriber' );
	if ( $subscriber ) {
		$subscriber->add_cap( 'datamachine_chat' );
		$subscriber->add_cap( 'datamachine_create_own_agent' );
	}
}

/**
 * Remove Data Machine custom capabilities from roles.
 *
 * @since 0.37.0
 * @return void
 */
function datamachine_remove_capabilities(): void {
	$capabilities = array(
		'datamachine_manage_agents',
		'datamachine_manage_flows',
		'datamachine_manage_settings',
		'datamachine_chat',
		'datamachine_use_tools',
		'datamachine_view_logs',
		'datamachine_create_own_agent',
	);

	$roles = array( 'administrator', 'editor', 'author', 'subscriber' );

	foreach ( $roles as $role_name ) {
		$role = get_role( $role_name );
		if ( ! $role ) {
			continue;
		}

		foreach ( $capabilities as $capability ) {
			$role->remove_cap( $capability );
		}
	}
}

function datamachine_deactivate_plugin() {
	datamachine_remove_capabilities();

	// Unschedule all recurring maintenance actions.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'datamachine_cleanup_stale_claims', array(), 'datamachine-maintenance' );
		as_unschedule_all_actions( 'datamachine_cleanup_failed_jobs', array(), 'datamachine-maintenance' );
		as_unschedule_all_actions( 'datamachine_cleanup_completed_jobs', array(), 'datamachine-maintenance' );
		as_unschedule_all_actions( 'datamachine_cleanup_logs', array(), 'datamachine-maintenance' );
		as_unschedule_all_actions( 'datamachine_cleanup_processed_items', array(), 'datamachine-maintenance' );
		as_unschedule_all_actions( 'datamachine_cleanup_as_actions', array(), 'datamachine-maintenance' );
		as_unschedule_all_actions( 'datamachine_cleanup_old_files', array(), 'datamachine-files' );
		as_unschedule_all_actions( 'datamachine_cleanup_chat_sessions', array(), 'datamachine-chat' );
	}
}

/**
 * Plugin activation handler.
 *
 * Creates database tables, log directory, and re-schedules any flows
 * with non-manual scheduling intervals.
 *
 * @param bool $network_wide Whether the plugin is being network-activated.
 */
function datamachine_activate_plugin( $network_wide = false ) {
	// Agent tables are network-scoped — create once regardless of activation mode.
	datamachine_create_network_agent_tables();

	if ( is_multisite() && $network_wide ) {
		datamachine_for_each_site( 'datamachine_activate_for_site' );
	} else {
		datamachine_activate_for_site();
	}
}

/**
 * Create network-scoped agent tables.
 *
 * Agent identity, tokens, and access grants are shared across the multisite
 * network, following the WordPress pattern where wp_users/wp_usermeta use
 * base_prefix while per-site content uses site-specific prefixes.
 *
 * Safe to call multiple times — dbDelta is idempotent.
 */
function datamachine_create_network_agent_tables() {
	\DataMachine\Core\Database\Agents\Agents::create_table();
	\DataMachine\Core\Database\Agents\Agents::ensure_site_scope_column();
	\DataMachine\Core\Database\Agents\AgentAccess::create_table();
	\DataMachine\Core\Database\Agents\AgentTokens::create_table();
}

/**
 * Run activation tasks for a single site.
 *
 * Creates tables, log directory, default memory files, and re-schedules flows.
 * Called directly on single-site, or per-site during network activation and
 * new site creation.
 */
function datamachine_activate_for_site() {
	datamachine_register_capabilities();

	// Create logs table first — other table migrations log messages during creation.
	\DataMachine\Core\Database\Logs\LogRepository::create_table();

	// Agent tables are network-scoped (base_prefix) — ensure they exist.
	// Safe to call per-site because dbDelta + base_prefix is idempotent.
	datamachine_create_network_agent_tables();

	$db_pipelines = new \DataMachine\Core\Database\Pipelines\Pipelines();
	$db_pipelines->create_table();
	$db_pipelines->migrate_columns();

	$db_flows = new \DataMachine\Core\Database\Flows\Flows();
	$db_flows->create_table();
	$db_flows->migrate_columns();

	$db_jobs = new \DataMachine\Core\Database\Jobs\Jobs();
	$db_jobs->create_table();

	$db_processed_items = new \DataMachine\Core\Database\ProcessedItems\ProcessedItems();
	$db_processed_items->create_table();

	$db_identity_index = new \DataMachine\Core\Database\PostIdentityIndex\PostIdentityIndex();
	$db_identity_index->create_table();

	\DataMachine\Core\Database\Chat\Chat::create_table();
	\DataMachine\Core\Database\Chat\Chat::ensure_context_column();
	\DataMachine\Core\Database\Chat\Chat::ensure_agent_id_column();

	// Ensure default agent memory files exist.
	datamachine_ensure_default_memory_files();

	// Run layered architecture migration (idempotent).
	datamachine_migrate_to_layered_architecture();

	// Migrate flow_config handler keys from singular to plural (idempotent).
	datamachine_migrate_handler_keys_to_plural();

	// Backfill agent_id on pipelines, flows, and jobs from user_id→owner_id mapping (idempotent).
	datamachine_backfill_agent_ids();

	// Assign orphaned resources (agent_id IS NULL) to sole agent on single-agent installs (idempotent).
	datamachine_assign_orphaned_resources_to_sole_agent();

	// Migrate USER.md to network-scoped paths and create NETWORK.md on multisite (idempotent).
	datamachine_migrate_user_md_to_network_scope();

	// Migrate per-site agents to network-scoped tables (idempotent).
	datamachine_migrate_agents_to_network_scope();

	// Drop orphaned per-site agent tables left behind by the migration (idempotent).
	datamachine_drop_orphaned_agent_tables();

	// Regenerate SITE.md with enriched content and clean up legacy SiteContext transient.
	datamachine_regenerate_site_md();
	delete_transient( 'datamachine_site_context_data' );

	// Clean up legacy per-agent-type log level options (idempotent).
	foreach ( array( 'pipeline', 'chat', 'system' ) as $legacy_agent_type ) {
		delete_option( "datamachine_log_level_{$legacy_agent_type}" );
	}

	// Re-schedule any flows with non-manual scheduling
	datamachine_activate_scheduled_flows();

	// Track DB schema version so deploy-time migrations auto-run.
	update_option( 'datamachine_db_version', DATAMACHINE_VERSION, true );
}

/**
 * Resolve or create first-class agent ID for a WordPress user.
 *
 * @since 0.37.0
 *
 * @param int $user_id WordPress user ID.
 * @return int Agent ID, or 0 when resolution fails.
 */
function datamachine_resolve_or_create_agent_id( int $user_id ): int {
	$user_id = absint( $user_id );

	if ( $user_id <= 0 ) {
		return 0;
	}

	$agents_repo = new \DataMachine\Core\Database\Agents\Agents();
	$existing    = $agents_repo->get_by_owner_id( $user_id );

	if ( ! empty( $existing['agent_id'] ) ) {
		return (int) $existing['agent_id'];
	}

	$user = get_user_by( 'id', $user_id );
	if ( ! $user ) {
		return 0;
	}

	$agent_slug  = sanitize_title( (string) $user->user_login );
	$agent_name  = (string) $user->display_name;
	$agent_model = \DataMachine\Core\PluginSettings::getContextModel( 'chat' );

	return $agents_repo->create_if_missing(
		$agent_slug,
		$agent_name,
		$user_id,
		array(
			'model' => array(
				'default' => $agent_model,
			),
		)
	);
}

/**
 * Run a callback for every site on the network.
 *
 * Switches to each site, runs the callback, then restores. Used by
 * activation hooks and new site hooks to ensure per-site setup.
 *
 * @param callable $callback Function to call in each site context.
 */
function datamachine_for_each_site( callable $callback ) {
	$sites = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $sites as $blog_id ) {
		switch_to_blog( $blog_id );
		$callback();
		restore_current_blog();
	}
}

/**
 * Create Data Machine tables and defaults when a new site is added to the network.
 *
 * Only runs if Data Machine is network-active.
 *
 * @param WP_Site $new_site New site object.
 */
function datamachine_on_new_site( \WP_Site $new_site ) {
	if ( ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
		return;
	}

	switch_to_blog( $new_site->blog_id );
	datamachine_activate_defaults_for_site();
	datamachine_activate_for_site();
	restore_current_blog();
}

function datamachine_check_requirements() {
	if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>';
				printf(
					esc_html( 'Data Machine requires PHP %2$s or higher. You are running PHP %1$s.' ),
					esc_html( PHP_VERSION ),
					'8.0'
				);
				echo '</p></div>';
			}
		);
		return false;
	}

	global $wp_version;
	$current_wp_version = $wp_version ?? '0.0.0';
	if ( version_compare( $current_wp_version, '6.9', '<' ) ) {
		add_action(
			'admin_notices',
			function () use ( $current_wp_version ) {
				echo '<div class="notice notice-error"><p>';
				printf(
					esc_html( 'Data Machine requires WordPress %2$s or higher. You are running WordPress %1$s.' ),
					esc_html( $current_wp_version ),
					'6.9'
				);
				echo '</p></div>';
			}
		);
		return false;
	}

	if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>';
				echo esc_html( 'Data Machine: Composer dependencies are missing. Please run "composer install" or contact Chubes to report a bug.' );
				echo '</p></div>';
			}
		);
		return false;
	}

	return true;
}
