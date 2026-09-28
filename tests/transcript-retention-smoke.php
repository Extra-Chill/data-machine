<?php
/**
 * Focused smoke coverage for network-safe transcript retention (#3328).
 *
 * Run with: php tests/transcript-retention-smoke.php
 */

$root     = dirname( __DIR__ );
$provider = file_get_contents( $root . '/inc/Engine/AI/System/SystemAgentServiceProvider.php' ) ?: '';
$registry = file_get_contents( $root . '/inc/Engine/Tasks/RecurringScheduleRegistry.php' ) ?: '';
$cleanup  = file_get_contents( $root . '/inc/Engine/AI/System/Tasks/Retention/RetentionCleanup.php' ) ?: '';
$task     = file_get_contents( $root . '/inc/Engine/AI/System/Tasks/Retention/RetentionChatSessionsTask.php' ) ?: '';
$chat     = file_get_contents( $root . '/inc/Core/Database/Chat/Chat.php' ) ?: '';
$cli      = file_get_contents( $root . '/inc/Cli/Commands/RetentionCommand.php' ) ?: '';

$failures = 0;
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( $condition ) {
		echo "PASS: {$message}\n";
		return;
	}

	++$failures;
	echo "FAIL: {$message}\n";
};

$assert(
	(bool) preg_match( "/TASK_CHAT_SESSIONS[\\s\\S]*?'network_only'\\s*=>\\s*true/", $provider ),
	'chat retention recurrence is declared network-only'
);
$assert(
	str_contains( $registry, "'network_only'       => false" ),
	'recurring registry normalizes network ownership'
);
// Behavioural: one ownership rule, exercised on a simulated main site and subsite (#3569).
$flow_routines = file_get_contents( $root . '/inc/Engine/Scheduling/FlowRoutines.php' ) ?: '';
$assert(
	str_contains( $flow_routines, 'RecurringScheduleRegistry::isOwnedByCurrentSite( $schedule )' )
		&& str_contains( $provider, 'RecurringScheduleRegistry::isOwnedByCurrentSite( $def )' )
		&& ! str_contains( $provider, 'function isScheduleOwnedByCurrentSite' ),
	'reconciliation and dispatch share one site-ownership rule'
);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}
$GLOBALS['__transcript_smoke_main_site'] = true;
if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return true;
	}
}
if ( ! function_exists( 'is_main_site' ) ) {
	function is_main_site(): bool {
		return (bool) $GLOBALS['__transcript_smoke_main_site'];
	}
}
if ( ! class_exists( 'DataMachine\\Core\\PluginSettings' ) ) {
	eval( 'namespace DataMachine\\Core; class PluginSettings { public static function get( $key, $default = null ) { return $default; } }' );
}
require_once $root . '/inc/Engine/Tasks/RecurringScheduleRegistry.php';

$network_schedule = array( 'network_only' => true );
$site_schedule    = array( 'network_only' => false );
$main_owns        = \DataMachine\Engine\Tasks\RecurringScheduleRegistry::isOwnedByCurrentSite( $network_schedule );
$GLOBALS['__transcript_smoke_main_site'] = false;
$subsite_owns     = \DataMachine\Engine\Tasks\RecurringScheduleRegistry::isOwnedByCurrentSite( $network_schedule );
$subsite_local    = \DataMachine\Engine\Tasks\RecurringScheduleRegistry::isOwnedByCurrentSite( $site_schedule );
$assert(
	$main_owns && ! $subsite_owns && $subsite_local,
	'network-only schedules are owned by the main site only; site schedules run everywhere'
);
$assert(
	str_contains( $task, 'RetentionCleanup::cleanupChatSessions()' )
		&& str_contains( $cleanup, 'cleanup_pipeline_transcripts( $transcript_retention_days )' ),
	'recurring retention task reaches pipeline transcript cleanup'
);
$assert(
	str_contains( $cleanup, "PluginSettings::get( 'chat_retention_days', 90 )" )
		&& str_contains( $cleanup, "get_option( 'datamachine_pipeline_transcript_retention_days', 30 )" ),
	'human and pipeline retention keep independent 90-day and 30-day defaults'
);
$assert(
	(bool) preg_match( "/DELETE FROM %i[\\s\\S]*?NOT \\(mode = %s AND metadata LIKE %s\\)/", $chat ),
	'human-session deletion excludes pipeline transcript rows'
);
$assert(
	str_contains( $cleanup, 'countChatSessionBreakdown()' )
		&& str_contains( $cli, 'Human chat sessions' )
		&& str_contains( $cli, 'Pipeline transcripts' ),
	'dry-run and status surfaces distinguish both eligibility thresholds'
);

if ( $failures > 0 ) {
	echo "transcript-retention-smoke failed: {$failures} assertion(s).\n";
	exit( 1 );
}

echo "All transcript retention smoke assertions passed.\n";
