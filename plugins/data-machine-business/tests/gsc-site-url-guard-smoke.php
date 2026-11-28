<?php
/**
 * Pure-PHP smoke test for the GSC site_url property guard and 403 error surface (#59).
 *
 * Verifies that:
 *  - a model-supplied site_url matching the configured property resolves to the
 *    configured property (no rejection),
 *  - a mismatched site_url is rejected with a WP_Error before any Google call,
 *  - domain (sc-domain:) and URL-prefix forms normalize as equivalent,
 *  - a raw "HTTP 403" HttpClient error is rewritten into an actionable message.
 *
 * Run with: php tests/gsc-site-url-guard-smoke.php
 *
 * @package DataMachineBusiness\Tests
 */

namespace {
	$__gsc_failures = array();
	$__gsc_passes   = 0;

	// Minimal WP shims required by the abilities file at load + call time.
	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			private string $code;
			private string $message;
			public function __construct( string $code = '', string $message = '' ) {
				$this->code    = $code;
				$this->message = $message;
			}
			public function get_error_message(): string {
				return $this->message;
			}
			public function get_error_code(): string {
				return $this->code;
			}
		}
	}
	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ): bool {
			return $thing instanceof \WP_Error;
		}
	}
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $str ) {
			return trim( (string) $str );
		}
	}
	if ( ! function_exists( 'wp_parse_url' ) ) {
		function wp_parse_url( $url, $component = -1 ) {
			return parse_url( $url, $component );
		}
	}
	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = 'default' ) {
			return $text;
		}
	}
	if ( ! function_exists( 'doing_action' ) ) {
		function doing_action( $action = null ) {
			return false;
		}
	}
	if ( ! function_exists( 'did_action' ) ) {
		function did_action( $action ) {
			return 1;
		}
	}
	if ( ! function_exists( 'add_action' ) ) {
		function add_action( ...$args ) {
			return true;
		}
	}
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}
	if ( ! defined( 'JSON_ERROR_NONE' ) ) {
		define( 'JSON_ERROR_NONE', 0 );
	}
}

namespace DataMachine\Abilities {
	class PermissionHelper {
		public static function can_manage(): bool {
			return true;
		}
	}
}

namespace DataMachine\Core {
	class HttpClient {
		public static function post( $url, $args = array() ) {
			return array( 'success' => true, 'data' => '{}', 'status_code' => 200 );
		}
		public static function get( $url, $args = array() ) {
			return array( 'success' => true, 'data' => '{}', 'status_code' => 200 );
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Abilities/Analytics/GoogleSearchConsoleAbilities.php';

	function gsc_guard_assert( bool $condition, string $name ): void {
		global $__gsc_failures, $__gsc_passes;
		if ( $condition ) {
			$__gsc_passes++;
			echo "  \u{2713} {$name}\n";
			return;
		}
		$__gsc_failures[] = $name;
		echo "  \u{2717} {$name}\n";
	}

	echo "GSC site_url guard smoke (#59)\n";
	echo "------------------------------\n";

	$class = new \ReflectionClass( \DataMachineBusiness\Abilities\Analytics\GoogleSearchConsoleAbilities::class );

	$resolve = $class->getMethod( 'resolve_site_url' );
	$resolve->setAccessible( true );

	$normalize = $class->getMethod( 'normalize_property' );
	$normalize->setAccessible( true );

	$describe = $class->getMethod( 'describe_request_error' );
	$describe->setAccessible( true );

	$config = array( 'site_url' => 'sc-domain:extrachill.com' );

	// No supplied site_url -> configured property (CLI default path preserved).
	$r = $resolve->invoke( null, array(), $config );
	gsc_guard_assert( 'sc-domain:extrachill.com' === $r, 'no site_url falls back to configured property' );

	// Matching URL-prefix form -> resolves to configured property, no error.
	$r = $resolve->invoke( null, array( 'site_url' => 'https://extrachill.com/' ), $config );
	gsc_guard_assert( 'sc-domain:extrachill.com' === $r, 'matching URL-prefix site_url resolves to configured property' );

	// Mismatched subdomain -> WP_Error, never forwarded to Google.
	$r = $resolve->invoke( null, array( 'site_url' => 'https://studio.extrachill.com/' ), $config );
	gsc_guard_assert( is_wp_error( $r ), 'mismatched subdomain site_url is rejected with WP_Error' );
	gsc_guard_assert(
		is_wp_error( $r ) && 'gsc_property_not_verified' === $r->get_error_code(),
		'rejection uses gsc_property_not_verified error code'
	);
	gsc_guard_assert(
		is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'studio.extrachill.com' ),
		'rejection message names the unauthorized property'
	);

	// Empty configured property -> supplied value honoured (back-compat).
	$r = $resolve->invoke( null, array( 'site_url' => 'https://example.com/' ), array() );
	gsc_guard_assert( 'https://example.com/' === $r, 'empty configured property honours supplied site_url' );

	// Normalization equivalences.
	gsc_guard_assert(
		$normalize->invoke( null, 'sc-domain:extrachill.com' ) === $normalize->invoke( null, 'https://extrachill.com/' ),
		'sc-domain and https URL-prefix normalize equal'
	);
	gsc_guard_assert(
		$normalize->invoke( null, 'https://extrachill.com' ) !== $normalize->invoke( null, 'https://extrachill.com/blog/' ),
		'domain-level and path-scoped URL-prefix properties normalize distinct'
	);

	// 403 error surface rewrite.
	$msg = $describe->invoke( null, 'Google Search Console Ability POST returned HTTP 403', 'sc-domain:extrachill.com' );
	gsc_guard_assert( false !== strpos( $msg, 'not verified' ), '403 error rewritten to actionable "not verified" message' );
	gsc_guard_assert( false !== strpos( $msg, 'property-authorization' ), '403 error clarifies it is a property-authorization issue' );

	// Non-403 error passes through unchanged.
	$msg = $describe->invoke( null, 'timeout', 'sc-domain:extrachill.com' );
	gsc_guard_assert(
		false !== strpos( $msg, 'Failed to connect to Google Search Console API: timeout' ),
		'non-403 error preserves the generic connection-failure message'
	);

	if ( ! empty( $__gsc_failures ) ) {
		echo "\nFAILURES:\n";
		foreach ( $__gsc_failures as $failure ) {
			echo " - {$failure}\n";
		}
		exit( 1 );
	}

	echo "\n{$__gsc_passes} assertions passed.\n";
}
