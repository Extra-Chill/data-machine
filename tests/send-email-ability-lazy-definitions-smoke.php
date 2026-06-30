<?php
/**
 * Regression: always-on send-email ability registration must not translate
 * labels/descriptions while the plugin file is loading before init.
 */

define( 'ABSPATH', __DIR__ );

$datamachine_test_actions       = array();
$datamachine_test_doing_actions = array();
$datamachine_test_did_actions   = array();
$datamachine_test_translations  = 0;
$datamachine_test_registered    = array();

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
    	global $datamachine_test_translations;
    	if ( 'data-machine' === $domain ) {
    		$datamachine_test_translations++;
    	}
    	return $text;
    }
}

if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
    	global $datamachine_test_actions;
    	$datamachine_test_actions[ $hook_name ][] = $callback;
    	return true;
    }
}

function doing_action( $hook_name = null ) {
	global $datamachine_test_doing_actions;
	return isset( $datamachine_test_doing_actions[ $hook_name ] );
}

function did_action( $hook_name ) {
	global $datamachine_test_did_actions;
	return $datamachine_test_did_actions[ $hook_name ] ?? 0;
}

function wp_register_ability( $name, $args ) {
	global $datamachine_test_registered;
	$datamachine_test_registered[ $name ] = $args;
}

require_once __DIR__ . '/../inc/Abilities/Publish/SendEmailAbility.php';
require_once __DIR__ . '/../inc/Abilities/Publish/SendEmailQueuedAbility.php';

\DataMachine\Abilities\Publish\SendEmailAbility::ensure_registered();
\DataMachine\Abilities\Publish\SendEmailQueuedAbility::ensure_registered();

if ( 0 !== $datamachine_test_translations ) {
	fwrite( fopen( 'php://stderr', 'w' ), "FAIL: send-email abilities translated definitions before wp_abilities_api_init.\n" );
	exit( 1 );
}

foreach ( $datamachine_test_actions['wp_abilities_api_init'] ?? array() as $callback ) {
	$datamachine_test_doing_actions['wp_abilities_api_init'] = true;
	$datamachine_test_did_actions['wp_abilities_api_init']   = 1;
	$callback();
	unset( $datamachine_test_doing_actions['wp_abilities_api_init'] );
}

if ( ! isset( $datamachine_test_registered['datamachine/send-email'], $datamachine_test_registered['datamachine/send-email-queued'] ) ) {
	fwrite( fopen( 'php://stderr', 'w' ), "FAIL: send-email abilities were not registered when wp_abilities_api_init fired.\n" );
	exit( 1 );
}

if ( 0 === $datamachine_test_translations ) {
	fwrite( fopen( 'php://stderr', 'w' ), "FAIL: send-email definitions were not built during wp_abilities_api_init.\n" );
	exit( 1 );
}

echo "send-email-ability-lazy-definitions-smoke: ok\n";
