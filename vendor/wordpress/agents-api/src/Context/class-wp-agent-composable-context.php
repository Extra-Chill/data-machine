<?php
/**
 * Composable agent context value object.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Composable_Context' ) ) {
	/**
	 * Represents the result of composing context sections.
	 */
	final class WP_Agent_Composable_Context {

		/**
		 * @param string $slug     Context slug.
		 * @param string $content  Composed content.
		 * @param array<mixed>  $sections Section metadata keyed by section slug.
		 * @param array<mixed>  $metadata Composition metadata.
		 */
		public function __construct(
			public readonly string $slug,
			public readonly string $content,
			public readonly array $sections = array(),
			public readonly array $metadata = array(),
		) {}

		/**
		 * Compose context content from ordered section metadata.
		 *
		 * @param string $context_slug Context identifier.
		 * @param array<mixed>  $sections     Section metadata keyed by section slug.
		 * @param array<mixed>  $context      Runtime context passed to section callbacks.
		 * @return self
		 */
		public static function compose( string $context_slug, array $sections, array $context = array() ): self {
			$parts    = array();
			$included = array();

			foreach ( $sections as $slug => $section ) {
				if ( ! is_array( $section ) || ! is_callable( $section['callback'] ?? null ) ) {
					continue;
				}

				$output = call_user_func( $section['callback'], $context, $section );
				if ( is_string( $output ) && '' !== trim( $output ) ) {
					$section_slug = $section['slug'] ?? '';
					$parts[]      = trim( $output );
					$included[]   = is_string( $slug ) ? $slug : ( is_string( $section_slug ) ? $section_slug : '' );
				}
			}

			$content = implode( "\n\n", $parts );
			if ( function_exists( 'apply_filters' ) ) {
				$filtered = apply_filters( 'agents_api_composable_context_content', $content, $context_slug, $sections, $context );
				$content  = is_string( $filtered ) ? $filtered : $content;
			}

			return new self(
				$context_slug,
				$content,
				$sections,
				array(
					'included_sections' => array_values( array_filter( $included ) ),
					'section_count'     => count( $included ),
				)
			);
		}

		/**
		 * Whether the composed context has non-empty content.
		 *
		 * @return bool
		 */
		public function has_content(): bool {
			return '' !== trim( $this->content );
		}

		/**
		 * Array representation for adapters and tests.
		 *
		 * @return array<string, mixed>
		 */
		public function to_array(): array {
			return array(
				'slug'     => $this->slug,
				'content'  => $this->content,
				'sections' => $this->sections,
				'metadata' => $this->metadata,
			);
		}
	}
}
