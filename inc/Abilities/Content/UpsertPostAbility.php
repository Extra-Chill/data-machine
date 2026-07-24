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
use DataMachine\Core\Content\ContentFormat;
use DataMachine\Core\Database\PostIdentityReservations\PostIdentityReservations;
use DataMachine\Core\SourceDate;
use DataMachine\Core\WordPress\ResolvePostByPath;
use DataMachine\Core\WordPress\PostTracking;
use DataMachine\Core\WordPress\WordPressPublishHelper;

defined( 'ABSPATH' ) || exit;

class UpsertPostAbility {

	public const ABILITY_NAME           = 'datamachine/upsert-post';
	public const META_CONTENT_HASH      = '_datamachine_content_hash';
	public const META_RAW_SOURCE        = '_datamachine_raw_source';
	public const META_STUB_MARKER       = '_datamachine_auto_stub';
	public const META_ORIGINAL_DATE_GMT = '_datamachine_original_date_gmt';

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
							'post_type'              => array(
								'type'        => 'string',
								'description' => 'Post type slug (e.g. post, page, wiki, ec_doc).',
							),
							'title'                  => array(
								'type'        => 'string',
								'description' => 'Post title.',
							),
							'content'                => array(
								'type'        => 'string',
								'description' => 'Post content. Replaces existing content when updating. Raw ability callers that omit content_format are treated as providing block markup for backwards compatibility.',
							),
							'content_format'         => array(
								'type'        => 'string',
								'enum'        => array( 'blocks', 'html', 'markdown' ),
								'description' => 'Authoring/source format of content, not the stored post_content format. Raw ability/API calls default to blocks for compatibility. Pass markdown or html when supplying those source formats; the post type stored format is decided by datamachine_post_content_format.',
							),
							'post_id'                => array(
								'type'        => 'integer',
								'description' => 'Explicit post ID. Most deterministic identity. Overrides other identity fields.',
							),
							'slug'                   => array(
								'type'        => 'string',
								'description' => 'Post slug (post_name). Used with parent_id for scoped lookup.',
							),
							'parent_id'              => array(
								'type'        => 'integer',
								'description' => 'Parent post ID for scoped slug lookup.',
							),
							'parent_path'            => array(
								'type'        => 'string',
								'description' => 'Slash-delimited parent slug path (e.g. "artist/link-pages"). Resolved via ResolvePostByPath. Overrides parent_id.',
							),
							'identity_meta'          => array(
								'type'        => 'object',
								'description' => 'Custom meta-based identity. {key: "_source_file", value: "artist/getting-started.md"}',
								'properties'  => array(
									'key'   => array( 'type' => 'string' ),
									'value' => array( 'type' => 'string' ),
								),
								'required'    => array( 'key', 'value' ),
							),
							'content_hash'           => array(
								'type'        => 'string',
								'description' => 'Hash of normalized content for no_change detection. If omitted, no idempotency check is performed (always writes).',
							),
							'raw_source'             => array(
								'type'        => 'string',
								'description' => 'Optional raw source (e.g. markdown) stored in _datamachine_raw_source meta for round-trip sync.',
							),
							'source_url'             => array(
								'type'        => 'string',
								'description' => 'Original source URL for attribution and dedupe.',
							),
							'original_date_gmt'      => array(
								'type'        => 'string',
								'description' => 'Original source publication date in GMT.',
							),

							'add_source_attribution' => array(
								'type'        => 'boolean',
								'description' => 'When true, append a clickable source link to the stored content when source_url is available.',
							),

