<?php
/**
 * Get Post Blocks Ability
 *
 * Parses a post's Gutenberg content into indexed blocks with optional
 * filtering by block type and text search. This is the read primitive
 * for block-level content editing.
 *
 * @package DataMachine\Abilities\Content
 * @since 0.28.0
 */

namespace DataMachine\Abilities\Content;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Content\ContentFormat;

defined( 'ABSPATH' ) || exit;

class GetPostBlocksAbility {

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->registerAbility();
		self::$registered = true;
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/get-post-blocks',
				array(
					'label'               => __( 'Get Post Blocks', 'data-machine' ),
					'description'         => __( 'Parse a post into Gutenberg blocks with optional filtering by type or content', 'data-machine' ),
					'category'            => 'datamachine-content',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'post_id' ),
						'properties' => array(
							'post_id'         => array(
								'type'        => 'integer',
								'description' => __( 'Post ID to parse', 'data-machine' ),
							),
							'blog_id'         => array(
								'type'        => 'integer',
								'description' => __( 'Optional. Multisite blog ID the post lives on. Omit to use the current site. The read runs in that blog\'s context.', 'data-machine' ),
							),
							'block_types'     => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => __( 'Filter to specific block types (e.g. ["core/paragraph", "core/heading"]). Empty = all blocks.', 'data-machine' ),
							),
							'search'          => array(
								'type'        => 'string',
								'description' => __( 'Filter to blocks containing this text (case-insensitive)', 'data-machine' ),
							),
							'prefer_autosave' => array(
								'type'        => 'boolean',
								'description' => __( 'When true (default), read the calling user\'s latest autosave revision if it is newer than the saved post — so an in-flight draft is proofread, not the stale saved version. Set false to always read the saved post.', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'      => array( 'type' => 'boolean' ),
							'post_id'      => array( 'type' => 'integer' ),
							'total_blocks' => array( 'type' => 'integer' ),
							'blocks'       => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'index'      => array( 'type' => 'integer' ),
										'block_name' => array( 'type' => 'string' ),
										'inner_html' => array( 'type' => 'string' ),
									),
								),
							),
							'error'        => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'execute' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	/**
	 * Execute the ability.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function execute( array $input ): array|\WP_Error {
		// prefer_autosave defaults true: proofread the freshest authored content (in-flight autosave).
		$post_id         = absint( $input['post_id'] ?? 0 );
		$block_types     = $input['block_types'] ?? array();
		$search          = $input['search'] ?? '';
		$prefer_autosave = ! array_key_exists( 'prefer_autosave', $input ) || ! empty( $input['prefer_autosave'] );

		if ( $post_id <= 0 ) {
			return new \WP_Error( 'invalid_get_post_id', 'Valid post_id is required', array( 'status' => 400 ) );
		}

		// Resolve the target blog. On multisite the post may live on another
		// site than the one this request landed on; switch to it for the read.
		$ctx = BlogContext::enter( $input );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}

		try {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return new \WP_Error( 'get_post_not_found', sprintf( 'Post #%d does not exist', $post_id ), array( 'status' => 404 ) );
			}

			// Prefer the calling user's in-flight autosave when it is newer than
			// the saved post — so a draft being actively typed is proofread, not
			// the stale saved version. Runs inside the post's blog context above.
			$source_content = BlogContext::freshest_authored_content( $post, $prefer_autosave );

			$block_content = ContentFormat::storedToBlocks( $source_content, (string) $post->post_type );
			if ( is_wp_error( $block_content ) ) {
				return new \WP_Error(
					$block_content->get_error_code(),
					$block_content->get_error_message(),
					array_merge(
						array(
							'status'  => 500,
							'post_id' => $post_id,
						),
						is_array( $block_content->get_error_data() ) ? $block_content->get_error_data() : array()
					)
				);
			}

			$blocks  = parse_blocks( $block_content );
			$results = array();

			foreach ( $blocks as $index => $block ) {
				// Skip empty/freeform blocks with no content.
				$block_name = $block['blockName'] ?? null;
				$inner_html = $block['innerHTML'] ?? '';

				if ( null === $block_name && '' === trim( $inner_html ) ) {
					continue;
				}

				// Filter by block type if specified.
				if ( ! empty( $block_types ) && ! in_array( $block_name, $block_types, true ) ) {
					continue;
				}

				// Filter by search text if specified.
				if ( '' !== $search && false === stripos( $inner_html, $search ) ) {
					continue;
				}

				$results[] = array(
					'index'      => $index,
					'block_name' => $block_name ?? 'core/freeform',
					'inner_html' => $inner_html,
				);
			}

			return array(
				'success'      => true,
				'post_id'      => $post_id,
				'total_blocks' => count( $blocks ),
				'blocks'       => $results,
			);
		} finally {
			BlogContext::leave( $ctx );
		}
	}
}
