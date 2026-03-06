<?php
/**
 * Shared constants for file abilities.
 *
 * @package DataMachine\Abilities\File
 * @since   0.38.0
 */

namespace DataMachine\Abilities\File;

defined( 'ABSPATH' ) || exit;

class FileConstants {

	/**
	 * Core agent identity files that cannot be deleted.
	 *
	 * @var string[]
	 */
	const PROTECTED_FILES = array( 'SOUL.md', 'MEMORY.md' );

	/**
	 * Files that belong in the user layer rather than the agent identity layer.
	 *
	 * @var string[]
	 */
	const USER_LAYER_FILES = array( 'USER.md' );
}
