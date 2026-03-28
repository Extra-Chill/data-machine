//! datamachine_ensure — extracted from migrations.php.


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
	$ability = \DataMachine\Abilities\File\ScaffoldAbilities::get_ability();
	if ( ! $ability ) {
		return;
	}

	$default_user_id = \DataMachine\Core\FilesRepository\DirectoryManager::get_default_agent_user_id();

	$ability->execute( array( 'layer' => 'agent', 'user_id' => $default_user_id ) );
	$ability->execute( array( 'layer' => 'user', 'user_id' => $default_user_id ) );

	// Scaffold default context memory files (contexts/{context}.md).
	datamachine_ensure_default_context_files( $default_user_id );
}

/**
 * Scaffold default context memory files (contexts/{context}.md).
 *
 * Creates the contexts/ directory and writes default context files
 * for each core execution context. Existing files are never overwritten.
 *
 * @since 0.58.0
 *
 * @param int $user_id Default agent user ID.
 */
function datamachine_ensure_default_context_files( int $user_id ): void {
	$dm          = new \DataMachine\Core\FilesRepository\DirectoryManager();
	$contexts_dir = $dm->get_contexts_directory( array( 'user_id' => $user_id ) );

	if ( ! $dm->ensure_directory_exists( $contexts_dir ) ) {
		return;
	}

	global $wp_filesystem;
	if ( ! $wp_filesystem ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		\WP_Filesystem();
	}

	$defaults = datamachine_get_default_context_files();

	foreach ( $defaults as $slug => $content ) {
		$filepath = trailingslashit( $contexts_dir ) . $slug . '.md';
		if ( file_exists( $filepath ) ) {
			continue;
		}
		$wp_filesystem->put_contents( $filepath, $content, FS_CHMOD_FILE );
	}
}
