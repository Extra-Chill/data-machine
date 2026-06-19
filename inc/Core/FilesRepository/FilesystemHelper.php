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
	 * Best-effort: the group-writable bit is a convenience, not a correctness
	 * requirement. On multisite installs the target file is frequently owned by
	 * www-data while the CLI runs as a different user in the www-data group, so
	 * an unconditional chmod() on a non-owned file fails with EPERM and PHP
	 * raises a Warning — even though setgid directories usually mean the file is
	 * already group-writable. We therefore skip the chmod when the bit is
	 * already set or when the process does not own the file, and suppress any
	 * residual warning so a failed chmod never surfaces in CLI stderr or the
	 * production error log.
	 *
	 * @since 0.32.0
	 * @param string $filepath Absolute path to the file.
	 * @return bool True if the file is (or was made) group-writable, false otherwise.
	 */
	public static function make_group_writable( string $filepath ): bool {
		if ( ! file_exists( $filepath ) ) {
			return false;
		}

		// Already group-writable (commonly via setgid dirs) — nothing to do.
		$perms = fileperms( $filepath );
		if ( false !== $perms && ( $perms & 0020 ) === 0020 ) {
			return true;
		}

		// chmod() only succeeds for the file owner (or root). On non-owned
		// files it fails with EPERM, so skip it rather than emit a Warning.
		if ( function_exists( 'posix_getuid' ) ) {
			$owner = fileowner( $filepath );
			if ( false !== $owner && posix_getuid() !== $owner ) {
				return false;
			}
		}

		// Suppress any residual warning — a failed chmod is non-fatal here.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.PHP.NoSilencedErrors.Discouraged
		return @chmod( $filepath, self::AGENT_FILE_PERMISSIONS );
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
