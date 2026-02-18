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

defined( 'ABSPATH' ) || exit;

class GetPostBlocksAbility {

	private static bool $registered = false;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) || self::$registered ) {
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
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'post_id' ),
						'properties' => array(
							'post_id'     => array(
								'type'        => 'integer',
								'description' => __( 'Post ID to parse', 'data-machine' ),
							),
							'block_types' => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => __( 'Filter to specific block types (e.g. ["core/paragraph", "core/heading"]). Empty = all blocks.', 'data-machine' ),
							),
							'search'      => array(
								'type'        => 'string',
								'description' => __( 'Filter to blocks containing this text (case-insensitive)', 'data-machine' ),
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

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	private function registerChatTool(): void {
		add_filter(
			'datamachine_chat_tools',
			function ( $tools ) {
				$tools['get_post_blocks'] = array( self::class, 'getChatTool' );
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
				'post_id'     => array(
					'type'        => 'integer',
					'required'    => true,
					'description' => 'Post ID to parse',
				),
				'block_types' => array(
					'type'        => 'array',
					'required'    => false,
					'description' => 'Filter to specific block types (e.g. ["core/paragraph"])',
				),
				'search'      => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Filter to blocks containing this text (case-insensitive)',
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
		$post_id     = absint( $input['post_id'] ?? 0 );
		$block_types = $input['block_types'] ?? array();
		$search      = $input['search'] ?? '';

		if ( $post_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'Valid post_id is required',
			);
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Post #%d does not exist', $post_id ),
			);
		}

		$blocks  = parse_blocks( $post->post_content );
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
	}
}
