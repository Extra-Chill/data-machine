<?php
/**
 * Lifecycle smoke checks for Business ability registration.
 *
 * Run with: php tests/ability-registration-lifecycle-smoke.php
 *
 * @package DataMachineBusiness\Tests
 */

$root              = dirname( __DIR__ );
$data_machine_root = getenv( 'DATA_MACHINE_PATH' ) ?: dirname( $root ) . '/data-machine';
$helper_path       = $data_machine_root . '/inc/Abilities/AbilityRegistration.php';
$failures          = array();
$passes            = 0;
$actions           = array();
$registered        = array();
$action_state      = array(
	'doing' => false,
	'did'   => 0,
);

function assert_ability_registration( bool $condition, string $name, array &$failures, int &$passes ): void {
	if ( $condition ) {
		$passes++;
		echo "  PASS {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  FAIL {$name}\n";
}

function doing_action( string $hook ): bool {
	global $action_state;
	return 'wp_abilities_api_init' === $hook && $action_state['doing'];
}

function did_action( string $hook ): int {
	global $action_state;
	return 'wp_abilities_api_init' === $hook ? $action_state['did'] : 0;
}

function add_action( string $hook, callable $callback ): void {
	global $actions;
	$actions[ $hook ][] = $callback;
}

function do_action( string $hook ): void {
	global $actions, $action_state;
	$action_state['doing'] = true;
	foreach ( $actions[ $hook ] ?? array() as $callback ) {
		$callback();
	}
	$action_state['doing'] = false;
	$action_state['did']++;
}

function wp_register_ability( string $name, array $args ): void {
	global $registered;
	$registered[ $name ] = $args;
}

function __( string $text, string $domain = '' ): string {
	return $text;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}

assert_ability_registration(
	file_exists( $helper_path ),
	'Data Machine AbilityRegistration helper is available',
	$failures,
	$passes
);

if ( ! file_exists( $helper_path ) ) {
	exit( 1 );
}

require_once $helper_path;
require_once $root . '/inc/Abilities/PageSpeed/PageSpeedAbility.php';

$registered = array();
new \DataMachineBusiness\Abilities\PageSpeed\PageSpeedAbility();
assert_ability_registration(
	empty( $registered ) && isset( $actions['wp_abilities_api_init'][0] ),
	'construction before wp_abilities_api_init defers registration',
	$failures,
	$passes
);

do_action( 'wp_abilities_api_init' );
assert_ability_registration(
	isset( $registered['datamachine/pagespeed'] ),
	'queued registration runs during wp_abilities_api_init',
	$failures,
	$passes
);

$reflection = new ReflectionProperty( \DataMachineBusiness\Abilities\PageSpeed\PageSpeedAbility::class, 'registered' );
$reflection->setValue( false );
$actions    = array();
$registered = array();

new \DataMachineBusiness\Abilities\PageSpeed\PageSpeedAbility();
assert_ability_registration(
	empty( $registered ) && empty( $actions ),
	'construction after wp_abilities_api_init does not register late',
	$failures,
	$passes
);

$ability_files = array(
	'inc/Abilities/Analytics/BingWebmasterAbilities.php',
	'inc/Abilities/Analytics/ContentFlagsAbility.php',
	'inc/Abilities/Analytics/ContentPerformanceAbility.php',
	'inc/Abilities/Analytics/GoogleAnalyticsAbilities.php',
	'inc/Abilities/Analytics/GoogleSearchConsoleAbilities.php',
	'inc/Abilities/Analytics/GscOpportunityAbility.php',
	'inc/Abilities/Analytics/MediavineReportsAbilities.php',
	'inc/Abilities/Discord/FetchMessagesDiscordAbility.php',
	'inc/Abilities/Discord/PostMessageDiscordAbility.php',
	'inc/Abilities/GoogleDrive/DownloadGoogleDriveAbility.php',
	'inc/Abilities/GoogleDrive/FetchGoogleDriveAbility.php',
	'inc/Abilities/GoogleDrive/ListGoogleDriveFilesAbility.php',
	'inc/Abilities/GoogleDrive/ReadGoogleDriveDocAbility.php',
	'inc/Abilities/GoogleSheets/FetchGoogleSheetsAbility.php',
	'inc/Abilities/GoogleSheets/PublishGoogleSheetsAbility.php',
	'inc/Abilities/MediaHygiene/MediaHygieneAbility.php',
	'inc/Abilities/PageSpeed/PageSpeedAbility.php',
	'inc/Abilities/Sendy/SendyAbilities.php',
	'inc/Abilities/Slack/FetchMessagesSlackAbility.php',
	'inc/Abilities/Slack/PostMessageSlackAbility.php',
);

foreach ( $ability_files as $ability_file ) {
	$source = file_get_contents( $root . '/' . $ability_file );
	assert_ability_registration(
		false !== strpos( $source, 'AbilityRegistration::on_abilities_api_init' ),
		"{$ability_file} uses the canonical lifecycle helper",
		$failures,
		$passes
	);
}

if ( ! empty( $failures ) ) {
	echo "\nFAILURES:\n" . implode( "\n", $failures ) . "\n";
	exit( 1 );
}

echo "\n{$passes} assertions passed.\n";
