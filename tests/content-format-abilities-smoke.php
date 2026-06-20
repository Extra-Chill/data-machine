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

	if ( ! class_exists( 'WP_Post' ) ) {
		class WP_Post {
			public int $ID = 0;
			public string $post_type = '';
			public string $post_title = '';
			public string $post_name = '';
			public string $post_content = '';
			public string $post_modified_gmt = '';
		}
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

	if ( ! class_exists( 'WP_Error' ) ) {
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
	}

	$GLOBALS['__content_ability_filters'] = array();
	$fixture_post_id                      = 7;
	$fixture_post                         = function_exists( 'get_post' ) ? (object) array() : new WP_Post();
	$fixture_post->ID                     = $fixture_post_id;
	$fixture_post->post_type              = 'wiki';
	$fixture_post->post_title             = 'Markdown Page';
	$fixture_post->post_name              = 'markdown-page';
	$fixture_post->post_content           = "# Original\n\nHello world.";
	$fixture_post->post_modified_gmt      = '2026-01-01 00:00:00';

	$GLOBALS['__content_ability_posts']       = array(
		$fixture_post_id => $fixture_post,
	);
	$GLOBALS['__content_ability_next_id']     = 20;
	$GLOBALS['__content_ability_conversions'] = array();
	$GLOBALS['__content_ability_meta']        = array();

	if ( function_exists( 'wp_insert_post' ) ) {
		$inserted_fixture_id = wp_insert_post(
			array(
				'post_type'    => 'wiki',
				'post_title'   => 'Markdown Page',
				'post_name'    => 'markdown-page',
				'post_content' => "# Original\n\nHello world.",
				'post_status'  => 'publish',
			),
			true
		);

		if ( ! is_wp_error( $inserted_fixture_id ) ) {
			$fixture_post_id = (int) $inserted_fixture_id;
		}
	}

	if ( ! function_exists( 'is_multisite' ) ) {
		function is_multisite(): bool {
			return false;
		}
	}

	if ( ! function_exists( 'get_current_blog_id' ) ) {
		function get_current_blog_id(): int {
			return 1;
		}
	}

	if ( ! function_exists( 'get_current_user_id' ) ) {
		function get_current_user_id(): int {
			return 0;
		}
	}

	if ( ! function_exists( 'wp_get_post_autosave' ) ) {
		function wp_get_post_autosave( int $post_id, int $user_id ) {
			unset( $post_id, $user_id );
			return false;
		}
	}

	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
			$GLOBALS['__content_ability_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
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
	}

	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ): string {
			return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) );
		}
	}

	if ( ! function_exists( 'sanitize_title' ) ) {
		function sanitize_title( $title ): string {
			return strtolower( trim( preg_replace( '/[^a-zA-Z0-9]+/', '-', (string) $title ), '-' ) );
		}
	}

	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $value ): string {
			return trim( (string) $value );
		}
	}

	if ( ! function_exists( 'esc_url_raw' ) ) {
		function esc_url_raw( $url ): string {
			return trim( (string) $url );
		}
	}

	if ( ! function_exists( 'esc_url' ) ) {
		function esc_url( $url ): string {
			return trim( (string) $url );
		}
	}

	if ( ! function_exists( 'absint' ) ) {
		function absint( $value ): int {
			return max( 0, (int) $value );
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ): bool {
			return $thing instanceof WP_Error;
		}
	}

	if ( ! function_exists( 'wp_kses_post' ) ) {
		function wp_kses_post( $content ): string {
			return (string) $content;
		}
	}

	if ( ! function_exists( 'wp_unslash' ) ) {
		function wp_unslash( $value ) {
			return $value;
		}
	}

	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = 'default' ) {
			unset( $domain );
			return $text;
		}
	}

	if ( ! function_exists( 'do_action' ) ) {
		function do_action( ...$args ): void {
			$GLOBALS['__content_ability_actions'][] = $args;
		}
	}

	if ( ! function_exists( 'get_post' ) ) {
		function get_post( int $post_id ) {
			return $GLOBALS['__content_ability_posts'][ $post_id ] ?? null;
		}
	}

	if ( ! function_exists( 'get_permalink' ) ) {
		function get_permalink( int $post_id ): string {
			return "https://example.test/?p={$post_id}";
		}
	}

	if ( ! function_exists( 'get_post_meta' ) ) {
		function get_post_meta( int $post_id, string $key, bool $single = false ) {
			$value = $GLOBALS['__content_ability_meta'][ $post_id ][ $key ] ?? '';
			return $single ? $value : array( $value );
		}
	}

	if ( ! function_exists( 'update_post_meta' ) ) {
		function update_post_meta( int $post_id, string $key, $value ): void {
			$GLOBALS['__content_ability_meta'][ $post_id ][ $key ] = $value;
		}
	}

	if ( ! function_exists( 'get_date_from_gmt' ) ) {
		function get_date_from_gmt( string $date ): string {
			return $date;
		}
	}

	if ( ! function_exists( 'taxonomy_exists' ) ) {
		function taxonomy_exists( string $taxonomy ): bool {
			unset( $taxonomy );
			return false;
		}
	}

	if ( ! function_exists( 'wp_update_post' ) ) {
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
	}

	if ( ! function_exists( 'wp_insert_post' ) ) {
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
			foreach ( $post_data['meta_input'] ?? array() as $key => $value ) {
				$GLOBALS['__content_ability_meta'][ $id ][ $key ] = $value;
			}
			$existing->ID                              = $id;
			$GLOBALS['__content_ability_posts'][ $id ] = $existing;
			return $id;
		}
	}

	function blocks_engine_php_transformer_convert_format( string $content, string $from, string $to, array $options = array() ): array {
		unset( $options );
		$GLOBALS['__content_ability_conversions'][] = array( $from, $to, $content );

		if ( str_contains( $content, 'CONVERT_FAIL' ) ) {
			return array(
				'schema'      => 'blocks-engine/php-transformer/result/v1',
				'status'      => 'failed',
				'diagnostics' => array(
					array(
						'code'    => 'blocks_engine_conversion_failed',
						'message' => 'Blocks Engine conversion failed.',
					),
				),
			);
		}

		if ( 'blocks' === $from ) {
			if ( str_contains( $content, '<!-- wp:' ) && ! str_contains( $content, '<!-- /wp:' ) ) {
				return array(
					'schema'      => 'blocks-engine/php-transformer/result/v1',
					'status'      => 'failed',
					'diagnostics' => array(
						array(
							'code'    => 'blocks_unclosed_comment',
							'message' => 'Serialized block markup contains an unclosed block comment.',
						),
					),
				);
			}

			if ( ! str_contains( $content, '<!-- wp:' ) ) {
				return array(
					'schema'      => 'blocks-engine/php-transformer/result/v1',
					'status'      => 'failed',
					'diagnostics' => array(
						array(
							'code'    => 'blocks_missing_comments',
							'message' => 'Declared blocks content does not contain serialized block comments.',
						),
					),
				);
			}
		}

		if ( $from === $to && 'blocks' === $to ) {
			return array(
				'schema'            => 'blocks-engine/php-transformer/result/v1',
				'status'            => 'success',
				'serialized_blocks' => str_replace( array( "\r\n", "\r" ), "\n", $content ),
				'documents'         => array(),
			);
		}

		if ( $from === $to ) {
			return array(
				'schema'            => 'blocks-engine/php-transformer/result/v1',
				'status'            => 'success',
				'serialized_blocks' => '',
				'documents'         => array(
					array(
						'format'  => $to,
						'content' => str_replace( array( "\r\n", "\r" ), "\n", $content ),
					),
				),
			);
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
			return array(
				'schema'            => 'blocks-engine/php-transformer/result/v1',
				'status'            => 'success',
				'serialized_blocks' => implode( "\n", $blocks ),
				'documents'         => array(),
			);
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
				return array(
					'schema'            => 'blocks-engine/php-transformer/result/v1',
					'status'            => 'success',
					'serialized_blocks' => implode( "\n", $blocks ),
					'documents'         => array(),
				);
			}
			return array(
				'schema'            => 'blocks-engine/php-transformer/result/v1',
				'status'            => 'success',
				'serialized_blocks' => "<!-- wp:html -->\n{$content}\n<!-- /wp:html -->",
				'documents'         => array(),
			);
		}

		if ( 'blocks' === $from && 'markdown' === $to ) {
			$content = preg_replace( '/<!--\s*\/?wp:[^>]+-->\s*/', '', $content );
			$content = preg_replace( '/<h[1-6][^>]*>(.*?)<\/h[1-6]>/', '# $1', $content );
			$content = preg_replace( '/<p[^>]*>(.*?)<\/p>/', '$1', $content );
			return array(
				'schema'            => 'blocks-engine/php-transformer/result/v1',
				'status'            => 'success',
				'serialized_blocks' => '',
				'documents'         => array(
					array(
						'format'  => 'markdown',
						'content' => trim( html_entity_decode( strip_tags( $content ) ) ),
					),
				),
			);
		}

		return array(
			'schema'      => 'blocks-engine/php-transformer/result/v1',
			'status'      => 'failed',
			'diagnostics' => array(
				array(
					'code'    => 'unsupported',
					'message' => "Unsupported {$from} to {$to}.",
				),
			),
		);
	}

	if ( ! function_exists( 'parse_blocks' ) ) {
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
	}

	if ( ! function_exists( 'serialize_blocks' ) ) {
		function serialize_blocks( array $blocks ): string {
			$serialized = array();
			foreach ( $blocks as $block ) {
				$name         = $block['blockName'] ?? 'core/freeform';
				$html         = $block['innerHTML'] ?? '';
				$serialized[] = "<!-- wp:{$name} -->\n{$html}\n<!-- /wp:{$name} -->";
			}
			return implode( "\n", $serialized );
		}
	}

	function content_ability_post_count(): int {
		return count( $GLOBALS['__content_ability_posts'] );
	}

	function content_ability_raw_upsert_execute_callers(): array {
		$root     = dirname( __DIR__ );
		$matches  = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root . '/inc', FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file instanceof SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}

			$path = $file->getPathname();
			if ( str_ends_with( $path, '/UpsertPostAbility.php' ) ) {
				continue;
			}

			$source = file_get_contents( $path );
			if ( false !== $source && false !== strpos( $source, 'UpsertPostAbility::execute' ) ) {
				$matches[] = str_replace( $root . '/', '', $path );
			}
		}

		return $matches;
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
	include_once dirname( __DIR__ ) . '/inc/Core/SourceDate.php';
	include_once dirname( __DIR__ ) . '/inc/Core/WordPress/PostTracking.php';
	include_once dirname( __DIR__ ) . '/inc/Core/WordPress/WordPressPublishHelper.php';
	include_once dirname( __DIR__ ) . '/inc/Abilities/Content/BlockSanitizer.php';
	include_once dirname( __DIR__ ) . '/inc/Abilities/Content/BlogContext.php';
	include_once dirname( __DIR__ ) . '/inc/Abilities/Content/GetPostBlocksAbility.php';
	include_once dirname( __DIR__ ) . '/inc/Abilities/Content/EditPostBlocksAbility.php';
	include_once dirname( __DIR__ ) . '/inc/Abilities/Content/UpsertPostAbility.php';

	$get = DataMachine\Abilities\Content\GetPostBlocksAbility::execute( array( 'post_id' => $fixture_post_id ) );
	assert_content_ability( 'markdown-post-read-succeeds', true === $get['success'] );
	assert_content_ability( 'markdown-post-read-converts-to-blocks', 'core/heading' === ( $get['blocks'][0]['block_name'] ?? '' ) );
	$stored_after_read = get_post( $fixture_post_id )->post_content ?? '';
	assert_content_ability( 'markdown-post-read-does-not-mutate-storage', "# Original\n\nHello world." === $stored_after_read );
	$paragraph_block_index = 1;
	foreach ( $get['blocks'] ?? array() as $block ) {
		if ( 'core/paragraph' === ( $block['block_name'] ?? '' ) && false !== strpos( $block['inner_html'] ?? '', 'Hello world.' ) ) {
			$paragraph_block_index = (int) ( $block['index'] ?? $paragraph_block_index );
			break;
		}
	}

	$edit = DataMachine\Abilities\Content\EditPostBlocksAbility::execute(
		array(
			'post_id' => $fixture_post_id,
			'edits'   => array(
				array(
					'block_index' => $paragraph_block_index,
					'find'        => 'Hello world.',
					'replace'     => 'Hello markdown.',
				),
			),
		)
	);
	assert_content_ability( 'markdown-post-edit-succeeds', true === $edit['success'] );
	$stored_after_edit = get_post( $fixture_post_id )->post_content ?? '';
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

	$source_upsert = DataMachine\Abilities\Content\UpsertPostAbility::execute(
		array(
			'post_type'         => 'wiki',
			'title'             => 'Source Dated Markdown',
			'content'           => "# Source\n\nRaw markdown.",
			'content_format'    => 'markdown',
			'source_url'        => 'https://example.com/source-post/',
			'original_date_gmt' => '2020-09-24T06:12:53+00:00',
		)
	);
	$source_id     = (int) ( $source_upsert['post_id'] ?? 0 );
	$source_post   = get_post( $source_id );
	assert_content_ability( 'upsert-source-date-succeeds', true === $source_upsert['success'] );
	assert_content_ability( 'upsert-source-url-meta-stored', 'https://example.com/source-post/' === get_post_meta( $source_id, '_datamachine_source_url', true ) );
	assert_content_ability( 'upsert-original-date-meta-stored', '2020-09-24 06:12:53' === get_post_meta( $source_id, '_datamachine_original_date_gmt', true ) );
	assert_content_ability( 'upsert-original-date-applies-post-date-gmt', '2020-09-24 06:12:53' === ( $source_post->post_date_gmt ?? '' ) );

	$attributed_upsert = DataMachine\Abilities\Content\UpsertPostAbility::execute(
		array(
			'post_type'              => 'wiki',
			'title'                  => 'Attributed Markdown',
			'content'                => "# Attributed\n\nRaw markdown.",
			'content_format'         => 'markdown',
			'raw_source'             => "# Attributed\n\nRaw markdown.",
			'content_hash'           => hash( 'sha256', "# Attributed\n\nRaw markdown." ),
			'source_url'             => 'https://example.com/attributed-source/',
			'add_source_attribution' => true,
		)
	);
	$attributed_id   = (int) ( $attributed_upsert['post_id'] ?? 0 );
	$attributed_post = get_post( $attributed_id );
	$attributed_body = (string) ( $attributed_post->post_content ?? '' );
	assert_content_ability( 'upsert-source-attribution-succeeds', true === $attributed_upsert['success'] );
	assert_content_ability( 'upsert-source-attribution-adds-markdown-link', str_contains( $attributed_body, '**Source:** [https://example.com/attributed-source/](https://example.com/attributed-source/)' ) );
	assert_content_ability( 'upsert-source-attribution-updates-raw-source', str_contains( (string) get_post_meta( $attributed_id, '_datamachine_raw_source', true ), 'https://example.com/attributed-source/' ) );
	assert_content_ability( 'upsert-source-attribution-hashes-attributed-content', hash( 'sha256', $attributed_body ) === get_post_meta( $attributed_id, '_datamachine_content_hash', true ) );

	$attributed_repeat = DataMachine\Abilities\Content\UpsertPostAbility::execute(
		array(
			'post_type'              => 'wiki',
			'post_id'                => $attributed_id,
			'title'                  => 'Attributed Markdown',
			'content'                => "# Attributed\n\nRaw markdown.",
			'content_format'         => 'markdown',
			'content_hash'           => hash( 'sha256', "# Attributed\n\nRaw markdown." ),
			'source_url'             => 'https://example.com/attributed-source/',
			'add_source_attribution' => true,
		)
	);
	assert_content_ability( 'upsert-source-attribution-repeat-no-change', 'no_change' === ( $attributed_repeat['action'] ?? '' ) );
	assert_content_ability( 'upsert-source-attribution-repeat-no-duplicate', 1 === substr_count( (string) get_post( $attributed_id )->post_content, '**Source:**' ) );

	$future_source_upsert = DataMachine\Abilities\Content\UpsertPostAbility::execute(
		array(
			'post_type'         => 'wiki',
			'title'             => 'Future Source Date Ignored',
			'content'           => "# Future\n\nRaw markdown.",
			'content_format'    => 'markdown',
			'source_url'        => 'https://example.com/future-source-post/',
			'original_date_gmt' => '2999-01-01T00:00:00+00:00',
		)
	);
	$future_source_id     = (int) ( $future_source_upsert['post_id'] ?? 0 );
	$future_source_post   = get_post( $future_source_id );
	assert_content_ability( 'upsert-future-source-date-succeeds', true === $future_source_upsert['success'] );
	assert_content_ability( 'upsert-future-source-url-still-stored', 'https://example.com/future-source-post/' === get_post_meta( $future_source_id, '_datamachine_source_url', true ) );
	assert_content_ability( 'upsert-future-original-date-ignored', '' === get_post_meta( $future_source_id, '_datamachine_original_date_gmt', true ) );
	assert_content_ability( 'upsert-future-original-date-does-not-set-post-date-gmt', '2999-01-01 00:00:00' !== ( $future_source_post->post_date_gmt ?? '' ) );

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
	/** @var array<int, array{0:string, 1:string, 2:string}> $conversions */
	$conversions   = $GLOBALS['__content_ability_conversions'];
	$last_convert  = array();
	if ( count( $conversions ) > 0 ) {
		$last_convert = $conversions[ count( $conversions ) - 1 ];
	}
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

	$posts_before_raw_markdown_default = content_ability_post_count();
	$raw_markdown_without_format       = DataMachine\Abilities\Content\UpsertPostAbility::execute(
		array(
			'post_type' => 'post',
			'title'     => 'Raw Markdown Without Format',
			'content'   => "# Missing Declaration\n\nThis is markdown, not serialized blocks.",
		)
	);
	assert_content_ability( 'raw-upsert-omitted-format-treats-markdown-as-blocks', false === $raw_markdown_without_format['success'] );
	assert_content_ability( 'raw-upsert-omitted-markdown-fails-loudly', 'datamachine_content_format_blocks_missing_comments' === ( $raw_markdown_without_format['error_code'] ?? '' ) );
	assert_content_ability( 'raw-upsert-omitted-markdown-does-not-write-post', $posts_before_raw_markdown_default === content_ability_post_count() );
	assert_content_ability( 'internal-upsert-execute-callers-are-explicitly-audited', array() === content_ability_raw_upsert_execute_callers() );

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
	assert_content_ability( 'malformed-blocks-source-preserves-transformer-error-code', 'datamachine_content_format_blocks_unclosed_comment' === ( $malformed_blocks['error_code'] ?? '' ) );
	assert_content_ability( 'malformed-blocks-source-does-not-write-post', $posts_before_malformed === content_ability_post_count() );

	$conversion_error = DataMachine\Abilities\Content\UpsertPostAbility::execute(
		array(
			'post_type'      => 'post',
			'title'          => 'Conversion Failure',
			'content'        => 'CONVERT_FAIL',
			'content_format' => 'markdown',
		)
	);
	assert_content_ability( 'blocks-engine-conversion-error-fails', false === $conversion_error['success'] );
	assert_content_ability( 'blocks-engine-conversion-error-code-is-distinct-from-missing-transformer', 'datamachine_content_format_blocks_engine_conversion_failed' === ( $conversion_error['error_code'] ?? '' ) );

	$tool = DataMachine\Abilities\Content\UpsertPostAbility::getChatTool();
	assert_content_ability( 'chat-tool-content-format-is-optional', ! in_array( 'content_format', $tool['required'] ?? array(), true ) );
	assert_content_ability( 'chat-tool-description-prefers-markdown-authoring', false !== strpos( $tool['description'], 'write content as markdown' ) );
	assert_content_ability( 'chat-tool-content-format-description-defaults-markdown', false !== strpos( $tool['parameters']['content_format']['description'] ?? '', 'default to markdown' ) );
	assert_content_ability( 'chat-tool-guidance-does-not-default-to-blocks', false === strpos( $tool['parameters']['content_format']['description'] ?? '', 'Defaults to blocks' ) );

	echo "\nContentFormat abilities smoke: {$total} assertions, {$failed} failures.\n";

	exit( min( 1, $failed ) );
}
