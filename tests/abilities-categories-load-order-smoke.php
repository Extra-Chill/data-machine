<?php
/**
 * Regression smoke for #2287: ability categories must register before any
 * ability registration, regardless of which plugin instantiates the
 * abilities registry first.
 *
 * `AbilityCategories::ensure_registered()` must use the public lifecycle-safe
 * registration path:
 *      - `doing_action()` → register immediately
 *      - `! did_action()` → hook for later
 *      - already-fired → no-op instead of writing through registry internals
 *
 * Run with: php tests/abilities-categories-load-order-smoke.php
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
// ============================================================
// Behavioral simulation: load class under three stubbed states
// ============================================================
//
// This block stubs WordPress lifecycle functions (doing_action/did_action/
// add_action/wp_register_ability_category) to drive AbilityCategories through
// its three timing states. Those stubs are only installed when the real
// functions are absent (`if ( ! function_exists() )`), so under a real
// WordPress runtime they are inert:
// `doing_action( 'wp_abilities_api_categories_init' )` reflects the live
// dispatch state (false during the test) and the simulation can't control it,
// which makes state 1 fail spuriously. The behavioral path is exercised in the
// pure-PHP context, so skip the stub-driven simulation under WordPress.
if ( defined( 'WPINC' ) ) {
	if ( $failed > 0 ) {
		fwrite( fopen( 'php://stderr', 'w' ), "\nabilities-categories-load-order-smoke: {$failed}/{$total} assertions failed\n" );
		exit( 1 );
	}

	echo "\nabilities-categories-load-order behavioral simulation skipped under WordPress.\n";
	return;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/' );
}

// State container so closure stubs can mutate them.
$state = (object) array(
	'doing'      => false,
	'did'        => 0,
	'hooked'     => array(),
);

// Translation/hook stubs.
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		return $text;
	}
}
if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( $hook ) {
		global $datamachine_2287_state;
		return $datamachine_2287_state->doing && $hook === 'wp_abilities_api_categories_init';
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook ) {
		global $datamachine_2287_state;
		return $hook === 'wp_abilities_api_categories_init' ? $datamachine_2287_state->did : 0;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		global $datamachine_2287_state;
		$datamachine_2287_state->hooked[ $hook ][] = $callback;
		return true;
	}
}
if ( ! function_exists( 'wp_register_ability_category' ) ) {
	function wp_register_ability_category( $slug, $args ) {
		global $datamachine_2287_state;
		// Only succeed when the action is firing (mirror core's guard).
		if ( ! doing_action( 'wp_abilities_api_categories_init' ) ) {
			$datamachine_2287_state->doing_it_wrong = ( $datamachine_2287_state->doing_it_wrong ?? 0 ) + 1;
			return null;
		}
		$datamachine_2287_state->registered[ $slug ] = $args;
		return true;
	}
}

// Minimal fake of the category registry so the late-registration path
// (headless runtime load order: plugin included AFTER the
// one-shot `wp_abilities_api_categories_init` fired) can be exercised. Mirrors
// core: `WP_Ability_Categories_Registry::register()` has NO lifecycle guard —
// only the `wp_register_ability_category()` wrapper does.
if ( ! class_exists( 'WP_Ability_Categories_Registry' ) ) {
	class WP_Ability_Categories_Registry {
		private static ?WP_Ability_Categories_Registry $instance = null;
		public static function get_instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}
		public function is_registered( $slug ): bool {
			global $datamachine_2287_state;
			return isset( $datamachine_2287_state->registered[ $slug ] );
		}
		public function register( $slug, $args ): bool {
			global $datamachine_2287_state;
			$datamachine_2287_state->registered[ $slug ] = $args;
			return true;
		}
	}
}

$GLOBALS['datamachine_2287_state'] = $state;

require_once $plugin_root . '/inc/Abilities/AbilityCategories.php';

$reset = static function (): void {
	global $datamachine_2287_state;
	$datamachine_2287_state->doing  = false;
	$datamachine_2287_state->did    = 0;
	$datamachine_2287_state->hooked = array();
	$datamachine_2287_state->registered = array();
	$datamachine_2287_state->doing_it_wrong = 0;

	$reflection = new ReflectionClass( \DataMachine\Abilities\AbilityCategories::class );
	$prop       = $reflection->getProperty( 'registered' );
	$prop->setValue( null, false );

};

// --- State 1: doing_action — register immediately via helper.
$reset();
$state->doing = true;
$state->did   = 0;
\DataMachine\Abilities\AbilityCategories::ensure_registered();
$assert(
	'state 1: when doing_action fires, categories register immediately via wp_register_ability_category()',
	isset( $state->registered['datamachine-publishing'] )
		&& isset( $state->registered['datamachine-fetch'] )
		&& isset( $state->registered['datamachine-analytics'] )
		&& empty( $state->hooked )
);

// --- State 2: pre-action — hook for later.
$reset();
$state->doing = false;
$state->did   = 0;
\DataMachine\Abilities\AbilityCategories::ensure_registered();
$assert(
	'state 2: when action has not fired, register() is hooked on wp_abilities_api_categories_init',
	isset( $state->hooked['wp_abilities_api_categories_init'] )
		&& count( $state->hooked['wp_abilities_api_categories_init'] ) === 1
		&& empty( $state->registered )
);

// --- State 3: post-action (headless runtime load order).
// The one-shot `wp_abilities_api_categories_init` already fired during
// `wp-load.php` before `run-php` included the plugin file. Categories must
// register late via the registry instance so category-bound abilities (e.g.
// `datamachine/run-agent-bundle`) are not silently dropped by core's
// category-exists check. See Extra-Chill/data-machine#2629.
$reset();
$state->doing = false;
$state->did   = 1; // action has fired and completed
\DataMachine\Abilities\AbilityCategories::ensure_registered();
$assert(
	'state 3: when action already fired, categories register late via the registry instance',
	isset( $state->registered['datamachine-agent'] )
		&& isset( $state->registered['datamachine-publishing'] )
);

$assert(
	'state 3: late path does not call wp_register_ability_category() (which would fire _doing_it_wrong)',
	0 === ( $state->doing_it_wrong ?? 0 )
);

$assert(
	'state 3: late path does not hook the already-fired action',
	empty( $state->hooked )
);

// --- Idempotency: a second call after success is a no-op.
$prior = $state->registered;
\DataMachine\Abilities\AbilityCategories::ensure_registered();
$assert(
	'idempotent: second ensure_registered() call is a no-op (static guard)',
	$state->registered === $prior
);

// --- Provider composition: the lightweight path attaches category and ability hooks.
$reset();
require_once $plugin_root . '/inc/Abilities/AbilityRegistration.php';
if ( ! class_exists( 'DataMachine\\Abilities\\PermissionHelper' ) ) {
	eval(
		'namespace DataMachine\Abilities;
		class PermissionHelper {
			public static function can_manage(): bool { return true; }
		}'
	);
}
require_once $plugin_root . '/inc/Core/Bootstrap/AbilityServiceProvider.php';
\DataMachine\Core\Bootstrap\AbilityServiceProvider::register_lightweight();
$ability_hooks = count( $state->hooked['wp_abilities_api_init'] ?? array() );
$assert(
	'lightweight provider composes categories before lightweight abilities',
	1 === count( $state->hooked['wp_abilities_api_categories_init'] ?? array() )
		&& $ability_hooks >= 4
);

if ( $failed > 0 ) {
	fwrite( fopen( 'php://stderr', 'w' ), "\nabilities-categories-load-order-smoke: {$failed}/{$total} assertions failed\n" );
	exit( 1 );
}

echo "\nAll {$total} abilities-categories-load-order assertions passed.\n";
