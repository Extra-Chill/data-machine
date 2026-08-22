<?php
/**
 * Guideline Agent Memory Store
 *
 * Optional {@see WP_Agent_Memory_Store} implementation that persists
 * agent memory records as `wp_guideline` posts tagged with
 * `wp_guideline_type=memory`.
 *
 * Data Machine does not register the Guidelines substrate and does not make
 * this store the default. Consumers that run on a host where Guidelines are
 * available can feature-detect {@see self::is_available()} and opt in via the
 * canonical `wp_agent_memory_store` filter. When unavailable, the built-in
 * disk store remains the default behavior.
 *
	 * Identity model: one post = one (layer, workspace, user_id, agent_id, filename) tuple.
 * Filename is the relative path within the layer (`MEMORY.md`,
 * `daily/2026/04/17.md`, `contexts/chat.md`).
 *
 * @package DataMachine\Core\FilesRepository
 * @since   next
 */

namespace DataMachine\Core\FilesRepository;

use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_List_Entry;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Metadata;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Query;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Read_Result;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Scope;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store_Capabilities;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Write_Result;

defined( 'ABSPATH' ) || exit;

class GuidelineAgentMemoryStore implements WP_Agent_Memory_Store {

	const POST_TYPE   = 'wp_guideline';
	const TAXONOMY    = 'wp_guideline_type';
	const TERM_MEMORY = 'memory';

	const META_LAYER          = '_datamachine_memory_layer';
	const META_WORKSPACE_TYPE = '_datamachine_memory_workspace_type';
	const META_WORKSPACE_ID   = '_datamachine_memory_workspace_id';
	const META_USER_ID        = '_datamachine_memory_user_id';
	const META_AGENT_ID       = '_datamachine_memory_agent_id';
	const META_FILENAME       = '_datamachine_memory_filename';
	const META_HASH           = '_datamachine_memory_hash';
	const META_BYTES          = '_datamachine_memory_bytes';
	const META_METADATA       = '_datamachine_memory_metadata';

	const GUIDELINE_META_SCOPE        = '_wp_guideline_scope';
	const GUIDELINE_META_USER_ID      = '_wp_guideline_user_id';
	const GUIDELINE_META_WORKSPACE_ID = '_wp_guideline_workspace_id';

	const GUIDELINE_SCOPE_PRIVATE_MEMORY     = 'private_user_workspace_memory';
	const GUIDELINE_SCOPE_WORKSPACE_GUIDANCE = 'workspace_shared_guidance';

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
	 * @param WP_Agent_Memory_Scope $scope Scope to encode.
	 * @return string
	 */
	public static function post_name_for_scope( WP_Agent_Memory_Scope $scope ): string {
		return 'memory-' . sha1( $scope->key() );
	}

	/**
	 * @inheritDoc
	 */
	public function capabilities(): WP_Agent_Memory_Store_Capabilities {
		return WP_Agent_Memory_Store_Capabilities::all();
	}

	/**
	 * @inheritDoc
	 */
	public function read( WP_Agent_Memory_Scope $scope, array $metadata_fields = WP_Agent_Memory_Metadata::FIELDS ): WP_Agent_Memory_Read_Result {
		$post = $this->find_post( $scope );
		if ( ! $post instanceof \WP_Post ) {
			return WP_Agent_Memory_Read_Result::not_found();
		}

		if ( ! $this->can_read_post( $post ) ) {
			return WP_Agent_Memory_Read_Result::not_found();
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
		return new WP_Agent_Memory_Read_Result( true, $content, $hash, $bytes, $updated, $this->metadata_for_post( $post, $metadata_fields ) );
	}

	/**
	 * @inheritDoc
	 */
	public function write( WP_Agent_Memory_Scope $scope, string $content, ?string $if_match = null, ?WP_Agent_Memory_Metadata $metadata = null ): WP_Agent_Memory_Write_Result {
		if ( ! self::is_available() ) {
			return WP_Agent_Memory_Write_Result::failure( 'capability' );
		}

		$existing = $this->find_post( $scope );

		if ( null !== $if_match && $existing instanceof \WP_Post ) {
			$stored = (string) get_post_meta( $existing->ID, self::META_HASH, true );
			if ( '' === $stored ) {
				$stored = sha1( (string) $existing->post_content );
			}

			if ( $stored !== $if_match ) {
				return WP_Agent_Memory_Write_Result::failure( 'conflict' );
			}
		}

		if ( $existing instanceof \WP_Post && ! $this->can_write_post( $existing ) ) {
			return WP_Agent_Memory_Write_Result::failure( 'capability' );
		}

		if ( ! $existing instanceof \WP_Post && ! $this->can_create_scope( $scope ) ) {
			return WP_Agent_Memory_Write_Result::failure( 'capability' );
		}

		$hash               = sha1( $content );
		$bytes              = strlen( $content );
		$author             = $scope->user_id > 0 ? $scope->user_id : 0;
		$metadata           = ( $metadata ?? new WP_Agent_Memory_Metadata() )->with_defaults();
		$guideline_metadata = $this->guideline_metadata_for_scope( $scope );

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
				return WP_Agent_Memory_Write_Result::failure( 'io' );
			}

			update_post_meta( $existing->ID, self::META_HASH, $hash );
			update_post_meta( $existing->ID, self::META_BYTES, $bytes );
			update_post_meta( $existing->ID, self::META_WORKSPACE_TYPE, $scope->workspace_type );
			update_post_meta( $existing->ID, self::META_WORKSPACE_ID, $scope->workspace_id );
			foreach ( $guideline_metadata as $meta_key => $meta_value ) {
				update_post_meta( $existing->ID, $meta_key, $meta_value );
			}
			update_post_meta( $existing->ID, self::META_METADATA, $metadata->to_array() );

			$this->emit_guideline_updated_event( $existing->ID );

			return WP_Agent_Memory_Write_Result::ok( $hash, $bytes, $metadata );
		}

