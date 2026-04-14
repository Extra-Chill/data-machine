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
					'category'            => 'datamachine/content',
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
						'preview' => array(
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
				$tools['edit_post_blocks'] = array(
					'_callable' => array( self::class, 'getChatTool' ),
					'contexts'  => array( 'chat' ),
					'ability'   => 'datamachine/edit-post-blocks',
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
			'description' => 'Surgical find/replace within specific Gutenberg blocks by index. Use get_post_blocks first to identify target blocks and indices. Set preview=true to return a diff preview without applying changes.',
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
				'preview' => array(
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
		$preview = ! empty( $input['preview'] );

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

		$blocks       = parse_blocks( $post->post_content );
		$total_blocks = count( $blocks );
		$changes      = array();

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

			$new_html                            = self::smart_text_replace( $inner_html, $find, $replace );
			$blocks[ $block_index ]['innerHTML'] = $new_html;

			// Also update innerContent entries that match.
			if ( ! empty( $blocks[ $block_index ]['innerContent'] ) ) {
				$blocks[ $block_index ]['innerContent'] = array_map(
					function ( $content ) use ( $find, $replace ) {
						if ( is_string( $content ) ) {
							return self::smart_text_replace( $content, $find, $replace );
						}
						return $content;
					},
					$blocks[ $block_index ]['innerContent']
				);
			}

			$changes[] = array(
				'block_index'    => $block_index,
				'block_name'     => $blocks[ $block_index ]['blockName'] ?? 'unknown',
				'find_length'    => strlen( $find ),
				'replace_length' => strlen( $replace ),
				'success'        => true,
			);
		}

		// Only save/preview if at least one edit succeeded.
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

		// --- Preview mode: store pending edit, return diff data ---
		if ( $preview ) {
			$diff_id = PendingDiffStore::generate_id();

			// Build per-edit diff data for the frontend.
			$diffs = array();
			foreach ( $edits as $edit ) {
				$block_index = absint( $edit['block_index'] ?? 0 );
				if ( $block_index < $total_blocks && ! empty( $edit['find'] ) ) {
					$diffs[] = array(
						'block_index'        => $block_index,
						'originalContent'    => $edit['find'],
						'replacementContent' => $edit['replace'] ?? '',
					);
				}
			}

			$diff = CanonicalDiffPreview::build( array(
				'diff_id'             => $diff_id,
				'diff_type'           => 'edit',
				'original_content'    => implode( "\n", array_column( $diffs, 'originalContent' ) ),
				'replacement_content' => implode( "\n", array_column( $diffs, 'replacementContent' ) ),
				'summary'             => 'Preview generated. Accept or reject to apply changes.',
				'items'               => $diffs,
			) );

			CanonicalDiffPreview::store_pending( $diff_id, array(
				'type'    => 'edit_post_blocks',
				'post_id' => $post_id,
				'input'   => array(
					'post_id' => $post_id,
					'edits'   => $edits,
				),
				'diff'    => $diff,
			) );

			return CanonicalDiffPreview::response(
				$post_id,
				'Preview generated. Accept or reject to apply changes.',
				$diff,
				array(
					'changes_applied' => $changes,
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

	/**
	 * HTML-aware text replacement.
	 *
	 * Splits content into HTML tags and text nodes, only performs
	 * replacement within text nodes. This prevents corruption of
	 * HTML attributes (href, class, src, etc.) when the search text
	 * matches attribute values.
	 *
	 * Ported from Wordsurf's edit_post tool.
	 *
	 * @since 0.58.0
	 *
	 * @param string $content        HTML content.
	 * @param string $find           Text to search for.
	 * @param string $replace        Replacement text.
	 * @param bool   $case_sensitive Case-sensitive search (default true).
	 * @return string Modified content.
	 */
	private static function smart_text_replace( string $content, string $find, string $replace, bool $case_sensitive = true ): string {
		// No HTML tags — simple replacement.
		if ( false === strpos( $content, '<' ) ) {
			return $case_sensitive
				? str_replace( $find, $replace, $content )
				: str_ireplace( $find, $replace, $content );
		}

		// Split into HTML tags and text nodes.
		$parts  = preg_split( '/(<[^>]+>)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		$result = '';

		foreach ( $parts as $part ) {
			// HTML tag — pass through untouched.
			if ( str_starts_with( $part, '<' ) && str_ends_with( $part, '>' ) ) {
				$result .= $part;
				continue;
			}

			// Text node — safe to replace.
			$result .= $case_sensitive
				? str_replace( $find, $replace, $part )
				: str_ireplace( $find, $replace, $part );
		}

		return $result;
	}
}
