<?php
/**
 * Smoke test for AgentAbilities registration timing.
 *
 * Run with: php tests/agent-abilities-late-registration-smoke.php
 */

namespace {
	define( 'ABSPATH', sys_get_temp_dir() . '/datamachine-agent-abilities-late-registration/' );

	$GLOBALS['datamachine_test_registered_abilities'] = array();
	$GLOBALS['datamachine_test_added_actions']        = array();

	function doing_action( string $hook = '' ): bool {
		return false;
	}

	function did_action( string $hook = '' ): int {
		return 'wp_abilities_api_init' === $hook ? 1 : 0;
	}

	function add_action( string $hook, callable $callback ): void {
		$GLOBALS['datamachine_test_added_actions'][] = array( $hook, $callback );
	}

	function wp_register_ability( string $name, array $args ): void {
		$GLOBALS['datamachine_test_registered_abilities'][ $name ] = $args;
	}
}

namespace DataMachine\Abilities {
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

	echo "=== AgentAbilities Registration Timing Smoke (#1846) ===\n";

	new AgentAbilities();

	$registered = array_keys( $GLOBALS['datamachine_test_registered_abilities'] );

	$assert( 'does not call wp_register_ability after wp_abilities_api_init has fired', array() === $registered );
	$assert( 'does not defer to an already-fired action', array() === $GLOBALS['datamachine_test_added_actions'] );

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
