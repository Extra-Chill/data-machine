<?php
/**
 * Section Registry
 *
 * Central registry for composable file sections. Plugins register content
 * sections that are assembled into composable memory files (e.g. AGENTS.md).
 * Each section has a slug, priority, callback, and optional metadata.
 *
 * Extension point: the `datamachine_sections` action fires once per request
 * when the registry is first consumed, allowing extensions to register
 * their sections.
 *
 * @package DataMachine\Engine\AI
 * @since   0.66.0
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

class SectionRegistry {

	/**
	 * Registered sections, keyed by filename then slug.
	 *
	 * @var array<string, array<string, array>> Filename => slug => section metadata.
	 */
	private static array $sections = array();

	/**
	 * Whether the action has been fired.
	 *
	 * @var bool
	 */
	private static bool $action_fired = false;

	/**
	 * Register a section within a composable file.
	 *
	 * @since 0.66.0
	 *
	 * @param string   $filename Target composable filename (e.g. 'AGENTS.md').
	 * @param string   $slug     Unique section identifier (e.g. 'mattic', 'datamachine-memory').
	 * @param int      $priority Sort order. Lower numbers appear first.
	 * @param callable $callback Returns the section content string.
	 * @param array    $args     {
	 *     Optional. Section metadata.
	 *
	 *     @type string $label       Human-readable label.
	 *     @type string $description Description of what this section provides.
	 * }
	 * @return void
	 */
	public static function register( string $filename, string $slug, int $priority, callable $callback, array $args = array() ): void {
		$filename = sanitize_file_name( $filename );
		$slug     = sanitize_key( $slug );

		if ( empty( $filename ) || empty( $slug ) ) {
			return;
		}

		if ( ! isset( self::$sections[ $filename ] ) ) {
			self::$sections[ $filename ] = array();
		}

		self::$sections[ $filename ][ $slug ] = array(
			'slug'        => $slug,
			'priority'    => $priority,
			'callback'    => $callback,
			'label'       => $args['label'] ?? self::slug_to_label( $slug ),
			'description' => $args['description'] ?? '',
		);
	}

	/**
	 * Deregister a section from a composable file.
	 *
	 * @since 0.66.0
	 *
	 * @param string $filename Target composable filename.
	 * @param string $slug     Section identifier to remove.
	 * @return void
	 */
	public static function deregister( string $filename, string $slug ): void {
		$filename = sanitize_file_name( $filename );
		$slug     = sanitize_key( $slug );

		unset( self::$sections[ $filename ][ $slug ] );
	}

	/**
	 * Get all sections for a composable file, sorted by priority.
	 *
	 * @since 0.66.0
	 *
	 * @param string $filename Composable filename.
	 * @return array<string, array> Slug => section metadata, sorted by priority ascending.
	 */
	public static function get_sections( string $filename ): array {
		self::ensure_action_fired();

		$filename = sanitize_file_name( $filename );
		$sections = self::$sections[ $filename ] ?? array();

		uasort(
			$sections,
			function ( $a, $b ) {
				return $a['priority'] <=> $b['priority'];
			}
		);

		return $sections;
	}

	/**
	 * Generate assembled content for a composable file.
	 *
	 * Invokes each section's callback in priority order and concatenates
	 * the results with double newlines. Applies the
	 * `datamachine_composable_content` filter for last-chance modifications.
	 *
	 * @since 0.66.0
	 *
	 * @param string $filename Composable filename.
	 * @param array  $context  Optional context passed to callbacks and filter.
	 * @return string Assembled file content.
	 */
	public static function generate( string $filename, array $context = array() ): string {
		$sections = self::get_sections( $filename );
		$parts    = array();

		foreach ( $sections as $slug => $section ) {
			$output = call_user_func( $section['callback'], $context );

			if ( is_string( $output ) && '' !== trim( $output ) ) {
				$parts[] = trim( $output );
			}
		}

		$content = implode( "\n\n", $parts );

		/**
		 * Filter the assembled content of a composable file.
		 *
		 * Fires after all registered sections have been invoked and
		 * concatenated. Allows last-chance modifications before the
		 * file is written to disk.
		 *
		 * @since 0.66.0
		 *
		 * @param string $content  Assembled content.
		 * @param string $filename Composable filename.
		 * @param array  $context  Generation context.
		 */
		$content = apply_filters( 'datamachine_composable_content', $content, $filename, $context );

		return is_string( $content ) ? $content : '';
	}

	/**
	 * Check if any sections are registered for a composable file.
	 *
	 * @since 0.66.0
	 *
	 * @param string $filename Composable filename.
	 * @return bool
	 */
	public static function has_sections( string $filename ): bool {
		self::ensure_action_fired();

		$filename = sanitize_file_name( $filename );
		return ! empty( self::$sections[ $filename ] );
	}

	/**
	 * Get all filenames that have registered sections.
	 *
	 * @since 0.66.0
	 *
	 * @return string[]
	 */
	public static function get_filenames(): array {
		self::ensure_action_fired();

		return array_keys( array_filter(
			self::$sections,
			function ( $sections ) {
				return ! empty( $sections );
			}
		) );
	}

	/**
	 * Reset the registry. Primarily for testing.
	 *
	 * @since 0.66.0
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$sections     = array();
		self::$action_fired = false;
	}

	/**
	 * Fire the datamachine_sections action once per request.
	 *
	 * @return void
	 */
	private static function ensure_action_fired(): void {
		if ( ! self::$action_fired ) {
			/**
			 * Fires when the section registry is first consumed.
			 *
			 * Extensions register their composable file sections by calling
			 * SectionRegistry::register() inside this action callback.
			 *
			 * @since 0.66.0
			 *
			 * @param array<string, array<string, array>> $sections Current registry state (read-only snapshot).
			 */
			do_action( 'datamachine_sections', self::$sections );
			self::$action_fired = true;
		}
	}

	/**
	 * Derive a human-readable label from a section slug.
	 *
	 * @param string $slug The section slug.
	 * @return string Label.
	 */
	private static function slug_to_label( string $slug ): string {
		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}
}
