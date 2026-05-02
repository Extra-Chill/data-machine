<?php
/**
 * Data Machine — Layered architecture migration.
 *
 * Migrates existing user_id-scoped agent files to the layered architecture
 * and provides recursive directory copying utilities.
 *
 * @package DataMachine
 * @since 0.60.0
 */

defined( 'ABSPATH' ) || exit;

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
		// Compose from registered sections. Caller already guarantees the
		// shared dir exists; ComposableFileGenerator will set permissions.
		\DataMachine\Engine\AI\ComposableFileGenerator::regenerate( 'SITE.md' );
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
			$agent_model = \DataMachine\Core\PluginSettings::getModelForMode( 'chat' );

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
		$default_model   = \DataMachine\Core\PluginSettings::getModelForMode( 'chat' );

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
