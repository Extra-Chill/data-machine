<?php
/**
 * Smoke test for the generic import-agent ability slice (#1306).
 *
 * Run with: php tests/import-agent-ability-smoke.php
 */

$root = dirname( __DIR__ );

$agent_abilities = file_get_contents( $root . '/inc/Abilities/AgentAbilities.php' ) ?: '';
$agents_command  = file_get_contents( $root . '/inc/Cli/Commands/AgentsCommand.php' ) ?: '';

$assertions = 0;

$assert = function ( string $label, bool $condition ) use ( &$assertions ): void {
	++$assertions;
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$label}\n" );
		exit( 1 );
	}
	echo "ok - {$label}\n";
};

echo "=== Import Agent Ability Smoke (#1306) ===\n";

echo "\n[1] Ability surface\n";
$assert( 'registers datamachine/import-agent ability', str_contains( $agent_abilities, "'datamachine/import-agent'" ) );
$assert( 'ability requires source input', str_contains( $agent_abilities, "'required'   => array( 'source' )" ) );
$assert( 'ability exposes on_conflict enum', str_contains( $agent_abilities, "'on_conflict'" ) && str_contains( $agent_abilities, "array( 'error', 'replace', 'skip' )" ) );
$assert( 'ability exposes owner_id input', str_contains( $agent_abilities, "'owner_id'" ) );
$assert( 'ability output includes imported summary', str_contains( $agent_abilities, "'imported'" ) );
$assert( 'ability output includes auth warnings', str_contains( $agent_abilities, "'auth_warnings'" ) );
$assert( 'ability execute callback points at importAgent', str_contains( $agent_abilities, "'execute_callback'    => array( self::class, 'importAgent' )" ) );

echo "\n[2] Import behavior contract\n";
$assert( 'source loader supports directories', str_contains( $agent_abilities, 'return $bundler->from_directory( $source );' ) );
$assert( 'source loader supports zip archives', str_contains( $agent_abilities, 'return $bundler->from_zip( $source );' ) );
$assert( 'source loader supports json bundles', str_contains( $agent_abilities, 'return $bundler->from_json' ) );
$assert( 'owner resolution checks current user', str_contains( $agent_abilities, 'get_current_user_id()' ) );
$assert( 'owner resolution checks default owner option', str_contains( $agent_abilities, "get_option( 'datamachine_default_owner_id'" ) );
$assert( 'owner resolution rejects unresolved owner instead of falling through to admin', str_contains( $agent_abilities, 'Unable to resolve import owner' ) );
$assert( 'conflict error fails before importer writes', str_contains( $agent_abilities, "'error' === $" . 'on_conflict' ) && str_contains( $agent_abilities, 'already exists. Use on_conflict=replace' ) );
$assert( 'conflict skip returns skipped success', str_contains( $agent_abilities, "'skipped'       => true" ) );
$assert( 'slug override rewrites bundle slug before import', str_contains( $agent_abilities, "$" . "bundle['agent']['agent_slug'] = $" . 'slug;' ) );

echo "\n[3] Auth-ref warning contract\n";
$assert( 'auth refs are routed through resolver filter', str_contains( $agent_abilities, "apply_filters( 'datamachine_auth_ref_to_handler_config'" ) );
$assert( 'unresolved auth refs are collected as warnings', str_contains( $agent_abilities, 'collect_import_auth_warnings' ) && str_contains( $agent_abilities, 'is_wp_error( $resolved )' ) );
$assert( 'auth warning records handler slug', str_contains( $agent_abilities, "'handler_slug' => (string) $" . 'handler_slug' ) );
$assert( 'auth warning records auth ref', str_contains( $agent_abilities, "'auth_ref'     => (string) $" . "handler_config['auth_ref']" ) );

echo "\n[4] CLI wrapper\n";
$assert( 'agent import command documents on-conflict', str_contains( $agents_command, '[--on-conflict=<policy>]' ) );
$assert( 'agent import command resolves ability from registry', str_contains( $agents_command, "wp_get_ability( 'datamachine/import-agent' )" ) );
$assert( 'agent import command executes ability with source', str_contains( $agents_command, "'source'      => $" . 'path' ) );
$assert( 'agent import command passes owner_id', str_contains( $agents_command, "'owner_id'    => $" . 'owner_id' ) );
$assert( 'agent import command passes on_conflict', str_contains( $agents_command, "'on_conflict' => (string) ( $" . "assoc_args['on-conflict']" ) );
$assert( 'agent import command supports JSON output from ability result', str_contains( $agents_command, "'json' === $" . 'format' ) && str_contains( $agents_command, 'wp_json_encode( $result, JSON_PRETTY_PRINT )' ) );

echo "\nAssertions: {$assertions}\n";
echo "PASS\n";
