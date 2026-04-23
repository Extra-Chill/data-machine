<?php
/**
 * ResolvePostByPath
 *
 * Generic helper for resolving a hierarchical post by its slash-delimited
 * slug path. Works with any hierarchical post type (pages, wiki, docs, etc.).
 *
 * When create_stubs is enabled, missing intermediate nodes are created as
 * empty auto-stub posts so that deep-tree generation works without pre-seeding.
 * Each stub is stamped with a configurable post-meta marker so maintenance
 * pipelines can find and enrich them later.
 *
 * This is the DM-core primitive that replaces the ad-hoc path-resolution
 * logic duplicated across Intelligence, data-machine-events, and extrachill-docs.
 *
 * @package DataMachine\Core\WordPress
 * @since   0.77.0
 */

namespace DataMachine\Core\WordPress;

defined( 'ABSPATH' ) || exit;

class ResolvePostByPath {

	/**
	 * Resolve a post ID from a slug, simple slug, or slash-delimited path.
	 *
	 * Accepts:
	 * - Numeric string: "42" → looks up by post ID
	 * - Simple slug: "getting-started" → looks up by post_name under the given parent
	 * - Path: "artist/getting-started" → resolves parent then child
	 *
	 * @param string $identifier  Slug, path, or numeric ID.
	 * @param string $post_type   Post type to search within.
	 * @param int    $parent_id   Parent post ID (0 for top-level).
	 * @return int|\WP_Error Post ID on success, WP_Error on failure.
	 */
	public static function resolve( string $identifier, string $post_type = 'page', int $parent_id = 0 ) {
		$identifier = trim( $identifier );

		if ( is_numeric( $identifier ) ) {
			$post = get_post( (int) $identifier );
			if ( $post && $post->post_type === $post_type ) {
				return (int) $post->ID;
			}
			return new \WP_Error(
				'not_found',
				sprintf( 'No %s with ID %s.', $post_type, $identifier )
			);
		}

		if ( str_contains( $identifier, '/' ) ) {
			$parts     = array_values( array_filter( explode( '/', $identifier ) ) );
			$current_parent = $parent_id;

			foreach ( $parts as $slug_part ) {
				$found = get_posts(
					array(
						'post_type'      => $post_type,
						'post_status'    => 'any',
						'name'           => $slug_part,
						'post_parent'    => $current_parent,
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'no_found_rows'  => true,
					)
				);

				if ( empty( $found ) ) {
					return new \WP_Error(
						'not_found',
						sprintf(
							'%s not found at path: %s (failed at \'%s\').',
							$post_type,
							$identifier,
							$slug_part
						)
					);
				}

				$current_parent = (int) $found[0];
			}

			return $current_parent;
		}

		$found = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'name'           => $identifier,
				'post_parent'    => $parent_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( ! empty( $found ) ) {
			return (int) $found[0];
		}

		return new \WP_Error(
			'not_found',
			sprintf( '%s not found: %s', $post_type, $identifier )
		);
	}

	/**
	 * Resolve a slash-delimited parent path, creating missing intermediate
	 * nodes as empty auto-stub posts.
	 *
	 * Idempotent: re-running with the same path finds existing nodes at
	 * each segment and creates none.
	 *
	 * @param string $slug_path      Slash-delimited slug path (e.g. "artist/link-pages").
	 * @param string $post_type      Post type for created stubs.
	 * @param string $stub_meta_key  Post-meta key to stamp on auto-stubs.
	 * @param array  $stub_defaults  Default post args for stubs (post_status, etc.).
	 * @return int|\WP_Error Leaf post ID on success, WP_Error on failure.
	 */
	public static function resolve_or_create_stubs(
		string $slug_path,
		string $post_type = 'page',
		string $stub_meta_key = '_datamachine_auto_stub',
		array $stub_defaults = array()
	) {
		$parts = array_values(
			array_filter(
				array_map( 'trim', explode( '/', $slug_path ) )
			)
		);

		if ( empty( $parts ) ) {
			return new \WP_Error( 'invalid_path', 'Path is empty.' );
		}

		$parent_id = 0;

		foreach ( $parts as $slug ) {
			$resolved = self::resolve_or_create_stub(
				$slug,
				$post_type,
				$parent_id,
				$stub_meta_key,
				$stub_defaults
			);

			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}

			$parent_id = (int) $resolved;
		}

		return $parent_id;
	}

	/**
	 * Get-or-create a single stub post under a parent.
	 *
	 * @param string $slug           Slug segment.
	 * @param string $post_type      Post type.
	 * @param int    $parent_id      Parent post ID.
	 * @param string $stub_meta_key  Marker meta key.
	 * @param array  $stub_defaults  Default post args.
	 * @return int|\WP_Error Post ID.
	 */
	public static function resolve_or_create_stub(
		string $slug,
		string $post_type,
		int $parent_id,
		string $stub_meta_key,
		array $stub_defaults = array()
	) {
		$found = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'name'           => $slug,
				'post_parent'    => $parent_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( ! empty( $found ) ) {
			return (int) $found[0];
		}

		$title = ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );

		$defaults = array(
			'post_type'      => $post_type,
			'post_title'     => $title,
			'post_name'      => $slug,
			'post_parent'    => $parent_id,
			'post_status'    => 'publish',
			'post_content'   => '',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		);

		$post_data = wp_parse_args( $stub_defaults, $defaults );

		$new_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		if ( '' !== $stub_meta_key ) {
			update_post_meta( (int) $new_id, $stub_meta_key, '1' );
		}

		return (int) $new_id;
	}

	/**
	 * Build the full slash-delimited path for a post.
	 *
	 * @param int|\WP_Post $post Post ID or post object.
	 * @return string Full slug path (e.g. "parent/child/grandchild").
	 */
	public static function build_path( $post ): string {
		$post = get_post( $post );
		if ( ! $post ) {
			return '';
		}

		$parts   = array( $post->post_name );
		$current = $post;

		while ( $current->post_parent ) {
			$current = get_post( $current->post_parent );
			if ( ! $current ) {
				break;
			}
			array_unshift( $parts, $current->post_name );
		}

		return implode( '/', $parts );
	}
}
