<?php
/**
 * Guideline Agent Memory Store
 *
 * Optional {@see AgentMemoryStoreInterface} implementation that persists
 * agent memory records as `wp_guideline` posts tagged with
 * `wp_guideline_type=memory`.
 *
 * Data Machine does not register the Guidelines substrate and does not make
 * this store the default. Consumers that run on a host where Guidelines are
 * available can feature-detect {@see self::is_available()} and opt in via the
 * `agents_api_memory_store` filter. When unavailable, the built-in
 * disk store remains the default behavior.
 *
 * Identity model: one post = one (layer, user_id, agent_id, filename) tuple.
 * Filename is the relative path within the layer (`MEMORY.md`,
 * `daily/2026/04/17.md`, `contexts/chat.md`).
 *
 * @package DataMachine\Core\FilesRepository
 * @since   next
 */

namespace DataMachine\Core\FilesRepository;

use AgentsAPI\Core\FilesRepository\AgentMemoryListEntry;
use AgentsAPI\Core\FilesRepository\AgentMemoryReadResult;
use AgentsAPI\Core\FilesRepository\AgentMemoryScope;
use AgentsAPI\Core\FilesRepository\AgentMemoryStoreInterface;
use AgentsAPI\Core\FilesRepository\AgentMemoryWriteResult;

defined( 'ABSPATH' ) || exit;

class GuidelineAgentMemoryStore implements AgentMemoryStoreInterface {

	const POST_TYPE   = 'wp_guideline';
	const TAXONOMY    = 'wp_guideline_type';
	const TERM_MEMORY = 'memory';

	const META_LAYER    = '_datamachine_memory_layer';
	const META_USER_ID  = '_datamachine_memory_user_id';
	const META_AGENT_ID = '_datamachine_memory_agent_id';
	const META_FILENAME = '_datamachine_memory_filename';
	const META_HASH     = '_datamachine_memory_hash';
	const META_BYTES    = '_datamachine_memory_bytes';

	/**
	 * Whether the host has the Guidelines substrate this store needs.
	 *
	 * `wp_guideline` is not guaranteed in WordPress core today. Consumers must
	 * opt in only when both the CPT and taxonomy exist, or after registering a
	 * deliberate polyfill themselves.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return post_type_exists( self::POST_TYPE ) && taxonomy_exists( self::TAXONOMY );
	}

	/**
	 * Deterministic post_name for a memory scope.
	 *
	 * sha1 is sufficient here: this is a stable key inside the `wp_guideline`
	 * CPT, not a security primitive.
	 *
	 * @param AgentMemoryScope $scope Scope to encode.
	 * @return string
	 */
	public static function post_name_for_scope( AgentMemoryScope $scope ): string {
		return 'memory-' . sha1( $scope->key() );
	}

	/**
	 * @inheritDoc
	 */
	public function read( AgentMemoryScope $scope ): AgentMemoryReadResult {
		$post = $this->find_post( $scope );
		if ( ! $post instanceof \WP_Post ) {
			return AgentMemoryReadResult::not_found();
		}

		$content = (string) $post->post_content;
		$hash    = (string) get_post_meta( $post->ID, self::META_HASH, true );
		if ( '' === $hash ) {
			$hash = sha1( $content );
		}

		$bytes_meta = get_post_meta( $post->ID, self::META_BYTES, true );
		$bytes      = is_numeric( $bytes_meta ) ? (int) $bytes_meta : strlen( $content );

		$updated = strtotime( (string) $post->post_modified_gmt );
		$updated = false === $updated ? null : (int) $updated;

		return new AgentMemoryReadResult( true, $content, $hash, $bytes, $updated );
	}

	/**
	 * @inheritDoc
	 */
	public function write( AgentMemoryScope $scope, string $content, ?string $if_match = null ): AgentMemoryWriteResult {
		if ( ! self::is_available() ) {
			return AgentMemoryWriteResult::failure( 'capability' );
		}

		$existing = $this->find_post( $scope );

		if ( null !== $if_match && $existing instanceof \WP_Post ) {
			$stored = (string) get_post_meta( $existing->ID, self::META_HASH, true );
			if ( '' === $stored ) {
				$stored = sha1( (string) $existing->post_content );
			}

			if ( $stored !== $if_match ) {
				return AgentMemoryWriteResult::failure( 'conflict' );
			}
		}

		$hash   = sha1( $content );
		$bytes  = strlen( $content );
		$author = $scope->user_id > 0 ? $scope->user_id : 0;

		if ( $existing instanceof \WP_Post ) {
			$updated = wp_update_post(
				array(
					'ID'           => $existing->ID,
					'post_content' => $content,
					'post_title'   => $scope->filename,
					'post_author'  => $author,
				),
				true
			);

			if ( is_wp_error( $updated ) ) {
				return AgentMemoryWriteResult::failure( 'io' );
			}

			update_post_meta( $existing->ID, self::META_HASH, $hash );
			update_post_meta( $existing->ID, self::META_BYTES, $bytes );

			$this->emit_guideline_updated_event( $existing->ID );

			return AgentMemoryWriteResult::ok( $hash, $bytes );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'post_title'     => $scope->filename,
				'post_name'      => self::post_name_for_scope( $scope ),
				'post_content'   => $content,
				'post_author'    => $author,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'meta_input'     => array(
					self::META_LAYER    => $scope->layer,
					self::META_USER_ID  => $scope->user_id,
					self::META_AGENT_ID => $scope->agent_id,
					self::META_FILENAME => $scope->filename,
					self::META_HASH     => $hash,
					self::META_BYTES    => $bytes,
				),
			),
			true
		);

