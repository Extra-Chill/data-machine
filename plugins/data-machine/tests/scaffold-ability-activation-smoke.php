<?php
/**
 * Smoke test for scaffold ability access during activation.
 *
 * Run with: php tests/scaffold-ability-activation-smoke.php
 */

namespace {
	define( 'ABSPATH', sys_get_temp_dir() . '/datamachine-scaffold-ability-activation/' );

	$GLOBALS['datamachine_test_state'] = (object) array(
		'did_init'            => 0,
		'wp_get_ability_calls' => 0,
	);

	class WP_Ability {}

	function did_action( string $hook = '' ): int {
		return 'init' === $hook ? $GLOBALS['datamachine_test_state']->did_init : 0;
	}

	function wp_get_ability( string $name ): ?WP_Ability {
		++$GLOBALS['datamachine_test_state']->wp_get_ability_calls;
		return new WP_Ability();
	}
}

namespace DataMachine\Abilities\File {
	require_once dirname( __DIR__ ) . '/inc/Abilities/File/ScaffoldAbilities.php';
}

namespace {
	use DataMachine\Abilities\File\ScaffoldAbilities;

	echo "=== Scaffold Ability Activation Smoke ===\n";

	$ability = ScaffoldAbilities::get_ability();
	if ( null !== $ability ) {
		fwrite( fopen( 'php://stderr', 'w' ), "FAIL: pre-init scaffold ability lookup should defer\n" );
		exit( 1 );
	}
	if ( 0 !== $GLOBALS['datamachine_test_state']->wp_get_ability_calls ) {
		fwrite( fopen( 'php://stderr', 'w' ), "FAIL: pre-init scaffold ability lookup should not call wp_get_ability\n" );
		exit( 1 );
	}

	echo "ok - pre-init scaffold ability lookup defers without touching the registry\n";

	$GLOBALS['datamachine_test_state']->did_init = 1;
	$ability = ScaffoldAbilities::get_ability();
	if ( ! $ability instanceof WP_Ability ) {
		fwrite( fopen( 'php://stderr', 'w' ), "FAIL: post-init scaffold ability lookup should use wp_get_ability\n" );
		exit( 1 );
	}

	echo "ok - post-init scaffold ability lookup uses the public wrapper\n";
	echo "All scaffold ability activation assertions passed.\n";
}
