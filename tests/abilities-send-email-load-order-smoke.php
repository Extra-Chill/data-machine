<?php
/**
 * Regression smoke for #2303: email abilities must register on lite frontend
 * requests where `datamachine_should_load_full_runtime()` returns false.
 *
 * Run with: php tests/abilities-send-email-load-order-smoke.php
 *
 * @package DataMachine\Tests
 */

declare( strict_types = 1 );

$failed = 0;
$total  = 0;

$assert = static function ( string $name, bool $condition ) use ( &$failed, &$total ): void {
	++$total;
	if ( $condition ) {
		echo "  PASS: {$name}\n";
		return;
	}

	echo "  FAIL: {$name}\n";
	++$failed;
};

$plugin_root = dirname( __DIR__ );
$bootstrap   = file_get_contents( $plugin_root . '/data-machine.php' );
$send_source = file_get_contents( $plugin_root . '/inc/Abilities/Publish/SendEmailAbility.php' );
$queue_source = file_get_contents( $plugin_root . '/inc/Abilities/Publish/SendEmailQueuedAbility.php' );

if ( false === $bootstrap || false === $send_source || false === $queue_source ) {
	fwrite( fopen( 'php://stderr', 'w' ), "FAIL: unable to read plugin source\n" );
	exit( 1 );
}

$assert(
	'data-machine.php registers send-email abilities unconditionally at file load',
	str_contains( $bootstrap, 'Register `datamachine/send-email` and `datamachine/send-email-queued`' )
		&& str_contains( $bootstrap, "require_once __DIR__ . '/inc/Abilities/Publish/SendEmailAbility.php';\nrequire_once __DIR__ . '/inc/Abilities/Publish/SendEmailQueuedAbility.php';\n\\DataMachine\\Abilities\\Publish\\SendEmailAbility::ensure_registered();\n\\DataMachine\\Abilities\\Publish\\SendEmailQueuedAbility::ensure_registered();" )
);

$assert(
	'unconditional call site is outside datamachine_run_datamachine_plugin()',
	(bool) preg_match(
		'/^\\\\DataMachine\\\\Abilities\\\\Media\\\\ImageTemplateAbilities::ensure_registered\(\);\s*\n\s*\n\/\*\*\s*\*\s*Register `datamachine\/send-email`/m',
		$bootstrap
	)
);

$assert(
	'in-runtime send-email instantiation remains as defensive idempotent path',
	str_contains( $bootstrap, "new \\DataMachine\\Abilities\\Publish\\SendEmailAbility();" )
);

$assert(
	'in-runtime send-email-queued instantiation remains as defensive idempotent path',
	str_contains( $bootstrap, "new \\DataMachine\\Abilities\\Publish\\SendEmailQueuedAbility();" )
);

foreach ( array( 'send-email' => $send_source, 'send-email-queued' => $queue_source ) as $label => $source ) {
	$assert(
		"{$label}: ensure_registered() handles doing_action state",
		str_contains( $source, "doing_action( 'wp_abilities_api_init' )" )
	);
	$assert(
		"{$label}: ensure_registered() handles pre-action state",
		str_contains( $source, "! did_action( 'wp_abilities_api_init' )" )
			&& str_contains( $source, "add_action(\n\t\t\t\t'wp_abilities_api_init'" )
	);
	$assert(
		"{$label}: ensure_registered() avoids post-action registry writes",
		! str_contains( $source, '\\WP_Abilities_Registry::get_instance()' )
			&& ! str_contains( $source, '$registry->register( $name, $args )' )
	);
	$assert(
		"{$label}: ensure_registered() documents missing late registration surface",
		str_contains( $source, 'does not expose a late-registration surface' )
	);
	$assert(
		"{$label}: definitions are shared across timing branches",
		str_contains( $source, 'private static function get_ability_definitions' )
	);
}

// The behavioral simulation below stubs WordPress lifecycle functions, which
// are only installed when the real ones are absent. Under a real WordPress
// runtime those stubs are inert and
// the simulation cannot control `doing_action()`, making state 1 fail
// spuriously. The source-string assertions above lock the real contract in
// every backend and the behavioral path is covered in the pure-PHP / PHPUnit
// context, so skip the stub-driven simulation under a real WordPress runtime.
if ( defined( 'WPINC' ) ) {
	if ( $failed > 0 ) {
		fwrite( fopen( 'php://stderr', 'w' ), "\nabilities-send-email-load-order-smoke: {$failed}/{$total} assertions failed\n" );
		exit( 1 );
	}

	echo "\nAll {$total} abilities-send-email-load-order source-string assertions passed (behavioral simulation skipped under real WordPress).\n";
	return;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/' );
}

