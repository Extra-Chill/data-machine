<?php
/**
 * Source smoke for bootstrap service-provider composition and ordering.
 *
 * Run with: php tests/bootstrap-service-provider-composition-smoke.php
 *
 * @package DataMachine\Tests
 */

$root      = dirname( __DIR__ );
$main      = (string) file_get_contents( $root . '/data-machine.php' );
$bootstrap = (string) file_get_contents( $root . '/inc/bootstrap.php' );
$failures  = array();

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$providers = array(
	'CliServiceProvider',
	'AlwaysOnServiceProvider',
	'ActivationServiceProvider',
	'RuntimeServiceProvider',
	'RestServiceProvider',
	'HostIntegrationServiceProvider',
);

foreach ( $providers as $provider ) {
	$path = $root . '/inc/Core/Bootstrap/' . $provider . '.php';
	$assert( is_file( $path ), "{$provider} exists" );
	$assert( str_contains( (string) file_get_contents( $path ), "final class {$provider}" ), "{$provider} is explicit" );
}

$assert( str_contains( $main, 'CliServiceProvider::register();' ), 'entrypoint delegates CLI composition' );
$cli = (string) file_get_contents( $root . '/inc/Core/Bootstrap/CliServiceProvider.php' );
$assert( str_contains( $cli, 'CommandRegistry::map()' ), 'CLI provider owns command registration' );
$assert( str_contains( $cli, 'WP_CLI::add_command' ), 'CLI provider registers every mapped command' );
$assert( str_contains( $main, 'RuntimeServiceProvider::register();' ), 'entrypoint delegates full runtime composition' );
$assert( str_contains( $main, 'AbilityServiceProvider::register_lightweight();' ), 'lightweight abilities remain unconditional' );
$assert( str_contains( $main, 'AlwaysOnServiceProvider::register_scheduler();' ), 'entrypoint delegates scheduler integrations' );
$assert( str_contains( $main, 'AlwaysOnServiceProvider::register_wordpress_hooks();' ), 'entrypoint delegates always-on WordPress hooks' );
$assert( str_contains( $main, 'ActivationServiceProvider::register_defaults_hook( __FILE__ );' ), 'entrypoint delegates defaults activation registration' );
$assert( str_contains( $main, 'ActivationServiceProvider::register_lifecycle_hooks( __FILE__ );' ), 'entrypoint delegates lifecycle registration' );
$assert( str_contains( $main, 'ActivationServiceProvider::register_new_site_hook();' ), 'entrypoint delegates multisite setup registration' );
$assert( str_contains( $bootstrap, 'HostIntegrationServiceProvider::register();' ), 'bootstrap delegates host integrations' );

$runtime = (string) file_get_contents( $root . '/inc/Core/Bootstrap/RuntimeServiceProvider.php' );
$order   = array(
	'RuntimeEnvironment::should_load_full_runtime()',
	'self::register_step_types();',
	'self::register_handlers();',
	'ToolServiceProvider::register();',
	'RestServiceProvider::register();',
	'AbilityServiceProvider::register_full_runtime();',
);
$offset  = -1;
foreach ( $order as $needle ) {
	$position = strpos( $runtime, $needle );
	$assert( false !== $position && $position > $offset, "runtime order preserves {$needle}" );
	$offset = false === $position ? $offset : $position;
}

$rest       = (string) file_get_contents( $root . '/inc/Core/Bootstrap/RestServiceProvider.php' );
$rest_calls = substr_count( $rest, '::register();' );
$assert( 27 === $rest_calls, 'REST provider composes all 27 controllers' );
$assert( ! str_contains( $main, '\\DataMachine\\Api\\Execute::register();' ), 'entrypoint no longer composes concrete REST controllers' );
$assert( substr_count( $main, 'register_activation_hook(' ) === 0, 'entrypoint no longer registers lifecycle hooks directly' );

$removed_functions = array(
	'datamachine_skip_action_scheduler_migration_during_install',
	'datamachine_run_datamachine_plugin',
	'datamachine_activate_plugin_defaults',
	'datamachine_activate_defaults_for_site',
	'datamachine_load_handlers',
	'datamachine_allow_json_upload',
	'datamachine_remove_capabilities',
	'datamachine_deactivate_plugin',
	'datamachine_activate_plugin',
	'datamachine_activate_for_site',
	'datamachine_for_each_site',
	'datamachine_on_new_site',
);
foreach ( $removed_functions as $function ) {
	$assert( ! str_contains( $main, "function {$function}(" ), "{$function} forwarding global is absent" );
}
$assert( str_contains( $main, 'function datamachine_register_capabilities(): void' ), 'externally consumed capability compatibility function remains' );
$assert( str_contains( $main, 'function datamachine_load_step_types(): void' ), 'externally consumed step-type compatibility function remains' );
$assert( str_contains( $main, 'function datamachine_create_network_agent_tables(): void' ), 'externally consumed network-schema compatibility function remains' );
$assert( str_contains( $main, 'function datamachine_ensure_all_tables(): bool' ), 'externally consumed schema compatibility function remains' );
$assert( str_contains( $bootstrap, 'function datamachine_register_default_memory_files(): void' ), 'memory compatibility function remains' );

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}
	exit( 1 );
}

echo "PASS: bootstrap providers preserve composition and registration order\n";
