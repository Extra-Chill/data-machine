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
	 * Reset the cached initialization state.
	 *
	 * Useful for testing or when filesystem credentials change.
	 */
	public static function reset(): void {
		self::$initialized = null;
	}
}
