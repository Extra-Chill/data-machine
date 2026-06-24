<?php
/**
 * Smoke test for AgentAbilities registration timing.
 *
 * Run with: php tests/agent-abilities-late-registration-smoke.php
 *
 * Runs in two modes:
 *  - Real WordPress runtime (which boots WordPress and includes the plugin):
 *    assert the canonical
 *    `datamachine/run-agent-bundle` ability is registered. This is the headless
 *    runtime-host load order (WordPress boots, then includes the plugin file) that
 *    must resolve the ability — see Extra-Chill/data-machine#2629.
 *  - Standalone pure-PHP (`php tests/...`): drive the three registration timing
 *    states with stubbed WordPress lifecycle functions.
 */

namespace {
	// Real WordPress runtime: the lifecycle functions and registry already
	// exist, so the pure-PHP stubs below must NOT be declared (they would fatal
	// with "Cannot redeclare"). Assert the real registration outcome and return.
	if ( function_exists( 'wp_get_ability' ) ) {
		// Real WordPress runtime.
		// The harness boots a bare WordPress with the plugin DIRECTORY mounted
		// but NOT activated, so load the agent ability registration the same
		// unconditional way data-machine.php does at file scope, then assert the
		// ability resolves through `wp_get_ability()` (which lazily fires
		// `wp_abilities_api_init`). This exercises the real registration code
		// path headless runtime runs depend on for `datamachine/run-agent-bundle`.
		if ( ! class_exists( '\DataMachine\Abilities\AgentAbilities' ) ) {
			require_once dirname( __DIR__ ) . '/vendor/autoload.php';
			require_once dirname( __DIR__ ) . '/inc/Abilities/AbilityCategories.php';
			require_once dirname( __DIR__ ) . '/inc/Abilities/AgentAbilities.php';
		}
		\DataMachine\Abilities\AbilityCategories::ensure_registered();
		new \DataMachine\Abilities\AgentAbilities();

		$ability = wp_get_ability( 'datamachine/run-agent-bundle' );
		if ( ! $ability || ! method_exists( $ability, 'execute' ) ) {
			echo "FAIL: datamachine/run-agent-bundle is not registered in WordPress runtime\n";
			exit( 1 );
		}

		echo "OK: agent bundle ability is registered in WordPress runtime\n";
		return;
	}

	define( 'ABSPATH', sys_get_temp_dir() . '/datamachine-agent-abilities-late-registration/' );

	$GLOBALS['datamachine_test_state'] = (object) array(
		'doing'         => false,
		'did'           => 0,
		'registered'    => array(),
		'added_actions' => array(),
	);

	// All stubs below are declared conditionally so they never clash with a
	// real WordPress runtime. (The real-WP runtime is handled by the early
	// return above; these conditionals are belt-and-suspenders so the file is
	// always safe to include.)
	if ( ! class_exists( 'WP_Ability' ) ) {
		class WP_Ability {}
	}

	if ( ! function_exists( 'doing_action' ) ) {
		function doing_action( string $hook = '' ): bool {
			return 'wp_abilities_api_init' === $hook && $GLOBALS['datamachine_test_state']->doing;
		}
	}

	if ( ! function_exists( 'did_action' ) ) {
		function did_action( string $hook = '' ): int {
			return 'wp_abilities_api_init' === $hook ? $GLOBALS['datamachine_test_state']->did : 0;
		}
	}

	if ( ! function_exists( 'add_action' ) ) {
		function add_action( string $hook, callable $callback ): void {
			$GLOBALS['datamachine_test_state']->added_actions[] = array( $hook, $callback );
		}
	}

	if ( ! function_exists( 'wp_register_ability' ) ) {
		function wp_register_ability( string $name, array $args ): ?WP_Ability {
			if ( ! doing_action( 'wp_abilities_api_init' ) ) {
				return null;
			}

			$GLOBALS['datamachine_test_state']->registered[ $name ] = $args;
			return new WP_Ability();
		}
	}

	// Minimal fake of the abilities registry so the late-registration path
	// (headless runtime load order, where the plugin file is
	// included AFTER `wp_abilities_api_init` has already fired) can be
	// exercised. Mirrors core: `WP_Abilities_Registry::register()` has NO
	// lifecycle guard — only the `wp_register_ability()` wrapper does.
	if ( ! class_exists( 'WP_Abilities_Registry' ) ) {
		class WP_Abilities_Registry {
			private static ?WP_Abilities_Registry $instance = null;
			public static function get_instance(): self {
				if ( null === self::$instance ) {
					self::$instance = new self();
				}
				return self::$instance;
			}
			public function is_registered( string $name ): bool {
				return isset( $GLOBALS['datamachine_test_state']->registered[ $name ] );
			}
			public function register( string $name, array $args ): WP_Ability {
				$GLOBALS['datamachine_test_state']->registered[ $name ] = $args;
				return new WP_Ability();
			}
		}
	}
}

