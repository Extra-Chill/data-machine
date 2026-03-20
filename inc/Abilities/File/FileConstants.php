<?php
/**
 * Shared constants and helpers for file abilities.
 *
 * Protection and layer routing are now driven by the MemoryFileRegistry.
 * The static methods here provide a stable API for consumers that need
 * to check protection status or layer routing without importing the
 * registry directly.
 *
 * @package DataMachine\Abilities\File
 * @since   0.38.0
 * @since   0.42.0 Delegated to MemoryFileRegistry for protection and layer.
 */

namespace DataMachine\Abilities\File;

use DataMachine\Engine\AI\MemoryFileRegistry;

defined( 'ABSPATH' ) || exit;

class FileConstants {

	/**
	 * Check if a file is protected from deletion.
	 *
	 * @param string $filename Filename to check.
	 * @return bool
	 */
	public static function is_protected( string $filename ): bool {
		return MemoryFileRegistry::is_protected( $filename );
	}

	/**
	 * Get all protected filenames.
	 *
	 * @return string[]
	 */
	public static function get_protected_files(): array {
		return MemoryFileRegistry::get_protected_filenames();
	}

	/**
	 * Get the layer a file belongs to, or null if not registered.
	 *
	 * @param string $filename Filename to check.
	 * @return string|null 'shared', 'agent', 'user', or null.
	 */
	public static function get_layer( string $filename ): ?string {
		return MemoryFileRegistry::get_layer( $filename );
	}

	/**
	 * Check if a file belongs to the user layer.
	 *
	 * @param string $filename Filename to check.
	 * @return bool
	 */
	public static function is_user_layer( string $filename ): bool {
		return MemoryFileRegistry::LAYER_USER === MemoryFileRegistry::get_layer( $filename );
	}

	/**
	 * Check if a file belongs to the shared layer.
	 *
	 * @param string $filename Filename to check.
	 * @return bool
	 */
	public static function is_shared_layer( string $filename ): bool {
		return MemoryFileRegistry::LAYER_SHARED === MemoryFileRegistry::get_layer( $filename );
	}

	/**
	 * Check if a file is editable by the given (or current) user.
	 *
	 * @since 0.50.0
	 *
	 * @param string $filename Filename to check.
	 * @param int    $user_id  Optional. User ID. 0 = current user.
	 * @return bool
	 */
	public static function is_editable( string $filename, int $user_id = 0 ): bool {
		return MemoryFileRegistry::is_editable( $filename, $user_id );
	}

	/**
	 * Get the raw edit capability for a file.
	 *
	 * @since 0.50.0
	 *
	 * @param string $filename Filename to check.
	 * @return bool|string|null true, false, capability string, or null if unregistered.
	 */
	public static function get_edit_capability( string $filename ) {
		return MemoryFileRegistry::get_edit_capability( $filename );
	}

	/**
	 * Legacy constant — kept for backward compatibility.
	 * Use is_protected() or get_protected_files() instead.
	 *
	 * @deprecated 0.42.0 Use FileConstants::is_protected() or MemoryFileRegistry::is_protected().
	 * @var string[]
	 */
	const PROTECTED_FILES = array( 'SOUL.md', 'MEMORY.md', 'SITE.md', 'RULES.md', 'USER.md' );

	/**
	 * Legacy constant — kept for backward compatibility.
	 * Use is_user_layer() or get_layer() instead.
	 *
	 * @deprecated 0.42.0 Use FileConstants::is_user_layer() or MemoryFileRegistry::get_layer().
	 * @var string[]
	 */
	const USER_LAYER_FILES = array( 'USER.md' );
}
