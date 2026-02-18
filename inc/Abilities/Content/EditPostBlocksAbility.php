<?php
/**
 * Edit Post Blocks Ability
 *
 * Surgical find/replace within specific Gutenberg blocks by index.
 * Parses → edits targeted blocks → sanitizes → saves. The write
 * primitive for block-level content editing.
 *
 * @package DataMachine\Abilities\Content
 * @since 0.28.0
 */

namespace DataMachine\Abilities\Content;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class EditPostBlocksAbility {

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
				'datamachine/edit-post-blocks',
				array(
					'label'               => __( 'Edit Post Blocks', 'data-machine' ),
					'description'         => __( 'Surgical find/replace within specific Gutenberg blocks by index', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'post_id', 'edits' ),
						'properties' => array(
							'post_id' => array(
								'type'        => 'integer',
								'description' => __( 'Post ID to edit', 'data-machine' ),
							),
							'edits'   => array(
								'type'        => 'array',
								'description' => __( 'Array of edit operations', 'data-machine' ),
								'items'       => array(
									'type'       => 'object',
									'required'   => array( 'block_index', 'find', 'replace' ),
									'properties' => array(
										'block_index' => array(
											'type'        => 'integer',
											'description' => __( 'Zero-based block index to edit', 'data-machine' ),
										),
										'find'        => array(
											'type'        => 'string',
											'description' => __( 'Text to find within the block', 'data-machine' ),
										),
										'replace'     => array(
											'type'        => 'string',
											'description' => __( 'Replacement text', 'data-machine' ),
										),
									),
								),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'         => array( 'type' => 'boolean' ),
							'post_id'         => array( 'type' => 'integer' ),
							'post_url'        => array( 'type' => 'string' ),
							'changes_applied' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
							'error'           => array( 'type' => 'string' ),
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
				$tools['edit_post_blocks'] = array( self::class, 'getChatTool' );
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
			'description' => 'Surgical find/replace within specific Gutenberg blocks by index. Use get_post_blocks first to identify target blocks and indices.',
			'parameters'  => array(
				'post_id' => array(
					'type'        => 'integer',
					'required'    => true,
					'description' => 'Post ID to edit',
				),
				'edits'   => array(
					'type'        => 'array',
					'required'    => true,
					'description' => 'Array of { block_index, find, replace } operations',
				),
			),
		);
	}

	/**
	 * Chat tool handler.
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
			'tool_name' => 'edit_post_blocks',
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function execute( array $input ): array {
		$post_id = absint( $input['post_id'] ?? 0 );
		$edits   = $input['edits'] ?? array();

		if ( $post_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'Valid post_id is required',
			);
		}

		if ( empty( $edits ) || ! is_array( $edits ) ) {
			return array(
				'success' => false,
				'error'   => 'At least one edit operation is required',
			);
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Post #%d does not exist', $post_id ),
			);
		}

		$blocks        = parse_blocks( $post->post_content );
		$total_blocks  = count( $blocks );
		$changes       = array();

		foreach ( $edits as $edit ) {
			$block_index = $edit['block_index'] ?? null;
			$find        = $edit['find'] ?? '';
			$replace     = $edit['replace'] ?? '';

			if ( null === $block_index || '' === $find ) {
				$changes[] = array(
					'block_index' => $block_index,
					'success'     => false,
					'error'       => 'Missing required block_index or find parameter',
				);
				continue;
			}

			$block_index = absint( $block_index );

			if ( $block_index >= $total_blocks ) {
				$changes[] = array(
					'block_index' => $block_index,
					'success'     => false,
					'error'       => sprintf( 'Block index %d out of range (total: %d)', $block_index, $total_blocks ),
				);
				continue;
			}

			$inner_html = $blocks[ $block_index ]['innerHTML'] ?? '';

			if ( false === strpos( $inner_html, $find ) ) {
				$changes[] = array(
					'block_index' => $block_index,
					'find'        => mb_substr( $find, 0, 100 ),
					'success'     => false,
					'error'       => 'Target text not found in block',
				);
				continue;
			}

			$new_html = str_replace( $find, $replace, $inner_html );
			$blocks[ $block_index ]['innerHTML'] = $new_html;

			// Also update innerContent entries that match.
			if ( ! empty( $blocks[ $block_index ]['innerContent'] ) ) {
				$blocks[ $block_index ]['innerContent'] = array_map(
					function ( $content ) use ( $find, $replace ) {
						if ( is_string( $content ) ) {
							return str_replace( $find, $replace, $content );
						}
						return $content;
					},
					$blocks[ $block_index ]['innerContent']
				);
			}

			$changes[] = array(
				'block_index'  => $block_index,
				'block_name'   => $blocks[ $block_index ]['blockName'] ?? 'unknown',
				'find_length'  => strlen( $find ),
				'replace_length' => strlen( $replace ),
				'success'      => true,
			);
		}

		// Only save if at least one edit succeeded.
		$successful = array_filter( $changes, fn( $c ) => ! empty( $c['success'] ) );

		if ( empty( $successful ) ) {
			return array(
				'success'         => false,
				'post_id'         => $post_id,
				'changes_applied' => $changes,
				'error'           => 'No edits were applied — all operations failed',
			);
		}

		$new_content = BlockSanitizer::sanitizeAndSerialize( $blocks );
		$result      = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $new_content,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'post_id' => $post_id,
				'error'   => 'Failed to save: ' . $result->get_error_message(),
			);
		}

		do_action(
			'datamachine_log',
			'info',
			sprintf( 'Block edits applied to post #%d (%d edits)', $post_id, count( $successful ) ),
			array(
				'post_id'     => $post_id,
				'edits_total' => count( $edits ),
				'edits_ok'    => count( $successful ),
			)
		);

		return array(
			'success'         => true,
			'post_id'         => $post_id,
			'post_url'        => get_permalink( $post_id ),
			'changes_applied' => $changes,
		);
	}

}
