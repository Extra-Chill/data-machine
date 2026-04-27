<?php
/**
 * Pure-PHP smoke test for webhook auth v2 migration (#1333).
 *
 * Run with: php tests/webhook-auth-v2-migration-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

$options = array();

function get_option( string $key, $default = false ) {
	global $options;
	return array_key_exists( $key, $options ) ? $options[ $key ] : $default;
}

function update_option( string $key, $value, bool $autoload = true ): bool {
	global $options;
	$GLOBALS['__webhook_auth_v2_migration_autoload'][ $key ] = $autoload;
	$options[ $key ] = $value;
	return true;
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function do_action( string $hook, ...$args ): void {
	$GLOBALS['__webhook_auth_v2_migration_actions'][] = array( $hook, $args );
}

class WebhookAuthV2MigrationWpdb {
	public string $prefix = 'wp_';

	/** @var array<string, array<int, array<string, mixed>>> */
	public array $rows = array();

	public function prepare( string $query, ...$args ): string {
		return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
	}

	public function get_var( string $query ) {
		foreach ( array_keys( $this->rows ) as $table ) {
			if ( str_contains( $query, $table ) ) {
				return $table;
			}
		}
		return null;
	}

	public function get_results( string $query, $output ) {
		foreach ( $this->rows as $table => $rows ) {
			if ( str_contains( $query, $table ) ) {
				return $rows;
			}
		}
		return array();
	}

	public function update( string $table, array $data, array $where, array $formats, array $where_formats ): bool {
		$GLOBALS['__webhook_auth_v2_migration_update_formats'][] = array( $formats, $where_formats );
		$id_column = array_key_first( $where );
		if ( null === $id_column ) {
			return false;
		}
		$id_value = $where[ $id_column ];

		foreach ( $this->rows[ $table ] as &$row ) {
			if ( array_key_exists( $id_column, $row ) && (string) $row[ $id_column ] === (string) $id_value ) {
				$row = array_merge( $row, $data );
				return true;
			}
		}
		unset( $row );

		return false;
	}
}

require_once __DIR__ . '/../inc/Api/WebhookVerificationResult.php';
require_once __DIR__ . '/../inc/Api/WebhookVerifier.php';
require_once __DIR__ . '/../inc/migrations/webhook-auth-v2.php';

$failures = array();
$passes   = 0;

