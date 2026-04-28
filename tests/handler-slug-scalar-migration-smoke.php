<?php
/**
 * Pure-PHP smoke test for handler slug scalar migration.
 *
 * Run with: php tests/handler-slug-scalar-migration-smoke.php
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
	$GLOBALS['__handler_slug_scalar_migration_autoload'][ $key ] = $autoload;
	$options[ $key ] = $value;
	return true;
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function do_action( string $hook, ...$args ): void {
	$GLOBALS['__handler_slug_scalar_migration_actions'][] = array( $hook, $args );
}

function apply_filters( string $hook, $value ) {
	if ( 'datamachine_step_types' !== $hook ) {
		return $value;
	}

	return array(
		'ai'           => array( 'uses_handler' => false, 'multi_handler' => false ),
		'system_task'  => array( 'uses_handler' => false, 'multi_handler' => false ),
		'webhook_gate' => array( 'uses_handler' => false, 'multi_handler' => false ),
		'fetch'        => array( 'uses_handler' => true, 'multi_handler' => false ),
		'publish'      => array( 'uses_handler' => true, 'multi_handler' => true ),
		'upsert'       => array( 'uses_handler' => true, 'multi_handler' => true ),
	);
}

class HandlerSlugScalarMigrationWpdb {
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
		$GLOBALS['__handler_slug_scalar_migration_update_formats'][] = array( $formats, $where_formats );
		$id_column = array_key_first( $where );
		if ( null === $id_column ) {
			return false;
		}
		$id_value  = $where[ $id_column ];

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

require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfig.php';
require_once __DIR__ . '/../inc/migrations/handler-slug-scalar.php';

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

echo "handler slug scalar migration smoke\n";
echo "-----------------------------------\n";

$wpdb = new HandlerSlugScalarMigrationWpdb();
$wpdb->rows['wp_datamachine_flows'] = array(
	array(
		'flow_id'     => 10,
		'flow_config' => wp_json_encode(
			array(
				'fetch_10'  => array(
					'step_type'       => 'fetch',
					'handler_slugs'   => array( 'rss' ),
					'handler_configs' => array( 'rss' => array( 'url' => 'https://example.com/feed.xml' ) ),
				),
				'system_10' => array(
					'step_type'       => 'system_task',
					'handler_slugs'   => array( 'system_task' ),
					'handler_configs' => array( 'system_task' => array( 'task' => 'daily_memory_generation' ) ),
				),
				'publish_10' => array(
					'step_type'       => 'publish',
					'handler_slugs'   => array( 'wordpress_publish', 'email_publish' ),
					'handler_configs' => array(
						'wordpress_publish' => array( 'post_type' => 'post' ),
						'email_publish'     => array( 'to' => 'ops@example.com' ),
					),
				),
			)
		),
	),
);
$wpdb->rows['wp_datamachine_pipelines'] = array(
	array(
		'pipeline_id'     => 20,
		'pipeline_config' => wp_json_encode(
			array(
				'system_pipeline' => array(
					'step_type'       => 'system_task',
					'handler_slugs'   => array( 'system_task' ),
					'handler_configs' => array( 'system_task' => array( 'task' => 'agent_call' ) ),
				),
			)
		),
	),
);

datamachine_migrate_handler_slug_scalar();

$flow_config     = json_decode( $wpdb->rows['wp_datamachine_flows'][0]['flow_config'], true );
$pipeline_config = json_decode( $wpdb->rows['wp_datamachine_pipelines'][0]['pipeline_config'], true );

assert_equals( true, get_option( 'datamachine_handler_slug_scalar_migrated' ), 'migration gate set', $failures, $passes );
assert_equals( 'rss', $flow_config['fetch_10']['handler_slug'] ?? null, 'fetch slug collapsed to scalar', $failures, $passes );
assert_equals( array( 'url' => 'https://example.com/feed.xml' ), $flow_config['fetch_10']['handler_config'] ?? null, 'fetch config collapsed to scalar', $failures, $passes );
assert_equals( false, array_key_exists( 'handler_slugs', $flow_config['fetch_10'] ), 'fetch plural slugs removed', $failures, $passes );
assert_equals( array( 'task' => 'daily_memory_generation' ), $flow_config['system_10']['handler_config'] ?? null, 'system_task config collapsed', $failures, $passes );
assert_equals( false, array_key_exists( 'handler_slugs', $flow_config['system_10'] ), 'system_task synthetic slugs removed', $failures, $passes );
assert_equals( array( 'wordpress_publish', 'email_publish' ), $flow_config['publish_10']['handler_slugs'] ?? null, 'publish multi slugs preserved', $failures, $passes );
assert_equals( array( 'task' => 'agent_call' ), $pipeline_config['system_pipeline']['handler_config'] ?? null, 'pipeline system_task config collapsed', $failures, $passes );

echo "\n-----------------------------------\n";
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
