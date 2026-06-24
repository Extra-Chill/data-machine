<?php
/**
 * Regression smoke for Data Machine agent ability registration lifecycle.
 *
 * Run with: php tests/agent-abilities-registration-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}

	if ( function_exists( 'wp_get_ability' ) ) {
		// Real WordPress runtime.
		// The harness boots a bare WordPress with the plugin DIRECTORY mounted
		// but NOT activated, so Data Machine's normal `plugins_loaded`
		// registration never runs. This mirrors headless runtime tasks that need
		// `datamachine/run-agent-bundle` to register on demand, regardless of
		// request shape.
		//
		// Load the agent ability registration the same unconditional way
		// data-machine.php does at file scope, then assert the ability resolves.
		// `wp_get_ability()` lazily fires `wp_abilities_api_init`; the classes
		// handle every timing state (hook before the fire, register during it,
		// or register late through the registry instance after it).
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

	if ( ! class_exists( 'WP_Ability' ) ) {
		class WP_Ability {}
	}

	$GLOBALS['agent_abilities_actions']              = array();
	$GLOBALS['agent_abilities_doing_action']         = false;
	$GLOBALS['agent_abilities_did_action']           = false;
	$GLOBALS['agent_abilities_registered_abilities'] = array();

	// Minimal fake of the WordPress abilities registry so the late-registration
	// path (used in headless runtime load order, where the plugin
	// is included AFTER `wp_abilities_api_init` has already fired) can be
	// exercised without a full WordPress runtime. Mirrors how
	// `WP_Abilities_Registry::register()` has NO lifecycle guard — only the
	// `wp_register_ability()` wrapper does.
	if ( ! class_exists( 'WP_Abilities_Registry' ) ) {
		class WP_Abilities_Registry {
			private static ?WP_Abilities_Registry $instance = null;
			public static function get_instance(): self {
				if ( null === self::$instance ) {
					self::$instance = new self();
				}
				return self::$instance;
			}
			public function is_registered( $name ) {
				return isset( $GLOBALS['agent_abilities_registered_abilities'][ $name ] );
			}
			public function register( $name, $args ) {
				$GLOBALS['agent_abilities_registered_abilities'][ $name ] = $args;
				return new WP_Ability();
			}
		}
	}

	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = 'default' ) {
			return $text;
		}
	}

	if ( ! function_exists( 'doing_action' ) ) {
		function doing_action( $hook ) {
			return 'wp_abilities_api_init' === $hook && $GLOBALS['agent_abilities_doing_action'];
		}
	}

	if ( ! function_exists( 'did_action' ) ) {
		function did_action( $hook ) {
			return 'wp_abilities_api_init' === $hook && $GLOBALS['agent_abilities_did_action'] ? 1 : 0;
		}
	}

	if ( ! function_exists( 'add_action' ) ) {
		function add_action( $hook, $callback ) {
			$GLOBALS['agent_abilities_actions'][ $hook ][] = $callback;
		}
	}

	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
			$GLOBALS['agent_abilities_filters'][ $hook ][ $priority ][] = compact( 'callback', 'accepted_args' );
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $hook, $value, ...$args ) {
			if ( empty( $GLOBALS['agent_abilities_filters'][ $hook ] ) ) {
				return $value;
			}

			ksort( $GLOBALS['agent_abilities_filters'][ $hook ] );
			foreach ( $GLOBALS['agent_abilities_filters'][ $hook ] as $callbacks ) {
				foreach ( $callbacks as $entry ) {
					$accepted = (int) $entry['accepted_args'];
					$value    = call_user_func_array( $entry['callback'], array_slice( array_merge( array( $value ), $args ), 0, $accepted ) );
				}
			}

			return $value;
		}
	}

	if ( ! function_exists( 'wp_register_ability' ) ) {
		function wp_register_ability( $name, $args ) {
			$GLOBALS['agent_abilities_registered_abilities'][ $name ] = $args;
			return new WP_Ability();
		}
	}

	function agent_abilities_assert( $condition, $message ) {
		if ( ! $condition ) {
			echo "FAIL: {$message}\n";
			exit( 1 );
		}
	}

	function agent_abilities_reset_registered_guard() {
		$property = new ReflectionProperty( \DataMachine\Abilities\AgentAbilities::class, 'registered' );
		$property->setValue( null, false );
	}
}

namespace DataMachine\Abilities {
	// Conditional so it is NOT compile-time hoisted: the real-WP branch at the
	// top of this file requires the real AbilityCategories class and returns
	// before reaching the pure-PHP harness below, so this stub must never clash
	// with it.
	if ( ! class_exists( __NAMESPACE__ . '\\AbilityCategories' ) ) {
		class AbilityCategories {
			public static function ensure_registered(): void {}
		}
	}
}

namespace {
	require_once __DIR__ . '/../vendor/autoload.php';
	$GLOBALS['agent_abilities_actions'] = array();
	require_once __DIR__ . '/../inc/Abilities/AgentAbilities.php';

	new \DataMachine\Abilities\AgentAbilities();
	agent_abilities_assert( ! empty( $GLOBALS['agent_abilities_actions']['wp_abilities_api_init'] ), 'constructor hooks registration before wp_abilities_api_init fires' );

	$GLOBALS['agent_abilities_doing_action'] = true;
	$GLOBALS['agent_abilities_did_action']   = true;
	foreach ( $GLOBALS['agent_abilities_actions']['wp_abilities_api_init'] as $callback ) {
		call_user_func( $callback );
	}
	$GLOBALS['agent_abilities_doing_action'] = false;
	agent_abilities_assert( isset( $GLOBALS['agent_abilities_registered_abilities']['datamachine/run-agent-bundle'] ), 'run-agent-bundle registers during wp_abilities_api_init' );

	// Headless runtime load order: the host boots WordPress
	// through `wp-load.php` (firing the one-shot `wp_abilities_api_init`) and
	// only THEN includes the plugin file. The constructor sees the action has
	// already completed and must register immediately through the registry
	// instance, NOT silently no-op (the original bug that left
	// `datamachine/run-agent-bundle` unregistered for headless runtime runs).
	$GLOBALS['agent_abilities_actions']              = array();
	$GLOBALS['agent_abilities_registered_abilities'] = array();
	$GLOBALS['agent_abilities_doing_action']         = false;
	$GLOBALS['agent_abilities_did_action']           = true;
	agent_abilities_reset_registered_guard();

	new \DataMachine\Abilities\AgentAbilities();
	agent_abilities_assert(
		isset( $GLOBALS['agent_abilities_registered_abilities']['datamachine/run-agent-bundle'] ),
		'late construction (post-init, headless runtime load order) registers run-agent-bundle via the registry'
	);

	// Re-constructing after a successful late registration must remain
	// idempotent — the registry `is_registered()` guard prevents duplicate
	// registration and a `_doing_it_wrong()` notice.
	$already = $GLOBALS['agent_abilities_registered_abilities']['datamachine/run-agent-bundle'];
	agent_abilities_reset_registered_guard();
	new \DataMachine\Abilities\AgentAbilities();
	agent_abilities_assert(
		$already === $GLOBALS['agent_abilities_registered_abilities']['datamachine/run-agent-bundle'],
		'repeat late construction is idempotent (registry is_registered guard holds)'
	);

	echo "OK: agent abilities registration lifecycle smoke passed\n";
}
