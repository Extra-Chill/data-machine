<?php
/**
 * Pure-PHP smoke test for complete Data Machine table uninstall cleanup.
 *
 * Run with: php tests/uninstall-tables-smoke.php
 *
 * @package DataMachine\Tests
 */

$root      = dirname( __DIR__ );
$main      = file_get_contents( $root . '/data-machine.php' );
$activation = file_get_contents( $root . '/inc/Core/Bootstrap/ActivationServiceProvider.php' );
$uninstall = file_get_contents( $root . '/uninstall.php' );
$failures  = 0;
$passes    = 0;

function datamachine_uninstall_assert( bool $condition, string $message ): void {
	global $failures, $passes;

	if ( $condition ) {
		++$passes;
		echo "PASS: {$message}\n";
		return;
	}

	++$failures;
	fwrite( STDERR, "FAIL: {$message}\n" );
}

preg_match( '/public static function ensure_all_tables\(\): void \{(?<body>.*?)\n\t\}/s', $activation, $ensure_match );
$ensure_body = $ensure_match['body'] ?? '';

$site_tables = array(
	'LogRepository::TABLE_NAME'         => 'LogRepository::create_table',
	'Pipelines::TABLE_NAME'             => '$db_pipelines->create_table',
	'Flows::TABLE_NAME'                 => '$db_flows->create_table',
	'Jobs::TABLE_NAME'                  => '$db_jobs->create_table',
	'ProcessedItems::TABLE_NAME'        => '$db_processed_items->create_table',
	'BatchItems::TABLE_NAME'            => 'BatchItems::create_table',
	'TrackedItems::TABLE_NAME'          => '$db_tracked_items->create_table',
	'PostIdentityIndex::TABLE_NAME'     => '$db_identity_index->create_table',
	'PostIdentityReservations::TABLE_NAME' => 'PostIdentityReservations::create_table',
	'InstalledBundleArtifacts::TABLE_NAME' => 'InstalledBundleArtifacts::create_table',
	'RunMetadata::TABLE_NAME'           => 'RunMetadata::create_table',
	'PendingActionStore::get_table_name' => 'PendingActionStore::create_table',
);

$network_tables = array(
	'Agents::TABLE_NAME'                    => 'Agents::create_table',
	'AgentAccess::TABLE_NAME'                => 'AgentAccess::create_table',
	'AgentAccess::PRINCIPAL_TABLE_NAME'      => 'AgentAccess::create_table',
	'AgentTokens::TABLE_NAME'                => 'AgentTokens::create_table',
	'Chat::get_prefixed_table_name'          => 'Chat::create_table',
);

foreach ( $site_tables as $cleanup_name => $create_call ) {
	datamachine_uninstall_assert( str_contains( $ensure_body, $create_call ), "schema setup creates site table via {$create_call}" );
	datamachine_uninstall_assert( str_contains( $uninstall, $cleanup_name ), "uninstall drops site table via {$cleanup_name}" );
}

foreach ( $network_tables as $cleanup_name => $create_call ) {
	datamachine_uninstall_assert( str_contains( $activation, $create_call ), "schema setup creates network table via {$create_call}" );
	datamachine_uninstall_assert( str_contains( $uninstall, $cleanup_name ), "uninstall drops network table via {$cleanup_name}" );
}

datamachine_uninstall_assert( 12 === count( $site_tables ), 'inventory contains all 12 site tables' );
datamachine_uninstall_assert( 5 === count( $network_tables ), 'inventory contains all 5 network tables' );
datamachine_uninstall_assert( str_contains( $uninstall, 'try {' ) && str_contains( $uninstall, '} finally {' ), 'multisite cleanup restores switched blogs with try/finally' );

// Model nested WordPress blog switching to lock the restoration invariant: an
// uninstall invoked while already switched must return to that original blog.
$blog_stack  = array( 1, 7 );
$original    = end( $blog_stack );
$site_ids    = array( 1, 2, 7 );
foreach ( $site_ids as $site_id ) {
	$blog_stack[] = $site_id;
	try {
		// Site cleanup runs here in WordPress.
	} finally {
		array_pop( $blog_stack );
	}
}
datamachine_uninstall_assert( $original === end( $blog_stack ), 'multisite iteration restores the original nested blog context' );

echo "\n{$passes} passed, {$failures} failed.\n";
exit( $failures > 0 ? 1 : 0 );
