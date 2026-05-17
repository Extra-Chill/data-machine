<?php
/**
 * Pure-PHP smoke test for `flow update --handler-config` params corruption
 * (#2059).
 *
 * Run with: php tests/flow-update-handler-config-params-smoke.php
 *
 * Pins three behaviours of the CLI write path for handler/settings configs
 * whose schema declares a `json` field (e.g. the SystemTask `params` field):
 *
 *   1. SettingsHandler::sanitize() preserves a nested array `params` value
 *      verbatim instead of coercing it to an empty string via the default
 *      sanitize_text_field() fallback.
 *   2. SettingsHandler::sanitize() decodes a JSON-string `params` value
 *      into the same nested-array shape, so callers passing raw JSON do
 *      not silently lose data.
 *   3. SettingsHandler::sanitize() never returns a scalar string for a
 *      `json` field, so downstream array_merge() calls in SystemTaskStep
 *      cannot fatal on "Argument #1 must be of type array, string given".
 *
 * The pre-fix behaviour was: sanitizeField() had no `case 'json':` branch,
 * so the params array fell through to `default: sanitizeText()`, which
 * called sanitize_text_field() on the array and returned "". The corrupt
 * empty string was then stored in flow_step_settings.params, where
 * FlowStepConfig::getPrimaryHandlerConfig() reads it preferentially over
 * handler_config.params, and SystemTaskStep::executeStep() fataled.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// ─── Minimal WP function stubs ────────────────────────────────────────

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}
		if ( is_string( $value ) ) {
			return stripslashes( $value );
		}
		return $value;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		// Mirrors WP core: arrays/objects coerce to empty string.
		if ( is_array( $str ) || is_object( $str ) ) {
			return '';
		}
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $str ) ) );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) {
		if ( is_array( $str ) || is_object( $str ) ) {
			return '';
		}
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return (string) $url;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = '' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = '' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return $text;
	}
}

require_once dirname( __DIR__ ) . '/inc/Core/Steps/Settings/SettingsHandler.php';
require_once dirname( __DIR__ ) . '/inc/Core/Steps/SystemTask/SystemTaskSettings.php';

// SystemTaskSettings::getTaskOptions() reads from a TaskRegistry that is
// not bootstrapped in this smoke. The `task` field is a `select` whose
// allowed values come from those options; with an empty option list, any
// provided task slug normalises to the empty-string default. That is fine
// for this test — the bug we are pinning lives in the `params` JSON field
// path, not in the `task` select path. We assert on `params` shape only.

// ─── Test harness ─────────────────────────────────────────────────────

$failures = array();
$passes   = 0;

function smoke_assert( bool $condition, string $name, string $detail = '' ): void {
	global $failures, $passes;
	if ( $condition ) {
		++$passes;
		echo "  ✓ {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  ✗ {$name}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
}

function smoke_assert_equals( $expected, $actual, string $name ): void {
	global $failures, $passes;
	if ( $expected === $actual ) {
		++$passes;
		echo "  ✓ {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  ✗ {$name}\n";
	echo '    expected: ' . var_export( $expected, true ) . "\n";
	echo '    actual:   ' . var_export( $actual, true ) . "\n";
}

echo "flow update --handler-config params shape smoke (#2059)\n";
echo "-------------------------------------------------------\n";

use DataMachine\Core\Steps\SystemTask\SystemTaskSettings;

echo "\n[1] nested-array params is preserved verbatim:\n";

$raw = array(
	'task'   => 'dispatch_message',
	'params' => array(
		'channel'   => 'example-channel',
		'recipient' => 'example-recipient',
		'message'   => 'hello world',
	),
);

$sanitized = SystemTaskSettings::sanitize( $raw );

smoke_assert(
	is_array( $sanitized['params'] ?? null ),
	'params remains an array after sanitize'
);
smoke_assert(
	'' !== ( $sanitized['params'] ?? '' ),
	'params is not coerced to empty string'
);
smoke_assert_equals(
	array(
		'channel'   => 'example-channel',
		'recipient' => 'example-recipient',
		'message'   => 'hello world',
	),
	$sanitized['params'] ?? null,
	'nested params keys round-trip verbatim'
);

echo "\n[2] JSON-string params is decoded into a nested array:\n";

$raw_json_string = array(
	'task'   => 'dispatch_message',
	'params' => '{"channel":"json-channel","recipient":"json-recipient","message":"from json"}',
);

$sanitized_json = SystemTaskSettings::sanitize( $raw_json_string );

smoke_assert(
	is_array( $sanitized_json['params'] ?? null ),
	'JSON-string params decodes to array'
);
smoke_assert_equals(
	array(
		'channel'   => 'json-channel',
		'recipient' => 'json-recipient',
		'message'   => 'from json',
	),
	$sanitized_json['params'] ?? null,
	'JSON-string params decodes to the same nested shape'
);

echo "\n[3] params is never a scalar string (regression guard for SystemTaskStep array_merge):\n";

$cases = array(
	'nested array'  => array(
		'task'   => 'dispatch_message',
		'params' => array( 'a' => 1, 'b' => 2 ),
	),
	'JSON string'   => array(
		'task'   => 'dispatch_message',
		'params' => '{"a":1,"b":2}',
	),
	'missing key'   => array(
		'task' => 'dispatch_message',
	),
	'empty array'   => array(
		'task'   => 'dispatch_message',
		'params' => array(),
	),
	'invalid JSON'  => array(
		'task'   => 'dispatch_message',
		'params' => 'not-json-at-all',
	),
	'empty string'  => array(
		'task'   => 'dispatch_message',
		'params' => '',
	),
);

foreach ( $cases as $key => $input ) {
	$result = SystemTaskSettings::sanitize( $input );
	smoke_assert(
		is_array( $result['params'] ?? null ),
		"params is an array (case: {$key})",
		'got ' . gettype( $result['params'] ?? null )
	);
	// The whole point: array_merge() must not fatal on this value.
	$merged = null;
	try {
		$merged = array_merge( $result['params'], array( 'task_type' => 'dispatch_message' ) );
	} catch ( \TypeError $e ) {
		// Pinned regression: pre-fix, this threw "Argument #1 must be of type array, string given".
		$merged = $e->getMessage();
	}
	smoke_assert(
		is_array( $merged ),
		"array_merge(params, [...]) succeeds (case: {$key})",
		is_string( $merged ) ? $merged : ''
	);
}

echo "\n-------------------------------------------------------\n";
$total = $passes + count( $failures );
echo "{$passes} / {$total} passed\n";

if ( ! empty( $failures ) ) {
	echo "\nFailures:\n";
	foreach ( $failures as $failure ) {
		echo " - {$failure}\n";
	}
	exit( 1 );
}

echo "\nAll assertions passed.\n";
