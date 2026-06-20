<?php
/**
 * Pure-PHP smoke test for Blocks Engine PHP Transformer class conversion.
 *
 * Run with: php tests/content-format-blocks-engine-class-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace Automattic\BlocksEngine\PhpTransformer\FormatBridge {
	class FormatBridge {

		public function convertResult( string $content, string $from, string $to, array $options = array() ): object {
			$GLOBALS['__content_format_blocks_engine_class_calls'][] = array( $from, $to, $content, $options );

			return new class( $content, $from, $to ) {

				private string $content;
				private string $from;
				private string $to;

				public function __construct( string $content, string $from, string $to ) {
					$this->content = $content;
					$this->from    = $from;
					$this->to      = $to;
				}

				public function toArray(): array {
					return array(
						'schema'            => 'blocks-engine/php-transformer/result/v1',
						'status'            => 'success',
						'serialized_blocks' => 'blocks' === $this->to ? "<!-- wp:paragraph -->\n<p>{$this->content}</p>\n<!-- /wp:paragraph -->" : '',
						'documents'         => array(
							array(
								'format'  => $this->to,
								'content' => "[{$this->from}:{$this->to}]{$this->content}",
							),
						),
					);
				}
			};
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$failed = 0;
	$total  = 0;

	function assert_content_format_blocks_engine_class( string $name, bool $condition ): void {
		global $failed, $total;
		++$total;
		if ( $condition ) {
			echo "  PASS: {$name}\n";
			return;
		}

		echo "  FAIL: {$name}\n";
		++$failed;
	}

	$GLOBALS['__content_format_blocks_engine_class_filters'] = array();
	$GLOBALS['__content_format_blocks_engine_class_calls']   = array();

	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
			$GLOBALS['__content_format_blocks_engine_class_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook, $value, ...$args ) {
			if ( empty( $GLOBALS['__content_format_blocks_engine_class_filters'][ $hook ] ) ) {
				return $value;
			}

			ksort( $GLOBALS['__content_format_blocks_engine_class_filters'][ $hook ] );
			foreach ( $GLOBALS['__content_format_blocks_engine_class_filters'][ $hook ] as $callbacks ) {
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
		}
	}

	require_once dirname( __DIR__ ) . '/inc/Core/Content/ContentFormat.php';

	$blocks = \DataMachine\Core\Content\ContentFormat::convert( 'Hello', 'html', 'blocks', array( 'post_type' => 'page' ) );

	assert_content_format_blocks_engine_class( 'blocks-engine-class-converts-to-blocks', is_string( $blocks ) && false !== strpos( $blocks, '<!-- wp:paragraph -->' ) );
	assert_content_format_blocks_engine_class( 'blocks-engine-class-receives-context', 'page' === ( $GLOBALS['__content_format_blocks_engine_class_calls'][0][3]['post_type'] ?? '' ) );

	echo "\nContentFormat Blocks Engine class smoke: {$total} assertions, {$failed} failures.\n";

	exit( min( 1, $failed ) );
}
