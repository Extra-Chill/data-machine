<?php
/**
 * Pure-PHP smoke test for pending-action inspection normalization.
 *
 * Run with: php tests/pending-action-inspection-normalization-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

require_once __DIR__ . '/../inc/Engine/AI/Actions/PendingActionInspectionAbility.php';

$assertions = 0;

function datamachine_pending_inspection_assert( bool $condition, string $message ): void {
	global $assertions;
	++$assertions;
	if ( ! $condition ) {
		fwrite( fopen( 'php://stderr', 'w' ), "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$rows = \DataMachine\Engine\AI\Actions\PendingActionInspectionAbility::normalize_action_rows(
	array(
		array(
			'action_id'   => 'act_bundle_upgrade',
			'kind'        => 'bundle_upgrade',
			'summary'     => 'Upgrade bundle',
			'preview'     => array( 'bundle' => 'example' ),
			'apply_input' => array( 'bundle' => 'example' ),
			'workspace'   => array(
				'workspace_type' => 'wordpress-site',
				'workspace_id'   => 'site-1',
			),
			'agent'       => 'agent:44',
			'creator'     => 'user:9',
			'status'      => 'pending',
			'created_at'  => '2026-06-06T00:00:00+00:00',
			'expires_at'  => null,
			'metadata'    => array(
				'datamachine' => array(
					'audit_context' => array(
						'tool_name'           => 'upgrade_bundle',
						'parameters_redacted' => array(
							'token' => '[redacted]',
						),
						'principal_context'   => array(
							'principal_class'    => 'agent_token',
							'effective_agent_id' => 'agent:44',
						),
					),
					'context'       => array( 'session_id' => 'sess-1' ),
					'resolve_with'  => 'resolve_pending_action',
				),
			),
		),
	)
);

$row = $rows[0] ?? array();

datamachine_pending_inspection_assert( 44 === $row['agent_id'], 'agent_id is flattened from canonical agent ref' );
datamachine_pending_inspection_assert( 9 === $row['created_by'], 'created_by is flattened from canonical creator ref' );
datamachine_pending_inspection_assert( '2026-06-06T00:00:00+00:00' === $row['created_at_iso'], 'created_at_iso is available for CLI tables' );
datamachine_pending_inspection_assert( 'wordpress-site' === $row['workspace_type'], 'workspace_type is flattened for dashboards' );
datamachine_pending_inspection_assert( 'site-1' === $row['workspace_id'], 'workspace_id is flattened for dashboards' );
datamachine_pending_inspection_assert( 'sess-1' === $row['context']['session_id'], 'Data Machine context is surfaced' );
datamachine_pending_inspection_assert( 'agent_token' === $row['principal_context']['principal_class'], 'safe principal context is surfaced' );
datamachine_pending_inspection_assert( '[redacted]' === $row['audit_context']['parameters_redacted']['token'], 'redacted audit parameters are preserved' );
datamachine_pending_inspection_assert( 'resolve_pending_action' === $row['resolve_with'], 'frontend resolver hint is present' );
datamachine_pending_inspection_assert( 'act_bundle_upgrade' === $row['resolve_params']['action_id'], 'frontend resolve params include action id' );

echo "OK ({$assertions} assertions)\n";