		$meta_input = array_merge(
			array(
				self::META_LAYER          => $scope->layer,
				self::META_WORKSPACE_TYPE => $scope->workspace_type,
				self::META_WORKSPACE_ID   => $scope->workspace_id,
				self::META_USER_ID        => $scope->user_id,
				self::META_AGENT_ID       => $scope->agent_id,
				self::META_FILENAME       => $scope->filename,
				self::META_HASH           => $hash,
				self::META_BYTES          => $bytes,
				self::META_METADATA       => $metadata->to_array(),
			),
			$guideline_metadata
		);

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
				'meta_input'     => $meta_input,
			),
			true
		);

		if ( is_wp_error( $post_id ) || 0 === $post_id ) {
			return WP_Agent_Memory_Write_Result::failure( 'io' );
		}

		wp_set_object_terms( $post_id, array( self::TERM_MEMORY ), self::TAXONOMY, false );
		$this->emit_guideline_updated_event( (int) $post_id );

		return WP_Agent_Memory_Write_Result::ok( $hash, $bytes, $metadata );
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
	public function exists( WP_Agent_Memory_Scope $scope ): bool {
		$post = $this->find_post( $scope );
		return $post instanceof \WP_Post && $this->can_read_post( $post );
	}

	/**
	 * @inheritDoc
	 */
	public function delete( WP_Agent_Memory_Scope $scope ): WP_Agent_Memory_Write_Result {
		$post = $this->find_post( $scope );
		if ( ! $post instanceof \WP_Post ) {
			return WP_Agent_Memory_Write_Result::ok( '', 0 );
		}

		if ( ! $this->can_write_post( $post ) ) {
			return WP_Agent_Memory_Write_Result::failure( 'capability' );
		}

		$deleted = wp_delete_post( $post->ID, true );
		if ( ! $deleted ) {
			return WP_Agent_Memory_Write_Result::failure( 'io' );
		}

		return WP_Agent_Memory_Write_Result::ok( '', 0 );
	}

	/**
	 * @inheritDoc
	 */
	public function list_layer( WP_Agent_Memory_Scope $scope_query, ?WP_Agent_Memory_Query $query = null ): array {
		$posts   = $this->query_scope_posts( $scope_query, null );
		$entries = array();

		foreach ( $posts as $post ) {
			if ( ! $this->can_read_post( $post ) ) {
				continue;
			}

			$filename = (string) get_post_meta( $post->ID, self::META_FILENAME, true );
			if ( '' === $filename || false !== strpos( $filename, '/' ) ) {
				continue;
			}

			$entry = $this->entry_for( $post, $filename, $scope_query->layer, $query?->metadata_fields ?? WP_Agent_Memory_Metadata::FIELDS );
			if ( $this->entry_matches_query( $entry, $query ) ) {
				$entries[] = $entry;
			}
		}

		if ( null !== $query && null !== $query->order_by ) {
			$entries = $this->sort_entries( $entries, $query );
		} else {
			usort( $entries, static fn( $a, $b ) => strcmp( $a->filename, $b->filename ) );
		}
		return $entries;
	}

	/**
	 * @inheritDoc
	 */
	public function list_subtree( WP_Agent_Memory_Scope $scope_query, string $prefix, ?WP_Agent_Memory_Query $query = null ): array {
		$prefix = trim( $prefix, '/' );
		if ( '' === $prefix ) {
			return array();
		}

		$posts   = $this->query_scope_posts( $scope_query, $prefix );
		$entries = array();

		foreach ( $posts as $post ) {
			if ( ! $this->can_read_post( $post ) ) {
				continue;
			}

			$filename = (string) get_post_meta( $post->ID, self::META_FILENAME, true );
			if ( 0 !== strpos( $filename, $prefix . '/' ) ) {
				continue;
			}

			$entry = $this->entry_for( $post, $filename, $scope_query->layer, $query?->metadata_fields ?? WP_Agent_Memory_Metadata::FIELDS );
			if ( $this->entry_matches_query( $entry, $query ) ) {
				$entries[] = $entry;
			}
		}

		if ( null !== $query && null !== $query->order_by ) {
			$entries = $this->sort_entries( $entries, $query );
		} else {
			usort( $entries, static fn( $a, $b ) => strcmp( $a->filename, $b->filename ) );
		}
		return $entries;
	}

	/**
	 * Locate the post matching a full memory scope.
	 *
	 * @param WP_Agent_Memory_Scope $scope Scope to locate.
	 * @return \WP_Post|null
	 */
	private function find_post( WP_Agent_Memory_Scope $scope ): ?\WP_Post {
		if ( ! self::is_available() ) {
			return null;
		}

		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'name'           => self::post_name_for_scope( $scope ),
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		return empty( $posts ) ? null : $posts[0];
	}

	/**
	 * Query memory posts for a layer/user/agent triple, optionally scoped to a subtree.
	 *
	 * @param WP_Agent_Memory_Scope $scope_query Scope query. Filename is ignored.
	 * @param string|null      $prefix      Optional subtree prefix.
	 * @return \WP_Post[]
	 */
	private function query_scope_posts( WP_Agent_Memory_Scope $scope_query, ?string $prefix ): array {
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
				'key'     => self::META_WORKSPACE_TYPE,
				'value'   => $scope_query->workspace_type,
				'compare' => '=',
			),
			array(
				'key'     => self::META_WORKSPACE_ID,
				'value'   => $scope_query->workspace_id,
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
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Guideline-backed memory files are isolated by the Data Machine memory term.
				'tax_query'      => array(
					array(
						'taxonomy' => self::TAXONOMY,
						'field'    => 'slug',
						'terms'    => array( self::TERM_MEMORY ),
					),
				),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Agent/file scope is stored as guideline metadata by this backend.
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
	 * @return WP_Agent_Memory_List_Entry
	 */
	private function entry_for( \WP_Post $post, string $filename, string $layer, array $metadata_fields = WP_Agent_Memory_Metadata::FIELDS ): WP_Agent_Memory_List_Entry {
		$bytes_meta = get_post_meta( $post->ID, self::META_BYTES, true );
		$bytes      = is_numeric( $bytes_meta ) ? (int) $bytes_meta : strlen( (string) $post->post_content );

		$updated = strtotime( (string) $post->post_modified_gmt );
		$updated = false === $updated ? null : (int) $updated;

		return new WP_Agent_Memory_List_Entry( $filename, $layer, $bytes, $updated, $this->metadata_for_post( $post, $metadata_fields ) );
	}

	private function metadata_for_post( \WP_Post $post, array $metadata_fields = WP_Agent_Memory_Metadata::FIELDS ): WP_Agent_Memory_Metadata {
		$raw = get_post_meta( $post->ID, self::META_METADATA, true );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		return WP_Agent_Memory_Metadata::from_array( $raw )->with_defaults()->only_fields( $metadata_fields );
	}

	private function entry_matches_query( WP_Agent_Memory_List_Entry $entry, ?WP_Agent_Memory_Query $query ): bool {
		if ( null === $query || null === $entry->metadata ) {
			return true;
		}

		$metadata = $entry->metadata;
		if ( array() !== $query->source_types && ! in_array( (string) $metadata->source_type, $query->source_types, true ) ) {
			return false;
		}

		if ( null !== $query->min_confidence && ( null === $metadata->confidence || $metadata->confidence < $query->min_confidence ) ) {
			return false;
		}

		if ( array() !== $query->authority_tiers && ! in_array( (string) $metadata->authority_tier, $query->authority_tiers, true ) ) {
			return false;
		}

		return array() === $query->validators || in_array( (string) $metadata->validator, $query->validators, true );
	}

	/**
	 * @param WP_Agent_Memory_List_Entry[] $entries Entries to sort.
	 * @return WP_Agent_Memory_List_Entry[]
	 */
	private function sort_entries( array $entries, ?WP_Agent_Memory_Query $query ): array {
		if ( null === $query || null === $query->order_by ) {
			return $entries;
		}

		$order_by = $query->order_by;
		$order    = 'asc' === strtolower( $query->order ) ? 'asc' : 'desc';
		usort(
			$entries,
			static function ( WP_Agent_Memory_List_Entry $left, WP_Agent_Memory_List_Entry $right ) use ( $order_by, $order ): int {
				$left_value  = self::entry_sort_value( $left, $order_by );
				$right_value = self::entry_sort_value( $right, $order_by );
				$delta       = $left_value <=> $right_value;
				return 'asc' === $order ? $delta : -$delta;
			}
		);

		return $entries;
	}

	private static function entry_sort_value( WP_Agent_Memory_List_Entry $entry, string $field ) {
		if ( 'updated_at' === $field ) {
			return $entry->updated_at ?? 0;
		}

		if ( null === $entry->metadata ) {
			return null;
		}

		return match ( $field ) {
			'confidence'     => $entry->metadata->confidence,
			'authority_tier' => $entry->metadata->authority_tier,
			'created_at'     => $entry->metadata->created_at,
			default          => null,
		};
	}

	/**
	 * Build the shared wp_guideline metadata required by Agents API.
	 *
	 * @param WP_Agent_Memory_Scope $scope Memory scope.
	 * @return array<string, string|int>
	 */
	private function guideline_metadata_for_scope( WP_Agent_Memory_Scope $scope ): array {
		return array(
			self::GUIDELINE_META_SCOPE        => $this->guideline_scope_for_layer( $scope->layer ),
			self::GUIDELINE_META_USER_ID      => $scope->user_id,
			self::GUIDELINE_META_WORKSPACE_ID => $scope->workspace_id,
		);
	}

	/**
	 * Map Data Machine memory layers to Agents API guideline scopes.
	 *
	 * @param string $layer Memory layer.
	 * @return string Guideline scope value.
	 */
	private function guideline_scope_for_layer( string $layer ): string {
		if ( 'shared' === $layer || 'network' === $layer ) {
			return self::GUIDELINE_SCOPE_WORKSPACE_GUIDANCE;
		}

		return self::GUIDELINE_SCOPE_PRIVATE_MEMORY;
	}

	/**
	 * Check whether the current context may read a guideline-backed memory post.
	 *
	 * @param \WP_Post $post Memory post.
	 * @return bool Whether read is allowed.
	 */
	private function can_read_post( \WP_Post $post ): bool {
		if ( $this->should_bypass_capability_checks() ) {
			return true;
		}

		return $this->is_private_memory_post( $post )
			// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Agents API registers this guideline capability.
			? current_user_can( 'read_private_agent_memory', $post->ID )
			// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Agents API registers this guideline capability.
			: current_user_can( 'read_workspace_guidelines', $post->ID );
	}

	/**
	 * Check whether the current context may edit/delete a guideline-backed memory post.
	 *
	 * @param \WP_Post $post Memory post.
	 * @return bool Whether write is allowed.
	 */
	private function can_write_post( \WP_Post $post ): bool {
		if ( $this->should_bypass_capability_checks() ) {
			return true;
		}

		return $this->is_private_memory_post( $post )
			// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Agents API registers this guideline capability.
			? current_user_can( 'edit_private_agent_memory', $post->ID )
			// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Agents API registers this guideline capability.
			: current_user_can( 'edit_workspace_guidelines', $post->ID );
	}

	/**
	 * Check whether the current context may create memory for a scope.
	 *
	 * @param WP_Agent_Memory_Scope $scope Target scope.
	 * @return bool Whether create is allowed.
	 */
	private function can_create_scope( WP_Agent_Memory_Scope $scope ): bool {
		if ( $this->should_bypass_capability_checks() ) {
			return true;
		}

		if ( self::GUIDELINE_SCOPE_PRIVATE_MEMORY === $this->guideline_scope_for_layer( $scope->layer ) ) {
			// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Agents API registers this guideline capability.
			return $this->current_user_owns_scope( $scope ) && current_user_can( 'edit_agent_memory' );
		}

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Agents API registers this guideline capability.
		return current_user_can( 'edit_workspace_guidelines' );
	}

	/**
	 * Whether capability checks should be bypassed for privileged non-interactive execution.
	 *
	 * @return bool Whether to bypass.
	 */
	private function should_bypass_capability_checks(): bool {
		return ( defined( 'WP_CLI' ) && WP_CLI ) || ! function_exists( 'current_user_can' );
	}

	/**
	 * Check if a memory post is private user-workspace memory.
	 *
	 * @param \WP_Post $post Memory post.
	 * @return bool Whether post is private memory.
	 */
	private function is_private_memory_post( \WP_Post $post ): bool {
		return self::GUIDELINE_SCOPE_PRIVATE_MEMORY === get_post_meta( $post->ID, self::GUIDELINE_META_SCOPE, true );
	}

	/**
	 * Check if the current user owns a private memory scope.
	 *
	 * @param WP_Agent_Memory_Scope $scope Memory scope.
	 * @return bool Whether the current user owns the scope.
	 */
	private function current_user_owns_scope( WP_Agent_Memory_Scope $scope ): bool {
		return function_exists( 'get_current_user_id' ) && $scope->user_id > 0 && get_current_user_id() === $scope->user_id;
	}
}
