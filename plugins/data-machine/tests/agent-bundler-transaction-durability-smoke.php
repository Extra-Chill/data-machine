<?php
/**
 * Source contract smoke for bundle transaction durability.
 *
 * Run with: php tests/agent-bundler-transaction-durability-smoke.php
 *
 * @package DataMachine\Tests
 */

$source = file_get_contents( dirname( __DIR__ ) . '/inc/Core/Agents/AgentBundler.php' );
if ( ! is_string( $source ) ) {
	exit( 1 );
}

$checks = array(
	'COMMIT failure throws rather than returning success' => str_contains( $source, "if ( false === \$wpdb->query( 'COMMIT' ) )" ) && str_contains( $source, 'Database COMMIT failed:' ),
	'normal import surfaces rollback diagnostics' => str_contains( $source, 'Agent install rolled back: %s' ) && str_contains( $source, 'Rollback also failed: %s' ),
	'graph import surfaces rollback diagnostics' => str_contains( $source, 'Subagent graph install rolled back: %s' ) && str_contains( $source, 'commit_transaction( $transaction_started );' ),
);

$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ": {$label}\n";
	if ( ! $passed ) {
		++$failed;
	}
}

exit( $failed ? 1 : 0 );
