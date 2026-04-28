<?php
/**
 * Smoke test for handler_config <-> auth_ref bridge.
 *
 * @package DataMachine\Tests
 */

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/smoke-wp-stubs.php';

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

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		unset( $hook, $args );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ): string {
		return (string) json_encode( $value );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return $text;
	}
}

$GLOBALS['auth_ref_smoke_filters'] = array();
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['auth_ref_smoke_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		if ( empty( $GLOBALS['auth_ref_smoke_filters'][ $hook ] ) ) {
			return $value;
		}

		ksort( $GLOBALS['auth_ref_smoke_filters'][ $hook ], SORT_NUMERIC );
		foreach ( $GLOBALS['auth_ref_smoke_filters'][ $hook ] as $callbacks ) {
			foreach ( $callbacks as [ $callback, $accepted_args ] ) {
				$value = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted_args ) );
			}
		}

		return $value;
	}
}

if ( ! function_exists( 'get_site_option' ) ) {
	function get_site_option( string $option, $default = false ) {
		return $GLOBALS['auth_ref_smoke_options'][ $option ] ?? $default;
	}
}

if ( ! function_exists( 'update_site_option' ) ) {
	function update_site_option( string $option, $value ): bool {
		$GLOBALS['auth_ref_smoke_options'][ $option ] = $value;
		return true;
	}
}

require_once __DIR__ . '/../inc/Engine/Bundle/BundleValidationException.php';
require_once __DIR__ . '/../inc/Engine/Bundle/AuthRef.php';
require_once __DIR__ . '/../inc/Core/OAuth/BaseAuthProvider.php';
require_once __DIR__ . '/../inc/Abilities/HandlerAbilities.php';
require_once __DIR__ . '/../inc/Abilities/AuthAbilities.php';
require_once __DIR__ . '/../inc/Engine/Bundle/AuthRefHandlerConfig.php';
require_once __DIR__ . '/../inc/Core/Steps/Fetch/Handlers/Email/EmailAuth.php';

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Abilities\HandlerAbilities;
use DataMachine\Core\Steps\Fetch\Handlers\Email\EmailAuth;
use DataMachine\Engine\Bundle\AuthRefHandlerConfig;

$passes = 0;
$fails  = 0;

$assert = static function ( string $label, bool $condition ) use ( &$passes, &$fails ): void {
	if ( $condition ) {
		echo "PASS: {$label}\n";
		++$passes;
		return;
	}

	echo "FAIL: {$label}\n";
	++$fails;
};

add_filter(
	'datamachine_handlers',
	static function ( array $handlers ): array {
		$handlers['email'] = array(
			'type'              => 'fetch',
			'requires_auth'     => true,
			'auth_provider_key' => 'email_imap',
		);
		return $handlers;
	},
	10,
	2
);

add_filter(
	'datamachine_auth_providers',
	static function ( array $providers ): array {
		$providers['email_imap'] = new EmailAuth();
		return $providers;
	},
	10,
	2
);

HandlerAbilities::clearCache();
AuthAbilities::clearCache();
AuthRefHandlerConfig::register();

$GLOBALS['auth_ref_smoke_options']['datamachine_auth_data'] = array(
	'email_imap' => array(
		'config' => array(
			'imap_host'       => 'imap.example.test',
			'imap_port'       => 993,
			'imap_encryption' => 'ssl',
			'imap_user'       => 'reader@example.test',
			'imap_password'   => 'super-secret-app-password',
		),
	),
);

echo "\n[1] Export rewrite strips secrets and inserts auth_ref\n";
$rewritten = apply_filters(
	'datamachine_handler_config_to_auth_ref',
	array(
		'imap_host'     => 'imap.example.test',
		'imap_user'     => 'reader@example.test',
		'imap_password' => 'super-secret-app-password',
		'folder'        => 'INBOX',
		'max_messages'  => 7,
	),
	'email',
	array( 'flow_id' => 12 )
);

