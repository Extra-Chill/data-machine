<?php
/**
 * Generic agent context section registry.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Context_Section_Registry' ) ) {
	/**
	 * Registers composable context sections independently from files or stores.
	 */
	final class WP_Agent_Context_Section_Registry {

		/**
		 * Registered sections keyed by context slug then section slug.
		 *
		 * @var array<string, array<string, array<string, mixed>>>
		 */
		private static array $sections = array();

		/**
		 * Whether extension hooks have been fired.
		 *
		 * @var bool
		 */
		private static bool $hooks_fired = false;

		/**
		 * Register a section for a composable context.
		 *
		 * @param string   $context_slug Context identifier.
		 * @param string   $section_slug Section identifier.
		 * @param int      $priority     Sort priority. Lower numbers compose first.
		 * @param callable $callback     Receives `(array $context, array $section)` and returns content.
		 * @param array<mixed>    $args         Section metadata.
		 * @return array<string, mixed>|null Normalized section metadata, or null on invalid input.
		 */
		public static function register( string $context_slug, string $section_slug, int $priority, callable $callback, array $args = array() ): ?array {
			$context_slug = self::sanitize_slug( $context_slug );
			$section_slug = self::sanitize_slug( $section_slug );

			if ( '' === $context_slug || '' === $section_slug ) {
				return null;
			}

			if ( ! isset( self::$sections[ $context_slug ] ) ) {
				self::$sections[ $context_slug ] = array();
			}

			$section = array(
				'context_slug'     => $context_slug,
				'slug'             => $section_slug,
				'priority'         => $priority,
				'callback'         => $callback,
				'retrieval_policy' => WP_Agent_Context_Injection_Policy::normalize( $args['retrieval_policy'] ?? null ),
				'modes'            => self::normalize_modes( $args['modes'] ?? array( WP_Agent_Memory_Registry::MODE_ALL ) ),
				'label'            => is_string( $args['label'] ?? null ) ? $args['label'] : self::slug_to_label( $section_slug ),
				'description'      => is_string( $args['description'] ?? null ) ? $args['description'] : '',
				'meta'             => is_array( $args['meta'] ?? null ) ? $args['meta'] : array(),
			);

			self::$sections[ $context_slug ][ $section_slug ] = $section;

			return $section;
		}

		/**
		 * Remove a registered section.
		 *
		 * @param string $context_slug Context identifier.
		 * @param string $section_slug Section identifier.
		 * @return void
		 */
		public static function unregister( string $context_slug, string $section_slug ): void {
			unset( self::$sections[ self::sanitize_slug( $context_slug ) ][ self::sanitize_slug( $section_slug ) ] );
		}

		/**
		 * Return sections for a context in priority order.
		 *
		 * @param string $context_slug Context identifier.
		 * @param array<mixed>  $context      Runtime context, optionally including `mode`.
		 * @return array<string, array<string, mixed>>
		 */
		public static function get_sections( string $context_slug, array $context = array() ): array {
			self::ensure_hooks_fired();

			$context_slug = self::sanitize_slug( $context_slug );
			$mode         = self::sanitize_slug( is_string( $context['mode'] ?? null ) ? $context['mode'] : '' );
			$sections     = self::$sections[ $context_slug ] ?? array();

			$sections = array_filter(
				$sections,
				static function ( array $section ) use ( $mode ): bool {
					$modes = self::normalize_modes( $section['modes'] ?? array( WP_Agent_Memory_Registry::MODE_ALL ) );
					return '' === $mode || in_array( WP_Agent_Memory_Registry::MODE_ALL, $modes, true ) || in_array( $mode, $modes, true );
				}
			);

			uasort(
				$sections,
				static function ( array $a, array $b ): int {
					$priority_order = $a['priority'] <=> $b['priority'];
					$a_slug         = is_string( $a['slug'] ?? null ) ? $a['slug'] : '';
					$b_slug         = is_string( $b['slug'] ?? null ) ? $b['slug'] : '';
					return 0 !== $priority_order ? $priority_order : strcmp( $a_slug, $b_slug );
				}
			);

			return $sections;
		}

		/**
		 * Compose a context from registered sections.
		 *
		 * @param string $context_slug Context identifier.
		 * @param array<mixed>  $context      Runtime context passed to callbacks.
		 * @return WP_Agent_Composable_Context
		 */
		public static function compose( string $context_slug, array $context = array() ): WP_Agent_Composable_Context {
			return WP_Agent_Composable_Context::compose( $context_slug, self::get_sections( $context_slug, $context ), $context );
		}

		/**
		 * Return context slugs that currently have sections.
		 *
		 * @return string[]
		 */
		public static function get_context_slugs(): array {
			self::ensure_hooks_fired();

			return array_keys( array_filter( self::$sections ) );
		}

		/**
		 * Reset registry state. Intended for tests.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$sections    = array();
			self::$hooks_fired = false;
		}

		/**
		 * Fire extension hook once.
		 *
		 * @return void
		 */
		private static function ensure_hooks_fired(): void {
			if ( self::$hooks_fired ) {
				return;
			}

			if ( function_exists( 'do_action' ) ) {
				do_action( 'agents_api_context_sections', self::$sections );
			}
			self::$hooks_fired = true;
		}

		/**
		 * @param mixed $modes Raw modes.
		 * @return string[]
		 */
		private static function normalize_modes( $modes ): array {
			if ( ! is_array( $modes ) || empty( $modes ) ) {
				return array( WP_Agent_Memory_Registry::MODE_ALL );
			}

			$normalized = array();
			foreach ( $modes as $mode ) {
				if ( ! is_string( $mode ) ) {
					continue;
				}

				$mode = self::sanitize_slug( $mode );
				if ( '' !== $mode ) {
					$normalized[] = $mode;
				}
			}

			$normalized = array_values( array_unique( $normalized ) );
			return empty( $normalized ) ? array( WP_Agent_Memory_Registry::MODE_ALL ) : $normalized;
		}

		/**
		 * @param string $slug Raw slug.
		 * @return string
		 */
		private static function sanitize_slug( string $slug ): string {
			$slug = strtolower( $slug );
			$slug = preg_replace( '/[^a-z0-9_\-]+/', '-', $slug );

			return trim( is_string( $slug ) ? $slug : '', '-' );
		}

		/**
		 * @param string $slug Section slug.
		 * @return string
		 */
		private static function slug_to_label( string $slug ): string {
			return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
		}
	}
}
