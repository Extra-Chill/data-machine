<?php
/**
 * InsertContentAbility — positional content insertion with diff preview.
 *
	 * Inserts new content at the beginning, end, or after a specific paragraph
	 * in a post. Returns canonical diff preview data for frontend/editor review.
 *
 * Ported from Wordsurf's insert_content tool (Phase 2 migration).
 *
 * @package DataMachine\Abilities\Content
 * @since 0.58.0
 */

namespace DataMachine\Abilities\Content;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class InsertContentAbility {

	private static bool $registered = false;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) || self::$registered ) {
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
			wp_register_ability( 'datamachine/insert-content', array(
				'label'               => 'Insert Content',
				'description'         => 'Insert new content at a specific position in a post (beginning, end, or after a paragraph).',
				'category'            => 'datamachine-content',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'post_id', 'content', 'position' ),
					'properties' => array(
						'post_id'               => array(
							'type'        => 'integer',
							'description' => 'The post to insert content into.',
						),
						'content'               => array(
							'type'        => 'string',
							'description' => 'The new content to insert (will be wrapped in WordPress paragraph blocks).',
						),
						'position'              => array(
							'type'        => 'string',
							'enum'        => array( 'beginning', 'end', 'after_paragraph' ),
							'description' => 'Where to insert: beginning, end, or after_paragraph.',
						),
						'target_paragraph_text' => array(
							'type'        => 'string',
							'description' => 'Required when position is after_paragraph. A short phrase (3-8 words) from the paragraph to insert after.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'diff_id' => array( 'type' => 'string' ),
						'diff'    => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => array( self::class, 'execute' ),
				'permission_callback' => fn() => PermissionHelper::can( 'chat' ),
				'meta'                => array( 'show_in_rest' => false ),
			) );
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register );
		}
	}

	/**
	 * Register as a chat tool.
	 */
	private function register_chat_tool(): void {
		add_filter(
			'datamachine_tools',
			function ( $tools ) {
				$tools['insert_content'] = array(
					'_callable' => array( self::class, 'getChatTool' ),
					'contexts'  => array( 'chat', 'pipeline', 'system', 'editor' ),
					'ability'   => 'datamachine/insert-content',
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
			'description' => 'Insert new content into a WordPress post at a specific position (beginning, end, or after a specific paragraph). Returns a canonical preview diff for user review.',
			'parameters'  => array(
				'post_id'               => array(
					'type'        => 'integer',
					'description' => 'The post ID to insert content into.',
				),
				'content'               => array(
					'type'        => 'string',
					'description' => 'The new content to insert (wrapped in paragraph blocks automatically).',
				),
				'position'              => array(
					'type'        => 'string',
					'enum'        => array( 'beginning', 'end', 'after_paragraph' ),
					'description' => 'Where to insert the content.',
				),
				'target_paragraph_text' => array(
					'type'        => 'string',
					'description' => 'Required when position is "after_paragraph". A short phrase (3-8 words) from the target paragraph.',
				),
			),
		);
	}

	/**
	 * Handle chat tool call.
	 *
	 * @param array $params   Tool parameters.
	 * @param array $tool_def Tool definition.
	 * @return array Result.
	 */
	public static function handleChatToolCall( array $params, array $tool_def = array() ): array {
		return self::execute( $params );
	}

	/**
	 * Execute the insert content ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with canonical diff preview data.
	 */
	public static function execute( array $input ): array {
		$post_id               = absint( $input['post_id'] ?? 0 );
		$content               = $input['content'] ?? '';
		$position              = $input['position'] ?? 'end';
		$target_paragraph_text = $input['target_paragraph_text'] ?? '';
		$preview               = ! array_key_exists( 'preview', $input ) || ! empty( $input['preview'] );

		if ( $post_id <= 0 || '' === $content ) {
			return array(
				'success' => false,
				'error'   => 'post_id and content are required.',
			);
		}

		if ( 'after_paragraph' === $position && '' === $target_paragraph_text ) {
			return array(
				'success' => false,
				'error'   => 'target_paragraph_text is required when position is "after_paragraph".',
			);
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Post #%d not found.', $post_id ),
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return array(
				'success' => false,
				'error'   => 'You do not have permission to edit this post.',
			);
		}

		$current_content = $post->post_content;
		$current_blocks  = parse_blocks( $current_content );

		// Wrap content in paragraph block.
		$block_content = "\n\n<!-- wp:paragraph -->\n<p>" . wp_kses_post( $content ) . "</p>\n<!-- /wp:paragraph -->";

		$insertion_point = '';
		$block_index     = count( $current_blocks );

		switch ( $position ) {
			case 'beginning':
				$new_content     = $block_content . "\n\n" . $current_content;
				$insertion_point = 'at the beginning of the post';
				$block_index     = 0;
				break;

			case 'end':
				$new_content     = $current_content . $block_content;
				$insertion_point = 'at the end of the post';
				$block_index     = count( $current_blocks );
				break;

			case 'after_paragraph':
				$result = self::insert_after_paragraph( $current_content, $block_content, $target_paragraph_text );
				if ( ! $result['success'] ) {
					return $result;
				}
					$new_content     = $result['content'];
					$insertion_point = $result['insertion_point'];
					$block_index     = (int) ( $result['block_index'] ?? count( $current_blocks ) );
				break;

			default:
				return array(
					'success' => false,
					'error'   => "Invalid position: {$position}. Must be beginning, end, or after_paragraph.",
				);
		}

		if ( ! $preview ) {
			$update = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $new_content,
				),
				true
			);

			if ( is_wp_error( $update ) ) {
				return array(
					'success' => false,
					'post_id' => $post_id,
					'error'   => 'Failed to save: ' . $update->get_error_message(),
				);
			}

			return array(
				'success'         => true,
				'post_id'         => $post_id,
				'post_url'        => get_permalink( $post_id ),
				'position'        => $position,
				'insertion_point' => $insertion_point,
				'new_content'     => $new_content,
			);
		}

		$diff_id = PendingDiffStore::generate_id();

		$diff = CanonicalDiffPreview::build( array(
			'diff_id'             => $diff_id,
			'diff_type'           => 'insert',
			'original_content'    => '',
			'replacement_content' => $content,
			'summary'             => sprintf( 'Prepared content insertion %s.', $insertion_point ),
			'position'            => $position,
			'insertion_point'     => $insertion_point,
			'items'               => array(
				array(
					'blockIndex'         => $block_index,
					'originalContent'    => '',
					'replacementContent' => $content,
				),
			),
			'editor'              => array(
				'toolCallId'           => $input['_original_call_id'] ?? '',
				'editType'             => 'content',
				'searchPattern'        => '',
				'caseSensitive'        => false,
				'isPreview'            => true,
				'previewBlockContent'  => $block_content,
				'originalBlockContent' => '',
				'originalBlockType'    => 'core/paragraph',
			),
		) );

		CanonicalDiffPreview::store_pending( $diff_id, array(
			'type'    => 'insert_content',
			'post_id' => $post_id,
			'input'   => array(
				'post_id'               => $post_id,
				'content'               => $content,
				'position'              => $position,
				'target_paragraph_text' => $target_paragraph_text,
			),
			'diff'    => $diff,
		) );

		return CanonicalDiffPreview::response(
			$post_id,
			sprintf( 'Prepared content insertion %s. Accept or reject to apply changes.', $insertion_point ),
			$diff,
			array(
				'position'        => $position,
				'insertion_point' => $insertion_point,
				'new_content'     => $new_content,
			)
		);
	}

	/**
	 * Insert content after a specific paragraph.
	 *
	 * @param string $content             Current post content.
	 * @param string $block_content       Block-wrapped content to insert.
	 * @param string $target_text         Text phrase to locate the target paragraph.
	 * @return array
	 */
	private static function insert_after_paragraph( string $content, string $block_content, string $target_text ): array {
		$separator  = '<!-- /wp:paragraph -->';
		$paragraphs = explode( $separator, $content );

		$target_index = null;

		foreach ( $paragraphs as $index => $paragraph ) {
			if ( false !== strpos( $paragraph, $target_text ) ) {
				$target_index = $index;
				break;
			}
		}

		if ( null === $target_index ) {
			// Provide paragraph previews to help the AI retry.
			$previews = array();
			foreach ( $paragraphs as $p ) {
				$text = trim( wp_strip_all_tags( $p ) );
				if ( '' !== $text ) {
					$previews[] = mb_substr( $text, 0, 60 ) . ( mb_strlen( $text ) > 60 ? '...' : '' );
				}
			}

			return array(
				'success'              => false,
				'error'                => sprintf( 'Could not find paragraph containing "%s".', $target_text ),
				'suggestion'           => 'Try a shorter, more specific phrase from the target paragraph.',
				'available_paragraphs' => $previews,
			);
		}

		// Reconstruct content with insertion.
		$parts = array();
		foreach ( $paragraphs as $index => $paragraph ) {
			$parts[] = $paragraph;
			if ( $index < count( $paragraphs ) - 1 ) {
				$parts[] = $separator;
			}
			if ( $index === $target_index ) {
				$parts[] = $block_content;
			}
		}

		return array(
			'success'         => true,
			'content'         => implode( '', $parts ),
			'block_index'     => $target_index + 1,
			'insertion_point' => sprintf( "after the paragraph containing '%s'", $target_text ),
		);
	}
}
