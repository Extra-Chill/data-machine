<?php
/**
 * Pure-PHP smoke test for legacy settings model-key migration.
 *
 * Run with: php tests/settings-mode-models-migration-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$options      = array();
$site_options = array();

function get_option( string $key, $default = false ) {
	global $options;
	return array_key_exists( $key, $options ) ? $options[ $key ] : $default;
}

function update_option( string $key, $value, bool $autoload = true ): bool {
	global $options;
	$options[ $key ] = $value;
	return true;
}

function get_site_option( string $key, $default = false ) {
	global $site_options;
	return array_key_exists( $key, $site_options ) ? $site_options[ $key ] : $default;
}

function update_site_option( string $key, $value ): bool {
	global $site_options;
	$site_options[ $key ] = $value;
	return true;
}

function sanitize_key( $key ) {
	$key = strtolower( (string) $key );
	return preg_replace( '/[^a-z0-9_\-]/', '', $key );
}

function sanitize_text_field( $value ) {
	return trim( (string) $value );
}

require_once __DIR__ . '/../inc/migrations/settings-mode-models.php';

$failures = array();
$passes   = 0;

function assert_equals( $expected, $actual, string $name, array &$failures, int &$passes ): void {
	if ( $expected === $actual ) {
		++$passes;
		echo "  PASS: {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  FAIL: {$name}\n";
	echo '    expected: ' . var_export( $expected, true ) . "\n";
	echo '    actual:   ' . var_export( $actual, true ) . "\n";
}

echo "settings-mode-models migration smoke\n";
echo "------------------------------------\n";

echo "\n[settings:1] Migrates site context_models to mode_models\n";
$options['datamachine_settings'] = array(
	'context_models'  => array(
		'pipeline' => array( 'provider' => 'openai', 'model' => 'gpt-5.4' ),
	),
	'default_model'   => 'gpt-5.4-mini',
	'unrelated_value' => true,
);
$site_options['datamachine_network_settings'] = array();

datamachine_migrate_settings_mode_models();

assert_equals(
	array(
		'default_model'   => 'gpt-5.4-mini',
		'unrelated_value' => true,
		'mode_models'     => array(
			'pipeline' => array( 'provider' => 'openai', 'model' => 'gpt-5.4' ),
		),
	),
	$options['datamachine_settings'],
	'site context_models moved and legacy key removed',
	$failures,
	$passes
);

echo "\n[settings:2] Migrates network agent_models to mode_models\n";
$options      = array();
$site_options = array(
	'datamachine_network_settings' => array(
		'agent_models'     => array(
			'chat' => array( 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-20250514' ),
		),
		'default_provider' => 'openai',
	),
);

datamachine_migrate_settings_mode_models();

assert_equals(
	array(
		'default_provider' => 'openai',
		'mode_models'      => array(
			'chat' => array( 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-20250514' ),
		),
	),
	$site_options['datamachine_network_settings'],
	'network agent_models moved and legacy key removed',
	$failures,
	$passes
);

echo "\n[settings:3] Existing mode_models wins over legacy keys\n";
$options      = array(
	'datamachine_settings' => array(
		'mode_models'    => array(
			'pipeline' => array( 'provider' => 'openai', 'model' => 'gpt-5.4' ),
		),
		'context_models' => array(
			'pipeline' => array( 'provider' => 'openai', 'model' => 'gpt-5.4-mini' ),
		),
	),
);
$site_options = array();

datamachine_migrate_settings_mode_models();

assert_equals(
	array(
		'mode_models' => array(
			'pipeline' => array( 'provider' => 'openai', 'model' => 'gpt-5.4' ),
		),
	),
	$options['datamachine_settings'],
	'canonical mode_models preserved and legacy key removed',
	$failures,
	$passes
);

echo "\n[settings:4] Migration is gated\n";
$options      = array(
	'datamachine_settings_mode_models_migrated' => true,
	'datamachine_settings' => array(
		'context_models' => array(
			'pipeline' => array( 'provider' => 'openai', 'model' => 'gpt-5.4' ),
		),
	),
);
$site_options = array();

datamachine_migrate_settings_mode_models();

assert_equals(
	array(
		'context_models' => array(
			'pipeline' => array( 'provider' => 'openai', 'model' => 'gpt-5.4' ),
		),
	),
	$options['datamachine_settings'],
	'gate prevents re-entry',
	$failures,
	$passes
);

echo "\n[settings:5] Direct array helper sanitizes extension modes\n";
$normalized = datamachine_migrate_settings_mode_models_array(
	array(
		'agent_models' => array(
			'Pipeline!' => array( 'provider' => ' openai ', 'model' => ' gpt-5.4 ' ),
			'invalid'   => 'not an array',
		),
	)
);

assert_equals(
	array(
		'mode_models' => array(
			'pipeline' => array( 'provider' => 'openai', 'model' => 'gpt-5.4' ),
		),
	),
	$normalized,
	'helper normalizes mode keys and drops invalid entries',
	$failures,
	$passes
);

echo "\n------------------------------------\n";
if ( ! empty( $failures ) ) {
	echo count( $failures ) . " failure(s)\n";
	exit( 1 );
}

echo "All {$passes} assertions passed.\n";
