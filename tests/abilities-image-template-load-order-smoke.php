<?php
/**
 * Regression smoke for #2290: `datamachine/render-image-template` must
 * register on every request that touches the abilities API, including
 * lite frontend requests where `datamachine_should_load_full_runtime()`
 * returns false.
 *
 * Two kinds of assertions:
 *
 * 1. Source-string assertions — `data-machine.php` must declare
 *    `ImageTemplateAbilities::ensure_registered()` in the lightweight
 *    ability manifest registered at file include time.
 *
 * 2. Behavioral assertions — `ImageTemplateAbilities::ensure_registered()`
 *    must handle all three timing states defensively, matching the
 *    pattern adopted for `AbilityCategories` in #2288.
 *
 * Run with: php tests/abilities-image-template-load-order-smoke.php
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

// ============================================================
// Source-string assertions
// ============================================================

$plugin_root  = dirname( __DIR__ );
$bootstrap    = file_get_contents( $plugin_root . '/data-machine.php' );
$class_source = file_get_contents( $plugin_root . '/inc/Abilities/Media/ImageTemplateAbilities.php' );

if ( false === $bootstrap || false === $class_source ) {
	fwrite( fopen( 'php://stderr', 'w' ), "FAIL: unable to read plugin source\n" );
	exit( 1 );
}

$assert(
	'data-machine.php declares ImageTemplateAbilities::ensure_registered() in the lightweight manifest',
	str_contains( $bootstrap, "'file'   => __DIR__ . '/inc/Abilities/Media/ImageTemplateAbilities.php'," )
		&& str_contains( $bootstrap, "'class'  => \\DataMachine\\Abilities\\Media\\ImageTemplateAbilities::class," )
		&& str_contains( $bootstrap, "'method' => 'ensure_registered'," )
);

$assert(
	'lightweight manifest registration is unconditional at file load',
	(bool) preg_match(
		'/^\\\\DataMachine\\\\Abilities\\\\AbilityCategories::ensure_registered\(\);\s*\n/m',
		$bootstrap
	)
		&& (bool) preg_match(
			'/^\\\\DataMachine\\\\Abilities\\\\AbilityManifest::register\( datamachine_lightweight_ability_manifest\(\) \);\s*$/m',
			$bootstrap
		)
);

$assert(
	'in-runtime instantiation is still present (defensive idempotent path)',
	str_contains( $bootstrap, "new \\DataMachine\\Abilities\\Media\\ImageTemplateAbilities();" )
);

// ============================================================
// Behavioral assertions: lifecycle-safe registration pattern
// ============================================================

$assert(
	'ensure_registered() handles doing_action state',
	str_contains( $class_source, "doing_action( 'wp_abilities_api_init' )" )
);

$assert(
	'ensure_registered() handles pre-action state',
	str_contains( $class_source, "! did_action( 'wp_abilities_api_init' )" )
		&& str_contains( $class_source, "add_action(\n\t\t\t\t'wp_abilities_api_init'" )
);

$assert(
	'ensure_registered() avoids post-action registry writes',
	! str_contains( $class_source, '\WP_Abilities_Registry::get_instance()' )
		&& ! str_contains( $class_source, '$registry->register( $name, $args )' )
);

$assert(
	'ensure_registered() documents missing late registration surface',
	str_contains( $class_source, 'does not expose a late-registration surface' )
);

$assert(
	'ability definitions extracted into shared helper to avoid drift',
	str_contains( $class_source, 'private static function get_ability_definitions(): array' )
);

// ============================================================
// Behavioral simulation: load class under three stubbed states
// ============================================================
//
// The stubs below (doing_action/did_action/add_action/wp_register_ability) are
// only installed when the real functions are absent, so under a real WordPress
// runtime they are inert and the
// simulation cannot control `doing_action()`, making state 1 fail spuriously.
// The source-string assertions above lock the real contract in every backend
// and the behavioral path is covered in the pure-PHP / PHPUnit context, so skip
// the stub-driven simulation under a real WordPress runtime.
if ( defined( 'WPINC' ) ) {
	if ( $failed > 0 ) {
		fwrite( fopen( 'php://stderr', 'w' ), "\nabilities-image-template-load-order-smoke: {$failed}/{$total} assertions failed\n" );
		exit( 1 );
	}

	echo "\nAll {$total} abilities-image-template-load-order source-string assertions passed (behavioral simulation skipped under real WordPress).\n";
	return;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/' );
}

$GLOBALS['datamachine_2290_state'] = (object) array(
	'doing'           => false,
	'did'             => 0,
	'hooked'          => array(),
	'registered'      => array(),
	'doing_it_wrong'  => 0,
);

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		return $text;
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( $hook ) {
		global $datamachine_2290_state;
		return $datamachine_2290_state->doing && $hook === 'wp_abilities_api_init';
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook ) {
		global $datamachine_2290_state;
		return $hook === 'wp_abilities_api_init' ? $datamachine_2290_state->did : 0;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		global $datamachine_2290_state;
		$datamachine_2290_state->hooked[ $hook ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $name, $args ) {
		global $datamachine_2290_state;
		// Mirror core's guard: only succeed when doing_action(wp_abilities_api_init) is true.
		if ( ! doing_action( 'wp_abilities_api_init' ) ) {
			++$datamachine_2290_state->doing_it_wrong;
			return null;
		}
		$datamachine_2290_state->registered[ $name ] = $args;
		return true;
	}
}

// Load or stub every collaborator used while building ability definitions.
require_once $plugin_root . '/inc/Abilities/AbilityRegistration.php';

if ( ! class_exists( 'DataMachine\\Abilities\\PermissionHelper' ) ) {
	eval(
		'namespace DataMachine\\Abilities;
		class PermissionHelper {
			public static function can_manage(): bool { return true; }
		}'
	);
}

$assert(
	'bootstrap loads AbilityRegistration used to build registration definitions',
	class_exists( 'DataMachine\\Abilities\\AbilityRegistration', false )
);

$assert(
	'bootstrap loads PermissionHelper referenced by registration definitions',
	class_exists( 'DataMachine\\Abilities\\PermissionHelper', false )
);

require_once $plugin_root . '/inc/Abilities/Media/ImageTemplateAbilities.php';

$reset = static function (): void {
	global $datamachine_2290_state;
	$datamachine_2290_state->doing          = false;
	$datamachine_2290_state->did            = 0;
	$datamachine_2290_state->hooked         = array();
	$datamachine_2290_state->registered     = array();
	$datamachine_2290_state->doing_it_wrong = 0;

	$reflection = new ReflectionClass( \DataMachine\Abilities\Media\ImageTemplateAbilities::class );
	$prop       = $reflection->getProperty( 'registered' );
	$prop->setValue( null, false );

};

// --- State 1: doing_action — register immediately.
$reset();
$GLOBALS['datamachine_2290_state']->doing = true;
\DataMachine\Abilities\Media\ImageTemplateAbilities::ensure_registered();
$assert(
	'state 1: when doing_action fires, abilities register immediately via wp_register_ability()',
	isset( $GLOBALS['datamachine_2290_state']->registered['datamachine/render-image-template'] )
		&& isset( $GLOBALS['datamachine_2290_state']->registered['datamachine/list-image-templates'] )
		&& $GLOBALS['datamachine_2290_state']->registered['datamachine/render-image-template']['execute_callback'] instanceof Closure
		&& $GLOBALS['datamachine_2290_state']->registered['datamachine/list-image-templates']['execute_callback'] instanceof Closure
		&& empty( $GLOBALS['datamachine_2290_state']->hooked )
);

// --- State 2: pre-action — hook for later.
$reset();
\DataMachine\Abilities\Media\ImageTemplateAbilities::ensure_registered();
$assert(
	'state 2: when action has not fired, callback is hooked on wp_abilities_api_init',
	isset( $GLOBALS['datamachine_2290_state']->hooked['wp_abilities_api_init'] )
		&& count( $GLOBALS['datamachine_2290_state']->hooked['wp_abilities_api_init'] ) === 1
		&& empty( $GLOBALS['datamachine_2290_state']->registered )
);

// --- State 3: post-action — no-op instead of mutating registry internals.
$reset();
$GLOBALS['datamachine_2290_state']->did = 1;
\DataMachine\Abilities\Media\ImageTemplateAbilities::ensure_registered();
$assert(
	'state 3: when action already fired, abilities do not register late',
	empty( $GLOBALS['datamachine_2290_state']->registered )
);

$assert(
	'state 3: no-op path does not call wp_register_ability() (which would fire _doing_it_wrong)',
	0 === $GLOBALS['datamachine_2290_state']->doing_it_wrong
);

$assert(
	'state 3: no-op path does not double-hook the action',
	empty( $GLOBALS['datamachine_2290_state']->hooked )
);

// --- Idempotency: a second post-action ensure_registered() remains a no-op.
$prior_registered = $GLOBALS['datamachine_2290_state']->registered;
\DataMachine\Abilities\Media\ImageTemplateAbilities::ensure_registered();
$assert(
	'idempotent: second post-action ensure_registered() call is a no-op',
	$GLOBALS['datamachine_2290_state']->registered === $prior_registered
		&& 0 === $GLOBALS['datamachine_2290_state']->doing_it_wrong
);

// --- Constructor path still works (for the in-runtime defensive call site).
$reset();
$GLOBALS['datamachine_2290_state']->doing = true;
new \DataMachine\Abilities\Media\ImageTemplateAbilities();
$assert(
	'constructor path: instantiating the class triggers ensure_registered()',
	isset( $GLOBALS['datamachine_2290_state']->registered['datamachine/render-image-template'] )
);

if ( $failed > 0 ) {
	fwrite( fopen( 'php://stderr', 'w' ), "\nabilities-image-template-load-order-smoke: {$failed}/{$total} assertions failed\n" );
	exit( 1 );
}

echo "\nAll {$total} abilities-image-template-load-order assertions passed.\n";
