<?php
/**
 * Memory File Registry
 *
 * Central registry for agent memory files injected into AI calls.
 * Pure container — no hardcoded files. Everything registers through
 * the same public API.
 *
 * @package DataMachine\Engine\AI
 * @since   0.30.0
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

class MemoryFileRegistry {

	/**
	 * Registered memory files.
	 *
	 * @var array<string, int> Filename => priority.
	 */
	private static array $files = array();

	/**
	 * Register a memory file.
	 *
	 * @param string $filename Filename relative to the agent directory.
	 * @param int    $priority Sort order. Lower numbers load first.
	 * @return void
	 */
	public static function register( string $filename, int $priority = 50 ): void {
		$filename = sanitize_file_name( $filename );

		if ( empty( $filename ) ) {
			return;
		}

		self::$files[ $filename ] = $priority;
	}

	/**
	 * Deregister a memory file.
	 *
	 * @param string $filename Filename to remove.
	 * @return void
	 */
	public static function deregister( string $filename ): void {
		unset( self::$files[ sanitize_file_name( $filename ) ] );
	}

	/**
	 * Check if a file is registered.
	 *
	 * @param string $filename Filename to check.
	 * @return bool
	 */
	public static function is_registered( string $filename ): bool {
		return isset( self::$files[ sanitize_file_name( $filename ) ] );
	}

	/**
	 * Get all registered files sorted by priority.
	 *
	 * @return array<string, int> Filename => priority, sorted ascending.
	 */
	public static function get_all(): array {
		$files = self::$files;
		asort( $files );
		return $files;
	}

	/**
	 * Get sorted filenames only.
	 *
	 * @return string[]
	 */
	public static function get_filenames(): array {
		return array_keys( self::get_all() );
	}

	/**
	 * Reset the registry. Primarily for testing.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$files = array();
	}
}