$assert( 'export returns array', is_array( $rewritten ) );
$assert( 'export inserts canonical auth_ref', 'email_imap:default' === ( $rewritten['auth_ref'] ?? null ) );
$assert( 'export preserves non-secret handler fields', 'INBOX' === ( $rewritten['folder'] ?? null ) && 7 === ( $rewritten['max_messages'] ?? null ) );
$assert( 'export strips password', ! array_key_exists( 'imap_password', $rewritten ) );
$assert( 'export strips host/user credential fields that include no secret words only when provider marks them by encrypted field absence', array_key_exists( 'imap_host', $rewritten ) );
$assert( 'export output does not contain secret value', ! str_contains( wp_json_encode( $rewritten ), 'super-secret-app-password' ) );

echo "\n[2] Import/runtime resolution restores local config while preserving static fields\n";
$resolved = apply_filters(
	'datamachine_auth_ref_to_handler_config',
	array(
		'auth_ref'     => 'email_imap:default',
		'folder'       => 'Support',
		'max_messages' => 3,
	),
	'email',
	array( 'flow_id' => 12 )
);

$assert( 'resolve returns array', is_array( $resolved ) );
$assert( 'resolve removes auth_ref marker', ! array_key_exists( 'auth_ref', $resolved ) );
$assert( 'resolve restores local secret only at runtime/import boundary', 'super-secret-app-password' === ( $resolved['imap_password'] ?? null ) );
$assert( 'resolve preserves handler static fields', 'Support' === ( $resolved['folder'] ?? null ) && 3 === ( $resolved['max_messages'] ?? null ) );

echo "\n[3] Unresolved and mismatched refs fail clearly without secret leakage\n";
$missing = apply_filters( 'datamachine_auth_ref_to_handler_config', array( 'auth_ref' => 'slack:default' ), 'email', array() );
$assert( 'missing provider returns WP_Error', is_wp_error( $missing ) );
$assert( 'missing provider error uses auth_ref code family', str_starts_with( $missing->get_error_code(), 'auth_ref_' ) );
$assert( 'missing provider error names install-local ref', str_contains( $missing->get_error_message(), 'slack:default' ) );
$assert( 'missing provider error does not leak local secret', ! str_contains( $missing->get_error_message(), 'super-secret-app-password' ) );

$bad = apply_filters( 'datamachine_auth_ref_to_handler_config', array( 'auth_ref' => 'bad ref' ), 'email', array() );
$assert( 'malformed ref returns WP_Error', is_wp_error( $bad ) && 'auth_ref_invalid' === $bad->get_error_code() );

echo "\n[4] Runtime execution paths call the resolver\n";
$root            = dirname( __DIR__ );
$fetch_step      = (string) file_get_contents( $root . '/inc/Core/Steps/Fetch/FetchStep.php' );
$publish_handler = (string) file_get_contents( $root . '/inc/Core/Steps/Publish/Handlers/PublishHandler.php' );
$upsert_handler  = (string) file_get_contents( $root . '/inc/Core/Steps/Upsert/Handlers/UpsertHandler.php' );
$bootstrap       = (string) file_get_contents( $root . '/data-machine.php' );

$assert( 'fetch step resolves auth_ref before handler execution', str_contains( $fetch_step, 'AuthRefHandlerConfig::resolve_runtime_config' ) );
$assert( 'publish handler resolves auth_ref before execution', str_contains( $publish_handler, 'AuthRefHandlerConfig::resolve_runtime_config' ) );
$assert( 'upsert handler resolves auth_ref before execution', str_contains( $upsert_handler, 'AuthRefHandlerConfig::resolve_runtime_config' ) );
$assert( 'plugin bootstrap registers auth_ref filters after handlers load', str_contains( $bootstrap, 'AuthRefHandlerConfig::register();' ) );

echo "\n=== Results ===\n";
echo "Passed: {$passes}\n";
echo "Failed: {$fails}\n";

if ( $fails > 0 ) {
	exit( 1 );
}
