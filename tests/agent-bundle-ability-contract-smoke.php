<?php
/**
 * Smoke test for ability-first agent bundle lifecycle contract (#1537).
 *
 * Run with: php tests/agent-bundle-ability-contract-smoke.php
 *
 * @package DataMachine\Tests
 */

$root     = dirname( __DIR__ );
$failures = array();
$passes   = 0;

function datamachine_bundle_ability_assert( bool $condition, string $label, array &$failures, int &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "  PASS {$label}\n";
		return;
	}

	$failures[] = $label;
	echo "  FAIL {$label}\n";
}

function datamachine_bundle_ability_contains( string $source, string $needle, string $label, array &$failures, int &$passes ): void {
	datamachine_bundle_ability_assert( false !== strpos( $source, $needle ), $label, $failures, $passes );
}

$abilities = (string) file_get_contents( $root . '/inc/Abilities/AgentAbilities.php' );
$cli       = (string) file_get_contents( $root . '/inc/Cli/Commands/AgentBundleCommand.php' );
$service   = (string) file_get_contents( $root . '/inc/Engine/Bundle/AgentBundleAbilityService.php' );

echo "agent-bundle-ability-contract-smoke\n";

echo "\n[1] Abilities expose the lifecycle contract\n";
foreach ( array(
	'datamachine/list-agent-bundles'                  => 'installed bundle listing ability registered',
	'datamachine/get-agent-bundle-status'             => 'bundle status ability registered',
	'datamachine/plan-agent-bundle-upgrade'           => 'upgrade planning ability registered',
	'datamachine/rebase-agent-bundle-artifacts'       => 'artifact rebase ability registered',
	'datamachine/apply-agent-bundle-upgrade'          => 'upgrade apply ability registered',
	'datamachine/resolve-agent-bundle-upgrade-action' => 'PendingAction resolution ability registered',
) as $needle => $label ) {
	datamachine_bundle_ability_contains( $abilities, $needle, $label, $failures, $passes );
}

echo "\n[2] Abilities are backed by one lifecycle service\n";
foreach ( array(
	'AgentBundleAbilityService'                  => 'ability callbacks use lifecycle service',
	'AgentBundleUpgradePlanner::plan'            => 'service owns upgrade planning',
	'AgentBundleUpgradePendingAction::stage'     => 'service owns approval staging',
	'ResolvePendingActionAbility::execute'       => 'service owns PendingAction apply bridge',
	'AgentBundleArtifactExtensions::current_artifacts' => 'service owns extension current-state collection',
) as $needle => $label ) {
	datamachine_bundle_ability_contains( $service . $abilities, $needle, $label, $failures, $passes );
}

echo "\n[3] WP-CLI wraps ability callbacks instead of duplicating public lifecycle logic\n";
foreach ( array(
	'AgentAbilities::listAgentBundles'              => 'installed command calls list ability callback',
	'AgentAbilities::getAgentBundleStatus'          => 'status command calls status ability callback',
	'AgentAbilities::planAgentBundleUpgrade'        => 'diff command calls plan ability callback',
	'AgentAbilities::applyAgentBundleUpgrade'       => 'upgrade command calls apply ability callback',
	'AgentAbilities::rebaseAgentBundleArtifacts'    => 'rebase command calls rebase ability callback',
	'AgentAbilities::resolveAgentBundleUpgradeAction' => 'apply command calls PendingAction ability callback',
) as $needle => $label ) {
	datamachine_bundle_ability_contains( $cli, $needle, $label, $failures, $passes );
}

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " ability contract assertion(s) failed.\n";
	exit( 1 );
}

echo "\nAgent bundle ability contract smoke passed ({$passes} assertions).\n";
