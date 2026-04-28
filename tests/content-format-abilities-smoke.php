<?php
/**
 * Pure-PHP smoke test for storage-format-aware content abilities.
 *
 * Run with: php tests/content-format-abilities-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Core\WordPress {
	class ResolvePostByPath {

		public static function build_path( $post ): string {
			return $post->post_name ?? '';
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$failed = 0;
	$total  = 0;

	function assert_content_ability( string $name, bool $condition ): void {
		global $failed, $total;
		++$total;
		if ( $condition ) {
			echo "  PASS: {$name}\n";
			return;
		}
		echo "  FAIL: {$name}\n";
		++$failed;
	}

	class WP_Error {

		private string $code;
		private string $message;
		/** @var mixed */
		private $data;

		public function __construct( string $code = '', string $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}

	$GLOBALS['__content_ability_filters']     = array();
	$GLOBALS['__content_ability_posts']       = array(
		7 => (object) array(
			'ID'           => 7,
			'post_type'    => 'wiki',
			'post_title'   => 'Markdown Page',
			'post_name'    => 'markdown-page',
			'post_content' => "# Original\n\nHello world.",
		),
	);
	$GLOBALS['__content_ability_next_id']     = 20;
	$GLOBALS['__content_ability_conversions'] = array();

	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['__content_ability_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
	}

	function apply_filters( string $hook, $value, ...$args ) {
		if ( empty( $GLOBALS['__content_ability_filters'][ $hook ] ) ) {
			return $value;
		}

		ksort( $GLOBALS['__content_ability_filters'][ $hook ] );
		foreach ( $GLOBALS['__content_ability_filters'][ $hook ] as $callbacks ) {
			foreach ( $callbacks as $registered_callback ) {
				list( $callback, $accepted_args ) = $registered_callback;
				$value                            = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted_args ) );
			}
		}
		return $value;
	}

	function sanitize_key( $key ): string {
		return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) );
	}

	function sanitize_title( $title ): string {
		return strtolower( trim( preg_replace( '/[^a-zA-Z0-9]+/', '-', (string) $title ), '-' ) );
	}

	function sanitize_text_field( $value ): string {
		return trim( (string) $value );
	}

	function absint( $value ): int {
		return max( 0, (int) $value );
	}

	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}

	function wp_kses_post( $content ): string {
		return (string) $content;
	}

	function wp_unslash( $value ) {
		return $value;
	}

	function __( $text, $domain = 'default' ) {
		unset( $domain );
		return $text;
	}

	function do_action( ...$args ): void {
		$GLOBALS['__content_ability_actions'][] = $args;
	}

	function get_post( int $post_id ) {
		return $GLOBALS['__content_ability_posts'][ $post_id ] ?? null;
	}

	function get_permalink( int $post_id ): string {
		return "https://example.test/?p={$post_id}";
	}

	function get_post_meta( int $post_id, string $key, bool $single = false ) {
		unset( $post_id, $key, $single );
		return '';
	}

	function taxonomy_exists( string $taxonomy ): bool {
		unset( $taxonomy );
		return false;
	}

	function wp_update_post( array $post_data, bool $wp_error = false ) {
		unset( $wp_error );
		$id = (int) ( $post_data['ID'] ?? 0 );
		if ( empty( $GLOBALS['__content_ability_posts'][ $id ] ) ) {
			return new WP_Error( 'missing', 'Missing post.' );
		}

		foreach ( $post_data as $key => $value ) {
			if ( 'ID' !== $key ) {
				$GLOBALS['__content_ability_posts'][ $id ]->{$key} = $value;
			}
		}
		return $id;
	}

	function wp_insert_post( array $post_data, bool $wp_error = false ) {
		unset( $wp_error );
		$id = (int) ( $post_data['ID'] ?? 0 );
		if ( $id <= 0 ) {
			$id = $GLOBALS['__content_ability_next_id']++;
		}

		$existing = $GLOBALS['__content_ability_posts'][ $id ] ?? (object) array( 'ID' => $id );
		foreach ( $post_data as $key => $value ) {
			if ( 'meta_input' !== $key ) {
				$existing->{$key} = $value;
			}
		}
		$existing->ID                              = $id;
		$GLOBALS['__content_ability_posts'][ $id ] = $existing;
		return $id;
	}

	function bfb_convert( string $content, string $from, string $to ) {
		$GLOBALS['__content_ability_conversions'][] = array( $from, $to, $content );

		if ( str_contains( $content, 'CONVERT_FAIL' ) ) {
			return new WP_Error( 'bfb_conversion_failed', 'BFB conversion failed.', array( 'format' => $from ) );
		}

		if ( $from === $to ) {
			return $content;
		}

		if ( 'markdown' === $from && 'blocks' === $to ) {
			$lines = preg_split( '/\R+/', trim( $content ) );
			if ( false === $lines ) {
				return new WP_Error( 'parse_failed', 'Could not split markdown.' );
			}

			$blocks = array();
			foreach ( $lines as $line ) {
				if ( str_starts_with( $line, '# ' ) ) {
					$text     = substr( $line, 2 );
					$blocks[] = "<!-- wp:heading -->\n<h2>{$text}</h2>\n<!-- /wp:heading -->";
				} else {
					$blocks[] = "<!-- wp:paragraph -->\n<p>{$line}</p>\n<!-- /wp:paragraph -->";
				}
			}
			return implode( "\n", $blocks );
		}

		if ( 'html' === $from && 'blocks' === $to ) {
			$blocks = array();
			if ( preg_match_all( '/<h[1-6][^>]*>(.*?)<\/h[1-6]>|<p[^>]*>(.*?)<\/p>/s', $content, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					if ( '' !== ( $match[1] ?? '' ) ) {
						$blocks[] = "<!-- wp:heading -->\n<h2>{$match[1]}</h2>\n<!-- /wp:heading -->";
				} else {
					$blocks[] = "<!-- wp:paragraph -->\n<p>" . ( $match[2] ?? '' ) . "</p>\n<!-- /wp:paragraph -->";
				}
				}
				return implode( "\n", $blocks );
			}
			return "<!-- wp:html -->\n{$content}\n<!-- /wp:html -->";
		}

		if ( 'blocks' === $from && 'markdown' === $to ) {
			$content = preg_replace( '/<!--\s*\/?wp:[^>]+-->\s*/', '', $content );
			$content = preg_replace( '/<h[1-6][^>]*>(.*?)<\/h[1-6]>/', '# $1', $content );
			$content = preg_replace( '/<p[^>]*>(.*?)<\/p>/', '$1', $content );
			return trim( html_entity_decode( strip_tags( $content ) ) );
		}

		return new WP_Error( 'unsupported', "Unsupported {$from} to {$to}." );
	}

	function bfb_normalize( string $content, string $format ) {
		if ( 'blocks' === $format ) {
			if ( str_contains( $content, '<!-- wp:' ) && ! str_contains( $content, '<!-- /wp:' ) ) {
				return new WP_Error(
					'bfb_blocks_unclosed_comment',
					'Serialized block markup contains an unclosed block comment.',
					array( 'open_blocks' => array( 'paragraph' ) )
				);
			}
			if ( ! str_contains( $content, '<!-- wp:' ) ) {
				return new WP_Error( 'bfb_blocks_missing_comments', 'Declared blocks content does not contain serialized block comments.' );
			}
		}

		return str_replace( array( "\r\n", "\r" ), "\n", $content );
	}

	function parse_blocks( string $content ): array {
		$blocks = array();
		if ( preg_match_all( '/<!-- wp:([^ ]+) -->\s*(.*?)\s*<!-- \/wp:\1 -->/s', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$block_name = str_contains( $match[1], '/' ) ? $match[1] : 'core/' . $match[1];
				$blocks[]   = array(
					'blockName'    => $block_name,
					'innerHTML'    => $match[2],
					'innerContent' => array( $match[2] ),
					'innerBlocks'  => array(),
				);
			}
			return $blocks;
		}

		return array(
			array(
				'blockName'    => null,
				'innerHTML'    => $content,
				'innerContent' => array( $content ),
				'innerBlocks'  => array(),
			),
		);
	}

	function serialize_blocks( array $blocks ): string {
		$serialized = array();
		foreach ( $blocks as $block ) {
			$name         = $block['blockName'] ?? 'core/freeform';
			$html         = $block['innerHTML'] ?? '';
			$serialized[] = "<!-- wp:{$name} -->\n{$html}\n<!-- /wp:{$name} -->";
		}
		return implode( "\n", $serialized );
	}

	function content_ability_post_count(): int {
		return count( $GLOBALS['__content_ability_posts'] );
	}

	add_filter(
		'datamachine_post_content_format',
		static function ( string $format, string $post_type ): string {
			return 'wiki' === $post_type ? 'markdown' : $format;
		},
		10,
		2
	);

	include_once dirname( __DIR__ ) . '/inc/Core/Content/ContentFormat.php';
	include_once dirname( __DIR__ ) . '/inc/Abilities/Content/BlockSanitizer.php';
	include_once dirname( __DIR__ ) . '/inc/Abilities/Content/GetPostBlocksAbility.php';
	include_once dirname( __DIR__ ) . '/inc/Abilities/Content/EditPostBlocksAbility.php';
	include_once dirname( __DIR__ ) . '/inc/Abilities/Content/UpsertPostAbility.php';

	$get = DataMachine\Abilities\Content\GetPostBlocksAbility::execute( array( 'post_id' => 7 ) );
	assert_content_ability( 'markdown-post-read-succeeds', true === $get['success'] );
	assert_content_ability( 'markdown-post-read-converts-to-blocks', 'core/heading' === ( $get['blocks'][0]['block_name'] ?? '' ) );
	$stored_after_read = get_post( 7 )->post_content ?? '';
	assert_content_ability( 'markdown-post-read-does-not-mutate-storage', "# Original\n\nHello world." === $stored_after_read );

	$edit = DataMachine\Abilities\Content\EditPostBlocksAbility::execute(
		array(
			'post_id' => 7,
			'edits'   => array(
				array(
					'block_index' => 1,
					'find'        => 'Hello world.',
					'replace'     => 'Hello markdown.',
				),
			),
		)
	);
	assert_content_ability( 'markdown-post-edit-succeeds', true === $edit['success'] );
	$stored_after_edit = get_post( 7 )->post_content ?? '';
	assert_content_ability( 'markdown-post-edit-saves-markdown', false === strpos( $stored_after_edit, '<!-- wp:' ) );
	assert_content_ability( 'markdown-post-edit-has-replacement', false !== strpos( $stored_after_edit, 'Hello markdown.' ) );

	$upsert   = DataMachine\Abilities\Content\UpsertPostAbility::execute(
		array(
			'post_type'      => 'wiki',
			'title'          => 'New Markdown',
			'content'        => "# Stored\n\nRaw markdown.",
			'content_format' => 'markdown',
		)
	);
	$new_id   = $upsert['post_id'] ?? 0;
	$new_post = get_post( (int) $new_id );
	assert_content_ability( 'upsert-markdown-source-succeeds', true === $upsert['success'] );
	assert_content_ability( 'upsert-markdown-source-stays-markdown', "# Stored\n\nRaw markdown." === ( $new_post->post_content ?? '' ) );

	$chat_upsert   = DataMachine\Abilities\Content\UpsertPostAbility::handleChatToolCall(
		array(
			'post_type' => 'post',
			'title'     => 'AI Markdown Default',
			'content'   => "# AI Heading\n\nAI paragraph.",
		)
	);
	$chat_id       = $chat_upsert['data']['post_id'] ?? 0;
	$chat_post     = get_post( (int) $chat_id );
	$chat_content  = $chat_post->post_content ?? '';
	$last_convert  = end( $GLOBALS['__content_ability_conversions'] );
	assert_content_ability( 'chat-upsert-default-succeeds', true === $chat_upsert['success'] );
	assert_content_ability( 'chat-upsert-defaults-authoring-format-to-markdown', array( 'markdown', 'blocks', "# AI Heading\n\nAI paragraph." ) === $last_convert );
	assert_content_ability( 'chat-upsert-markdown-default-stores-blocks-for-block-backed-post-type', false !== strpos( $chat_content, '<!-- wp:heading -->' ) );
	assert_content_ability( 'chat-upsert-markdown-default-has-paragraph', false !== strpos( $chat_content, 'AI paragraph.' ) );

	$raw_blocks = "<!-- wp:paragraph -->\n<p>Programmatic block content.</p>\n<!-- /wp:paragraph -->";
	$raw_upsert = DataMachine\Abilities\Content\UpsertPostAbility::execute(
		array(
			'post_type' => 'post',
			'title'     => 'Raw Blocks Default',
			'content'   => $raw_blocks,
		)
	);
	$raw_post   = get_post( (int) ( $raw_upsert['post_id'] ?? 0 ) );
	assert_content_ability( 'raw-upsert-omitted-format-preserves-compat-block-default', $raw_blocks === ( $raw_post->post_content ?? '' ) );

	$html_upsert = DataMachine\Abilities\Content\UpsertPostAbility::execute(
		array(
			'post_type'      => 'post',
			'title'          => 'Explicit HTML',
			'content'        => '<h2>HTML Heading</h2><p>HTML paragraph.</p>',
			'content_format' => 'html',
		)
	);
	$html_post   = get_post( (int) ( $html_upsert['post_id'] ?? 0 ) );
	assert_content_ability( 'explicit-html-format-succeeds', true === $html_upsert['success'] );
	assert_content_ability( 'explicit-html-format-converts-to-blocks', false !== strpos( $html_post->post_content ?? '', '<!-- wp:heading -->' ) );

	$explicit_blocks_to_markdown = DataMachine\Abilities\Content\UpsertPostAbility::execute(
		array(
			'post_type'      => 'wiki',
			'title'          => 'Explicit Blocks To Markdown',
			'content'        => "<!-- wp:heading -->\n<h2>Blocks Heading</h2>\n<!-- /wp:heading -->",
			'content_format' => 'blocks',
		)
	);
	$blocks_to_markdown_post     = get_post( (int) ( $explicit_blocks_to_markdown['post_id'] ?? 0 ) );
	assert_content_ability( 'explicit-blocks-format-succeeds', true === $explicit_blocks_to_markdown['success'] );
	assert_content_ability( 'explicit-blocks-format-converts-to-markdown-storage', '# Blocks Heading' === ( $blocks_to_markdown_post->post_content ?? '' ) );

	$posts_before_malformed = content_ability_post_count();
	$malformed_blocks       = DataMachine\Abilities\Content\UpsertPostAbility::execute(
		array(
			'post_type'      => 'post',
			'title'          => 'Malformed Blocks',
			'content'        => '<!-- wp:paragraph --><p>Unclosed</p>',
			'content_format' => 'blocks',
		)
	);
	assert_content_ability( 'malformed-blocks-source-fails', false === $malformed_blocks['success'] );
	assert_content_ability( 'malformed-blocks-source-preserves-bfb-error-code', 'bfb_blocks_unclosed_comment' === ( $malformed_blocks['error_code'] ?? '' ) );
	assert_content_ability( 'malformed-blocks-source-preserves-bfb-error-data', array( 'paragraph' ) === ( $malformed_blocks['error_data']['open_blocks'] ?? array() ) );
	assert_content_ability( 'malformed-blocks-source-does-not-write-post', $posts_before_malformed === content_ability_post_count() );

	$conversion_error = DataMachine\Abilities\Content\UpsertPostAbility::execute(
		array(
			'post_type'      => 'post',
			'title'          => 'Conversion Failure',
			'content'        => 'CONVERT_FAIL',
			'content_format' => 'markdown',
		)
	);
	assert_content_ability( 'bfb-conversion-error-fails', false === $conversion_error['success'] );
	assert_content_ability( 'bfb-conversion-error-code-is-distinct-from-missing-bfb', 'bfb_conversion_failed' === ( $conversion_error['error_code'] ?? '' ) );

	$tool = DataMachine\Abilities\Content\UpsertPostAbility::getChatTool();
	assert_content_ability( 'chat-tool-content-format-is-optional', ! in_array( 'content_format', $tool['required'] ?? array(), true ) );
	assert_content_ability( 'chat-tool-description-prefers-markdown-authoring', false !== strpos( $tool['description'], 'write content as markdown' ) );
	assert_content_ability( 'chat-tool-content-format-description-defaults-markdown', false !== strpos( $tool['parameters']['content_format']['description'] ?? '', 'default to markdown' ) );
	assert_content_ability( 'chat-tool-guidance-does-not-default-to-blocks', false === strpos( $tool['parameters']['content_format']['description'] ?? '', 'Defaults to blocks' ) );

	echo "\nContentFormat abilities smoke: {$total} assertions, {$failed} failures.\n";

	exit( min( 1, $failed ) );
}
