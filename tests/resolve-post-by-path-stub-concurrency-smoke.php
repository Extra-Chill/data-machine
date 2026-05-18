<?php
/**
 * Pure-PHP smoke test for concurrent path stub creation safeguards.
 *
 * Run with: php tests/resolve-post-by-path-stub-concurrency-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace {
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

$GLOBALS['__rpbp_posts']      = array();
$GLOBALS['__rpbp_next_id']    = 100;
$GLOBALS['__rpbp_options']    = array();
$GLOBALS['__rpbp_meta']       = array();
$GLOBALS['__rpbp_failures']   = 0;
$GLOBALS['__rpbp_race_slug']  = '';
$GLOBALS['__rpbp_race_fired'] = false;

class WP_Error {
	private string $code;
	private string $message;

	public function __construct( string $code = '', string $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
}

class WP_Post {
	public int $ID = 0;
	public string $post_type = 'wiki';
	public string $post_title = '';
	public string $post_name = '';
	public int $post_parent = 0;
}

function rpbp_assert( bool $ok, string $message ): void {
	if ( $ok ) {
		echo "  PASS: {$message}\n";
		return;
	}

	echo "  FAIL: {$message}\n";
	$GLOBALS['__rpbp_failures']++;
}

function rpbp_insert_post( string $slug, int $parent_id, string $title = '' ): int {
	$post              = new WP_Post();
	$post->ID          = $GLOBALS['__rpbp_next_id']++;
	$post->post_title  = '' !== $title ? $title : ucwords( str_replace( '-', ' ', $slug ) );
	$post->post_name   = $slug;
	$post->post_parent = $parent_id;

	$GLOBALS['__rpbp_posts'][ $post->ID ] = $post;
	return $post->ID;
}

function get_posts( array $args ): array {
	$out = array();
	foreach ( $GLOBALS['__rpbp_posts'] as $post ) {
		if ( ( $args['post_type'] ?? '' ) !== $post->post_type ) {
			continue;
		}
		if ( isset( $args['name'] ) && (string) $args['name'] !== $post->post_name ) {
			continue;
		}
		if ( isset( $args['post_parent'] ) && (int) $args['post_parent'] !== $post->post_parent ) {
			continue;
		}
		$out[] = 'ids' === ( $args['fields'] ?? '' ) ? $post->ID : $post;
	}
	return $out;
}

function wp_parse_args( array $args, array $defaults ): array { return array_merge( $defaults, $args ); }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }

function add_option( string $name, $value = '', $deprecated = '', $autoload = null ): bool {
	unset( $deprecated, $autoload );
	if ( array_key_exists( $name, $GLOBALS['__rpbp_options'] ) ) {
		return false;
	}
	$GLOBALS['__rpbp_options'][ $name ] = $value;
	return true;
}

function delete_option( string $name ): bool {
	unset( $GLOBALS['__rpbp_options'][ $name ] );
	return true;
}

function get_option( string $name, $default = false ) { return $GLOBALS['__rpbp_options'][ $name ] ?? $default; }

function wp_insert_post( array $post_data, bool $wp_error = false ) {
	unset( $wp_error );
	$slug      = (string) ( $post_data['post_name'] ?? '' );
	$parent_id = (int) ( $post_data['post_parent'] ?? 0 );

	if ( $GLOBALS['__rpbp_race_slug'] === $slug && ! $GLOBALS['__rpbp_race_fired'] ) {
		$GLOBALS['__rpbp_race_fired'] = true;
		rpbp_insert_post( $slug, $parent_id, 'Canonical Race Winner' );
	}

	if ( ! empty( get_posts( array( 'post_type' => (string) $post_data['post_type'], 'name' => $slug, 'post_parent' => $parent_id, 'fields' => 'ids' ) ) ) ) {
		$slug .= '-2';
	}

	return rpbp_insert_post( $slug, $parent_id, (string) ( $post_data['post_title'] ?? '' ) );
}

function get_post( int $post_id ) { return $GLOBALS['__rpbp_posts'][ $post_id ] ?? null; }

function wp_delete_post( int $post_id, bool $force_delete = false ) {
	unset( $force_delete );
	unset( $GLOBALS['__rpbp_posts'][ $post_id ] );
	return true;
}

function update_post_meta( int $post_id, string $key, $value ): void { $GLOBALS['__rpbp_meta'][ $post_id ][ $key ] = $value; }
}

namespace {
require_once dirname( __DIR__ ) . '/inc/Core/WordPress/ResolvePostByPath.php';

echo "=== resolve-post-by-path-stub-concurrency-smoke ===\n";

$GLOBALS['__rpbp_race_slug'] = 'jetpack';
$resolved = \DataMachine\Core\WordPress\ResolvePostByPath::resolve_or_create_stub( 'jetpack', 'wiki', 0, '_auto_stub' );

rpbp_assert( 100 === $resolved, 'returns the concurrently-created canonical stub' );
rpbp_assert( 1 === count( $GLOBALS['__rpbp_posts'] ), 'removes the duplicate suffixed stub created by the race' );
rpbp_assert( 'jetpack' === get_post( (int) $resolved )->post_name, 'canonical stub keeps requested slug' );

if ( $GLOBALS['__rpbp_failures'] > 0 ) {
	exit( 1 );
}

echo "All resolve-post-by-path stub concurrency checks passed.\n";
}
