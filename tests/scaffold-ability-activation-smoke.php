<?php
/**
 * Smoke test for scaffold ability access during activation.
 *
 * Run with: php tests/scaffold-ability-activation-smoke.php
 */

namespace {
	define( 'ABSPATH', sys_get_temp_dir() . '/datamachine-scaffold-ability-activation/' );

	class WP_Ability {}

	class WP_Abilities_Registry {
		public static function get_instance(): ?WP_Abilities_Registry {
			return null;
		}
	}

	function wp_get_ability( string $name ): ?WP_Ability {
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
		fwrite( STDERR, "FAIL: null registry should defer scaffold ability lookup\n" );
		exit( 1 );
	}

	echo "ok - null registry defers scaffold ability lookup\n";
	echo "All scaffold ability activation assertions passed.\n";
}
