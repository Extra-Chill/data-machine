<?php
/**
 * Pure-PHP smoke test for PendingAction.apply() honoring rebased artifacts (#1832).
 *
 * Run with: php tests/agent-bundle-pending-action-rebase-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace {

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$GLOBALS['__rebase_pa_filters'] = array();

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__rebase_pa_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
		ksort( $GLOBALS['__rebase_pa_filters'][ $hook ], SORT_NUMERIC );
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		if ( empty( $GLOBALS['__rebase_pa_filters'][ $hook ] ) ) {
			return $value;
		}
		foreach ( $GLOBALS['__rebase_pa_filters'][ $hook ] as $callbacks ) {
			foreach ( $callbacks as $registration ) {
				$value = call_user_func_array( $registration[0], array_slice( array_merge( array( $value ), $args ), 0, $registration[1] ) );
			}
		}
		return $value;
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return false;
	}
}

require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/BundleSchema.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/AgentBundleArtifactExtensions.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/AgentBundleArtifactHasher.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/AgentBundleArtifactRebase.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/AgentBundleUpgradePendingAction.php';

use DataMachine\Engine\Bundle\AgentBundleArtifactRebase;
use DataMachine\Engine\Bundle\AgentBundleUpgradePendingAction;

$failures = 0;
$total    = 0;

function pa_rebase_assert( string $label, bool $condition ): void {
	global $failures, $total;
	++$total;
	if ( $condition ) {
		echo "  PASS: {$label}\n";
		return;
	}
	echo "  FAIL: {$label}\n";
	++$failures;
}

function pa_rebase_assert_equals( string $label, $expected, $actual ): void {
	$ok = $expected === $actual;
	pa_rebase_assert( $label, $ok );
	if ( ! $ok ) {
		echo "    expected: " . var_export( $expected, true ) . "\n";
		echo "    actual:   " . var_export( $actual, true ) . "\n";
	}
}

echo "=== Agent Bundle PendingAction Rebase Apply Smoke (#1832) ===\n";

$captured_payloads = array();
add_filter(
	'datamachine_bundle_upgrade_apply_artifact',
	static function ( $result, array $artifact ) use ( &$captured_payloads ) {
		$key                       = ( $artifact['artifact_type'] ?? '' ) . ':' . ( $artifact['artifact_id'] ?? '' );
		$captured_payloads[ $key ] = $artifact['payload'];
		return array( 'wrote' => $key, 'hash' => $artifact['hash'] ?? null );
	},
	10,
	2
);

// Build a rebased entry for the locally-modified flow:install case.
$rebase = AgentBundleArtifactRebase::rebase(
	array(
		'artifact_type' => 'flow',
		'artifact_id'   => 'demo',
		'base'          => array(
			'flow_config' => array(
				'1_step_1' => array(
					'handler'        => 'github',
					'handler_config' => array( 'owner' => 'old', 'max_items' => 25 ),
				),
			),
		),
		'local' => array(
			'flow_config' => array(
				'1_step_1' => array(
					'handler'        => 'github',
					'handler_config' => array( 'owner' => 'old', 'max_items' => 1 ),
				),
			),
		),
		'remote' => array(
			'flow_config' => array(
				'1_step_1' => array(
					'handler'        => 'github-a8c',
					'handler_config' => array( 'owner' => 'Automattic', 'max_items' => 25 ),
				),
			),
		),
	),
	AgentBundleArtifactRebase::POLICY_BURN_IN_SAFE
);

pa_rebase_assert( 'rebase produced unambiguous merge', false === $rebase['requires_approval'] );

$apply_input = array(
	'agent'              => array( 'agent_id' => 7 ),
	'approved_artifacts' => array( 'flow:demo' ),
	'target_artifacts'   => array(
		array(
			'artifact_type' => 'flow',
			'artifact_id'   => 'demo',
			'source_path'   => 'flows/demo.json',
			'payload'       => array(
				'flow_config' => array(
					'1_step_1' => array(
						'handler'        => 'github-a8c',
						'handler_config' => array( 'owner' => 'Automattic', 'max_items' => 25 ),
					),
				),
			),
		),
	),
	'rebased_artifacts'  => array( $rebase ),
);

$result = AgentBundleUpgradePendingAction::apply( $apply_input );

pa_rebase_assert( 'apply success', true === ( $result['success'] ?? false ) );
pa_rebase_assert_equals( 'apply count', 1, count( $result['applied'] ?? array() ) );
pa_rebase_assert_equals( 'no failures', 0, count( $result['failed'] ?? array() ) );

$applied_entry = $result['applied'][0] ?? array();
pa_rebase_assert_equals( 'rebase metadata recorded on apply', AgentBundleArtifactRebase::POLICY_BURN_IN_SAFE, $applied_entry['rebase']['policy'] ?? null );
pa_rebase_assert( 'rebase metadata includes merged_hash', is_string( $applied_entry['rebase']['merged_hash'] ?? null ) );

// The captured payload should be the merged payload, not the wholesale target.
$captured = $captured_payloads['flow:demo'] ?? array();
pa_rebase_assert_equals(
	'apply handler received merged payload (max_items kept local)',
	1,
	$captured['flow_config']['1_step_1']['handler_config']['max_items'] ?? null
);
pa_rebase_assert_equals(
	'apply handler received merged payload (owner from remote)',
	'Automattic',
	$captured['flow_config']['1_step_1']['handler_config']['owner'] ?? null
);
pa_rebase_assert_equals(
	'apply handler received merged payload (handler from remote)',
	'github-a8c',
	$captured['flow_config']['1_step_1']['handler'] ?? null
);

// Sanity: with no rebased entry, apply receives the raw target (legacy path).
$captured_payloads = array();
$result_legacy     = AgentBundleUpgradePendingAction::apply(
	array(
		'agent'              => array( 'agent_id' => 7 ),
		'approved_artifacts' => array( 'flow:demo' ),
		'target_artifacts'   => $apply_input['target_artifacts'],
	)
);
pa_rebase_assert( 'legacy apply still succeeds without rebased_artifacts', true === ( $result_legacy['success'] ?? false ) );
pa_rebase_assert_equals(
	'legacy apply receives raw target max_items',
	25,
	$captured_payloads['flow:demo']['flow_config']['1_step_1']['handler_config']['max_items'] ?? null
);

// Ambiguous rebase entries are not applied even if approved.
$ambiguous_rebase = AgentBundleArtifactRebase::rebase(
	array(
		'artifact_type' => 'flow',
		'artifact_id'   => 'tricky',
		'base'          => array( 'flow_config' => array( '1_a_1' => array( 'handler_config' => array( 'tool' => 'old' ) ) ) ),
		'local'         => array( 'flow_config' => array( '1_a_1' => array( 'handler_config' => array( 'tool' => 'local' ) ) ) ),
		'remote'        => array( 'flow_config' => array( '1_a_1' => array( 'handler_config' => array( 'tool' => 'remote' ) ) ) ),
	),
	AgentBundleArtifactRebase::POLICY_BURN_IN_SAFE
);
pa_rebase_assert( 'tricky case is ambiguous', true === $ambiguous_rebase['requires_approval'] );

$captured_payloads = array();
$tricky_apply      = AgentBundleUpgradePendingAction::apply(
	array(
		'agent'              => array( 'agent_id' => 7 ),
		'approved_artifacts' => array( 'flow:tricky' ),
		'target_artifacts'   => array(
			array(
				'artifact_type' => 'flow',
				'artifact_id'   => 'tricky',
				'source_path'   => 'flows/tricky.json',
				'payload'       => array( 'flow_config' => array( '1_a_1' => array( 'handler_config' => array( 'tool' => 'remote' ) ) ) ),
			),
		),
		'rebased_artifacts'  => array( $ambiguous_rebase ),
	)
);
pa_rebase_assert(
	'ambiguous rebase entry does not override target on apply',
	'remote' === ( $captured_payloads['flow:tricky']['flow_config']['1_a_1']['handler_config']['tool'] ?? null )
);

echo "\nTotal assertions: {$total}\n";
if ( 0 !== $failures ) {
	echo "Failures: {$failures}\n";
	exit( 1 );
}
echo "All assertions passed.\n";

}
