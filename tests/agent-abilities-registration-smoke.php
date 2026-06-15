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
	class AbilityCategories {
		public static function ensure_registered(): void {}
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

	$GLOBALS['agent_abilities_actions']              = array();
	$GLOBALS['agent_abilities_registered_abilities'] = array();
	$GLOBALS['agent_abilities_did_action']           = true;
	agent_abilities_reset_registered_guard();

	new \DataMachine\Abilities\AgentAbilities();
	agent_abilities_assert( array() === $GLOBALS['agent_abilities_registered_abilities'], 'late construction does not register outside the abilities lifecycle' );

	$GLOBALS['agent_abilities_doing_action'] = true;
	agent_abilities_reset_registered_guard();
	new \DataMachine\Abilities\AgentAbilities();
	$GLOBALS['agent_abilities_doing_action'] = false;
	agent_abilities_assert( isset( $GLOBALS['agent_abilities_registered_abilities']['datamachine/run-agent-bundle'] ), 'late no-op construction does not poison a later in-lifecycle registration' );

	echo "OK: agent abilities registration lifecycle smoke passed\n";
}
