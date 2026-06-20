<?php
/**
 * Pure-PHP smoke test for Blocks Engine PHP Transformer content conversion.
 *
 * Run with: php tests/content-format-blocks-engine-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failed = 0;
$total  = 0;

function assert_content_format_blocks_engine( string $name, bool $condition ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  PASS: {$name}\n";
		return;
	}

	echo "  FAIL: {$name}\n";
	++$failed;
}

$GLOBALS['__content_format_blocks_engine_filters']     = array();
$GLOBALS['__content_format_blocks_engine_helper_calls'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['__content_format_blocks_engine_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		if ( empty( $GLOBALS['__content_format_blocks_engine_filters'][ $hook ] ) ) {
			return $value;
		}

		ksort( $GLOBALS['__content_format_blocks_engine_filters'][ $hook ] );
		foreach ( $GLOBALS['__content_format_blocks_engine_filters'][ $hook ] as $callbacks ) {
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

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {

		private string $code;
		private string $message;

		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

function blocks_engine_php_transformer_convert_format( string $content, string $from, string $to, array $options = array() ): array {
	$GLOBALS['__content_format_blocks_engine_helper_calls'][] = array( $from, $to, $content, $options );

	if ( 'FAIL' === $content ) {
		return array(
			'schema'      => 'blocks-engine/php-transformer/result/v1',
			'status'      => 'failed',
			'diagnostics' => array(
				array(
					'code'    => 'fixture_failed',
					'message' => 'Fixture conversion failed.',
				),
			),
		);
	}

	return array(
		'schema'            => 'blocks-engine/php-transformer/result/v1',
		'status'            => 'success',
		'serialized_blocks' => 'blocks' === $to ? "<!-- wp:paragraph -->\n<p>{$content}</p>\n<!-- /wp:paragraph -->" : '',
		'documents'         => array(
			array(
				'format'  => $to,
				'content' => "[{$from}:{$to}]{$content}",
			),
		),
	);
}

require_once dirname( __DIR__ ) . '/inc/Core/Content/ContentFormat.php';

use DataMachine\Core\Content\ContentFormat;

$blocks = ContentFormat::convert( 'Hello', 'html', 'blocks', array( 'post_type' => 'page' ) );

assert_content_format_blocks_engine( 'blocks-engine-helper-converts-to-blocks', is_string( $blocks ) && false !== strpos( $blocks, '<!-- wp:paragraph -->' ) );
assert_content_format_blocks_engine( 'blocks-engine-helper-receives-context', 'page' === ( $GLOBALS['__content_format_blocks_engine_helper_calls'][0][3]['post_type'] ?? '' ) );
assert_content_format_blocks_engine( 'blocks-engine-helper-is-runtime-transformer', 1 === count( $GLOBALS['__content_format_blocks_engine_helper_calls'] ) );

$markdown = ContentFormat::convert( '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->', 'blocks', 'markdown' );
assert_content_format_blocks_engine( 'blocks-engine-document-content-used-for-non-blocks', '[blocks:markdown]<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->' === $markdown );

$failure = ContentFormat::convert( 'FAIL', 'markdown', 'blocks' );
assert_content_format_blocks_engine( 'blocks-engine-failure-becomes-wp-error', is_wp_error( $failure ) );
assert_content_format_blocks_engine( 'blocks-engine-failure-code-preserved', is_wp_error( $failure ) && 'datamachine_content_format_fixture_failed' === $failure->get_error_code() );

echo "\nContentFormat Blocks Engine smoke: {$total} assertions, {$failed} failures.\n";

exit( min( 1, $failed ) );
