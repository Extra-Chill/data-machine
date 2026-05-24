<?php
/**
 * Pure-PHP smoke coverage for signed pending-action resolution URLs.
 *
 * Run with: php tests/signed-pending-action-resolution-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'DATAMACHINE_PENDING_ACTION_TRANSIENT_FALLBACK' ) ) {
	define( 'DATAMACHINE_PENDING_ACTION_TRANSIENT_FALLBACK', true );
}

$GLOBALS['__signed_filters']    = array();
$GLOBALS['__signed_transients'] = array();
$GLOBALS['__signed_options']    = array();

require_once __DIR__ . '/fixtures/rest-url-stub.php';

function datamachine_signed_assert( bool $condition, string $message, array &$failures, int &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "PASS: {$message}\n";
		return;
	}

	$failures[] = $message;
	echo "FAIL: {$message}\n";
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		unset( $domain );
		return $text;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return trim( wp_strip_all_tags( (string) $value ) );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return strip_tags( (string) $text );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		return '11111111-2222-4333-8444-555555555555';
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 0;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		return $GLOBALS['__signed_options'][ $option ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) {
		unset( $autoload );
		$GLOBALS['__signed_options'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $key, $value, $url ) {
		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook = '' ) {
		unset( $hook );
		return 0;
	}
}
if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( $hook = '' ) {
		unset( $hook );
		return false;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__signed_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
		ksort( $GLOBALS['__signed_filters'][ $hook ], SORT_NUMERIC );
		return true;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $hook, $callback, $priority, $accepted_args );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		if ( empty( $GLOBALS['__signed_filters'][ $hook ] ) ) {
			return $value;
		}

		foreach ( $GLOBALS['__signed_filters'][ $hook ] as $callbacks ) {
			foreach ( $callbacks as $registration ) {
				$value = call_user_func_array( $registration[0], array_slice( array_merge( array( $value ), $args ), 0, $registration[1] ) );
			}
		}

		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		apply_filters( $hook, null, ...$args );
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ) {
		unset( $expiration );
		$GLOBALS['__signed_transients'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['__signed_transients'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['__signed_transients'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
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

require_once dirname( __DIR__ ) . '/vendor/automattic/agents-api/agents-api.php';
require_once dirname( __DIR__ ) . '/inc/Core/Workspace/WordPressWorkspaceScope.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/PendingActionObservers.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/PendingActionStore.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/ResolvePendingActionAbility.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/SignPendingActionResolutionAbility.php';

$failures = array();
$passes   = 0;

echo "signed-pending-action-resolution-smoke\n";

$action_id = 'act_signed_smoke';
\DataMachine\Engine\AI\Actions\PendingActionStore::store(
	$action_id,
	array(
		'kind'         => 'signed_smoke',
		'summary'      => 'Signed URL smoke action',
		'preview_data' => array( 'title' => 'Preview' ),
		'apply_input'  => array( 'value' => 42 ),
		'created_by'   => 0,
		'agent_id'     => 0,
		'context'      => array(),
	)
);

add_filter(
	'datamachine_pending_action_handlers',
	static function ( array $handlers ): array {
		$handlers['signed_smoke'] = array(
			'apply' => static function ( array $apply_input ): array {
				return array(
					'success' => true,
					'value'   => $apply_input['value'] ?? null,
				);
			},
		);
		return $handlers;
	}
);

$signed = \DataMachine\Engine\AI\Actions\SignPendingActionResolutionAbility::execute(
	array(
		'action_id' => $action_id,
		'lifetime'  => 60,
		'resolver'  => 'email_approval',
	)
);

datamachine_signed_assert( true === ( $signed['success'] ?? false ), 'signing ability succeeds for pending action', $failures, $passes );
datamachine_signed_assert( str_contains( (string) ( $signed['approve_url'] ?? '' ), '/actions/resolve-by-token?t=' ), 'approve URL targets token route', $failures, $passes );
datamachine_signed_assert( str_contains( (string) ( $signed['reject_url'] ?? '' ), '/actions/resolve-by-token?t=' ), 'reject URL targets token route', $failures, $passes );
datamachine_signed_assert( ! empty( $GLOBALS['__signed_options']['datamachine_pending_action_resolution_secret'] ), 'HMAC secret is generated in wp_options', $failures, $passes );

$query = array();
parse_str( (string) parse_url( (string) $signed['approve_url'], PHP_URL_QUERY ), $query );

$resolved = \DataMachine\Engine\AI\Actions\SignPendingActionResolutionAbility::resolve_token( (string) ( $query['t'] ?? '' ) );

datamachine_signed_assert( true === ( $resolved['success'] ?? false ), 'valid approve token resolves action', $failures, $passes );
datamachine_signed_assert( 'accepted' === ( $resolved['decision'] ?? null ), 'approve token records accepted decision', $failures, $passes );
datamachine_signed_assert( 'signed_smoke' === ( $resolved['kind'] ?? null ), 'resolution keeps pending action kind', $failures, $passes );
datamachine_signed_assert( null === \DataMachine\Engine\AI\Actions\PendingActionStore::get( $action_id ), 'resolved transient action is no longer pending', $failures, $passes );

\DataMachine\Engine\AI\Actions\SignPendingActionResolutionAbility::rotate_secret();
$after_rotation = \DataMachine\Engine\AI\Actions\SignPendingActionResolutionAbility::resolve_token( (string) ( $query['t'] ?? '' ) );
datamachine_signed_assert( false === ( $after_rotation['success'] ?? true ), 'rotating the HMAC secret invalidates existing tokens', $failures, $passes );

if ( ! empty( $failures ) ) {
	echo "\nFailures:\n";
	foreach ( $failures as $failure ) {
		echo " - {$failure}\n";
	}
	exit( 1 );
}

echo "\nSigned pending-action resolution smoke passed ({$passes} assertions).\n";
