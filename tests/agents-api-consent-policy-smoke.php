<?php
/**
 * Smoke coverage for Data Machine's Agents API consent policy adapter.
 *
 * Run with: php tests/agents-api-consent-policy-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require_once __DIR__ . '/agents-api-loader.php';
datamachine_tests_require_agents_api();

require_once dirname( __DIR__ ) . '/inc/Engine/AI/DataMachineAgentConsentPolicy.php';

use AgentsAPI\AI\Consent\AgentConsentOperation;
use DataMachine\Engine\AI\DataMachineAgentConsentPolicy;

$failures = array();
$passes   = 0;

echo "agents-api-consent-policy-smoke\n";

$assert_true = static function ( bool $condition, string $label ) use ( &$failures, &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "PASS: {$label}\n";
		return;
	}

	$failures[] = $label;
	echo "FAIL: {$label}\n";
};

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $_hook_name, $value ) {
		return $value;
	}
}

$policy = DataMachineAgentConsentPolicy::get();

$assert_true( $policy instanceof WP_Agent_Consent_Policy_Interface, 'Data Machine exposes the Agents API consent policy interface' );
$assert_true( AgentConsentOperation::STORE_MEMORY === 'store_memory', 'Agents API memory operation vocabulary is installed' );
$assert_true( AgentConsentOperation::STORE_TRANSCRIPT === 'store_transcript', 'Agents API transcript operation vocabulary is installed' );

$memory_decision = $policy->can_store_memory(
	array(
		'mode'               => 'ability',
		'interactive'        => true,
		'permission_granted' => true,
		'agent_id'           => 3,
		'user_id'            => 7,
	)
);
$assert_true( $memory_decision->is_allowed(), 'memory storage consent follows Data Machine memory permissions' );
$assert_true( AgentConsentOperation::STORE_MEMORY === $memory_decision->operation(), 'memory decision uses Agents API store_memory operation' );

$pipeline_default = $policy->can_store_transcript(
	array(
		'mode'        => 'pipeline',
		'interactive' => false,
		'agent_id'    => 3,
		'user_id'     => 7,
	)
);
$assert_true( ! $pipeline_default->is_allowed(), 'non-interactive transcript storage remains denied by default' );
$assert_true( AgentConsentOperation::STORE_TRANSCRIPT === $pipeline_default->operation(), 'pipeline decision uses Agents API store_transcript operation' );

$pipeline_opt_in = $policy->can_store_transcript(
	array(
		'mode'                => 'pipeline',
		'interactive'         => false,
		'persist_transcript'  => true,
		'configured_setting'  => 'datamachine_persist_pipeline_transcripts',
		'agent_id'            => 3,
		'user_id'             => 7,
	)
);
$assert_true( $pipeline_opt_in->is_allowed(), 'non-interactive transcript storage is allowed only when configured' );
$assert_true( 'datamachine_transcript_opt_in' === $pipeline_opt_in->reason(), 'configured pipeline transcript consent has stable audit reason' );

$chat_decision = $policy->can_store_transcript(
	array(
		'mode'        => 'chat',
		'interactive' => true,
		'agent_id'    => 3,
		'user_id'     => 7,
	)
);
$assert_true( $chat_decision->is_allowed(), 'interactive chat transcript storage preserves chat session behavior' );

$share_default = $policy->can_share_transcript( array( 'mode' => 'chat', 'interactive' => true ) );
$assert_true( ! $share_default->is_allowed(), 'transcript sharing is independently denied without explicit consent' );
$assert_true( AgentConsentOperation::SHARE_TRANSCRIPT === $share_default->operation(), 'sharing decision uses Agents API share_transcript operation' );

$escalation_default = $policy->can_escalate_to_human( array( 'mode' => 'chat', 'interactive' => true ) );
$assert_true( ! $escalation_default->is_allowed(), 'human escalation is independently denied without explicit consent' );
$assert_true( AgentConsentOperation::ESCALATE_TO_HUMAN === $escalation_default->operation(), 'escalation decision uses Agents API escalate_to_human operation' );

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " Data Machine consent policy assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} Data Machine consent policy assertions passed.\n";
