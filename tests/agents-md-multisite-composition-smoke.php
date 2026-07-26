<?php
/**
 * Multisite root convention-path composition regression coverage.
 *
 * Run with: php tests/agents-md-multisite-composition-smoke.php
 *
 * @package DataMachine\Tests
 */

declare( strict_types=1 );

namespace DataMachine\Engine\AI {
	final class SectionRegistry {
		public static array $sections = array();

		public static function render_sections( string $filename, array $context = array() ): array {
			unset( $filename, $context );
			return self::$sections[ $GLOBALS['current_blog_id'] ] ?? array();
		}

		public static function filter_content( string $content, string $filename, array $context = array() ): string {
			unset( $filename, $context );
			return $content;
		}
	}
}

namespace {
	define( 'ABSPATH', '/tmp/' );

	$GLOBALS['current_blog_id'] = 2;
	$GLOBALS['site_ids']        = array( 2, 1 );
	$GLOBALS['site_urls']       = array(
		1 => 'https://first.test',
		2 => 'https://second.test',
	);
	$GLOBALS['site_options'] = array();

	function get_current_blog_id(): int {
		return $GLOBALS['current_blog_id'];
	}

	function get_sites( array $args = array() ): array {
		unset( $args );
		return $GLOBALS['site_ids'];
	}

	function get_home_url( int $blog_id, string $path = '' ): string {
		return $GLOBALS['site_urls'][ $blog_id ] . $path;
	}

	function untrailingslashit( string $value ): string {
		return rtrim( $value, '/\\' );
	}

	function sanitize_key( string $value ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ?? '' );
	}

	function get_option( string $name, mixed $default = false ): mixed {
		return $GLOBALS['site_options'][ get_current_blog_id() ][ $name ] ?? $default;
	}

	function update_option( string $name, mixed $value, bool $autoload = true ): bool {
		unset( $autoload );
		$GLOBALS['site_options'][ get_current_blog_id() ][ $name ] = $value;
		return true;
	}

	function get_blog_option( int $blog_id, string $name, mixed $default = false ): mixed {
		return $GLOBALS['site_options'][ $blog_id ][ $name ] ?? $default;
	}

	function assert_true( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
		fwrite( STDOUT, "PASS: {$message}\n" );
	}

	require_once dirname( __DIR__ ) . '/inc/Engine/AI/MultisiteSectionAggregator.php';

	\DataMachine\Engine\AI\SectionRegistry::$sections = array(
		1 => array(
			'provider-one' => array(
				'slug'     => 'provider-one',
				'priority' => 20,
				'content'  => "## Provider One\n`wp neutral one`",
			),
		),
		2 => array(
			'provider-two' => array(
				'slug'     => 'provider-two',
				'priority' => 30,
				'content'  => "## Provider Two\n`wp --allow-root neutral two`\n`wp --url=https://explicit.test neutral explicit`",
			),
		),
	);

	$second_only = \DataMachine\Engine\AI\MultisiteSectionAggregator::compose( 'AGENTS.md' );
	assert_true( '' === $second_only, 'incomplete first-run snapshots do not replace the shared root' );
	assert_true( \DataMachine\Engine\AI\MultisiteSectionAggregator::has_current_snapshot( 'AGENTS.md' ), 'current site snapshot is persisted' );

	$GLOBALS['current_blog_id'] = 1;
	$combined                    = \DataMachine\Engine\AI\MultisiteSectionAggregator::compose( 'AGENTS.md' );
	assert_true( str_contains( $combined, 'Provider One' ), 'first site provider is retained' );
	assert_true( str_contains( $combined, 'Provider Two' ), 'disjoint second site provider is retained' );
	assert_true( str_contains( $combined, 'wp --url=https://first.test neutral one' ), 'first site command targets its owner' );
	assert_true( str_contains( $combined, 'wp --url=https://second.test --allow-root neutral two' ), 'second site command targets its owner' );
	assert_true( str_contains( $combined, 'wp --url=https://explicit.test neutral explicit' ), 'explicit site routing is preserved' );
	assert_true( strpos( $combined, 'Provider One' ) < strpos( $combined, 'Provider Two' ), 'sections use deterministic priority order' );

	$GLOBALS['current_blog_id'] = 2;
	$from_second_context         = \DataMachine\Engine\AI\MultisiteSectionAggregator::compose( 'AGENTS.md' );
	assert_true( $combined === $from_second_context, 'composition bytes are independent of current site context' );

	\DataMachine\Engine\AI\SectionRegistry::$sections[2] = array();
	$after_removal = \DataMachine\Engine\AI\MultisiteSectionAggregator::compose( 'AGENTS.md' );
	assert_true( str_contains( $after_removal, 'Provider One' ), 'refreshing one site does not erase another site' );
	assert_true( ! str_contains( $after_removal, 'Provider Two' ), 'current site snapshot removes inactive providers' );

	fwrite( STDOUT, "Multisite AGENTS.md composition smoke passed.\n" );
}
