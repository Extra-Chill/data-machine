<?php
/**
 * Regression smoke for #2287: ability categories must register before any
 * ability registration, regardless of which plugin instantiates the
 * abilities registry first.
 *
 * Two kinds of assertions:
 *
 * 1. Source-string assertions — `data-machine.php` must call
 *    `AbilityCategories::ensure_registered()` UNCONDITIONALLY at file
 *    include time, NOT only inside the gated runtime function. Categories
 *    are a contract depended on by every extension plugin and must be
 *    available on every request that touches the abilities API.
 *
 * 2. Behavioral assertions — `AbilityCategories::ensure_registered()` must
 *    handle all three timing states defensively:
 *      - `doing_action()` → register immediately
 *      - `! did_action()` → hook for later
 *      - already-fired → register directly via the registry instance
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

// ============================================================
// Source-string assertions
// ============================================================

$plugin_root = dirname( __DIR__ );
$bootstrap   = file_get_contents( $plugin_root . '/data-machine.php' );
$categories  = file_get_contents( $plugin_root . '/inc/Abilities/AbilityCategories.php' );

if ( false === $bootstrap || false === $categories ) {
	fwrite( STDERR, "FAIL: unable to read plugin source\n" );
	exit( 1 );
}

$assert(
	'data-machine.php calls ensure_registered() unconditionally at file load',
	str_contains( $bootstrap, 'Register ability categories unconditionally on every request' )
		&& str_contains( $bootstrap, "require_once __DIR__ . '/inc/Abilities/AbilityCategories.php';\n\\DataMachine\\Abilities\\AbilityCategories::ensure_registered();" )
);

$assert(
	'unconditional call site is OUTSIDE datamachine_run_datamachine_plugin()',
	(bool) preg_match(
		'/^}\s*\/\*\*\s*\*\s*Register ability categories unconditionally on every request\./m',
		$bootstrap
	)
);

$assert(
	'in-runtime call is still present (defensive idempotent path)',
	substr_count(
		$bootstrap,
		'DataMachine\\Abilities\\AbilityCategories::ensure_registered()'
	) >= 2
);

// ============================================================
// Behavioral assertions: defensive three-state pattern
// ============================================================

$assert(
	'ensure_registered() handles doing_action state',
	str_contains( $categories, "doing_action( 'wp_abilities_api_categories_init' )" )
);

$assert(
	'ensure_registered() handles pre-action state',
	str_contains( $categories, "! did_action( 'wp_abilities_api_categories_init' )" )
		&& str_contains( $categories, "add_action( 'wp_abilities_api_categories_init'" )
);

$assert(
	'ensure_registered() handles post-action state via registry instance',
	str_contains( $categories, 'WP_Ability_Categories_Registry::get_instance()' )
		&& str_contains( $categories, '$registry->register(' )
);

$assert(
	'post-action recovery path is idempotent (skips already-registered slugs)',
	str_contains( $categories, '$registry->is_registered( $slug )' )
);

$assert(
	'category definitions extracted into shared helper to avoid drift',
	str_contains( $categories, 'private static function get_category_definitions(): array' )
);

// ============================================================
// Behavioral simulation: load class under three stubbed states
// ============================================================

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
		global $dm_2287_state;
		return $dm_2287_state->doing && $hook === 'wp_abilities_api_categories_init';
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook ) {
		global $dm_2287_state;
		return $hook === 'wp_abilities_api_categories_init' ? $dm_2287_state->did : 0;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		global $dm_2287_state;
		$dm_2287_state->hooked[ $hook ][] = $callback;
		return true;
	}
}
if ( ! function_exists( 'wp_register_ability_category' ) ) {
	function wp_register_ability_category( $slug, $args ) {
		global $dm_2287_state;
		// Only succeed when the action is firing (mirror core's guard).
		if ( ! doing_action( 'wp_abilities_api_categories_init' ) ) {
			$dm_2287_state->doing_it_wrong = ( $dm_2287_state->doing_it_wrong ?? 0 ) + 1;
			return null;
		}
		$dm_2287_state->registered[ $slug ] = $args;
		return true;
	}
}

// Stub the categories registry singleton for the post-action recovery test.
if ( ! class_exists( 'WP_Ability_Categories_Registry' ) ) {
	class WP_Ability_Categories_Registry {
		/** @var array<string, array<string, mixed>> */
		public array $registered = array();
		private static ?self $instance = null;

		public static function get_instance(): ?self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public static function reset(): void {
			self::$instance = null;
		}

		public function is_registered( string $slug ): bool {
			return isset( $this->registered[ $slug ] );
		}

		public function register( string $slug, array $args ): bool {
			$this->registered[ $slug ] = $args;
			return true;
		}
	}
}

$GLOBALS['dm_2287_state'] = $state;

require_once $plugin_root . '/inc/Abilities/AbilityCategories.php';

$reset = static function (): void {
	global $dm_2287_state;
	$dm_2287_state->doing  = false;
	$dm_2287_state->did    = 0;
	$dm_2287_state->hooked = array();
	$dm_2287_state->registered = array();
	$dm_2287_state->doing_it_wrong = 0;

	$reflection = new ReflectionClass( \DataMachine\Abilities\AbilityCategories::class );
	$prop       = $reflection->getProperty( 'registered' );
	$prop->setAccessible( true );
	$prop->setValue( null, false );

	WP_Ability_Categories_Registry::reset();
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

// --- State 3: post-action — register directly via registry instance.
//
// This is the #2287 scenario: the abilities registry was instantiated by a
// frontend `wp_get_ability()` call (e.g. Frontend Agent Chat enqueue) before
// Data Machine's hook was attached. Without the recovery path, every
// `wp_register_ability( ..., [ 'category' => 'datamachine-publishing' ] )`
// in extension plugins triggers a `_doing_it_wrong` notice and the ability
// is dropped from the registry.
$reset();
$state->doing = false;
$state->did   = 1; // action has fired and completed
\DataMachine\Abilities\AbilityCategories::ensure_registered();
$registry = WP_Ability_Categories_Registry::get_instance();
$assert(
	'state 3 (regression for #2287): when action already fired, categories register directly via registry instance',
	$registry instanceof WP_Ability_Categories_Registry
		&& $registry->is_registered( 'datamachine-publishing' )
		&& $registry->is_registered( 'datamachine-fetch' )
		&& $registry->is_registered( 'datamachine-analytics' )
		&& $registry->is_registered( 'datamachine-media' )
);

$assert(
	'state 3: recovery path does not call wp_register_ability_category() (which would fire _doing_it_wrong)',
	0 === ( $state->doing_it_wrong ?? 0 )
);

$assert(
	'state 3: recovery path does not double-hook the action',
	empty( $state->hooked )
);

// --- Idempotency: a second call after success is a no-op.
$prior = $state->registered;
\DataMachine\Abilities\AbilityCategories::ensure_registered();
$assert(
	'idempotent: second ensure_registered() call is a no-op (static guard)',
	$state->registered === $prior
);

if ( $failed > 0 ) {
	fwrite( STDERR, "\nabilities-categories-load-order-smoke: {$failed}/{$total} assertions failed\n" );
	exit( 1 );
}

echo "\nAll {$total} abilities-categories-load-order assertions passed.\n";
