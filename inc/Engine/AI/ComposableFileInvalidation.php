<?php
/**
 * Composable File Invalidation
 *
 * Rebuilds on-disk composable files (AGENTS.md and any others registered via
 * MemoryFileRegistry as `composable => true`) when the underlying state
 * changes. Plugin (de)activation is handled by default because it adds or
 * removes SectionRegistry entries. Plugins whose sections read live state
 * register their own state-change hooks via the
 * `datamachine_composable_invalidation_hooks` filter so files stay in sync
 * without manual `wp datamachine agent compose` runs.
 *
 * Regeneration is debounced to one run per 60 seconds so frequent-fire hooks
 * (e.g. save_post) are safe to register.
 *
 * @package DataMachine\Engine\AI
 * @since   0.75.0
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

class ComposableFileInvalidation {

	/**
	 * Transient key guarding the 60-second debounce window.
	 */
	private const DEBOUNCE_TRANSIENT = 'datamachine_composable_regenerating';

	/**
	 * Regenerate every registered composable file, debounced.
	 *
	 * @return void
	 */
	public static function regenerate(): void {
		if ( get_transient( self::DEBOUNCE_TRANSIENT ) ) {
			return;
		}
		set_transient( self::DEBOUNCE_TRANSIENT, 1, 60 );

		if ( ! class_exists( '\DataMachine\Engine\AI\ComposableFileGenerator' ) ) {
			return;
		}

		ComposableFileGenerator::regenerate_all();
	}

	/**
	 * Wire the regeneration callback to plugin-lifecycle and plugin-declared hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		$callback = array( self::class, 'regenerate' );

		add_action( 'activated_plugin', $callback );
		add_action( 'deactivated_plugin', $callback );

		/**
		 * WordPress hook names that should trigger composable file regeneration.
		 *
		 * Plugins contributing live state to SectionRegistry callbacks register
		 * the action/filter names they fire on change. The regeneration is
		 * debounced to one run per 60 seconds.
		 *
		 * @since 0.75.0
		 *
		 * @param string[] $hooks Hook names to watch.
		 */
		$extra_hooks = apply_filters( 'datamachine_composable_invalidation_hooks', array() );
		if ( ! is_array( $extra_hooks ) ) {
			return;
		}

		foreach ( $extra_hooks as $hook ) {
			if ( is_string( $hook ) && '' !== $hook ) {
				add_action( $hook, $callback );
			}
		}
	}
}
