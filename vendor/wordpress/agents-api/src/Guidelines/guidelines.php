<?php
/**
 * Guideline public API helpers.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wp_guideline_types' ) ) {
	/**
	 * Returns registered guideline types keyed by slug.
	 *
	 * @return array<string, array{title: string}> Slug-keyed guideline type definitions.
	 */
	function wp_guideline_types(): array {
		/**
		 * Filters the guideline types available on this site.
		 *
		 * @param array<string, array{title: string}> $types Slug-keyed guideline type definitions.
		 */
		return apply_filters(
			'wp_guideline_types',
			array(
				'artifact' => array(
					'title' => __( 'Artifact', 'agents-api' ),
				),
				'content'  => array(
					'title' => __( 'Content', 'agents-api' ),
				),
			)
		);
	}
}

if ( ! function_exists( '_wp_guidelines_map_meta_cap' ) ) {
	/**
	 * Maps guideline capabilities to explicit memory and workspace-guidance policy.
	 *
	 * Private user-workspace memory is author-only by metadata, not by core private
	 * post status. Workspace-shared guidance uses an editorial/admin threshold.
	 *
	 * @access private
	 *
	 * @param string[] $caps    Primitive capabilities required by WordPress so far.
	 * @param string   $cap     Requested capability.
	 * @param int      $user_id User ID being checked.
	 * @param mixed[]  $args    Additional capability arguments, usually post ID first.
	 * @return string[] Required primitive capabilities.
	 */
	function _wp_guidelines_map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
		if ( in_array( $cap, array( 'read_post', 'edit_post', 'delete_post' ), true ) ) {
			$post_id = isset( $args[0] ) && is_scalar( $args[0] ) ? (int) $args[0] : 0;
			if ( $post_id <= 0 || ! _wp_guidelines_is_guideline_post( $post_id ) ) {
				return $caps;
			}

			if ( _wp_guidelines_is_private_memory( $post_id ) ) {
				return _wp_guidelines_map_private_memory_cap( 'read_post' === $cap ? 'read_private_agent_memory' : 'edit_private_agent_memory', $user_id, $post_id );
			}

			return 'read_post' === $cap ? array( 'read_workspace_guidelines' ) : array( 'edit_workspace_guidelines' );
		}

		if ( in_array( $cap, array( 'read_agent_memory', 'edit_agent_memory', 'read_workspace_guidelines', 'edit_workspace_guidelines' ), true ) ) {
			return _wp_guidelines_map_guideline_primitive_cap( $cap );
		}

		if ( in_array( $cap, array( 'read_private_agent_memory', 'edit_private_agent_memory', 'promote_agent_memory' ), true ) ) {
			$post_id = isset( $args[0] ) && is_scalar( $args[0] ) ? (int) $args[0] : 0;
			if ( $post_id <= 0 || ! _wp_guidelines_is_private_memory( $post_id ) ) {
				return array( 'do_not_allow' );
			}

			if ( 'promote_agent_memory' === $cap ) {
				return _wp_guidelines_private_memory_owner_id( $post_id ) === $user_id ? array( 'promote_agent_memory' ) : array( 'do_not_allow' );
			}

			return _wp_guidelines_map_private_memory_cap( $cap, $user_id, $post_id );
		}

		return $caps;
	}
}

if ( ! function_exists( '_wp_guidelines_map_guideline_primitive_cap' ) ) {
	/**
	 * Maps explicit guideline meta capabilities to host role thresholds.
	 *
	 * @access private
	 *
	 * @param string $cap Requested capability.
	 * @return string[] Required primitive capabilities.
	 */
	function _wp_guidelines_map_guideline_primitive_cap( string $cap ): array {
		switch ( $cap ) {
			case 'read_agent_memory':
				return array( 'read' );

			case 'edit_agent_memory':
			case 'read_workspace_guidelines':
				return array( 'edit_posts' );

			case 'edit_workspace_guidelines':
				return array( 'publish_posts' );
		}

		return array( 'do_not_allow' );
	}
}

