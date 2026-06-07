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

use WP_Agent_Context_Section_Registry;

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
	 *     @type string $owner       Logical owner of the section. Defaults to source_plugin.
	 *     @type string $freshness   Freshness model: static, generated, snapshot, conditional, or custom.
	 *     @type string $conditions  Human-readable inclusion conditions, or '-' when unconditional.
	 *     @type string $source_plugin Plugin slug/path that owns the registration.
	 *     @type string $source_file   File that registered the section.
	 *     @type string $source_callback Callback that renders the section.
	 *     @type string $registered_at WordPress hook active during registration.
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

		$section_callback = static function ( array $context, array $section ) use ( $callback ) {
			unset( $section );
			return call_user_func( $callback, $context );
		};

		$provenance = array_merge( self::registration_provenance( $callback ), array_intersect_key( $args, array_flip( array( 'source_plugin', 'source_file', 'source_callback', 'registered_at' ) ) ) );
		$owner      = $args['owner'] ?? $args['source_plugin'] ?? $provenance['source_plugin'];

		self::$sections[ $filename ][ $slug ] = array(
			'slug'            => $slug,
			'priority'        => $priority,
			'callback'        => $section_callback,
			'label'           => $args['label'] ?? self::slug_to_label( $slug ),
			'description'     => $args['description'] ?? '',
			'owner'           => is_string( $owner ) && '' !== trim( $owner ) ? trim( $owner ) : '-',
			'freshness'       => isset( $args['freshness'] ) && is_string( $args['freshness'] ) && '' !== trim( $args['freshness'] ) ? trim( $args['freshness'] ) : '-',
			'conditions'      => isset( $args['conditions'] ) && is_string( $args['conditions'] ) && '' !== trim( $args['conditions'] ) ? trim( $args['conditions'] ) : '-',
			'source_plugin'   => $provenance['source_plugin'],
			'source_file'     => $provenance['source_file'],
			'source_callback' => $provenance['source_callback'],
			'registered_at'   => $provenance['registered_at'],
		);

		WP_Agent_Context_Section_Registry::register(
			MemoryFileRegistry::context_slug_for_filename( $filename ),
			$slug,
			$priority,
			$section_callback,
			array(
				'label'            => self::$sections[ $filename ][ $slug ]['label'],
				'description'      => self::$sections[ $filename ][ $slug ]['description'],
				'retrieval_policy' => $args['retrieval_policy'] ?? null,
				'modes'            => $args['modes'] ?? array( MemoryFileRegistry::MODE_ALL ),
				'meta'             => array(
					'filename'        => $filename,
					'owner'           => self::$sections[ $filename ][ $slug ]['owner'],
					'freshness'       => self::$sections[ $filename ][ $slug ]['freshness'],
					'conditions'      => self::$sections[ $filename ][ $slug ]['conditions'],
					'source_plugin'   => self::$sections[ $filename ][ $slug ]['source_plugin'],
					'source_file'     => self::$sections[ $filename ][ $slug ]['source_file'],
					'source_callback' => self::$sections[ $filename ][ $slug ]['source_callback'],
					'registered_at'   => self::$sections[ $filename ][ $slug ]['registered_at'],
				),
			)
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
		WP_Agent_Context_Section_Registry::unregister( MemoryFileRegistry::context_slug_for_filename( $filename ), $slug );
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
	 * Get one registered section for a composable file.
	 *
	 * @since x.y.z
	 *
	 * @param string $filename Composable filename.
	 * @param string $slug     Section identifier.
	 * @return array<string, mixed>|null Section metadata, or null when not registered.
	 */
	public static function get_section( string $filename, string $slug ): ?array {
		$filename = sanitize_file_name( $filename );
		$slug     = sanitize_key( $slug );

		if ( empty( $filename ) || empty( $slug ) ) {
			return null;
		}

		$sections = self::get_sections( $filename );
		return $sections[ $slug ] ?? null;
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
		self::ensure_action_fired();

		$composition = WP_Agent_Context_Section_Registry::compose( MemoryFileRegistry::context_slug_for_filename( $filename ), $context );
		$content     = $composition->content;

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
		WP_Agent_Context_Section_Registry::reset();
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

	/**
	 * Infer registration provenance from the caller frame and callback.
	 *
	 * @param callable $callback Section render callback.
	 * @return array<string, string>
	 */
	private static function registration_provenance( callable $callback ): array {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Intentional provenance capture for registered composable sections.
		$trace         = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
		$source_file   = '';
		$registered_at = function_exists( 'current_filter' ) ? (string) current_filter() : '';

		foreach ( $trace as $frame ) {
			$file = (string) ( $frame['file'] ?? '' );
			if ( '' === $file || __FILE__ === $file ) {
				continue;
			}

			$source_file = $file;
			break;
		}

		return array(
			'source_plugin'   => self::source_plugin_from_file( $source_file ),
			'source_file'     => self::normalize_source_file( $source_file ),
			'source_callback' => self::describe_callback( $callback ),
			'registered_at'   => '' !== $registered_at ? $registered_at : '-',
		);
	}

	/**
	 * Normalize a source file for operator-facing output.
	 *
	 * @param string $file Absolute file path.
	 * @return string Relative plugin/site path when possible.
	 */
	private static function normalize_source_file( string $file ): string {
		if ( '' === $file ) {
			return '-';
		}

		if ( function_exists( 'plugin_basename' ) ) {
			$plugin_basename = plugin_basename( $file );
			if ( is_string( $plugin_basename ) && '' !== $plugin_basename && $plugin_basename !== $file ) {
				return $plugin_basename;
			}
		}

		if ( defined( 'ABSPATH' ) ) {
			$root = rtrim( (string) ABSPATH, '/\\' ) . DIRECTORY_SEPARATOR;
			if ( 0 === strpos( $file, $root ) ) {
				return ltrim( substr( $file, strlen( $root ) ), '/\\' );
			}
		}

		return basename( $file );
	}

	/**
	 * Derive a plugin owner from a source file.
	 *
	 * @param string $file Absolute file path.
	 * @return string Plugin slug/path, or '-' when unavailable.
	 */
	private static function source_plugin_from_file( string $file ): string {
		$source_file = self::normalize_source_file( $file );
		if ( '-' === $source_file ) {
			return '-';
		}

		$parts = explode( '/', str_replace( '\\', '/', $source_file ) );
		return $parts[0] ?? '-';
	}

	/**
	 * Describe a PHP callback for CLI provenance output.
	 *
	 * @param callable $callback Callback to describe.
	 * @return string Human-readable callback name.
	 */
	private static function describe_callback( callable $callback ): string {
		if ( is_string( $callback ) ) {
			return $callback;
		}

		if ( $callback instanceof \Closure ) {
			return 'Closure';
		}

		if ( is_array( $callback ) && 2 === count( $callback ) ) {
			$target = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			return $target . '::' . (string) $callback[1];
		}

		if ( is_object( $callback ) ) {
			return get_class( $callback ) . '::__invoke';
		}

		return '-';
	}
}
