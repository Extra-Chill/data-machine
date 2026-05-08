<?php
/**
 * Smoke test for AgentAbilities registration after wp_abilities_api_init fired.
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

	echo "=== AgentAbilities Late Registration Smoke (#1846) ===\n";

	new AgentAbilities();

	$registered = array_keys( $GLOBALS['datamachine_test_registered_abilities'] );
	$expected   = array(
		'datamachine/export-agent',
		'datamachine/rename-agent',
		'datamachine/list-agents',
		'datamachine/create-agent',
		'datamachine/import-agent',
		'datamachine/get-agent',
		'datamachine/update-agent',
		'datamachine/delete-agent',
	);

	$assert( 'registers immediately after wp_abilities_api_init has fired', in_array( 'datamachine/import-agent', $registered, true ) );
	$assert( 'registers all agent abilities', array() === array_diff( $expected, $registered ) );
	$assert( 'does not defer to an already-fired action', array() === $GLOBALS['datamachine_test_added_actions'] );

	echo "All {$assertions} AgentAbilities late registration assertions passed.\n";
}