							'post_status'            => array(
								'type'        => 'string',
								'description' => 'Post status for create path. Defaults to publish.',
							),
							'post_author'            => array(
								'type'        => 'integer',
								'description' => 'Post author user ID. Only applied on create; ignored on update.',
							),
							'post_excerpt'           => array(
								'type'        => 'string',
								'description' => 'Post excerpt.',
							),
							'taxonomies'             => array(
								'type'        => 'object',
								'description' => 'Taxonomy terms to assign. {taxonomy: [term1, term2]}',
							),
							'meta_input'             => array(
								'type'        => 'object',
								'description' => 'Additional post meta to set.',
							),
							'create_stubs'           => array(
								'type'        => 'boolean',
								'description' => 'When parent_path is provided, auto-create missing intermediate nodes as stubs.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'  => array( 'type' => 'boolean' ),
							'action'   => array(
								'type' => 'string',
								'enum' => array( 'created', 'updated', 'no_change' ),
							),
							'message'  => array( 'type' => 'string' ),
							'post_id'  => array( 'type' => 'integer' ),
							'post_url' => array( 'type' => 'string' ),
							'path'     => array( 'type' => 'string' ),
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

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register );
	}

	/**
	 * Register as a chat / pipeline tool.
	 */
	private function register_chat_tool(): void {
		add_filter(
			'datamachine_tools',
			function ( array $tools ): array {
				$tools['upsert_post'] = array(
					'_callable'                => array( self::class, 'getChatTool' ),
					'modes'                    => array( 'chat', 'pipeline', 'system' ),
					'ability'                  => self::ABILITY_NAME,
					// Generic content-writing tools are opt-in for pipeline AI
					// steps so a model cannot improvise arbitrary publishes that
					// bypass the flow's declared handler. Chat/system unaffected.
					// See https://github.com/Extra-Chill/data-machine/issues/2852.
					'requires_pipeline_opt_in' => true,
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
			'description' => 'Idempotently create or update a WordPress post. For normal authored prose, write content as markdown and omit content_format; Data Machine converts that authoring format to the post type stored format. Only set content_format when you are intentionally providing html or serialized block markup.',
			'parameters'  => array(
				'post_type'              => array(
					'type'        => 'string',
					'description' => 'Post type slug.',
				),
				'title'                  => array(
					'type'        => 'string',
					'description' => 'Post title.',
				),
				'content'                => array(
					'type'        => 'string',
					'description' => 'Post content to author. Use markdown for normal prose unless content_format explicitly says otherwise.',
				),
				'content_format'         => array(
					'type'        => 'string',
					'enum'        => array( 'markdown', 'html', 'blocks' ),
					'description' => 'Optional authoring/source format for content. Omit for normal prose; AI tool calls default to markdown. Set to html or blocks only when content is already in that format. This is distinct from the stored format chosen by the post type.',
				),
				'slug'                   => array(
					'type'        => 'string',
					'description' => 'Post slug for lookup.',
				),
				'parent_id'              => array(
					'type'        => 'integer',
					'description' => 'Parent post ID.',
				),
				'parent_path'            => array(
					'type'        => 'string',
					'description' => 'Slash-delimited parent path (e.g. "artist/link-pages").',
				),
				'post_author'            => array(
					'type'        => 'integer',
					'description' => 'Post author user ID (create only).',
				),
				'content_hash'           => array(
					'type'        => 'string',
					'description' => 'Hash for idempotency check.',
				),
				'source_url'             => array(
					'type'        => 'string',
					'description' => 'Original source URL for attribution and dedupe.',
				),
				'original_date_gmt'      => array(
					'type'        => 'string',
					'description' => 'Original source publication date in GMT.',
				),

				'add_source_attribution' => array(
					'type'        => 'boolean',
					'description' => 'When true, append a clickable source link to the stored content when source_url is available.',
				),
			),
			'required'    => array( 'post_type', 'title', 'content' ),
		);
	}

	/**
	 * Handle chat tool call.
	 */
	public static function handleChatToolCall( array $params, array $tool_def = array() ): array {
		unset( $tool_def );
		if ( empty( $params['content_format'] ) ) {
			$params['content_format'] = 'markdown';
		}

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
		$post_type         = sanitize_key( $input['post_type'] ?? '' );
		$title             = trim( $input['title'] ?? '' );
		$content           = $input['content'] ?? '';
		$content_format    = sanitize_key( $input['content_format'] ?? 'blocks' );
		$post_id           = absint( $input['post_id'] ?? 0 );
		$slug              = sanitize_title( $input['slug'] ?? '' );
		$parent_id         = absint( $input['parent_id'] ?? 0 );
		$parent_path       = trim( $input['parent_path'] ?? '' );
		$identity_meta     = $input['identity_meta'] ?? array();
		$content_hash      = $input['content_hash'] ?? '';
		$raw_source        = $input['raw_source'] ?? '';
		$source_url        = esc_url_raw( (string) ( $input['source_url'] ?? '' ) );
		$original_date_gmt = SourceDate::normalizeGmt( $input['original_date_gmt'] ?? '' );

		$add_source_attribution = ! empty( $input['add_source_attribution'] );

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

		if ( $add_source_attribution && '' !== $source_url ) {
			$content = WordPressPublishHelper::applySourceAttribution(
				(string) $content,
				$source_url,
				array(
					'link_handling'  => 'append',
					'content_format' => $content_format,
				)
			);

			if ( '' !== $raw_source ) {
				$raw_source = WordPressPublishHelper::applySourceAttribution(
					(string) $raw_source,
					$source_url,
					array(
						'link_handling'  => 'append',
						'content_format' => $content_format,
					)
				);
			}

			if ( '' !== $content_hash ) {
				$content_hash = hash( 'sha256', (string) $content );
			}
		}

		$stored_content = ContentFormat::sourceToStored( (string) $content, $content_format, $post_type );
		if ( is_wp_error( $stored_content ) ) {
			return array(
				'success'    => false,
				'error'      => $stored_content->get_error_message(),
				'error_code' => $stored_content->get_error_code(),
				'error_data' => $stored_content->get_error_data(),
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

		$write_context = array(
			'post_type'         => $post_type,
			'title'             => $title,
			'stored_content'    => $stored_content,
			'slug'              => $slug,
			'parent_id'         => $parent_id,
			'content_hash'      => $content_hash,
			'raw_source'        => $raw_source,
			'source_url'        => $source_url,
			'original_date_gmt' => $original_date_gmt,
			'post_status'       => $post_status,
			'post_author'       => $post_author,
			'post_excerpt'      => $post_excerpt,
			'taxonomies'        => $taxonomies,
			'meta_input'        => $meta_input,
		);

		if ( $post_id > 0 ) {
			$existing_id = self::resolve_existing_post( $post_id, $slug, $parent_id, is_array( $identity_meta ) ? $identity_meta : array(), $post_type );
			if ( is_wp_error( $existing_id ) ) {
				return array(
					'success' => false,
					'error'   => $existing_id->get_error_message(),
				);
			}

			return self::execute_resolved_write( $write_context, (int) $existing_id );
		}

		if ( self::has_usable_identity_meta( $identity_meta ) ) {
			return self::execute_identity_backed(
				$write_context,
				$identity_meta,
				$slug,
				$parent_id
			);
		}

		// Preserve explicit > identity > slug resolution for omitted, partial,
		// empty, and otherwise unusable identity_meta direct-execute inputs.
		$existing_id = self::resolve_existing_post(
			$post_id,
			$slug,
			$parent_id,
			is_array( $identity_meta ) ? $identity_meta : array(),
			$post_type
		);

		if ( is_wp_error( $existing_id ) ) {
			return array(
				'success' => false,
				'error'   => $existing_id->get_error_message(),
			);
		}

		return self::execute_resolved_write( $write_context, (int) $existing_id );
	}

	/**
	 * Execute an identity-backed write while holding its advisory fence.
	 *
	 * @param array $context       Normalized write context.
	 * @param array $identity_meta Identity meta input.
	 * @param string $slug         Slug fallback.
	 * @param int   $parent_id     Slug parent scope.
	 * @return array
	 */
	private static function execute_identity_backed(
		array $context,
		array $identity_meta,
		string $slug,
		int $parent_id
	): array {
		$post_type = $context['post_type'];

		$slug_fallback_id = 0;
		if ( '' !== $slug ) {
			$slug_fallback_id = self::resolve_existing_post( 0, $slug, $parent_id, array(), $post_type );
			if ( is_wp_error( $slug_fallback_id ) ) {
				return array(
					'success'    => false,
					'error'      => $slug_fallback_id->get_error_message(),
					'error_code' => $slug_fallback_id->get_error_code(),
				);
			}
		}

		$identity = PostIdentityReservations::normalize_identity( $post_type, $identity_meta );
		if ( is_wp_error( $identity ) ) {
			return array(
				'success'    => false,
				'error'      => $identity->get_error_message(),
				'error_code' => $identity->get_error_code(),
				'error_data' => $identity->get_error_data(),
			);
		}

		$shell = array(
			'post_author'    => $context['post_author'] > 0 ? $context['post_author'] : get_current_user_id(),
			'comment_status' => get_default_comment_status( $post_type ),
			'ping_status'    => get_default_comment_status( $post_type, 'pingback' ),
			'post_date'      => current_time( 'mysql' ),
			'post_date_gmt'  => current_time( 'mysql', true ),
			'guid'           => 'urn:uuid:' . wp_generate_uuid4(),
		);

		$reservations = new PostIdentityReservations();
		$locked       = $reservations->acquire_lock( $identity['identity_hash'] );
		if ( is_wp_error( $locked ) ) {
			return array(
				'success'    => false,
				'error'      => $locked->get_error_message(),
				'error_code' => $locked->get_error_code(),
				'error_data' => $locked->get_error_data(),
			);
		}

		$result   = array();
		$released = false;
		try {
			$reservation = $reservations->reserve_and_resolve(
				$post_type,
				$identity_meta,
				0,
				(int) $slug_fallback_id,
				$shell
			);
			if ( is_wp_error( $reservation ) ) {
				$result = array(
					'success'    => false,
					'error'      => $reservation->get_error_message(),
					'error_code' => $reservation->get_error_code(),
					'error_data' => $reservation->get_error_data(),
				);
			} else {
				do_action( 'datamachine_upsert_post_identity_before_population', (int) $reservation['post_id'] );

				$result = self::execute_resolved_write( $context, (int) $reservation['post_id'], $reservation, $reservations );
			}
		} catch ( \Throwable ) {
			try {
				$reservations->record_error( $identity['identity_hash'], 'identity_population_exception' );
			} catch ( \Throwable $diagnostic_exception ) {
				// Diagnostic persistence must not replace the retryable ability result.
				unset( $diagnostic_exception );
			}
			$result = array(
				'success'    => false,
				'error'      => 'Post identity population failed unexpectedly; retry is required.',
				'error_code' => 'identity_population_exception',
				'error_data' => array( 'retryable' => true ),
			);
		} finally {
			try {
				$released = $reservations->release_lock( $identity['identity_hash'] );
			} catch ( \Throwable ) {
				$released = false;
			}
		}

		if ( ! $released ) {
			return array(
				'success'    => false,
				'error'      => 'Post identity lock release is uncertain; retry is required.',
				'error_code' => 'identity_lock_release_uncertain',
				'error_data' => array( 'retryable' => true ),
			);
		}

		return $result;
	}

	/**
	 * Populate or update one already-resolved post through normal WordPress APIs.
	 *
	 * @param array                         $context      Normalized write context.
	 * @param int                           $existing_id  Existing or linked post ID.
	 * @param array|null                    $reservation Reservation outcome.
	 * @param PostIdentityReservations|null $reservations Reservation repository.
	 * @return array
	 */
	private static function execute_resolved_write(
		array $context,
		int $existing_id,
		?array $reservation = null,
		?PostIdentityReservations $reservations = null
	): array {
		$post_type         = $context['post_type'];
		$title             = $context['title'];
		$stored_content    = $context['stored_content'];
		$slug              = $context['slug'];
		$parent_id         = $context['parent_id'];
		$content_hash      = $context['content_hash'];
		$raw_source        = $context['raw_source'];
		$source_url        = $context['source_url'];
		$original_date_gmt = $context['original_date_gmt'];
		$post_status       = $context['post_status'];
		$post_author       = $context['post_author'];
		$post_excerpt      = $context['post_excerpt'];
		$taxonomies        = $context['taxonomies'];
		$meta_input        = $context['meta_input'];
		$has_reservation   = null !== $reservation && $reservations instanceof PostIdentityReservations;

		// Idempotency check. Parent/slug changes still need a write even when
		// the content hash is unchanged.
		if ( $existing_id > 0 && '' !== $content_hash ) {
			$stored_hash = get_post_meta( $existing_id, self::META_CONTENT_HASH, true );
			$post        = get_post( $existing_id );
			$same_parent = ! $post instanceof \WP_Post || $parent_id <= 0 || (int) $post->post_parent === $parent_id;
			$same_slug   = ! $post instanceof \WP_Post || '' === $slug || (string) $post->post_name === $slug;

			$identity_complete = ! $has_reservation
				|| (string) get_post_meta( $existing_id, $reservation['identity']['meta_key'], true ) === $reservation['identity']['meta_value'];
			if ( $stored_hash === $content_hash && $same_parent && $same_slug && $identity_complete ) {
				self::applySourceMetadata( $existing_id, $source_url, $original_date_gmt );
				if ( $has_reservation ) {
					$completed = $reservations->mark_complete( $reservation['identity'], $existing_id );
					if ( is_wp_error( $completed ) ) {
						return array(
							'success'    => false,
							'error'      => $completed->get_error_message(),
							'error_code' => $completed->get_error_code(),
						);
					}
				}
				$post_title = $post instanceof \WP_Post ? $post->post_title : $title;
				return array(
					'success'  => true,
					'action'   => 'no_change',
					'message'  => sprintf( 'No change: %s', $post_title ),
					'post_id'  => $existing_id,
					'post_url' => get_permalink( $existing_id ),
					'path'     => ResolvePostByPath::build_path( $existing_id ),
				);
			}
		}

		// Build post data.
		$post_data = array(
			'post_type'    => $post_type,
			'post_title'   => $title,
			'post_content' => $stored_content,
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

		if ( null !== $original_date_gmt ) {
			$post_data['post_date_gmt'] = $original_date_gmt;
			$post_data['post_date']     = get_date_from_gmt( $original_date_gmt );
		}

		if ( $existing_id > 0 ) {
			$post_data['ID'] = $existing_id;
			$was_allocated   = $has_reservation && ! empty( $reservation['allocated'] );
			$action          = $was_allocated ? 'created' : 'updated';
			if ( $was_allocated ) {
				$post_data['post_author']    = $reservation['shell']['post_author'];
				$post_data['comment_status'] = $reservation['shell']['comment_status'];
				$post_data['ping_status']    = $reservation['shell']['ping_status'];
			}
		} else {
			$action = 'created';
			if ( $post_author > 0 ) {
				$post_data['post_author'] = $post_author;
			}
		}

		// Merge caller-supplied meta_input.
		$all_meta = is_array( $meta_input ) ? $meta_input : array();
		if ( $has_reservation ) {
			$all_meta[ $reservation['identity']['meta_key'] ] = wp_slash( $reservation['identity']['meta_value'] );
		}

		if ( '' !== $content_hash ) {
			$all_meta[ self::META_CONTENT_HASH ] = $content_hash;
		}

		if ( '' !== $raw_source ) {
			$all_meta[ self::META_RAW_SOURCE ] = $raw_source;
		}

		if ( '' !== $source_url ) {
			$all_meta[ PostTracking::SOURCE_URL_META_KEY ] = $source_url;
		}

		if ( null !== $original_date_gmt ) {
			$all_meta[ self::META_ORIGINAL_DATE_GMT ] = $original_date_gmt;
		}

		if ( ! empty( $all_meta ) ) {
			$post_data['meta_input'] = $all_meta;
		}

		$id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $id ) ) {
			if ( $has_reservation ) {
				$reservations->record_error( $reservation['identity']['identity_hash'], (string) $id->get_error_code() );
			}
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
						if ( $existing instanceof \WP_Term ) {
							$term_ids[] = (int) $existing->term_id;
						} else {
							// Create term if not found.
							$result = wp_insert_term( $term, $taxonomy );
							if ( is_array( $result ) ) {
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

		if ( $has_reservation ) {
			$completed = $reservations->mark_complete( $reservation['identity'], (int) $id );
			if ( is_wp_error( $completed ) ) {
				return array(
					'success'    => false,
					'error'      => $completed->get_error_message(),
					'error_code' => $completed->get_error_code(),
				);
			}
		}

		$post       = get_post( (int) $id );
		$post_title = $post instanceof \WP_Post ? $post->post_title : $title;

		return array(
			'success'  => true,
			'action'   => $action,
			'message'  => sprintf( '%s: %s', ucfirst( $action ), $post_title ),
			'post_id'  => (int) $id,
			'post_url' => get_permalink( (int) $id ),
			'path'     => ResolvePostByPath::build_path( (int) $id ),
		);
	}

	/** Match the historical direct-execute identity_meta truthiness contract. */
	private static function has_usable_identity_meta( $identity_meta ): bool {
		if ( ! is_array( $identity_meta ) || empty( $identity_meta['key'] ) || empty( $identity_meta['value'] ) ) {
			return false;
		}

		return '' !== sanitize_key( (string) $identity_meta['key'] )
			&& '' !== sanitize_text_field( (string) $identity_meta['value'] );
	}

	/**
	 * Apply source metadata to an existing post when content is unchanged.
	 *
	 * @param int         $post_id           Post ID.
	 * @param string      $source_url        Source URL.
	 * @param string|null $original_date_gmt Original source date.
	 */
	private static function applySourceMetadata( int $post_id, string $source_url, ?string $original_date_gmt ): void {
		$update = array( 'ID' => $post_id );

		if ( '' !== $source_url ) {
			update_post_meta( $post_id, PostTracking::SOURCE_URL_META_KEY, $source_url );
		}

		if ( null !== $original_date_gmt ) {
			update_post_meta( $post_id, self::META_ORIGINAL_DATE_GMT, $original_date_gmt );
			$update['post_date_gmt'] = $original_date_gmt;
			$update['post_date']     = get_date_from_gmt( $original_date_gmt );
		}

		if ( count( $update ) > 1 ) {
			wp_update_post( $update );
		}
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
			$post             = get_post( $post_id );
			$actual_post_type = is_object( $post ) ? (string) $post->post_type : '';
			if ( $actual_post_type === $post_type ) {
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
				$found = $query->posts[0];
				return $found instanceof \WP_Post ? (int) $found->ID : (int) $found;
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
				$found = $query->posts[0];
				return $found instanceof \WP_Post ? (int) $found->ID : (int) $found;
			}
		}

		return 0;
	}
}
