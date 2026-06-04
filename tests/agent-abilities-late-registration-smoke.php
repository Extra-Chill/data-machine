<?php
/**
 * Smoke test for AgentAbilities registration timing.
 *
 * Run with: php tests/agent-abilities-late-registration-smoke.php
 */

namespace {
	define( 'ABSPATH', sys_get_temp_dir() . '/datamachine-agent-abilities-late-registration/' );

	$GLOBALS['datamachine_test_state'] = (object) array(
		'doing'         => false,
		'did'           => 0,
		'registered'    => array(),
		'added_actions' => array(),
	);

	class WP_Ability {}

	function doing_action( string $hook = '' ): bool {
		return 'wp_abilities_api_init' === $hook && $GLOBALS['datamachine_test_state']->doing;
	}

	function did_action( string $hook = '' ): int {
		return 'wp_abilities_api_init' === $hook ? $GLOBALS['datamachine_test_state']->did : 0;
	}

	function add_action( string $hook, callable $callback ): void {
		$GLOBALS['datamachine_test_state']->added_actions[] = array( $hook, $callback );
	}

	function wp_register_ability( string $name, array $args ): ?WP_Ability {
		if ( ! doing_action( 'wp_abilities_api_init' ) ) {
			return null;
		}

		$GLOBALS['datamachine_test_state']->registered[ $name ] = $args;
		return new WP_Ability();
	}
}

namespace DataMachine\Engine\Bundle {
	class AgentBundleArtifactRebase {
		public const POLICY_CONSERVATIVE = 'conservative';
		public const POLICY_BURN_IN_SAFE = 'burn-in-safe';
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
	$assert( 'agent abilities avoid direct WP_Abilities_Registry registration', ! str_contains( $source, 'WP_Abilities_Registry::get_instance()' ) );
	$assert( 'agent abilities document lifecycle-scoped registration', str_contains( $source, 'official lifecycle' ) );

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

	$reset_state();
	$GLOBALS['datamachine_test_state']->did = 1;
	new AgentAbilities();
	$assert( 'post-action constructor does not call wp_register_ability after wp_abilities_api_init has fired', array() === $GLOBALS['datamachine_test_state']->registered );
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
