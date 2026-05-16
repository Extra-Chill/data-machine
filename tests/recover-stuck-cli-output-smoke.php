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

$source = file_get_contents( __DIR__ . '/../inc/Cli/Commands/JobsCommand.php' ) ?: '';

echo "Case 1: recover-stuck exposes structured output\n";
assert_recover_stuck_cli_output_smoke( 'recover-stuck documents format option', str_contains( $source, '[--format=<format>]' ) );
assert_recover_stuck_cli_output_smoke( 'recover-stuck supports json examples', str_contains( $source, 'recover-stuck --dry-run --format=json' ) );
assert_recover_stuck_cli_output_smoke( 'non-table output uses WP_CLI print_value', str_contains( $source, "if ( 'table' !== \$format )" ) && str_contains( $source, 'WP_CLI::print_value' ) );
assert_recover_stuck_cli_output_smoke( 'structured output includes summary and jobs', str_contains( $source, "'summary' => \$summary" ) && str_contains( $source, "'jobs'    => \$jobs" ) );

echo "Case 2: recover-stuck separates actionable and guarded jobs\n";
assert_recover_stuck_cli_output_smoke( 'summary helper exists', str_contains( $source, 'private function summarize_recover_stuck_result' ) );
assert_recover_stuck_cli_output_smoke( 'summary computes actionable total', str_contains( $source, "'actionable'    => \$recovered + \$timed_out + \$stale_actions" ) );
assert_recover_stuck_cli_output_smoke( 'table headline reports guarded jobs separately', str_contains( $source, 'Found %d recoverable jobs/actions and %d guarded jobs.' ) );

echo "\nRecover-stuck CLI output smoke complete: {$total} assertions, {$failed} failures.\n";
if ( $failed > 0 ) {
	exit( 1 );
}