$GLOBALS['datamachine_2303_state'] = (object) array(
	'doing'          => false,
	'did'            => 0,
	'hooked'         => array(),
	'registered'     => array(),
	'doing_it_wrong' => 0,
);

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		return $text;
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( $hook ) {
		global $datamachine_2303_state;
		return $datamachine_2303_state->doing && 'wp_abilities_api_init' === $hook;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook ) {
		global $datamachine_2303_state;
		return 'wp_abilities_api_init' === $hook ? $datamachine_2303_state->did : 0;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		global $datamachine_2303_state;
		$datamachine_2303_state->hooked[ $hook ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $name, $args ) {
		global $datamachine_2303_state;
		if ( ! doing_action( 'wp_abilities_api_init' ) ) {
			++$datamachine_2303_state->doing_it_wrong;
			return null;
		}
		$datamachine_2303_state->registered[ $name ] = $args;
		return true;
	}
}

if ( ! class_exists( 'DataMachine\\Abilities\\PermissionHelper' ) ) {
	eval(
		'namespace DataMachine\\Abilities;
		class PermissionHelper {
			public static function can_manage(): bool { return true; }
		}'
	);
}

require_once $plugin_root . '/inc/Abilities/Publish/SendEmailAbility.php';
require_once $plugin_root . '/inc/Abilities/Publish/SendEmailQueuedAbility.php';

$reset_class = static function ( string $class ): void {
	$reflection = new ReflectionClass( $class );
	foreach ( array( 'registered', 'registration_pending', 'worker_registered' ) as $property ) {
		if ( ! $reflection->hasProperty( $property ) ) {
			continue;
		}
		$prop = $reflection->getProperty( $property );
		$prop->setValue( null, false );
	}
	if ( $reflection->hasProperty( 'instance' ) ) {
		$prop = $reflection->getProperty( 'instance' );
		$prop->setValue( null, null );
	}
};

$reset = static function () use ( $reset_class ): void {
	global $datamachine_2303_state;
	$datamachine_2303_state->doing          = false;
	$datamachine_2303_state->did            = 0;
	$datamachine_2303_state->hooked         = array();
	$datamachine_2303_state->registered     = array();
	$datamachine_2303_state->doing_it_wrong = 0;

	$reset_class( \DataMachine\Abilities\Publish\SendEmailAbility::class );
	$reset_class( \DataMachine\Abilities\Publish\SendEmailQueuedAbility::class );
};

$reset();
$GLOBALS['datamachine_2303_state']->doing = true;
\DataMachine\Abilities\Publish\SendEmailAbility::ensure_registered();
\DataMachine\Abilities\Publish\SendEmailQueuedAbility::ensure_registered();
$assert(
	'state 1: doing_action registers both email abilities immediately',
	isset( $GLOBALS['datamachine_2303_state']->registered['datamachine/send-email'] )
		&& isset( $GLOBALS['datamachine_2303_state']->registered['datamachine/send-email-queued'] )
);

$reset();
\DataMachine\Abilities\Publish\SendEmailAbility::ensure_registered();
\DataMachine\Abilities\Publish\SendEmailQueuedAbility::ensure_registered();
$assert(
	'state 2: pre-action hooks both ability registration callbacks',
	isset( $GLOBALS['datamachine_2303_state']->hooked['wp_abilities_api_init'] )
		&& 2 === count( $GLOBALS['datamachine_2303_state']->hooked['wp_abilities_api_init'] )
		&& empty( $GLOBALS['datamachine_2303_state']->registered )
);

$reset();
$GLOBALS['datamachine_2303_state']->did = 1;
\DataMachine\Abilities\Publish\SendEmailAbility::ensure_registered();
\DataMachine\Abilities\Publish\SendEmailQueuedAbility::ensure_registered();
$assert(
	'state 3: post-action does not register email abilities late',
	empty( $GLOBALS['datamachine_2303_state']->registered )
);

$assert(
	'state 3: no-op path avoids wp_register_ability() doing-it-wrong notices',
	0 === $GLOBALS['datamachine_2303_state']->doing_it_wrong
);

$prior_registered = $GLOBALS['datamachine_2303_state']->registered;
\DataMachine\Abilities\Publish\SendEmailAbility::ensure_registered();
\DataMachine\Abilities\Publish\SendEmailQueuedAbility::ensure_registered();
$assert(
	'idempotent: repeated post-action ensure_registered() calls remain no-ops',
	$GLOBALS['datamachine_2303_state']->registered === $prior_registered
		&& 0 === $GLOBALS['datamachine_2303_state']->doing_it_wrong
);

$reset();
$GLOBALS['datamachine_2303_state']->doing = true;
new \DataMachine\Abilities\Publish\SendEmailAbility();
new \DataMachine\Abilities\Publish\SendEmailQueuedAbility();
$assert(
	'constructor path: instantiating both classes triggers registration',
	isset( $GLOBALS['datamachine_2303_state']->registered['datamachine/send-email'] )
		&& isset( $GLOBALS['datamachine_2303_state']->registered['datamachine/send-email-queued'] )
);

if ( $failed > 0 ) {
	fwrite( fopen( 'php://stderr', 'w' ), "\nabilities-send-email-load-order-smoke: {$failed}/{$total} assertions failed\n" );
	exit( 1 );
}

echo "\nAll {$total} abilities-send-email-load-order assertions passed.\n";