if ( ! function_exists( '_wp_guidelines_map_private_memory_cap' ) ) {
	/**
	 * Maps a private memory capability for one guideline post.
	 *
	 * @access private
	 *
	 * @param string $cap     Requested private-memory capability.
	 * @param int    $user_id User ID being checked.
	 * @param int    $post_id Guideline post ID.
	 * @return string[] Required primitive capabilities.
	 */
	function _wp_guidelines_map_private_memory_cap( string $cap, int $user_id, int $post_id ): array {
		if ( _wp_guidelines_private_memory_owner_id( $post_id ) !== $user_id ) {
			return array( 'do_not_allow' );
		}

		return _wp_guidelines_map_guideline_primitive_cap( 'read_private_agent_memory' === $cap ? 'read_agent_memory' : 'edit_agent_memory' );
	}
}

if ( ! function_exists( '_wp_guidelines_is_guideline_post' ) ) {
	/**
	 * Checks whether a post ID points at a guideline post.
	 *
	 * @access private
	 *
	 * @param int $post_id Post ID.
	 * @return bool Whether the post is a guideline.
	 */
	function _wp_guidelines_is_guideline_post( int $post_id ): bool {
		$post = get_post( $post_id );
		return is_object( $post ) && WP_Guidelines_Substrate::POST_TYPE === $post->post_type;
	}
}

if ( ! function_exists( '_wp_guidelines_is_private_memory' ) ) {
	/**
	 * Checks whether a guideline post is private user-workspace memory.
	 *
	 * @access private
	 *
	 * @param int $post_id Guideline post ID.
	 * @return bool Whether the guideline is private memory.
	 */
	function _wp_guidelines_is_private_memory( int $post_id ): bool {
		return WP_Guidelines_Substrate::SCOPE_PRIVATE_MEMORY === get_post_meta( $post_id, WP_Guidelines_Substrate::META_SCOPE, true );
	}
}

if ( ! function_exists( '_wp_guidelines_private_memory_owner_id' ) ) {
	/**
	 * Returns the explicit owner for private user-workspace memory.
	 *
	 * @access private
	 *
	 * @param int $post_id Guideline post ID.
	 * @return int Owning user ID.
	 */
	function _wp_guidelines_private_memory_owner_id( int $post_id ): int {
		$owner_id = get_post_meta( $post_id, WP_Guidelines_Substrate::META_USER_ID, true );
		return is_scalar( $owner_id ) ? (int) $owner_id : 0;
	}
}

if ( ! function_exists( '_wp_guidelines_ensure_default_type_term' ) ) {
	/**
	 * Assigns the artifact type to guideline posts saved without a type.
	 *
	 * @access private
	 *
	 * @param int $post_id Saved post ID.
	 */
	function _wp_guidelines_ensure_default_type_term( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$terms = get_the_terms( $post_id, WP_Guidelines_Substrate::TAXONOMY );
		if ( is_wp_error( $terms ) || ! empty( $terms ) ) {
			return;
		}

		$term = term_exists( 'artifact', WP_Guidelines_Substrate::TAXONOMY );
		if ( ! $term ) {
			$term = wp_insert_term( 'artifact', WP_Guidelines_Substrate::TAXONOMY );
			if ( is_wp_error( $term ) ) {
				return;
			}
		}

		wp_set_object_terms( $post_id, (int) $term['term_id'], WP_Guidelines_Substrate::TAXONOMY );
	}
}

if ( ! function_exists( '_wp_guidelines_maybe_map_term_label' ) ) {
	/**
	 * Maps lazily-created guideline type slugs to human-readable labels.
	 *
	 * @access private
	 *
	 * @param array<string, mixed> $data     Term data to be inserted.
	 * @param string               $taxonomy Taxonomy slug.
	 * @return array<string, mixed> Possibly modified term data.
	 */
	function _wp_guidelines_maybe_map_term_label( array $data, string $taxonomy ): array {
		if ( WP_Guidelines_Substrate::TAXONOMY !== $taxonomy ) {
			return $data;
		}

		if ( ! isset( $data['name'], $data['slug'] ) || $data['name'] !== $data['slug'] ) {
			return $data;
		}

		$types = wp_guideline_types();
		$slug  = $data['slug'];
		if ( is_string( $slug ) && isset( $types[ $slug ] ) ) {
			$data['name'] = $types[ $slug ]['title'];
		}

		return $data;
	}
}