function assert_equals( $expected, $actual, string $name, array &$failures, int &$passes ): void {
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

echo "webhook auth v2 migration smoke\n";
echo "--------------------------------\n";

$wpdb = new WebhookAuthV2MigrationWpdb();
$wpdb->rows['wp_datamachine_flows'] = array(
	array(
		'flow_id'           => 10,
		'scheduling_config' => wp_json_encode(
			array(
				'webhook_enabled'          => true,
				'webhook_auth_mode'        => 'hmac_sha256',
				'webhook_signature_header' => 'X-Hub-Signature-256',
				'webhook_signature_format' => 'sha256=hex',
				'webhook_secret'           => 'legacy-secret',
			)
		),
	),
	array(
		'flow_id'           => 11,
		'scheduling_config' => wp_json_encode(
			array(
				'webhook_enabled'          => true,
				'webhook_auth_mode'        => 'hmac',
				'webhook_auth'             => array( 'mode' => 'hmac' ),
				'webhook_secrets'          => array( array( 'id' => 'current', 'value' => 'canonical' ) ),
				'webhook_signature_header' => 'stale',
				'webhook_secret'           => 'stale',
			)
		),
	),
	array(
		'flow_id'           => 12,
		'scheduling_config' => wp_json_encode(
			array(
				'webhook_enabled'   => true,
				'webhook_auth_mode' => 'hmac',
				'webhook_auth'      => array(
					'mode'             => 'hmac',
					'signed_template'  => '{body}',
					'signature_source' => array(
						'header'   => 'X-Sig',
						'extract'  => array( 'kind' => 'raw' ),
						'encoding' => 'base64',
					),
				),
				'webhook_secrets'   => array( array( 'id' => 'current', 'value' => 'canon' ) ),
			)
		),
	),
	array(
		'flow_id'           => 13,
		'scheduling_config' => wp_json_encode( array() ),
	),
	array(
		'flow_id'           => 14,
		'scheduling_config' => '{not-json',
	),
);

datamachine_migrate_webhook_auth_v2();

$legacy   = json_decode( $wpdb->rows['wp_datamachine_flows'][0]['scheduling_config'], true );
$orphaned = json_decode( $wpdb->rows['wp_datamachine_flows'][1]['scheduling_config'], true );
$canon    = json_decode( $wpdb->rows['wp_datamachine_flows'][2]['scheduling_config'], true );
$empty    = json_decode( $wpdb->rows['wp_datamachine_flows'][3]['scheduling_config'], true );

assert_equals( true, get_option( 'datamachine_webhook_auth_v2_migrated' ), 'migration gate set', $failures, $passes );
assert_equals( 'hmac', $legacy['webhook_auth_mode'] ?? null, 'legacy hmac_sha256 row converted to hmac', $failures, $passes );
assert_equals( '{body}', $legacy['webhook_auth']['signed_template'] ?? null, 'legacy row gets v2 signed template', $failures, $passes );
assert_equals( 'X-Hub-Signature-256', $legacy['webhook_auth']['signature_source']['header'] ?? null, 'legacy header preserved', $failures, $passes );
assert_equals( 'prefix', $legacy['webhook_auth']['signature_source']['extract']['kind'] ?? null, 'legacy sha256 prefix extraction preserved', $failures, $passes );
assert_equals( 'sha256=', $legacy['webhook_auth']['signature_source']['extract']['key'] ?? null, 'legacy sha256 prefix key preserved', $failures, $passes );
assert_equals( 'hex', $legacy['webhook_auth']['signature_source']['encoding'] ?? null, 'legacy encoding preserved', $failures, $passes );
assert_equals( 'current', $legacy['webhook_secrets'][0]['id'] ?? null, 'legacy singular secret promoted to roster id', $failures, $passes );
assert_equals( 'legacy-secret', $legacy['webhook_secrets'][0]['value'] ?? null, 'legacy singular secret promoted to roster value', $failures, $passes );
assert_equals( false, array_key_exists( 'webhook_signature_header', $legacy ), 'legacy header removed', $failures, $passes );
assert_equals( false, array_key_exists( 'webhook_signature_format', $legacy ), 'legacy format removed', $failures, $passes );
assert_equals( false, array_key_exists( 'webhook_secret', $legacy ), 'legacy secret removed', $failures, $passes );

assert_equals( 'hmac', $orphaned['webhook_auth_mode'] ?? null, 'orphaned row keeps canonical mode', $failures, $passes );
assert_equals( 'canonical', $orphaned['webhook_secrets'][0]['value'] ?? null, 'orphaned row preserves canonical roster', $failures, $passes );
assert_equals( false, array_key_exists( 'webhook_signature_header', $orphaned ), 'orphaned header removed', $failures, $passes );
assert_equals( false, array_key_exists( 'webhook_secret', $orphaned ), 'orphaned secret removed', $failures, $passes );
assert_equals( 'base64', $canon['webhook_auth']['signature_source']['encoding'] ?? null, 'already-canonical row unchanged', $failures, $passes );
assert_equals( array(), $empty, 'empty scheduling config unchanged', $failures, $passes );
assert_equals( 2, count( $GLOBALS['__webhook_auth_v2_migration_update_formats'] ?? array() ), 'only changed rows persisted', $failures, $passes );

datamachine_migrate_webhook_auth_v2();
assert_equals( 2, count( $GLOBALS['__webhook_auth_v2_migration_update_formats'] ?? array() ), 'second run is gated and does not persist rows again', $failures, $passes );

echo "\n--------------------------------\n";
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
