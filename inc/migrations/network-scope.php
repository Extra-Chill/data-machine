<?php
/**
 * Data Machine — Network scope migrations.
 *
 * Migrates USER.md, agents, and related tables from per-site scope to
 * network scope on multisite installations. Drops orphaned per-site
 * agent tables after consolidation.
 *
 * @package DataMachine
 * @since 0.60.0
 */

defined( 'ABSPATH' ) || exit;

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
		// Compose from registered sections. Generator self-skips on single-site
		// installs because every NETWORK.md section returns an empty string.
		\DataMachine\Engine\AI\ComposableFileGenerator::regenerate( 'NETWORK.md' );
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

	$network_agents_table = $wpdb->base_prefix . 'datamachine_agents';
	$network_access_table = $wpdb->base_prefix . 'datamachine_agent_access';
	$network_tokens_table = $wpdb->base_prefix . 'datamachine_agent_tokens';
	$migrated_agents      = 0;
	$migrated_access      = 0;

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
					'created_at'   => $agent['created_at'],
					'updated_at'   => $agent['updated_at'],
				),
				array( '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
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
