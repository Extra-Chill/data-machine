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
	'HostIntegrationServiceProvider',
);

foreach ( $providers as $provider ) {
	$path = $root . '/inc/Core/Bootstrap/' . $provider . '.php';
	$assert( is_file( $path ), "{$provider} exists" );
	$assert( str_contains( (string) file_get_contents( $path ), "final class {$provider}" ), "{$provider} is explicit" );
}

$assert( str_contains( $main, 'CliServiceProvider::register();' ), 'entrypoint delegates CLI composition' );
$cli = (string) file_get_contents( $root . '/inc/Core/Bootstrap/CliServiceProvider.php' );
$assert( str_contains( $cli, "'datamachine settings'" ), 'CLI provider owns the canonical command map' );
$assert( str_contains( $cli, 'WP_CLI::add_command' ), 'CLI provider registers every command' );
$assert( ! is_file( $root . '/inc/Cli/CommandRegistry.php' ), 'single-caller command registry is absent' );
$assert( str_contains( $main, 'RuntimeServiceProvider::register();' ), 'entrypoint delegates full runtime composition' );
$assert( str_contains( $main, 'AbilityServiceProvider::register_lightweight();' ), 'lightweight abilities remain unconditional' );
$assert( str_contains( $main, 'AlwaysOnServiceProvider::register_scheduler();' ), 'entrypoint delegates scheduler integrations' );
$assert( str_contains( $main, 'AlwaysOnServiceProvider::register_wordpress_hooks();' ), 'entrypoint delegates always-on WordPress hooks' );
$assert( str_contains( $main, 'ActivationServiceProvider::register_defaults_hook( __FILE__ );' ), 'entrypoint delegates defaults activation registration' );
$assert( str_contains( $main, 'ActivationServiceProvider::register_lifecycle_hooks( __FILE__ );' ), 'entrypoint delegates lifecycle registration' );
$assert( str_contains( $main, 'ActivationServiceProvider::register_new_site_hook();' ), 'entrypoint delegates multisite setup registration' );
$assert( str_contains( $bootstrap, 'HostIntegrationServiceProvider::register();' ), 'bootstrap delegates host integrations' );
$assert( str_contains( $bootstrap, "require_once __DIR__ . '/Engine/AI/Tools/ability-tool-projections.php';" ), 'bootstrap defines projection helpers before runtime composition' );

$runtime = (string) file_get_contents( $root . '/inc/Core/Bootstrap/RuntimeServiceProvider.php' );
$order   = array(
	'RuntimeEnvironment::should_load_full_runtime()',
	'new \\DataMachine\\Core\\Steps\\Fetch\\FetchStep();',
	'new \\DataMachine\\Core\\Steps\\Publish\\Handlers\\WordPress\\WordPress();',
	'new \\DataMachine\\Engine\\AI\\Tools\\Global\\AgentDailyMemory();',
	'new \\DataMachine\\Api\\Chat\\Tools\\ConsultAgent();',
	'\\DataMachine\\Api\\Execute::register();',
	'AbilityServiceProvider::register_full_runtime();',
);
$offset  = -1;
foreach ( $order as $needle ) {
	$position = strpos( $runtime, $needle );
	$assert( false !== $position && $position > $offset, "runtime order preserves {$needle}" );
	$offset = false === $position ? $offset : $position;
}

// Discover the current self-registering REST controllers rather than pinning a count.
$controllers = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/inc/Api', FilesystemIterator::SKIP_DOTS ) );
$rest_count  = 0;
foreach ( $controllers as $controller ) {
	if ( 'php' !== $controller->getExtension() ) {
		continue;
	}
	$source = (string) file_get_contents( $controller->getPathname() );
	if ( ! preg_match( '/public static function register\s*\(/', $source ) ) {
		continue;
	}
	preg_match( '/namespace\s+([^;]+);/', $source, $namespace );
	preg_match( '/class\s+(\w+)/', $source, $class );
	$registration = '\\' . $namespace[1] . '\\' . $class[1] . '::register();';
	$position     = strpos( $runtime, $registration );
	$assert( 1 === substr_count( $runtime, $registration ), "runtime registers {$registration} exactly once" );
	$assert( false !== $position && $position > strpos( $runtime, 'new \\DataMachine\\Api\\Chat\\Tools\\ConsultAgent();' ) && $position < strpos( $runtime, 'AbilityServiceProvider::register_full_runtime();' ), "{$registration} follows tools and precedes abilities" );
	++$rest_count;
}
$assert( $rest_count > 0, 'REST controller discovery is non-empty' );
$assert( ! is_file( $root . '/inc/Core/Bootstrap/RestServiceProvider.php' ), 'single-caller REST provider is absent' );
$assert( ! is_file( $root . '/inc/Engine/AI/Tools/ToolServiceProvider.php' ), 'single-caller tool provider is absent' );
$assert( ! str_contains( $main, '\\DataMachine\\Api\\Execute::register();' ), 'entrypoint no longer composes concrete REST controllers' );
$assert( substr_count( $main, 'register_activation_hook(' ) === 0, 'entrypoint no longer registers lifecycle hooks directly' );

$tool_provider_order = array(
	'new \\DataMachine\\Engine\\AI\\Configuration\\ImageGenerationSettings();',
	'\\datamachine_register_global_ability_tools();',
	'new \\DataMachine\\Engine\\AI\\Tools\\Global\\QueueValidator();',
);
$offset              = -1;
foreach ( $tool_provider_order as $needle ) {
	$position = strpos( $runtime, $needle );
	$assert( false !== $position && $position > $offset, "runtime tool order preserves {$needle}" );
	$offset = false === $position ? $offset : $position;
}

$removed_functions = array(
	'datamachine_skip_action_scheduler_migration_during_install',
	'datamachine_run_datamachine_plugin',
	'datamachine_activate_plugin_defaults',
	'datamachine_activate_defaults_for_site',
	'datamachine_load_step_types',
	'datamachine_load_handlers',
	'datamachine_allow_json_upload',
	'datamachine_remove_capabilities',
	'datamachine_deactivate_plugin',
	'datamachine_activate_plugin',
	'datamachine_create_network_agent_tables',
	'datamachine_activate_for_site',
	'datamachine_ensure_all_tables',
	'datamachine_for_each_site',
	'datamachine_on_new_site',
);
foreach ( $removed_functions as $function ) {
	$assert( ! str_contains( $main, "function {$function}(" ), "{$function} forwarding global is absent" );
}
$assert( str_contains( $main, 'function datamachine_register_capabilities(): void' ), 'externally consumed capability compatibility function remains' );
$assert( str_contains( $bootstrap, 'function datamachine_register_default_memory_files(): void' ), 'memory compatibility function remains' );

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}
	exit( 1 );
}

echo "PASS: bootstrap providers preserve composition and registration order\n";
