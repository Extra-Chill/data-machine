<?php
/**
 * Dependency-free continuity checks for the moved IndexNow integration.
 */

$root     = dirname( __DIR__ );
$failures = array();

$ability_path = $root . '/inc/Abilities/SEO/IndexNowAbilities.php';
$command_path = $root . '/inc/Cli/IndexNowCommand.php';
$provider     = file_get_contents( $root . '/inc/Bootstrap/ProviderModules.php' );
$registry     = file_get_contents( $root . '/inc/Cli/CommandRegistry.php' );
$ability      = file_get_contents( $ability_path );
$command      = file_get_contents( $command_path );

foreach ( array( $ability_path, $command_path ) as $path ) {
	if ( ! file_exists( $path ) ) {
		$failures[] = 'Missing owned file: ' . $path;
	}
}

foreach ( array( 'datamachine/indexnow-submit', 'datamachine/indexnow-status', 'datamachine/indexnow-generate-key', 'datamachine/indexnow-verify-key' ) as $name ) {
	if ( ! str_contains( $ability, $name ) || ! str_contains( $provider, $name ) ) {
		$failures[] = 'Ability ownership or provider registration missing: ' . $name;
	}
}

$continuity = array(
	'namespace DataMachineBusiness\\Abilities\\SEO' => $ability,
	'use DataMachine\\Core\\HttpClient'             => $ability,
	'use DataMachine\\Core\\PluginSettings'         => $ability,
	'use DataMachine\\Abilities\\PermissionHelper'  => $ability,
	'AbilityRegistration::on_abilities_api_init'       => $ability,
	"PluginSettings::get( 'indexnow_enabled'"         => $ability,
	"PluginSettings::get( 'indexnow_api_key'"         => $ability,
	"get_option( 'datamachine_settings'"              => $ability . $command,
	'datamachine_indexnow_skip_auto_submit'            => $ability,
	"add_action( 'wp_after_insert_post'"               => $ability,
	"add_action( 'parse_request'"                      => $ability,
	"\$key . '.txt'"                                  => $ability,
	"'indexnow'"                                      => $provider,
	"'datamachine indexnow'"                          => $registry,
	'IndexNowCommand::class'                           => $registry,
);

foreach ( $continuity as $needle => $haystack ) {
	if ( ! str_contains( $haystack, $needle ) ) {
		$failures[] = 'Continuity marker missing: ' . $needle;
	}
}

foreach ( array( 'MetaDescription', 'InternalLink' ) as $unrelated ) {
	if ( str_contains( $ability . $command, $unrelated ) ) {
		$failures[] = 'Unrelated SEO ownership moved: ' . $unrelated;
	}
}

if ( ! empty( $failures ) ) {
	fwrite( STDERR, "IndexNow ownership smoke failed:\n - " . implode( "\n - ", $failures ) . "\n" );
	exit( 1 );
}

echo "IndexNow ownership smoke checks passed.\n";