		if ( is_wp_error( $post_id ) || 0 === $post_id ) {
			return AgentMemoryWriteResult::failure( 'io' );
		}

		wp_set_object_terms( $post_id, array( self::TERM_MEMORY ), self::TAXONOMY, false );
		$this->emit_guideline_updated_event( (int) $post_id );

		return AgentMemoryWriteResult::ok( $hash, $bytes );
	}

	/**
	 * Emit a logical guideline update event for consumers watching the substrate.
	 *
	 * @since next
	 *
	 * @param int $post_id Guideline post ID.
	 */
	private function emit_guideline_updated_event( int $post_id ): void {
		do_action( 'datamachine_guideline_updated', $post_id, self::TERM_MEMORY );
	}

	/**
	 * @inheritDoc
	 */
	public function exists( AgentMemoryScope $scope ): bool {
		return $this->find_post( $scope ) instanceof \WP_Post;
	}

	/**
	 * @inheritDoc
	 */
	public function delete( AgentMemoryScope $scope ): AgentMemoryWriteResult {
		$post = $this->find_post( $scope );
		if ( ! $post instanceof \WP_Post ) {
			return AgentMemoryWriteResult::ok( '', 0 );
		}

		$deleted = wp_delete_post( $post->ID, true );
		if ( ! $deleted ) {
			return AgentMemoryWriteResult::failure( 'io' );
		}

		return AgentMemoryWriteResult::ok( '', 0 );
	}

	/**
	 * @inheritDoc
	 */
	public function list_layer( AgentMemoryScope $scope_query ): array {
		$posts   = $this->query_scope_posts( $scope_query, null );
		$entries = array();

		foreach ( $posts as $post ) {
			$filename = (string) get_post_meta( $post->ID, self::META_FILENAME, true );
			if ( '' === $filename || false !== strpos( $filename, '/' ) ) {
				continue;
			}

			$entries[] = $this->entry_for( $post, $filename, $scope_query->layer );
		}

		usort( $entries, static fn( $a, $b ) => strcmp( $a->filename, $b->filename ) );
		return $entries;
	}

	/**
	 * @inheritDoc
	 */
	public function list_subtree( AgentMemoryScope $scope_query, string $prefix ): array {
		$prefix = trim( $prefix, '/' );
		if ( '' === $prefix ) {
			return array();
		}

		$posts   = $this->query_scope_posts( $scope_query, $prefix );
		$entries = array();

		foreach ( $posts as $post ) {
			$filename = (string) get_post_meta( $post->ID, self::META_FILENAME, true );
			if ( 0 !== strpos( $filename, $prefix . '/' ) ) {
				continue;
			}

			$entries[] = $this->entry_for( $post, $filename, $scope_query->layer );
		}

		usort( $entries, static fn( $a, $b ) => strcmp( $a->filename, $b->filename ) );
		return $entries;
	}

	/**
	 * Locate the post matching a full memory scope.
	 *
	 * @param AgentMemoryScope $scope Scope to locate.
	 * @return \WP_Post|null
	 */
	private function find_post( AgentMemoryScope $scope ): ?\WP_Post {
		if ( ! self::is_available() ) {
			return null;
		}

		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'any',
				'name'             => self::post_name_for_scope( $scope ),
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'orderby'          => 'ID',
				'order'            => 'ASC',
			)
		);

		return empty( $posts ) ? null : $posts[0];
	}

	/**
	 * Query memory posts for a layer/user/agent triple, optionally scoped to a subtree.
	 *
	 * @param AgentMemoryScope $scope_query Scope query. Filename is ignored.
	 * @param string|null      $prefix      Optional subtree prefix.
	 * @return \WP_Post[]
	 */
	private function query_scope_posts( AgentMemoryScope $scope_query, ?string $prefix ): array {
		if ( ! self::is_available() ) {
			return array();
		}

		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'     => self::META_LAYER,
				'value'   => $scope_query->layer,
				'compare' => '=',
			),
			array(
				'key'     => self::META_USER_ID,
				'value'   => (string) $scope_query->user_id,
				'compare' => '=',
			),
			array(
				'key'     => self::META_AGENT_ID,
				'value'   => (string) $scope_query->agent_id,
				'compare' => '=',
			),
		);

		if ( null !== $prefix && '' !== $prefix ) {
			$meta_query[] = array(
				'key'     => self::META_FILENAME,
				'value'   => '^' . preg_quote( $prefix, '/' ) . '/',
				'compare' => 'REGEXP',
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'tax_query'      => array(
					array(
						'taxonomy' => self::TAXONOMY,
						'field'    => 'slug',
						'terms'    => array( self::TERM_MEMORY ),
					),
				),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => $meta_query,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		return array_values(
			array_filter(
				$query->posts,
				static fn( $post ): bool => $post instanceof \WP_Post
			)
		);
	}

	/**
	 * Build a list entry from a guideline memory post.
	 *
	 * @param \WP_Post $post     Memory post.
	 * @param string   $filename Scope filename.
	 * @param string   $layer    Scope layer.
	 * @return AgentMemoryListEntry
	 */
	private function entry_for( \WP_Post $post, string $filename, string $layer ): AgentMemoryListEntry {
		$bytes_meta = get_post_meta( $post->ID, self::META_BYTES, true );
		$bytes      = is_numeric( $bytes_meta ) ? (int) $bytes_meta : strlen( (string) $post->post_content );

		$updated = strtotime( (string) $post->post_modified_gmt );
		$updated = false === $updated ? null : (int) $updated;

		return new AgentMemoryListEntry( $filename, $layer, $bytes, $updated );
	}
}
