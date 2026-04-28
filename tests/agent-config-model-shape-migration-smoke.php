<?php
/**
 * Pure-PHP smoke test for the agent_config model.default.* migration.
 *
 * Run with: php tests/agent-config-model-shape-migration-smoke.php
 *
 * The migration flattens the legacy
 *   agent_config = { "model": { "default": { "provider", "model" } } }
 * shape persisted by the pre-fix `register-agents.php` writer to the
 * shape `PluginSettings::resolveModelForAgentMode()` actually reads:
 *   agent_config = { "default_provider", "default_model" }
 *
 * Contracts under test:
 *
 *  1. Legacy `model.default.{provider,model}` is flattened to top-level
 *     `default_provider` / `default_model`.
 *  2. Sibling keys (`tool_policy`, `directive_policy`, arbitrary plugin
 *     keys) are preserved verbatim.
 *  3. Empty legacy values are dropped — rows fall through to site/network
 *     defaults instead of being pinned to empty strings.
 *  4. Rows without a legacy `model` key are left alone.
 *  5. Empty `agent_config` is encoded as `{}`, not `[]`.
 *  6. Idempotent: gated on `datamachine_agent_config_model_shape_migrated`.
 *  7. Missing table is a no-op (gate still set so we don't loop).
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
	$options[ $key ] = $value;
	return true;
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function do_action( string $hook, ...$args ): void {
	$GLOBALS['__agent_config_migration_actions'][] = array( $hook, $args );
}

class AgentConfigModelShapeWpdb {
	public string $base_prefix = 'wp_';

	/** @var array<string, array<int, array<string, mixed>>> */
	public array $rows = array();

	/** Toggle to simulate "table does not exist". */
	public bool $table_present = true;

	public function prepare( string $query, ...$args ): string {
		return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
	}

	public function get_var( string $query ) {
		if ( ! $this->table_present ) {
			return null;
		}
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

require_once __DIR__ . '/../inc/migrations/agent-config-model-shape.php';

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

echo "agent-config-model-shape migration smoke\n";
echo "----------------------------------------\n";

// ---------------------------------------------------------------------
// Section 1: end-to-end on a representative spread of agent_config rows.
// ---------------------------------------------------------------------

echo "\n[shape:1] Flatten legacy and preserve siblings\n";

$wpdb         = new AgentConfigModelShapeWpdb();
$rows         = array(
	// Legacy with provider+model and tool_policy.
	array(
		'agent_id'     => 1,
		'agent_config' => wp_json_encode(
			array(
				'model'       => array(
					'default' => array(
						'provider' => 'openai',
						'model'    => 'gpt-5-mini',
					),
				),
				'tool_policy' => array(
					'mode'  => 'deny',
					'tools' => array( 'progress_story' ),
				),
				'extra_key'   => 'preserve_me',
			)
		),
	),
	// Legacy with empty provider/model — should leave both fields off
	// so site/network defaults apply.
	array(
		'agent_id'     => 2,
		'agent_config' => wp_json_encode(
			array(
				'model' => array(
					'default' => array(
						'provider' => '',
						'model'    => '',
					),
				),
			)
		),
	),
	// Already current shape — nothing to flatten, no `model` key.
	array(
		'agent_id'     => 3,
		'agent_config' => wp_json_encode(
			array(
				'default_provider' => 'openai',
				'default_model'    => 'gpt-5.4-nano',
			)
		),
	),
	// No legacy and no current — should not be touched.
	array(
		'agent_id'     => 4,
		'agent_config' => wp_json_encode(
			array(
				'allowed_redirect_uris' => array( 'example.com' ),
			)
		),
	),
);
$wpdb->rows['wp_datamachine_agents'] = $rows;

datamachine_migrate_agent_config_model_shape();

$by_id = array();
foreach ( $wpdb->rows['wp_datamachine_agents'] as $row ) {
	$by_id[ $row['agent_id'] ] = json_decode( $row['agent_config'], true );
}

assert_equals(
	array(
		'tool_policy'      => array(
			'mode'  => 'deny',
			'tools' => array( 'progress_story' ),
		),
		'extra_key'        => 'preserve_me',
		'default_provider' => 'openai',
		'default_model'    => 'gpt-5-mini',
	),
	$by_id[1],
	'agent 1 — legacy flattened, tool_policy + extra keys preserved',
	$failures,
	$passes
);

assert_equals(
	false,
	isset( $by_id[2]['default_provider'] ) || isset( $by_id[2]['default_model'] ) || isset( $by_id[2]['model'] ),
	'agent 2 — empty legacy values dropped, no pinned empty strings',
	$failures,
	$passes
);

assert_equals(
	array(
		'default_provider' => 'openai',
		'default_model'    => 'gpt-5.4-nano',
	),
	$by_id[3],
	'agent 3 — already-current shape preserved verbatim (gpt-5.4-nano stays)',
	$failures,
	$passes
);

assert_equals(
	array(
		'allowed_redirect_uris' => array( 'example.com' ),
	),
	$by_id[4],
	'agent 4 — rows without legacy model key are left alone',
	$failures,
	$passes
);

assert_equals(
	true,
	get_option( 'datamachine_agent_config_model_shape_migrated' ),
	'gate option set after first run',
	$failures,
	$passes
);

// ---------------------------------------------------------------------
// Section 2: empty agent_config encodes as `{}`, not `[]`.
// ---------------------------------------------------------------------

echo "\n[shape:2] Empty resulting config encodes as object, not array\n";

global $options;
$options = array();
$wpdb_b  = new AgentConfigModelShapeWpdb();
$wpdb_b->rows['wp_datamachine_agents'] = array(
	array(
		'agent_id'     => 5,
		'agent_config' => wp_json_encode(
			array(
				'model' => array(
					'default' => array(
						'provider' => '',
						'model'    => '',
					),
				),
			)
		),
	),
);

global $wpdb;
$wpdb = $wpdb_b;
datamachine_migrate_agent_config_model_shape();

assert_equals(
	'{}',
	$wpdb_b->rows['wp_datamachine_agents'][0]['agent_config'],
	'fully drained config writes `{}` not `[]`',
	$failures,
	$passes
);

// ---------------------------------------------------------------------
// Section 3: idempotent — second call is a no-op.
// ---------------------------------------------------------------------

echo "\n[shape:3] Second invocation short-circuits\n";

$wpdb_c = new AgentConfigModelShapeWpdb();
$wpdb_c->rows['wp_datamachine_agents'] = array(
	array(
		'agent_id'     => 6,
		'agent_config' => wp_json_encode(
			array(
				'model' => array( 'default' => array( 'provider' => 'openai', 'model' => 'gpt-5-mini' ) ),
			)
		),
	),
);
$wpdb = $wpdb_c;
// Gate from section 2 above is still set; this call must be a no-op.
datamachine_migrate_agent_config_model_shape();

$post = json_decode( $wpdb_c->rows['wp_datamachine_agents'][0]['agent_config'], true );
assert_equals(
	true,
	isset( $post['model']['default'] ),
	'gated re-entry leaves stale shape untouched (idempotent)',
	$failures,
	$passes
);

// ---------------------------------------------------------------------
// Section 4: missing table is a no-op but still sets the gate.
// ---------------------------------------------------------------------

echo "\n[shape:4] Missing agents table sets gate without erroring\n";

$options                = array();
$wpdb_d                 = new AgentConfigModelShapeWpdb();
$wpdb_d->table_present  = false;
$wpdb_d->rows['wp_datamachine_agents'] = array();
$wpdb                   = $wpdb_d;
datamachine_migrate_agent_config_model_shape();

assert_equals(
	true,
	get_option( 'datamachine_agent_config_model_shape_migrated' ),
	'gate set even when table is missing',
	$failures,
	$passes
);

echo "\n----------------------------------------\n";
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