namespace DataMachine\Engine\Bundle {
	if ( ! class_exists( __NAMESPACE__ . '\\AgentBundleArtifactRebase' ) ) {
		class AgentBundleArtifactRebase {
			public const POLICY_CONSERVATIVE = 'conservative';
			public const POLICY_BURN_IN_SAFE = 'burn-in-safe';
		}
	}
}

namespace DataMachine\Abilities {
	require_once dirname( __DIR__ ) . '/inc/Abilities/AbilityRegistration.php';
	require_once dirname( __DIR__ ) . '/inc/Abilities/AgentAbilities.php';
}

namespace {
	use DataMachine\Abilities\AgentAbilities;

	$assertions = 0;
	$assert     = function ( string $label, bool $condition ) use ( &$assertions ): void {
		++$assertions;
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$label}\n" );
			exit( 1 );
		}

		echo "ok - {$label}\n";
	};

	$reset_registered_guard = static function (): void {
		$reflection = new ReflectionClass( AgentAbilities::class );
		$prop       = $reflection->getProperty( 'registered' );
		$prop->setValue( null, false );
	};

	$reset_state = static function () use ( $reset_registered_guard ): void {
		$GLOBALS['datamachine_test_state']->doing         = false;
		$GLOBALS['datamachine_test_state']->did           = 0;
		$GLOBALS['datamachine_test_state']->registered    = array();
		$GLOBALS['datamachine_test_state']->added_actions = array();
		$reset_registered_guard();
	};

	echo "=== AgentAbilities Registration Timing Smoke (#2523) ===\n";

	$source = file_get_contents( dirname( __DIR__ ) . '/inc/Abilities/AgentAbilities.php' ) ?: '';
	$assert( 'agent abilities support a late registry-backed registration path for headless runtimes', str_contains( $source, 'WP_Abilities_Registry::get_instance()' ) );
	$assert( 'late path is guarded by is_registered() for idempotency', str_contains( $source, 'is_registered' ) );
	$assert( 'agent abilities document the headless runtime load order', str_contains( $source, 'headless runtime' ) );

	$reset_state();
	new AgentAbilities();
	$assert( 'pre-action constructor hooks wp_abilities_api_init registration', 1 === count( $GLOBALS['datamachine_test_state']->added_actions ) );
	$assert( 'pre-action constructor does not register immediately', array() === $GLOBALS['datamachine_test_state']->registered );

	$GLOBALS['datamachine_test_state']->doing = true;
	$GLOBALS['datamachine_test_state']->did   = 1;
	foreach ( $GLOBALS['datamachine_test_state']->added_actions as $action ) {
		$action[1]();
	}
	$GLOBALS['datamachine_test_state']->doing = false;

	$registered = array_keys( $GLOBALS['datamachine_test_state']->registered );
	$assert( 'normal bootstrap registers agent bundle/import abilities during wp_abilities_api_init', in_array( 'datamachine/import-agent', $registered, true ) && in_array( 'datamachine/run-agent-bundle', $registered, true ) );

	// Headless runtime load order: the host boots WordPress
	// through `wp-load.php` (firing the one-shot `wp_abilities_api_init`) and
	// only THEN includes the plugin file. The constructor must register
	// immediately through the registry instance — NOT silently no-op, which was
	// the original bug that left `datamachine/run-agent-bundle` unregistered for
	// headless runtime runs (Extra-Chill/data-machine#2629).
	$reset_state();
	$GLOBALS['datamachine_test_state']->did = 1;
	new AgentAbilities();
	$post_registered = array_keys( $GLOBALS['datamachine_test_state']->registered );
	$assert( 'post-action constructor registers run-agent-bundle via the registry (headless runtime load order)', in_array( 'datamachine/run-agent-bundle', $post_registered, true ) );
	$assert( 'post-action constructor registers import-agent via the registry', in_array( 'datamachine/import-agent', $post_registered, true ) );
	$assert( 'post-action constructor does not defer to an already-fired action', array() === $GLOBALS['datamachine_test_state']->added_actions );

	$plugin_source   = file_get_contents( dirname( __DIR__ ) . '/data-machine.php' ) ?: '';
	$provider_offset = strpos( $plugin_source, 'new \\DataMachine\\Engine\\AI\\System\\SystemAgentServiceProvider();' );
	$agent_offset    = strpos( $plugin_source, 'new \\DataMachine\\Abilities\\AgentAbilities();' );
	$upsert_offset   = strpos( $plugin_source, 'new \\DataMachine\\Abilities\\Content\\UpsertPostAbility();' );

	$assert( 'system provider is present', false !== $provider_offset );
	$assert( 'agent abilities are present', false !== $agent_offset );
	$assert( 'upsert-post ability is present', false !== $upsert_offset );
	$assert( 'system provider initializes after agent abilities', $agent_offset < $provider_offset );
	$assert( 'system provider initializes after upsert-post ability', $upsert_offset < $provider_offset );

	echo "All {$assertions} AgentAbilities registration timing assertions passed.\n";
}
