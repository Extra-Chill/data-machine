<?php
/**
 * Pure-PHP coverage for the explicit legacy email-flow migration marker.
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Core\Steps\Fetch\Handlers\Email {
	class EmailAuth {
		public static function legacy_default_marker( int $flow_id, string $flow_step_id, int $agent_id ): string {
			return "signed:{$flow_id}:{$flow_step_id}:{$agent_id}";
		}
	}
}

namespace DataMachine\Core\Database\Flows {
	class Flows {
		public function get_flow_config_json( int $flow_id ): ?string {
			return $GLOBALS['wpdb']->flows[ $flow_id ]['flow_config'] ?? null;
		}
		public function compare_and_swap_flow_config( int $flow_id, string $expected, array $replacement ): bool {
			if ( ! empty( $GLOBALS['migration_conflict_once'] ) ) {
				$GLOBALS['migration_conflict_once'] = false;
				$concurrent = json_decode( $GLOBALS['wpdb']->flows[ $flow_id ]['flow_config'], true );
				$concurrent['step-email']['handler_configs']['email']['folder'] = 'Concurrent Edit';
				$GLOBALS['wpdb']->flows[ $flow_id ]['flow_config'] = json_encode( $concurrent );
				return false;
			}
			if ( $expected !== ( $GLOBALS['wpdb']->flows[ $flow_id ]['flow_config'] ?? null ) ) {
				return false;
			}
			$GLOBALS['wpdb']->flows[ $flow_id ]['flow_config'] = json_encode( $replacement );
			return true;
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );

	$GLOBALS['migration_options'] = array();
	function get_option( string $name, $default = false ) { return $GLOBALS['migration_options'][ $name ] ?? $default; }
	function update_option( string $name, $value, $autoload = null ): bool { $GLOBALS['migration_options'][ $name ] = $value; return true; }
	function absint( $value ): int { return abs( (int) $value ); }
	function wp_json_encode( $value ): string { return json_encode( $value ); }

	class EmailFlowMigrationWpdb {
		public string $prefix = 'wp_';
		public array $flows = array();
		public function get_results( string $query, string $format ): array {
			return array_values( array_filter( $this->flows, static fn ( array $flow ): bool => (int) $flow['agent_id'] > 0 ) );
		}
	}

	$legacy_config = array(
		'step-email' => array(
			'handler_slugs'  => array( 'email' ),
			'handler_configs' => array( 'email' => array( 'folder' => 'INBOX' ) ),
		),
	);
	$new_config = $legacy_config;
	$GLOBALS['wpdb'] = new EmailFlowMigrationWpdb();
	$GLOBALS['wpdb']->flows[91] = array( 'flow_id' => 91, 'agent_id' => 303, 'flow_config' => json_encode( $legacy_config ) );
	$GLOBALS['migration_conflict_once'] = true;

	require_once __DIR__ . '/../inc/migrations/email-flow-auth.php';
	datamachine_migrate_legacy_email_flow_auth();

	$migrated = json_decode( $GLOBALS['wpdb']->flows[91]['flow_config'], true );
	$legacy_marker = $migrated['step-email']['handler_configs']['email']['_legacy_default_auth'] ?? '';
	$passed = 'signed:91:step-email:303' === $legacy_marker
		&& 'Concurrent Edit' === ( $migrated['step-email']['handler_configs']['email']['folder'] ?? '' );
	echo ( $passed ? 'PASS' : 'FAIL' ) . ": existing persisted flow receives bound marker\n";
	echo ( $passed ? 'PASS' : 'FAIL' ) . ": CAS retry preserves concurrent flow edit\n";

	$GLOBALS['wpdb']->flows[92] = array( 'flow_id' => 92, 'agent_id' => 303, 'flow_config' => json_encode( $new_config ) );
	datamachine_migrate_legacy_email_flow_auth();
	$new_after = json_decode( $GLOBALS['wpdb']->flows[92]['flow_config'], true );
	$new_unmarked = ! isset( $new_after['step-email']['handler_configs']['email']['_legacy_default_auth'] );
	echo ( $new_unmarked ? 'PASS' : 'FAIL' ) . ": newly created omission remains unmarked and denied\n";

	if ( ! $passed || ! $new_unmarked ) {
		exit( 1 );
	}
	echo "email-flow-auth-migration-smoke: ok\n";
}
