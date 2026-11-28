<?php
/**
 * Smoke test for BlogContext multisite targeting in content abilities.
 *
 * Exercises the target-resolution, validation, switch/restore, and
 * apply_input stamping logic that lets the block-content abilities edit a
 * post that lives on a different blog than the request landed on.
 *
 * Run with: php tests/blog-context-content-smoke.php
 *
 * @package DataMachine\Tests
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'STDERR' ) ) {
	define( 'STDERR', fopen( 'php://stderr', 'w' ) );
}

// --- Minimal WordPress multisite stubs -------------------------------------

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
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

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

// Mutable test world: the current blog plus a registry of network sites.
$GLOBALS['__test_is_multisite']   = true;
$GLOBALS['__test_current_blog']   = 1;
$GLOBALS['__test_switch_stack']   = array();
$GLOBALS['__test_sites']          = array(
	1  => (object) array( 'archived' => 0, 'deleted' => 0, 'spam' => 0 ),
	12 => (object) array( 'archived' => 0, 'deleted' => 0, 'spam' => 0 ),
	99 => (object) array( 'archived' => 1, 'deleted' => 0, 'spam' => 0 ), // archived
);

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return (bool) $GLOBALS['__test_is_multisite'];
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id(): int {
		return (int) $GLOBALS['__test_current_blog'];
	}
}

if ( ! function_exists( 'get_site' ) ) {
	function get_site( $blog_id ) {
		return $GLOBALS['__test_sites'][ (int) $blog_id ] ?? null;
	}
}

if ( ! function_exists( 'switch_to_blog' ) ) {
	function switch_to_blog( $blog_id ): bool {
		$GLOBALS['__test_switch_stack'][] = (int) $GLOBALS['__test_current_blog'];
		$GLOBALS['__test_current_blog']   = (int) $blog_id;
		return true;
	}
}

if ( ! function_exists( 'restore_current_blog' ) ) {
	function restore_current_blog(): bool {
		if ( empty( $GLOBALS['__test_switch_stack'] ) ) {
			return false;
		}
		$GLOBALS['__test_current_blog'] = (int) array_pop( $GLOBALS['__test_switch_stack'] );
		return true;
	}
}

require_once __DIR__ . '/../inc/Abilities/Content/BlogContext.php';

use DataMachine\Abilities\Content\BlogContext;

$failed = 0;
$total  = 0;

$assert = static function ( string $label, bool $condition ) use ( &$failed, &$total ): void {
	++$total;
	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}
	++$failed;
	echo "FAIL: {$label}\n";
};

// --- target_from_input ------------------------------------------------------

$GLOBALS['__test_current_blog'] = 1;
$assert( 'no blog_id resolves to current blog (0)', 0 === BlogContext::target_from_input( array() ) );
$assert( 'blog_id matching current blog resolves to 0', 0 === BlogContext::target_from_input( array( 'blog_id' => 1 ) ) );
$assert( 'cross-blog blog_id resolves to that blog', 12 === BlogContext::target_from_input( array( 'blog_id' => 12 ) ) );
$assert( 'zero blog_id resolves to 0', 0 === BlogContext::target_from_input( array( 'blog_id' => 0 ) ) );

$GLOBALS['__test_is_multisite'] = false;
$assert( 'single-site install never targets a blog', 0 === BlogContext::target_from_input( array( 'blog_id' => 12 ) ) );
$GLOBALS['__test_is_multisite'] = true;

// --- is_valid ---------------------------------------------------------------

$assert( 'valid network site passes', BlogContext::is_valid( 12 ) );
$assert( 'unknown site id fails', ! BlogContext::is_valid( 7 ) );
$assert( 'archived site fails', ! BlogContext::is_valid( 99 ) );
$assert( 'zero blog id fails', ! BlogContext::is_valid( 0 ) );

// --- enter / leave (switch + restore) --------------------------------------

$GLOBALS['__test_current_blog'] = 1;
$ctx = BlogContext::enter( array( 'blog_id' => 12 ) );
$assert( 'enter() switches to the target blog', 12 === get_current_blog_id() );
$assert( 'enter() reports switched=true', is_array( $ctx ) && true === $ctx['switched'] );
BlogContext::leave( $ctx );
$assert( 'leave() restores the original blog', 1 === get_current_blog_id() );

// enter() with no target is a no-op.
$ctx_noop = BlogContext::enter( array() );
$assert( 'enter() with no target does not switch', 1 === get_current_blog_id() );
$assert( 'enter() no-op reports switched=false', is_array( $ctx_noop ) && false === $ctx_noop['switched'] );
BlogContext::leave( $ctx_noop );
$assert( 'leave() on a no-op token keeps the current blog', 1 === get_current_blog_id() );

// enter() on an invalid blog returns WP_Error and does NOT switch.
$ctx_err = BlogContext::enter( array( 'blog_id' => 99 ) );
$assert( 'enter() on an invalid blog returns WP_Error', is_wp_error( $ctx_err ) );
$assert( 'enter() on an invalid blog does not switch', 1 === get_current_blog_id() );

// Nested restore correctness: a stray leave() on an error token must not pop.
BlogContext::leave( $ctx_err );
$assert( 'leave() ignores a WP_Error token (no stray restore)', 1 === get_current_blog_id() );

// --- with_blog_id (apply_input stamping) -----------------------------------

$stamped = BlogContext::with_blog_id( array( 'post_id' => 5 ), 12 );
$assert( 'with_blog_id stamps a positive blog id', 12 === ( $stamped['blog_id'] ?? null ) );
$assert( 'with_blog_id preserves existing keys', 5 === ( $stamped['post_id'] ?? null ) );

$unstamped = BlogContext::with_blog_id( array( 'post_id' => 5 ), 0 );
$assert( 'with_blog_id omits a zero blog id', ! array_key_exists( 'blog_id', $unstamped ) );

// --- round-trip: stamp then re-enter (mirrors stage -> resolve replay) ------

$GLOBALS['__test_current_blog'] = 12; // resolve turn lands on the chat-surface blog
$apply_input                    = BlogContext::with_blog_id( array( 'post_id' => 5 ), 1 );
$ctx_replay                     = BlogContext::enter( $apply_input );
$assert( 'resolve replay re-enters the stamped target blog', 1 === get_current_blog_id() );
BlogContext::leave( $ctx_replay );
$assert( 'resolve replay restores the calling blog', 12 === get_current_blog_id() );

// --- run_on_origin: STAGING happens on the calling blog, not the target -----
//
// Regression guard for the cross-site staging bug (data-machine#2678): the
// content abilities enter() the target blog for the post read/apply, but the
// pending-action store is per-blog and the accept/resolve turn runs on the
// CALLING blog. Staging inside the target-blog switch persists the action in
// the wrong blog's table, so the resolve never finds it and the accept fails.
// run_on_origin() must execute the staging callback with the ORIGIN blog
// active, then restore the target blog so the surrounding leave() balances.

// Cross-site: chat on blog 12, post on blog 1.
$GLOBALS['__test_current_blog'] = 12;
$ctx_stage                      = BlogContext::enter( array( 'blog_id' => 1 ) );
$assert( 'enter() switched to the target blog for the read', 1 === get_current_blog_id() );

$blog_seen_during_stage = null;
$stage_return           = BlogContext::run_on_origin(
	$ctx_stage,
	static function () use ( &$blog_seen_during_stage ) {
		$blog_seen_during_stage = get_current_blog_id();
		return 'staged';
	}
);
$assert( 'run_on_origin runs the staging callback on the ORIGIN blog (12), not the target (1)', 12 === $blog_seen_during_stage );
$assert( 'run_on_origin returns the callback value', 'staged' === $stage_return );
$assert( 'run_on_origin re-enters the target blog afterward (leave stays balanced)', 1 === get_current_blog_id() );

BlogContext::leave( $ctx_stage );
$assert( 'leave() after run_on_origin restores the calling blog', 12 === get_current_blog_id() );

// Same-blog / single-site: run_on_origin is a transparent pass-through.
$GLOBALS['__test_current_blog'] = 1;
$ctx_same                       = BlogContext::enter( array( 'blog_id' => 1 ) ); // target == current -> no switch
$blog_seen_same                 = null;
BlogContext::run_on_origin(
	$ctx_same,
	static function () use ( &$blog_seen_same ) {
		$blog_seen_same = get_current_blog_id();
	}
);
$assert( 'run_on_origin no-op (same blog) runs in place on blog 1', 1 === $blog_seen_same );
BlogContext::leave( $ctx_same );
$assert( 'same-blog run_on_origin leaves the blog unchanged', 1 === get_current_blog_id() );

if ( $failed > 0 ) {
	echo "=== blog-context-content-smoke: {$failed} FAIL of {$total} ===\n";
	exit( 1 );
}

echo "=== blog-context-content-smoke: ALL PASS ({$total}) ===\n";
