<?php
/**
 * Replace Post Blocks Ability
 *
 * Replace entire block innerHTML by index. For when AI rewrites a whole
 * paragraph (e.g. internal linking) rather than doing a find/replace.
 *
 * @package DataMachine\Abilities\Content
 * @since 0.28.0
 */

namespace DataMachine\Abilities\Content;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Engine\AI\Actions\PendingActionHelper;
use DataMachine\Engine\AI\Actions\PendingActionStore;

defined( 'ABSPATH' ) || exit;

class ReplacePostBlocksAbility {

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
				'datamachine/replace-post-blocks',
				array(
					'label'               => __( 'Replace Post Blocks', 'data-machine' ),
					'description'         => __( 'Replace entire block content by index. Use for AI-rewritten paragraphs.', 'data-machine' ),
					'category'            => 'datamachine-content',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'post_id', 'replacements' ),
						'properties' => array(
							'post_id'      => array(
								'type'        => 'integer',
								'description' => __( 'Post ID to edit', 'data-machine' ),
							),
							'replacements' => array(
								'type'        => 'array',
								'description' => __( 'Array of block replacement operations', 'data-machine' ),
								'items'       => array(
									'type'       => 'object',
									'required'   => array( 'block_index', 'new_content' ),
									'properties' => array(
										'block_index' => array(
											'type'        => 'integer',
											'description' => __( 'Zero-based block index to replace', 'data-machine' ),
										),
										'new_content' => array(
											'type'        => 'string',
											'description' => __( 'New innerHTML for the block', 'data-machine' ),
										),
									),
								),
							),
							'preview'      => array(
								'type'        => 'boolean',
								'description' => __( 'When true, return diff preview without applying changes', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'         => array( 'type' => 'boolean' ),
							'post_id'         => array( 'type' => 'integer' ),
							'post_url'        => array( 'type' => 'string' ),
							'blocks_replaced' => array(
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

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	private function registerChatTool(): void {
		add_filter(
			'datamachine_tools',
			function ( $tools ) {
				$tools['replace_post_blocks'] = array(
					'_callable' => array( self::class, 'getChatTool' ),
					'modes'     => array( 'chat' ),
					'ability'   => 'datamachine/replace-post-blocks',
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
			'description' => 'Replace entire Gutenberg block content by index. Use get_post_blocks first to find the right indices. Ideal for AI-rewritten paragraphs. Set preview=true to return a diff preview without applying changes.',
			'parameters'  => array(
				'post_id'      => array(
					'type'        => 'integer',
					'required'    => true,
					'description' => 'Post ID to edit',
				),
				'replacements' => array(
					'type'        => 'array',
					'required'    => true,
					'description' => 'Array of { block_index, new_content } operations',
				),
				'preview'      => array(
					'type'        => 'boolean',
					'description' => 'When true, return diff preview data without applying changes. The user can then accept or reject.',
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
		$tool_def;
		$result = self::execute( $parameters );

		return array(
			'success'   => $result['success'],
			'data'      => $result,
			'tool_name' => 'replace_post_blocks',
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function execute( array $input ): array {
		$post_id      = absint( $input['post_id'] ?? 0 );
		$replacements = $input['replacements'] ?? array();
		$preview      = ! empty( $input['preview'] );

		if ( $post_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'Valid post_id is required',
			);
		}

		if ( empty( $replacements ) || ! is_array( $replacements ) ) {
			return array(
				'success' => false,
				'error'   => 'At least one replacement is required',
			);
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Post #%d does not exist', $post_id ),
			);
		}

		$blocks       = parse_blocks( $post->post_content );
		$total_blocks = count( $blocks );
		$changes      = array();

		foreach ( $replacements as $replacement ) {
			$block_index = $replacement['block_index'] ?? null;
			$new_content = $replacement['new_content'] ?? null;

			if ( null === $block_index || null === $new_content ) {
				$changes[] = array(
					'block_index' => $block_index,
					'success'     => false,
					'error'       => 'Missing required block_index or new_content',
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

			$old_html = $blocks[ $block_index ]['innerHTML'] ?? '';

			$blocks[ $block_index ]['innerHTML'] = $new_content;

			// Update innerContent to match.
			if ( ! empty( $blocks[ $block_index ]['innerContent'] ) ) {
				// For simple blocks (no inner blocks), innerContent is typically [ html_string ].
				// Replace the first string entry with the new content.
				$replaced_inner = false;
				foreach ( $blocks[ $block_index ]['innerContent'] as $i => $content ) {
					if ( is_string( $content ) && ! $replaced_inner ) {
						$blocks[ $block_index ]['innerContent'][ $i ] = $new_content;
						$replaced_inner                               = true;
					}
				}
			}

			$changes[] = array(
				'block_index'        => $block_index,
				'block_name'         => $blocks[ $block_index ]['blockName'] ?? 'unknown',
				'old_length'         => strlen( $old_html ),
				'new_length'         => strlen( $new_content ),
				'originalContent'    => $old_html,
				'replacementContent' => $new_content,
				'success'            => true,
			);
		}

		$successful = array_filter( $changes, fn( $c ) => ! empty( $c['success'] ) );

		if ( empty( $successful ) ) {
			return array(
				'success'         => false,
				'post_id'         => $post_id,
				'blocks_replaced' => $changes,
				'error'           => 'No replacements were applied — all operations failed',
			);
		}

		$new_content = BlockSanitizer::sanitizeAndSerialize( $blocks );

		// --- Preview mode: stage pending action, return preview envelope ---
		if ( $preview ) {
			$action_id = PendingActionStore::generate_id();

			// Build per-block diff data for the frontend.
			$diffs = array();
			foreach ( $successful as $change ) {
				$diffs[] = array(
					'block_index'        => $change['block_index'],
					'originalContent'    => $change['originalContent'],
					'replacementContent' => $change['replacementContent'],
				);
			}

			$diff = CanonicalDiffPreview::build(
				array(
					'action_id'           => $action_id,
					'diff_type'           => 'replace',
					'original_content'    => implode( "\n", array_column( $diffs, 'originalContent' ) ),
					'replacement_content' => implode( "\n", array_column( $diffs, 'replacementContent' ) ),
					'summary'             => 'Preview generated. Accept or reject to apply changes.',
					'items'               => $diffs,
				)
			);

			$envelope = PendingActionHelper::stage(
				array(
					'action_id'    => $action_id,
					'kind'         => 'replace_post_blocks',
					'summary'      => sprintf( 'Preview block replacements on post #%d.', $post_id ),
					'apply_input'  => array(
						'post_id'      => $post_id,
						'replacements' => $replacements,
					),
					'preview_data' => $diff,
					'context'      => array( 'post_id' => $post_id ),
				)
			);

			if ( empty( $envelope['staged'] ) ) {
				return array(
					'success' => false,
					'post_id' => $post_id,
					'error'   => $envelope['error'] ?? 'Failed to stage preview.',
				);
			}

			// Strip raw HTML from the changes returned to the AI.
			$clean_changes = array_map(
				function ( $c ) {
					unset( $c['originalContent'], $c['replacementContent'] );
					return $c;
				},
				$changes
			);

			return array_merge(
				$envelope,
				array(
					'success'         => true,
					'is_preview'      => true,
					'post_id'         => $post_id,
					'blocks_replaced' => $clean_changes,
				)
			);
		}

		// --- Normal mode: apply immediately ---
		$result = wp_update_post(
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
			sprintf( 'Block replacements applied to post #%d (%d blocks)', $post_id, count( $successful ) ),
			array(
				'post_id'            => $post_id,
				'replacements_total' => count( $replacements ),
				'replacements_ok'    => count( $successful ),
			)
		);

		// Strip raw HTML from changes in normal mode too (not needed in response).
		$clean_changes = array_map(
			function ( $c ) {
				unset( $c['originalContent'], $c['replacementContent'] );
				return $c;
			},
			$changes
		);

		return array(
			'success'         => true,
			'post_id'         => $post_id,
			'post_url'        => get_permalink( $post_id ),
			'blocks_replaced' => $clean_changes,
		);
	}
}
