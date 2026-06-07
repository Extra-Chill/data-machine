<?php
/**
 * Centralized WP_Filesystem initialization and access.
 *
 * Provides a single point of access for the WordPress Filesystem API
 * across all file operations in the FilesRepository module.
 *
 * @package DataMachine\Core\FilesRepository
 * @since 0.11.5
 */

namespace DataMachine\Core\FilesRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilesystemHelper {

	/**
	 * Group-writable file permissions for agent files.
	 *
	 * Agent files live in wp-content/uploads/datamachine-files/agent/ and are
	 * written by PHP (www-data) but also need to be writable by the coding
	 * agent user (e.g. opencode) which runs in the www-data group.
	 *
	 * Using 0664 (owner rw, group rw, other r) instead of the default 0644
	 * ensures both users can read and write agent memory files.
	 *
	 * @since 0.32.0
	 * @var int
	 */
	const AGENT_FILE_PERMISSIONS = 0664;

	/**
	 * Group-writable directory permissions for agent directories.
	 *
	 * @since 0.32.0
	 * @var int
	 */
	const AGENT_DIR_PERMISSIONS = 0775;

	/**
	 * Cached initialization result
	 *
	 * @var bool|null
	 */
	private static ?bool $initialized = null;

	/**
	 * Initialize the WordPress Filesystem API.
	 *
	 * @return bool True if initialization succeeded
	 */
	public static function init(): bool {
		if ( null !== self::$initialized ) {
			return self::$initialized;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		self::$initialized = WP_Filesystem();
		return self::$initialized;
	}

	/**
	 * Get the WP_Filesystem instance.
	 *
	 * @return \WP_Filesystem_Base|null Filesystem instance or null if unavailable
	 */
	public static function get(): ?\WP_Filesystem_Base {
		if ( ! self::init() ) {
			return null;
		}
		global $wp_filesystem;
		return $wp_filesystem;
	}

	/**
	 * Set group-writable permissions on an agent file.
	 *
	 * Call this after writing any file in the agent directory to ensure
	 * both the web server user (www-data) and the coding agent user
	 * (e.g. opencode) can read and write the file.
	 *
	 * @since 0.32.0
	 * @param string $filepath Absolute path to the file.
	 * @return bool True if permissions were set, false on failure.
	 */
	public static function make_group_writable( string $filepath ): bool {
		if ( ! file_exists( $filepath ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		return chmod( $filepath, self::AGENT_FILE_PERMISSIONS );
	}

	/**
	 * Reset the cached initialization state.
	 *
	 * Useful for testing or when filesystem credentials change.
	 */
	public static function reset(): void {
		self::$initialized = null;
	}
}
