<?php
/**
 * Static smoke test for recover-stuck CLI output.
 *
 * Run with: php tests/recover-stuck-cli-output-smoke.php
 *
 * @package DataMachine\Tests
 */

$failed = 0;
$total  = 0;

function assert_recover_stuck_cli_output_smoke( string $name, bool $condition, string $detail = '' ): void {
	global $failed, $total;
	++$total;

	if ( $condition ) {
		echo "  [PASS] {$name}\n";
		return;
	}

	echo "  [FAIL] {$name}" . ( $detail ? " - {$detail}" : '' ) . "\n";
	++$failed;
}

$cli_source     = file_get_contents( __DIR__ . '/../inc/Cli/Commands/JobsCommand.php' ) ?: '';
$ability_source = file_get_contents( __DIR__ . '/../inc/Abilities/Job/RecoverStuckJobsAbility.php' ) ?: '';

echo "Case 1: recover-stuck exposes structured output\n";
assert_recover_stuck_cli_output_smoke( 'recover-stuck documents format option', str_contains( $cli_source, '[--format=<format>]' ) );
assert_recover_stuck_cli_output_smoke( 'recover-stuck supports json examples', str_contains( $cli_source, 'recover-stuck --dry-run --format=json' ) );
assert_recover_stuck_cli_output_smoke( 'non-table output uses WP_CLI print_value', str_contains( $cli_source, "if ( 'table' !== \$format )" ) && str_contains( $cli_source, 'WP_CLI::print_value' ) );
assert_recover_stuck_cli_output_smoke( 'structured output includes summary and jobs', str_contains( $cli_source, "'summary'        => \$summary" ) && str_contains( $cli_source, "'jobs'           => \$jobs" ) );
assert_recover_stuck_cli_output_smoke( 'structured output includes truncation metadata', str_contains( $cli_source, "'jobs_omitted'" ) && str_contains( $cli_source, "'jobs_truncated'" ) );
assert_recover_stuck_cli_output_smoke( 'structured output separates input, requested, and effective limits from logical metrics', str_contains( $cli_source, "'limit'          => array(" ) && str_contains( $cli_source, "'input_limit'          => \$summary['input_limit']" ) && str_contains( $cli_source, "'requested_limit'      => \$summary['requested_limit']" ) && str_contains( $cli_source, "'apply_limit'          => \$summary['apply_limit']" ) && str_contains( $cli_source, "'metrics'        => array(" ) && str_contains( $cli_source, "'target_attempts'" ) && str_contains( $cli_source, "'logical_touches'" ) && str_contains( $cli_source, "'logical_mutations'" ) );

echo "Case 2: recover-stuck separates actionable and guarded jobs\n";
assert_recover_stuck_cli_output_smoke( 'summary helper exists', str_contains( $cli_source, 'private function summarize_recover_stuck_result' ) );
assert_recover_stuck_cli_output_smoke( 'summary computes actionable total', str_contains( $cli_source, "'actionable'    => \$pending_ai_terminalized + \$recovered + \$timed_out + \$stale_actions" ) );
assert_recover_stuck_cli_output_smoke( 'summary explicitly includes expired pending AI terminalizations', str_contains( $cli_source, '$pending_ai_terminalized + $recovered' ) );
assert_recover_stuck_cli_output_smoke( 'table headline reports guarded jobs separately', str_contains( $cli_source, 'Found %d recoverable jobs/actions and %d guarded jobs.' ) );
assert_recover_stuck_cli_output_smoke( 'table output reports truncated details', str_contains( $cli_source, 'Output truncated; %d additional job/action details omitted.' ) );
assert_recover_stuck_cli_output_smoke( 'table output names input, requested, and effective limits plus logical metrics', str_contains( $cli_source, 'limit-mode=%s input-limit=%d requested-limit=%d %s apply-limit=%d logical_touch' ) && str_contains( $cli_source, 'target-attempts=%d logical-touches=%d logical-mutations=%d' ) );
assert_recover_stuck_cli_output_smoke( 'help states exact mode target and compound touch contracts', str_contains( $cli_source, 'effective request is one target' ) && str_contains( $cli_source, 'exact compound recovery may consume up to MAX_APPLY_LIMIT=100 logical touches even with --limit=1' ) );

$pathless_branch = strstr( $ability_source, 'if ( is_array( $child_diagnosis ) ) {' ) ?: '';
$pathless_branch = strstr( $pathless_branch, '// Diagnosis can change after claiming', true ) ?: '';
$policy_guard    = strpos( $pathless_branch, 'if ( ! $recover_pathless_children ) {' );
$dry_run_preview = strpos( $pathless_branch, 'if ( $dry_run ) {' );

echo "Case 3: pathless dry-run reporting honors apply authorization\n";
assert_recover_stuck_cli_output_smoke( 'pathless policy guard precedes actionable preview', false !== $policy_guard && false !== $dry_run_preview && $policy_guard < $dry_run_preview );
assert_recover_stuck_cli_output_smoke( 'unauthorized preview is policy-skipped', str_contains( $pathless_branch, '++$skipped;' ) && str_contains( $pathless_branch, '++$pathless_policy_skipped;' ) && str_contains( $pathless_branch, 'pathless_child_apply_policy_required' ) );
assert_recover_stuck_cli_output_smoke( 'authorized preview preserves actionable dispositions', str_contains( $pathless_branch, 'would_requeue_pathless_child' ) && str_contains( $pathless_branch, 'would_transition_pathless_child' ) && str_contains( $pathless_branch, '++$pathless_requeued;' ) && str_contains( $pathless_branch, '++$pathless_terminal;' ) );
assert_recover_stuck_cli_output_smoke( 'dry-run message reports guarded pathless candidates', str_contains( $ability_source, 'guard %d pathless children requiring explicit authorization' ) );

echo "\nRecover-stuck CLI output smoke complete: {$total} assertions, {$failed} failures.\n";
if ( $failed > 0 ) {
	exit( 1 );
}
