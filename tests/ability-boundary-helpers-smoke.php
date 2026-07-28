<?php
/**
 * Pure-PHP smoke test for ability registration and CLI runner helpers.
 *
 * Run with: php tests/ability-boundary-helpers-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Abilities {
	function doing_action( $hook = '' ) {
		global $datamachine_test_doing_action, $wp_current_filter;
		return 'wp_abilities_api_init' === $hook
			&& ( $datamachine_test_doing_action || in_array( $hook, $wp_current_filter, true ) );
	}

	function did_action( $hook = '' ) {
		global $datamachine_test_did_action;
		return 'wp_abilities_api_init' === $hook ? $datamachine_test_did_action : 0;
	}

	function add_action( $hook, $callback ) {
		global $datamachine_test_actions;
		$datamachine_test_actions[ $hook ][] = $callback;
	}
}

namespace DataMachine\Cli {
	function wp_get_ability( string $name ) {
		global $datamachine_test_abilities;
		return $datamachine_test_abilities[ $name ] ?? null;
	}

	function is_wp_error( $value ) {
		return false;
	}
}

namespace DataMachine\Core {

	function is_wp_error( $value ) {
		return false;
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$failed = 0;
	$total  = 0;

	$datamachine_test_doing_action = false;
	$datamachine_test_did_action   = 0;
	$datamachine_test_actions      = array();
	$datamachine_test_abilities    = array();
	$wp_current_filter             = array();

	if ( ! function_exists( 'wp_get_ability' ) ) {
		function wp_get_ability( string $name ) {
			return \DataMachine\Cli\wp_get_ability( $name );
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $value ) {
			return false;
		}
	}

class Datamachine_Test_Ability {
	public function execute( $input = null ) {
		return array(
			'success' => true,
			'input'   => $input,
		);
	}
}

require_once __DIR__ . '/../inc/Abilities/AbilityRegistration.php';
require_once __DIR__ . '/../inc/Core/AbilityResult.php';
require_once __DIR__ . '/../inc/Cli/AbilityRunner.php';

function assert_ability_boundary( string $name, bool $condition ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  PASS: {$name}\n";
		return;
	}
	echo "  FAIL: {$name}\n";
	++$failed;
}

echo "=== Ability Boundary Helpers Smoke ===\n";

$called = 0;
\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init(
	function () use ( &$called ) {
		++$called;
	}
);
assert_ability_boundary( 'registration hooks before wp_abilities_api_init fires', 1 === count( $datamachine_test_actions['wp_abilities_api_init'] ?? array() ) && 0 === $called );

$datamachine_test_doing_action = true;
\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init(
	function () use ( &$called ) {
		++$called;
	}
);
assert_ability_boundary( 'registration executes while wp_abilities_api_init is running', 1 === $called );

$datamachine_test_doing_action = false;
$datamachine_test_did_action   = 1;
$before_hook_count             = count( $datamachine_test_actions['wp_abilities_api_init'] ?? array() );
$called_during_late_context    = false;
\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init(
	function () use ( &$called, &$called_during_late_context ) {
		++$called;
		$called_during_late_context = \DataMachine\Abilities\doing_action( 'wp_abilities_api_init' );
	}
);
assert_ability_boundary( 'late registration executes without replaying wp_abilities_api_init', $before_hook_count === count( $datamachine_test_actions['wp_abilities_api_init'] ?? array() ) && 2 === $called && $called_during_late_context );

$datamachine_test_abilities['datamachine/example'] = new Datamachine_Test_Ability();
$result = \DataMachine\Cli\AbilityRunner::execute( 'datamachine/example', array( 'example' => true ) );
assert_ability_boundary( 'CLI runner executes registered abilities through wp_get_ability()', ! empty( $result['success'] ) && true === ( $result['input']['example'] ?? false ) );

$missing = \DataMachine\Cli\AbilityRunner::execute( 'datamachine/missing' );
assert_ability_boundary( 'CLI runner returns normalized failure for missing abilities', empty( $missing['success'] ) && false !== strpos( $missing['error'] ?? '', 'datamachine/missing' ) );

if ( $failed > 0 ) {
	echo "\nFAILURES: {$failed}/{$total}\n";
	exit( 1 );
}

echo "\nAll {$total} checks passed.\n";
}
