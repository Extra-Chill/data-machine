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

		private string $message;

		public function __construct( string $code = '', string $message = '' ) {
			unset( $code );
			$this->message = $message;
		}

		public function get_error_message(): string {
			return $this->message;
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

		if ( 'blocks' === $from && 'markdown' === $to ) {
			$content = preg_replace( '/<!--\s*\/?wp:[^>]+-->\s*/', '', $content );
			$content = preg_replace( '/<h[1-6][^>]*>(.*?)<\/h[1-6]>/', '# $1', $content );
			$content = preg_replace( '/<p[^>]*>(.*?)<\/p>/', '$1', $content );
			return trim( html_entity_decode( strip_tags( $content ) ) );
		}

		return new WP_Error( 'unsupported', "Unsupported {$from} to {$to}." );
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

	echo "\nContentFormat abilities smoke: {$total} assertions, {$failed} failures.\n";

	exit( min( 1, $failed ) );
}
