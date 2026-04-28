<?php
/**
 * Pure-PHP smoke test for installed bundle artifact persistence (#1531).
 *
 * Run with: php tests/agent-bundle-artifact-store-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook = '' ) {
		return 0;
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( $hook = '' ) {
		return false;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {
		// no-op.
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$args ) {
		// no-op.
	}
}

if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( $sql ) {
		$GLOBALS['datamachine_bundle_artifact_store_dbdelta_sql'] = $sql;
		return array();
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use DataMachine\Core\Database\BundleArtifacts\InstalledBundleArtifacts;
use DataMachine\Engine\Bundle\AgentBundleArtifactHasher;
use DataMachine\Engine\Bundle\AgentBundleArtifactStatus;
use DataMachine\Engine\Bundle\AgentBundleManifest;

final class BundleArtifactStoreFakeWpdb {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public array $rows = array();
	private int $next_id = 1;

	public function get_charset_collate(): string {
		return 'DEFAULT CHARSET=utf8mb4';
	}

	public function prepare( string $query, ...$args ): array {
		return array(
			'query' => $query,
			'args'  => $args,
		);
	}

	public function replace( string $table, array $row, array $format ) {
		$id = isset( $row['artifact_record_id'] ) ? (int) $row['artifact_record_id'] : 0;
		if ( $id <= 0 ) {
			$id = $this->next_id++;
		}
		$row['artifact_record_id'] = $id;
		$this->rows[ $id ]         = $row;

		return 1;
	}

	public function get_var( $prepared ) {
		$args = $prepared['args'] ?? array();
		if ( count( $args ) < 5 ) {
			return null;
		}

		foreach ( $this->rows as $row ) {
			if ( $this->matches_identity( $row, (int) $args[1], (string) $args[2], (string) $args[3], (string) $args[4] ) ) {
				return $row['artifact_record_id'];
			}
		}

		return null;
	}

	public function get_row( $prepared, $output = ARRAY_A ) {
		$args = $prepared['args'] ?? array();
		foreach ( $this->rows as $row ) {
			if ( $this->matches_identity( $row, (int) $args[1], (string) $args[2], (string) $args[3], (string) $args[4] ) ) {
				return $row;
			}
		}

		return null;
	}

	public function get_results( $prepared, $output = ARRAY_A ): array {
		$query = $prepared['query'] ?? '';
		$args  = $prepared['args'] ?? array();
		$rows  = array();

		foreach ( $this->rows as $row ) {
			if ( str_contains( $query, 'bundle_slug = %s' ) ) {
				if ( (int) $row['agent_id'] !== (int) $args[1] || (string) $row['bundle_slug'] !== (string) $args[2] ) {
					continue;
				}
			} elseif ( (int) $row['agent_id'] !== (int) $args[1] ) {
				continue;
			}

			$rows[] = $row;
		}

		usort(
			$rows,
			static fn( array $a, array $b ): int => ( $a['bundle_slug'] <=> $b['bundle_slug'] ) ?: ( $a['artifact_type'] <=> $b['artifact_type'] ) ?: ( $a['artifact_id'] <=> $b['artifact_id'] )
		);

		return $rows;
	}

	public function delete( string $table, array $where, array $format ) {
		foreach ( $this->rows as $id => $row ) {
			if ( $this->matches_identity( $row, (int) $where['agent_id'], (string) $where['bundle_slug'], (string) $where['artifact_type'], (string) $where['artifact_id'] ) ) {
				unset( $this->rows[ $id ] );
				return 1;
			}
		}

		return 0;
	}

	private function matches_identity( array $row, int $agent_id, string $bundle_slug, string $artifact_type, string $artifact_id ): bool {
		return (int) $row['agent_id'] === $agent_id
			&& (string) $row['bundle_slug'] === $bundle_slug
			&& (string) $row['artifact_type'] === $artifact_type
			&& (string) $row['artifact_id'] === $artifact_id;
	}
}

$failures = 0;
$total    = 0;

function assert_bundle_artifact_store( string $label, bool $condition ): void {
	global $failures, $total;
	++$total;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}
	echo "  FAIL: {$label}\n";
	++$failures;
}

function assert_bundle_artifact_store_equals( string $label, $expected, $actual ): void {
	assert_bundle_artifact_store( $label, $expected === $actual );
}

echo "=== Agent Bundle Artifact Store Smoke (#1531) ===\n";

$GLOBALS['wpdb'] = new BundleArtifactStoreFakeWpdb();

$manifest = AgentBundleManifest::from_array(
	array(
		'schema_version' => 1,
		'bundle_slug'    => 'WPCOM History',
		'bundle_version' => '2026.04.28',
		'exported_at'    => '2026-04-28T00:00:00Z',
		'exported_by'    => 'data-machine/test',
		'agent'          => array(
			'slug'         => 'wpcom-agent',
			'label'        => 'WPCOM Agent',
			'description'  => 'Maintains WPCOM history.',
			'agent_config' => array(),
		),
		'included'       => array(
			'memory'       => array(),
			'pipelines'    => array(),
			'flows'        => array(),
			'handler_auth' => 'refs',
		),
	)
);

$store = new InstalledBundleArtifacts();

echo "\n[1] Table schema tracks bundle artifacts independently\n";
InstalledBundleArtifacts::create_table();
$sql = $GLOBALS['datamachine_bundle_artifact_store_dbdelta_sql'] ?? '';
foreach ( array( 'datamachine_bundle_artifacts', 'agent_id', 'bundle_slug', 'bundle_version', 'artifact_type', 'artifact_id', 'source_path', 'installed_hash', 'current_hash', 'local_status', 'installed_at', 'updated_at' ) as $needle ) {
	assert_bundle_artifact_store( "schema includes {$needle}", str_contains( $sql, $needle ) );
}
assert_bundle_artifact_store( 'schema has stable artifact identity key', str_contains( $sql, 'UNIQUE KEY artifact_identity (agent_id, bundle_slug, artifact_type, artifact_id)' ) );

echo "\n[2] Install records store hashes, not payloads or secrets\n";
$installed_payload = array(
	'name'   => 'Historical digest',
	'config' => array(
		'query'     => 'WordPress.com launch',
		'api_token' => 'super-secret-token',
	),
);
$installed         = $store->record_install( $manifest, 'prompt', 'launch-history', 'prompts/launch-history.md', $installed_payload, 42, '2026-04-28 00:00:00' );
$stored_row        = array_values( $GLOBALS['wpdb']->rows )[0];
assert_bundle_artifact_store_equals( 'record install starts clean', AgentBundleArtifactStatus::CLEAN, $installed->to_array()['status'] );
assert_bundle_artifact_store_equals( 'agent id scopes installed row', 42, $stored_row['agent_id'] );
assert_bundle_artifact_store_equals( 'bundle slug normalized in stored row', 'wpcom-history', $stored_row['bundle_slug'] );
assert_bundle_artifact_store_equals( 'installed hash persisted', AgentBundleArtifactHasher::hash( $installed_payload ), $stored_row['installed_hash'] );
assert_bundle_artifact_store( 'raw payload is not stored', ! array_key_exists( 'payload', $stored_row ) );
assert_bundle_artifact_store( 'secret value is not stored', ! str_contains( json_encode( $stored_row ) ?: '', 'super-secret-token' ) );

echo "\n[3] Refreshing current payload distinguishes clean, modified, and missing\n";
$clean = $store->refresh_current_payload( 'wpcom-history', 'prompt', 'launch-history', $installed_payload, 42, '2026-04-28 01:00:00' );
assert_bundle_artifact_store_equals( 'same current payload remains clean', AgentBundleArtifactStatus::CLEAN, $clean?->to_array()['status'] ?? null );

$modified_payload = array(
	'name'   => 'Edited digest',
	'config' => array( 'query' => 'WordPress.com edited launch' ),
);
$modified         = $store->refresh_current_payload( 'wpcom-history', 'prompt', 'launch-history', $modified_payload, 42, '2026-04-28 02:00:00' );
assert_bundle_artifact_store_equals( 'changed current payload is modified', AgentBundleArtifactStatus::MODIFIED, $modified?->to_array()['status'] ?? null );
assert_bundle_artifact_store_equals( 'stored local_status mirrors modified status', AgentBundleArtifactStatus::MODIFIED, array_values( $GLOBALS['wpdb']->rows )[0]['local_status'] );

$missing = $store->refresh_current_payload( 'wpcom-history', 'prompt', 'launch-history', null, 42, '2026-04-28 03:00:00' );
assert_bundle_artifact_store_equals( 'null current payload is missing', AgentBundleArtifactStatus::MISSING, $missing?->to_array()['status'] ?? null );
assert_bundle_artifact_store_equals( 'missing row stores null current hash', null, array_values( $GLOBALS['wpdb']->rows )[0]['current_hash'] );

echo "\n[4] Lookup/list/delete APIs preserve artifact identity\n";
$store->record_install( $manifest, 'flow', 'launch-loop', 'flows/launch-loop.json', array( 'steps' => array( 'fetch', 'ai' ) ), 42, '2026-04-28 00:00:00' );
$store->record_install( $manifest, 'pipeline', 'launch-pipeline', 'pipelines/launch-pipeline.json', array( 'steps' => array( 'fetch' ) ), 7, '2026-04-28 00:00:00' );
assert_bundle_artifact_store_equals( 'get returns scoped artifact', 'prompt', $store->get( 'wpcom-history', 'prompt', 'launch-history', 42 )?->to_array()['artifact_type'] ?? null );
assert_bundle_artifact_store_equals( 'wrong agent scope does not leak artifact', null, $store->get( 'wpcom-history', 'prompt', 'launch-history', 7 ) );
assert_bundle_artifact_store_equals( 'list_for_bundle returns only scoped bundle rows', 2, count( $store->list_for_bundle( 'wpcom-history', 42 ) ) );
assert_bundle_artifact_store_equals( 'list_for_agent returns only agent rows', 1, count( $store->list_for_agent( 7 ) ) );
assert_bundle_artifact_store( 'delete succeeds', $store->delete( 'wpcom-history', 'flow', 'launch-loop', 42 ) );
assert_bundle_artifact_store_equals( 'deleted artifact no longer resolves', null, $store->get( 'wpcom-history', 'flow', 'launch-loop', 42 ) );

echo "\n[5] Orphan helper captures untracked runtime state without installed hash\n";
$orphaned = InstalledBundleArtifacts::orphaned_artifact( 'wpcom-history', '2026.04.28', 'tool_policy', 'publisher-policy', 'tool-policies/publisher-policy.json', array( 'tools' => array( 'publish' ) ), '2026-04-28 04:00:00' )->to_array();
assert_bundle_artifact_store_equals( 'orphaned helper classifies orphaned state', AgentBundleArtifactStatus::ORPHANED, $orphaned['status'] );
assert_bundle_artifact_store_equals( 'orphaned helper has no installed hash', null, $orphaned['installed_hash'] );
assert_bundle_artifact_store( 'orphaned helper hashes current payload', is_string( $orphaned['current_hash'] ) && 64 === strlen( $orphaned['current_hash'] ) );

echo "\nTotal assertions: {$total}\n";
// @phpstan-ignore-next-line Smoke assertion counter is mutated through helper calls at runtime.
if ( 0 !== $failures ) {
	echo "Failures: {$failures}\n";
	exit( 1 );
}

echo "All assertions passed.\n";
