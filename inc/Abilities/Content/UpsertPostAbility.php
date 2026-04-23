<?php
/**
 * UpsertPostAbility — datamachine/upsert-post
 *
 * Generic idempotent upsert for any WordPress post type. Finds an existing
 * post by identity, compares content, and returns created/updated/no_change.
 *
 * Identity resolution supports three strategies:
 *   1. post_id — explicit numeric ID (fastest, most deterministic)
 *   2. post_name + parent_id — slug-based lookup within a parent scope
 *   3. meta_key + meta_value — custom meta-based identity (e.g. _source_file)
 *
 * When content_hash is provided, the ability compares it against a stored
 * hash in post meta (_datamachine_content_hash) and returns no_change when
 * they match. This makes pipelines safe to re-run without churn.
 *
 * Provenance is stamped automatically: _datamachine_post_flow_id is written
 * by PostTracking (already in core), and this ability adds _datamachine_content_hash
 * plus optional _datamachine_raw_source for round-trip sync.
 *
 * @package DataMachine\Abilities\Content
 * @since   0.77.0
 */

namespace DataMachine\Abilities\Content;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\WordPress\ResolvePostByPath;

defined( 'ABSPATH' ) || exit;

class UpsertPostAbility {

	public const ABILITY_NAME           = 'datamachine/upsert-post';
	public const META_CONTENT_HASH      = '_datamachine_content_hash';
	public const META_RAW_SOURCE        = '_datamachine_raw_source';
	public const META_STUB_MARKER       = '_datamachine_auto_stub';

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->register_ability();
		$this->register_chat_tool();
		self::$registered = true;
	}

	/**
	 * Register the WordPress ability.
	 */
	private function register_ability(): void {
		$register = function () {
			wp_register_ability(
				self::ABILITY_NAME,
				array(
					'label'               => __( 'Upsert Post', 'data-machine' ),
					'description'         => __( 'Idempotently create or update a WordPress post. Returns created, updated, or no_change based on content hash comparison.', 'data-machine' ),
					'category'            => 'datamachine-content',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'post_type', 'title', 'content' ),
						'properties' => array(
							'post_type'      => array(
								'type'        => 'string',
								'description' => 'Post type slug (e.g. post, page, wiki, ec_doc).',
							),
							'title'          => array(
								'type'        => 'string',
								'description' => 'Post title.',
							),
							'content'        => array(
								'type'        => 'string',
								'description' => 'Post content (HTML or blocks). Replaces existing content when updating.',
							),
							'post_id'        => array(
								'type'        => 'integer',
								'description' => 'Explicit post ID. Most deterministic identity. Overrides other identity fields.',
							),
							'slug'           => array(
								'type'        => 'string',
								'description' => 'Post slug (post_name). Used with parent_id for scoped lookup.',
							),
							'parent_id'      => array(
								'type'        => 'integer',
								'description' => 'Parent post ID for scoped slug lookup.',
							),
							'parent_path'    => array(
								'type'        => 'string',
								'description' => 'Slash-delimited parent slug path (e.g. "artist/link-pages"). Resolved via ResolvePostByPath. Overrides parent_id.',
							),
							'identity_meta'  => array(
								'type'        => 'object',
								'description' => 'Custom meta-based identity. {key: "_source_file", value: "artist/getting-started.md"}',
								'properties'  => array(
									'key'   => array( 'type' => 'string' ),
									'value' => array( 'type' => 'string' ),
								),
								'required'    => array( 'key', 'value' ),
							),
							'content_hash'   => array(
								'type'        => 'string',
								'description' => 'Hash of normalized content for no_change detection. If omitted, no idempotency check is performed (always writes).',
							),
							'raw_source'     => array(
								'type'        => 'string',
								'description' => 'Optional raw source (e.g. markdown) stored in _datamachine_raw_source meta for round-trip sync.',
							),
							'post_status'    => array(
								'type'        => 'string',
								'description' => 'Post status for create path. Defaults to publish.',
							),
							'post_author'    => array(
								'type'        => 'integer',
								'description' => 'Post author user ID. Only applied on create; ignored on update.',
							),
							'post_excerpt'   => array(
								'type'        => 'string',
								'description' => 'Post excerpt.',
							),
							'taxonomies'     => array(
								'type'        => 'object',
								'description' => 'Taxonomy terms to assign. {taxonomy: [term1, term2]}',
							),
							'meta_input'     => array(
								'type'        => 'object',
								'description' => 'Additional post meta to set.',
							),
							'create_stubs'   => array(
								'type'        => 'boolean',
								'description' => 'When parent_path is provided, auto-create missing intermediate nodes as stubs.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'   => array( 'type' => 'boolean' ),
							'action'    => array( 'type' => 'string', 'enum' => array( 'created', 'updated', 'no_change' ) ),
							'message'   => array( 'type' => 'string' ),
							'post_id'   => array( 'type' => 'integer' ),
							'post_url'  => array( 'type' => 'string' ),
							'path'      => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'execute' ),
					'permission_callback' => fn() => PermissionHelper::can( 'chat' ),
					'meta'                => array(
						'show_in_rest' => true,
						'mcp'          => array( 'public' => true ),
						'annotations'  => array(
							'readonly'    => false,
							'destructive' => false,
							'idempotent'  => true,
						),
					),
				)
			);
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register );
		} else {
			$register();
		}
	}

	/**
	 * Register as a chat / pipeline tool.
	 */
	private function register_chat_tool(): void {
		add_filter(
			'datamachine_tools',
			function ( array $tools ): array {
				$tools['upsert_post'] = array(
					'_callable' => array( self::class, 'getChatTool' ),
					'modes'     => array( 'chat', 'pipeline', 'system' ),
					'ability'   => self::ABILITY_NAME,
				);
				return $tools;
			}
		);
	}

	/**
	 * Chat tool definition.
	 */
	public static function getChatTool(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handleChatToolCall',
			'description' => 'Idempotently create or update a WordPress post. Finds by identity (post_id, slug+parent, or custom meta), compares content hash, and returns created/updated/no_change. Use for pipeline-safe writes that avoid churn on re-runs.',
			'parameters'  => array(
				'post_type'    => array(
					'type'        => 'string',
					'description' => 'Post type slug.',
				),
				'title'        => array(
					'type'        => 'string',
					'description' => 'Post title.',
				),
				'content'      => array(
					'type'        => 'string',
					'description' => 'Post content (HTML or blocks).',
				),
				'slug'         => array(
					'type'        => 'string',
					'description' => 'Post slug for lookup.',
				),
				'parent_id'    => array(
					'type'        => 'integer',
					'description' => 'Parent post ID.',
				),
				'parent_path'  => array(
					'type'        => 'string',
					'description' => 'Slash-delimited parent path (e.g. "artist/link-pages").',
				),
				'post_author'  => array(
					'type'        => 'integer',
					'description' => 'Post author user ID (create only).',
				),
				'content_hash' => array(
					'type'        => 'string',
					'description' => 'Hash for idempotency check.',
				),
			),
			'required'    => array( 'post_type', 'title', 'content' ),
		);
	}

	/**
	 * Handle chat tool call.
	 */
	public static function handleChatToolCall( array $params, array $tool_def = array() ): array {
		$result = self::execute( $params );
		return array(
			'success'   => ! empty( $result['success'] ),
			'data'      => $result,
			'tool_name' => 'upsert_post',
		);
	}

	/**
	 * Execute the upsert.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with action: created|updated|no_change.
	 */
	public static function execute( array $input ): array {
		$post_type    = sanitize_key( $input['post_type'] ?? '' );
		$title        = trim( $input['title'] ?? '' );
		$content      = $input['content'] ?? '';
		$post_id      = absint( $input['post_id'] ?? 0 );
		$slug         = sanitize_title( $input['slug'] ?? '' );
		$parent_id    = absint( $input['parent_id'] ?? 0 );
		$parent_path  = trim( $input['parent_path'] ?? '' );
		$identity_meta = $input['identity_meta'] ?? array();
		$content_hash = $input['content_hash'] ?? '';
		$raw_source   = $input['raw_source'] ?? '';
		$post_status  = sanitize_key( $input['post_status'] ?? 'publish' );
		$post_author  = absint( $input['post_author'] ?? 0 );
		$post_excerpt = $input['post_excerpt'] ?? '';
		$taxonomies   = $input['taxonomies'] ?? array();
		$meta_input   = $input['meta_input'] ?? array();
		$create_stubs = ! empty( $input['create_stubs'] );

		if ( '' === $post_type || '' === $title ) {
			return array(
				'success' => false,
				'error'   => 'post_type and title are required.',
			);
		}

		// Resolve parent_path if provided.
		if ( '' !== $parent_path ) {
			if ( $create_stubs ) {
				$resolved = ResolvePostByPath::resolve_or_create_stubs(
					$parent_path,
					$post_type,
					self::META_STUB_MARKER
				);
			} else {
				$resolved = ResolvePostByPath::resolve( $parent_path, $post_type );
			}

			if ( is_wp_error( $resolved ) ) {
				return array(
					'success' => false,
					'error'   => $resolved->get_error_message(),
				);
			}

			$parent_id = (int) $resolved;
		}

		// Resolve existing post.
		$existing_id = self::resolve_existing_post(
			$post_id,
			$slug,
			$parent_id,
			$identity_meta,
			$post_type
		);

		if ( is_wp_error( $existing_id ) ) {
			return array(
				'success' => false,
				'error'   => $existing_id->get_error_message(),
			);
		}

		// Idempotency check.
		if ( $existing_id > 0 && '' !== $content_hash ) {
			$stored_hash = get_post_meta( $existing_id, self::META_CONTENT_HASH, true );
			if ( $stored_hash === $content_hash ) {
				$post = get_post( $existing_id );
				return array(
					'success'  => true,
					'action'   => 'no_change',
					'message'  => sprintf( 'No change: %s', $post->post_title ),
					'post_id'  => $existing_id,
					'post_url' => get_permalink( $existing_id ),
					'path'     => ResolvePostByPath::build_path( $post ),
				);
			}
		}

		// Build post data.
		$post_data = array(
			'post_type'    => $post_type,
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $post_status,
		);

		if ( '' !== $slug ) {
			$post_data['post_name'] = $slug;
		}

		if ( '' !== $post_excerpt ) {
			$post_data['post_excerpt'] = $post_excerpt;
		}

		if ( $parent_id > 0 ) {
			$post_data['post_parent'] = $parent_id;
		}

		if ( $existing_id > 0 ) {
			$post_data['ID'] = $existing_id;
			$action          = 'updated';
		} else {
			$action = 'created';
			if ( $post_author > 0 ) {
				$post_data['post_author'] = $post_author;
			}
		}

		// Merge caller-supplied meta_input.
		$all_meta = is_array( $meta_input ) ? $meta_input : array();

		if ( '' !== $content_hash ) {
			$all_meta[ self::META_CONTENT_HASH ] = $content_hash;
		}

		if ( '' !== $raw_source ) {
			$all_meta[ self::META_RAW_SOURCE ] = $raw_source;
		}

		if ( ! empty( $all_meta ) ) {
			$post_data['meta_input'] = $all_meta;
		}

		$id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $id ) ) {
			return array(
				'success' => false,
				'error'   => $id->get_error_message(),
			);
		}

		// Assign taxonomies.
		if ( is_array( $taxonomies ) && ! empty( $taxonomies ) ) {
			foreach ( $taxonomies as $taxonomy => $terms ) {
				if ( ! taxonomy_exists( $taxonomy ) ) {
					continue;
				}
				$term_ids = array();
				$terms    = is_array( $terms ) ? $terms : array( $terms );

				foreach ( $terms as $term ) {
					if ( is_numeric( $term ) ) {
						$term_ids[] = (int) $term;
					} else {
						$existing = get_term_by( 'name', $term, $taxonomy );
						if ( ! $existing ) {
							$existing = get_term_by( 'slug', sanitize_title( $term ), $taxonomy );
						}
						if ( $existing && ! is_wp_error( $existing ) ) {
							$term_ids[] = (int) $existing->term_id;
						} else {
							// Create term if not found.
							$result = wp_insert_term( $term, $taxonomy );
							if ( ! is_wp_error( $result ) && isset( $result['term_id'] ) ) {
								$term_ids[] = (int) $result['term_id'];
							}
						}
					}
				}

				if ( ! empty( $term_ids ) ) {
					wp_set_object_terms( (int) $id, $term_ids, $taxonomy );
				}
			}
		}

		$post = get_post( (int) $id );

		return array(
			'success'  => true,
			'action'   => $action,
			'message'  => sprintf( '%s: %s', ucfirst( $action ), $post->post_title ),
			'post_id'  => (int) $id,
			'post_url' => get_permalink( (int) $id ),
			'path'     => ResolvePostByPath::build_path( $post ),
		);
	}

	/**
	 * Resolve an existing post by the provided identity fields.
	 *
	 * Priority: post_id > identity_meta > slug+parent_id.
	 *
	 * @param int    $post_id      Explicit post ID.
	 * @param string $slug         Post slug.
	 * @param int    $parent_id    Parent post ID.
	 * @param array  $identity_meta Custom meta identity.
	 * @param string $post_type    Post type.
	 * @return int|\WP_Error Existing post ID, 0 if not found, or WP_Error.
	 */
	private static function resolve_existing_post(
		int $post_id,
		string $slug,
		int $parent_id,
		array $identity_meta,
		string $post_type
	) {
		// 1. Explicit post_id.
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( $post && $post->post_type === $post_type ) {
				return $post_id;
			}
			return new \WP_Error(
				'not_found',
				sprintf( 'Post #%d not found or wrong post type.', $post_id )
			);
		}

		// 2. Custom meta identity.
		if ( ! empty( $identity_meta['key'] ) && ! empty( $identity_meta['value'] ) ) {
			$meta_key   = sanitize_key( $identity_meta['key'] );
			$meta_value = sanitize_text_field( $identity_meta['value'] );

			$args = array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'meta_query'     => array(
					array(
						'key'   => $meta_key,
						'value' => $meta_value,
					),
				),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			);

			$query = new \WP_Query( $args );
			if ( ! empty( $query->posts ) ) {
				return (int) $query->posts[0];
			}
		}

		// 3. Slug + parent_id.
		if ( '' !== $slug ) {
			$args = array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'name'           => $slug,
				'post_parent'    => $parent_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			);

			$query = new \WP_Query( $args );
			if ( ! empty( $query->posts ) ) {
				return (int) $query->posts[0];
			}
		}

		return 0;
	}
}
