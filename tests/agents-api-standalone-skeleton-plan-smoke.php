<?php
/**
 * Static smoke test for the Agents API standalone skeleton plan (#1618).
 *
 * Run with: php tests/agents-api-standalone-skeleton-plan-smoke.php
 *
 * @package DataMachine\Tests
 */

$failures = array();
$passes   = 0;

echo "agents-api-standalone-skeleton-plan-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';

$doc_path = realpath( __DIR__ . '/../docs/development/agents-api-standalone-skeleton-plan.md' );
agents_api_smoke_assert_equals( true, is_string( $doc_path ), 'standalone skeleton plan doc exists', $failures, $passes );

$contents = is_string( $doc_path ) ? file_get_contents( $doc_path ) : '';
$contents = is_string( $contents ) ? $contents : '';

$required_sections = array(
 '# Agents API Standalone Skeleton Plan',
 '## Directory Shape',
 '## Plugin Bootstrap',
 '## Public Names',
 '## Dependency Policy',
 '## Explicit Non-Goals For V1',
 '## First Files To Move',
 '## Keep In Data Machine For Now',
 '## Extraction Sequence',
 '## Acceptance Tests For The First Extraction PR',
 '## Blockers Before #1596 Can Start',
 '## Review Checklist',
);

foreach ( $required_sections as $section ) {
	agents_api_smoke_assert_equals( true, false !== strpos( $contents, $section ), 'plan includes section ' . $section, $failures, $passes );
}

$required_decisions = array(
 'wp_agents_api_init',
 'wp_register_agent()',
 'WP_Agent',
 'WP_Agents_Registry',
 'AgentsAPI\\AI\\WP_Agent_Message',
 'AgentsAPI\\AI\\WP_Agent_Conversation_Result',
 'AgentsAPI\\AI\\Tools\\WP_Agent_Tool_Declaration',
 'AgentsAPI\\Core\\Database\\Chat\\WP_Agent_Conversation_Store',
 'AgentsAPI\\Core\\FilesRepository\\WP_Agent_Memory_Store',
 'No `wp-agents/v1` REST routes.',
 'No admin UI, React app, settings screen, list table, or agent CRUD screen.',
 'Data Machine may depend on `agents-api`; `agents-api` must not depend on Data Machine.',
 'Provider runtime work should target `wp-ai-client` directly.',
 'Do not reintroduce `chubes4/ai-http-client`, `chubes_ai_request`, `chubes_ai_providers`, `chubes_ai_models`, or `chubes_ai_provider_api_keys` into the skeleton.',
);

foreach ( $required_decisions as $decision ) {
	agents_api_smoke_assert_equals( true, false !== strpos( $contents, $decision ), 'plan records decision: ' . $decision, $failures, $passes );
}

$current_module_files = array(
 'agents-api.php',
 'inc/class-wp-agent.php',
 'inc/class-wp-agents-registry.php',
 'inc/register-agents.php',
 'inc/class-wp-agent-package.php',
 'inc/class-wp-agent-package-artifact.php',
 'inc/class-wp-agent-package-artifact-type.php',
 'inc/class-wp-agent-package-artifacts-registry.php',
 'inc/class-wp-agent-package-adoption-diff.php',
 'inc/class-wp-agent-package-adoption-result.php',
  'inc/class-wp-agent-package-adopter.php',
 'inc/register-agent-package-artifacts.php',
 'inc/AI/WP_Agent_Message.php',
 'inc/AI/WP_Agent_Conversation_Result.php',
 'inc/AI/Tools/WP_Agent_Tool_Declaration.php',
 'inc/Core/Database/Chat/WP_Agent_Conversation_Store.php',
 'inc/Core/FilesRepository/WP_Agent_Memory_Scope.php',
 'inc/Core/FilesRepository/WP_Agent_Memory_List_Entry.php',
 'inc/Core/FilesRepository/WP_Agent_Memory_Read_Result.php',
 'inc/Core/FilesRepository/WP_Agent_Memory_Write_Result.php',
 'inc/Core/FilesRepository/WP_Agent_Memory_Store.php',
);


$agents_api_vendor_path = dirname( datamachine_tests_agents_api_bootstrap_path() );
foreach ( $current_module_files as $relative_path ) {
	agents_api_smoke_assert_equals(
		true,
		file_exists( $agents_api_vendor_path . '/' . $relative_path ),
		'standalone dependency file exists: ' . $relative_path,
		$failures,
		$passes
	);
	agents_api_smoke_assert_equals( true, false !== strpos( $contents, $relative_path ), 'plan references current module file: ' . $relative_path, $failures, $passes );
}

$required_acceptance_proofs = array(
 'Standalone boot',
 'Product boundary',
 'Data Machine pipeline behavior',
 'Intelligence wiki behavior',
 'Memory store seam',
 'wp-ai-client gate',
);

foreach ( $required_acceptance_proofs as $proof ) {
	agents_api_smoke_assert_equals( true, false !== strpos( $contents, $proof ), 'plan defines acceptance proof: ' . $proof, $failures, $passes );
}

$forbidden_claims = array(
 'Create the standalone plugin in this PR',
 'Implement REST routes in v1',
 'Agents API owns Data Machine flows',
 'Agents API owns Data Machine jobs',
 'Agents API owns Intelligence wiki',
);

foreach ( $forbidden_claims as $claim ) {
	agents_api_smoke_assert_equals( false, false !== strpos( $contents, $claim ), 'plan avoids forbidden claim: ' . $claim, $failures, $passes );
}

agents_api_smoke_finish( 'Agents API standalone skeleton plan', $failures, $passes );
