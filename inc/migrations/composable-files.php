<?php
/**
 * Data Machine — Composable file auto-regeneration.
 *
 * Regenerates composable files (e.g. AGENTS.md) when the plugin landscape
 * changes (activation/deactivation), since those events add or remove
 * registered sections.
 *
 * @package DataMachine
 * @since   0.68.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Regenerate all composable files.
 *
 * Debounced via a 60-second transient to prevent excessive writes during
 * bulk plugin operations. Uses ComposableFileGenerator::regenerate_all()
 * which iterates every composable file in the MemoryFileRegistry.
 *
 * @since 0.68.0
 * @return void
 */
function datamachine_regenerate_composable_files(): void {
	// Debounce: skip if we regenerated in the last 60 seconds.
	if ( get_transient( 'datamachine_composable_regenerating' ) ) {
		return;
	}
	set_transient( 'datamachine_composable_regenerating', 1, 60 );

	if ( ! class_exists( '\DataMachine\Engine\AI\ComposableFileGenerator' ) ) {
		return;
	}

	\DataMachine\Engine\AI\ComposableFileGenerator::regenerate_all();
}

/**
 * Register hooks that trigger composable file regeneration.
 *
 * Plugin activation/deactivation can add or remove SectionRegistry entries,
 * so the composable files must be rebuilt to reflect the new section set.
 *
 * @since 0.68.0
 * @return void
 */
function datamachine_register_composable_file_invalidation(): void {
	$callback = 'datamachine_regenerate_composable_files';

	add_action( 'activated_plugin', $callback );
	add_action( 'deactivated_plugin', $callback );
}
