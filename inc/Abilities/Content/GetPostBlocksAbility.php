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
		$this->registerChatTool();
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

	private function registerChatTool(): void {
		add_filter(
			'datamachine_tools',
			function ( $tools ) {
				$tools['get_post_blocks'] = array(
					'_callable' => array( self::class, 'getChatTool' ),
					'modes'     => array( 'chat' ),
					'ability'   => 'datamachine/get-post-blocks',
				);
				return $tools;
			}
		);
	}

	/**
	 * Chat tool definition.
	 *
	 * @return array
	 */
	public static function getChatTool(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handleChatToolCall',
			'description' => 'Parse a WordPress post into its Gutenberg blocks. Optionally filter by block type or text content. Returns block index, type, and innerHTML for each matching block.',
			'parameters'  => array(
				'post_id'         => array(
					'type'        => 'integer',
					'required'    => true,
					'description' => 'Post ID to parse',
				),
				'blog_id'         => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Optional multisite blog ID the post lives on. Omit for the current site.',
				),
				'block_types'     => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'required'    => false,
					'description' => 'Filter to specific block types (e.g. ["core/paragraph"])',
				),
				'search'          => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Filter to blocks containing this text (case-insensitive)',
				),
				'prefer_autosave' => array(
					'type'        => 'boolean',
					'required'    => false,
					'description' => 'When true (default), read the caller\'s latest autosave revision if newer than the saved post, so an in-flight draft is proofread instead of the stale saved version.',
				),
			),
		);
	}

	/**
	 * Chat tool handler — wraps the ability execute.
	 *
	 * @param array $parameters Tool parameters.
	 * @param array $tool_def   Tool definition.
	 * @return array
	 */
	public static function handleChatToolCall( array $parameters, array $tool_def = array() ): array {
		$tool_def;
		$result = self::execute( $parameters );

		return array(
			'success'   => $result['success'],
			'data'      => $result,
			'tool_name' => 'get_post_blocks',
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function execute( array $input ): array {
		// prefer_autosave defaults true: proofread the freshest authored content (in-flight autosave).
		$post_id         = absint( $input['post_id'] ?? 0 );
		$block_types     = $input['block_types'] ?? array();
		$search          = $input['search'] ?? '';
		$prefer_autosave = ! array_key_exists( 'prefer_autosave', $input ) || ! empty( $input['prefer_autosave'] );

		if ( $post_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'Valid post_id is required',
			);
		}

		// Resolve the target blog. On multisite the post may live on another
		// site than the one this request landed on; switch to it for the read.
		$ctx = BlogContext::enter( $input );
		if ( is_wp_error( $ctx ) ) {
			return array(
				'success' => false,
				'error'   => $ctx->get_error_message(),
			);
		}

		try {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return array(
					'success' => false,
					'error'   => sprintf( 'Post #%d does not exist', $post_id ),
				);
			}

			// Prefer the calling user's in-flight autosave when it is newer than
			// the saved post — so a draft being actively typed is proofread, not
			// the stale saved version. Runs inside the post's blog context above.
			$source_content = BlogContext::freshest_authored_content( $post, $prefer_autosave );

			$block_content = ContentFormat::storedToBlocks( $source_content, (string) $post->post_type );
			if ( is_wp_error( $block_content ) ) {
				return array(
					'success' => false,
					'error'   => $block_content->get_error_message(),
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
