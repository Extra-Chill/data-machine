<?php
/**
 * Pure-PHP smoke test for Agents API registration semantics (#1639).
 *
 * Run with: php tests/agents-api-registration-smoke.php
 *
 * @package DataMachine\Tests
 */

$failures = array();
$passes   = 0;

echo "agents-api-registration-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

function agents_api_registration_reset(): void {
	WP_Agents_Registry::reset_for_tests();
	$GLOBALS['__agents_api_smoke_actions'] = array();
	$GLOBALS['__agents_api_smoke_wrong']   = array();
	$GLOBALS['__agents_api_smoke_current'] = array();
	$GLOBALS['__agents_api_smoke_done']    = array();
	do_action( 'init' );
}

echo "\n[1] Hook registration normalizes definitions without side effects:\n";
agents_api_registration_reset();
add_action(
	'wp_agents_api_init',
	static function (): void {
		wp_register_agent(
			new WP_Agent(
				'Example Agent!',
				array(
					'label'          => 'Example Agent',
					'description'    => 'Standalone module smoke',
					'memory_seeds'   => array( '../SOUL.md' => '/tmp/seed-soul.md' ),
					'owner_resolver' => static fn() => 7,
					'default_config' => array( 'default_provider' => 'openai' ),
				)
			)
		);
	}
);

$agents = wp_get_agents();
$agent  = wp_get_agent( 'Example Agent!' );
agents_api_smoke_assert_equals( array( 'example-agent' ), array_keys( $agents ), 'definition slug is normalized', $failures, $passes );
agents_api_smoke_assert_equals( 'Example Agent', $agents['example-agent']->get_label() ?? '', 'definition label is preserved', $failures, $passes );
agents_api_smoke_assert_equals( 'Standalone module smoke', $agents['example-agent']->get_description() ?? '', 'definition description is preserved', $failures, $passes );
agents_api_smoke_assert_equals( array( 'SOUL.md' => '/tmp/seed-soul.md' ), $agents['example-agent']->get_memory_seeds() ?? array(), 'memory seed filenames are sanitized', $failures, $passes );
agents_api_smoke_assert_equals( true, is_callable( $agents['example-agent']->get_owner_resolver() ?? null ), 'callable owner resolver is preserved', $failures, $passes );
agents_api_smoke_assert_equals( array( 'default_provider' => 'openai' ), $agents['example-agent']->get_default_config() ?? array(), 'default config is preserved', $failures, $passes );
agents_api_smoke_assert_equals( true, $agent instanceof WP_Agent, 'wp_get_agent returns an agent object', $failures, $passes );
agents_api_smoke_assert_equals( 'example-agent', $agent ? $agent->get_slug() : '', 'agent getter exposes slug', $failures, $passes );
agents_api_smoke_assert_equals( 'Example Agent', $agent ? $agent->get_label() : '', 'agent getter exposes label', $failures, $passes );
agents_api_smoke_assert_equals( 'Standalone module smoke', $agent ? $agent->get_description() : '', 'agent getter exposes description', $failures, $passes );
agents_api_smoke_assert_equals( array( 'SOUL.md' => '/tmp/seed-soul.md' ), $agent ? $agent->get_memory_seeds() : array(), 'agent getter exposes memory seeds', $failures, $passes );
agents_api_smoke_assert_equals( array( 'default_provider' => 'openai' ), $agent ? $agent->get_default_config() : array(), 'agent getter exposes default config', $failures, $passes );
agents_api_smoke_assert_equals( array(), $agent ? $agent->get_meta() : array( 'missing' ), 'agent getter exposes default meta', $failures, $passes );
agents_api_smoke_assert_equals( true, wp_has_agent( 'example-agent' ), 'wp_has_agent reports registered slug', $failures, $passes );
agents_api_smoke_assert_equals( array( 'example-agent' ), array_keys( wp_get_agents() ), 'wp_get_agents returns object map', $failures, $passes );

echo "\n[2] Public registration hook fires once on first read:\n";
agents_api_registration_reset();
$hook_calls = 0;
add_action(
	'wp_agents_api_init',
	static function () use ( &$hook_calls ): void {
		++$hook_calls;
		wp_register_agent( 'Hook Agent', array( 'label' => 'Hook Agent' ) );
	}
);

$agents = wp_get_agents();
agents_api_smoke_assert_equals( 1, $hook_calls, 'registration hook fires on first get_all call', $failures, $passes );
agents_api_smoke_assert_equals( array( 'hook-agent' ), array_keys( $agents ), 'hook-registered definition is collected', $failures, $passes );
agents_api_smoke_assert_equals( array( 'hook-agent' ), array_keys( wp_get_agents() ), 'registration hook does not refire on subsequent reads', $failures, $passes );
agents_api_smoke_assert_equals( 1, $hook_calls, 'hook call count remains stable after second read', $failures, $passes );

echo "\n[3] Invalid definitions are rejected or normalized predictably:\n";
agents_api_registration_reset();
add_action(
	'wp_agents_api_init',
	static function (): void {
		wp_register_agent( '!!!', array( 'label' => 'Invalid' ) );
		wp_register_agent( 'Minimal Agent' );
		wp_register_agent(
			'Messy Seeds',
			array(
				'memory_seeds' => array(
					'../MEMORY.md' => '/tmp/MEMORY.md',
					''             => '/tmp/empty.md',
					'empty-path.md' => '',
				),
			)
		);
		wp_register_agent( 'Bad Resolver', array( 'owner_resolver' => 'not callable' ) );
		wp_register_agent( 'Bad Seeds', array( 'memory_seeds' => 'not an array' ) );
		wp_register_agent( 'Unknown Property', array( 'label' => 'Unknown Property', 'mystery' => true ) );
	}
);

$agents = wp_get_agents();
agents_api_smoke_assert_equals( array( 'minimal-agent', 'messy-seeds', 'unknown-property' ), array_keys( $agents ), 'empty slugs are ignored while valid slugs are kept', $failures, $passes );
agents_api_smoke_assert_equals( 'minimal-agent', $agents['minimal-agent']->get_label() ?? '', 'missing label falls back to slug', $failures, $passes );
agents_api_smoke_assert_equals( array( 'MEMORY.md' => '/tmp/MEMORY.md' ), $agents['messy-seeds']->get_memory_seeds() ?? array(), 'empty memory seed keys and paths are dropped', $failures, $passes );
agents_api_smoke_assert_equals( false, isset( $agents['bad-resolver'] ), 'non-callable owner resolver rejects definition', $failures, $passes );
agents_api_smoke_assert_equals( false, isset( $agents['bad-seeds'] ), 'non-array memory seeds reject definition', $failures, $passes );
agents_api_smoke_assert_equals( 'Unknown Property', $agents['unknown-property']->get_label() ?? '', 'unknown properties do not block otherwise valid definitions', $failures, $passes );
agents_api_smoke_assert_equals( 4, count( $GLOBALS['__agents_api_smoke_wrong'] ), 'invalid registrations emit doing-it-wrong notices', $failures, $passes );

echo "\n[4] Duplicate registration and lifecycle errors follow core shape:\n";
agents_api_registration_reset();
add_action(
	'wp_agents_api_init',
	static function (): void {
		wp_register_agent( 'duplicate-agent', array( 'label' => 'First Label' ) );
		wp_register_agent( 'duplicate-agent', array( 'label' => 'Second Label' ) );
	}
);
$agents = wp_get_agents();
agents_api_smoke_assert_equals( 'First Label', $agents['duplicate-agent']->get_label() ?? '', 'duplicate registrations keep the first definition', $failures, $passes );
agents_api_smoke_assert_equals( 1, count( $GLOBALS['__agents_api_smoke_wrong'] ), 'duplicate registration emits doing-it-wrong notice', $failures, $passes );
$GLOBALS['__agents_api_smoke_wrong'] = array();
wp_register_agent( 'outside-hook', array( 'label' => 'Outside Hook' ) );
agents_api_smoke_assert_equals( 1, count( $GLOBALS['__agents_api_smoke_wrong'] ), 'outside-hook direct registration is rejected', $failures, $passes );
$removed = wp_unregister_agent( 'duplicate-agent' );
agents_api_smoke_assert_equals( true, $removed instanceof WP_Agent, 'wp_unregister_agent returns removed object', $failures, $passes );
agents_api_smoke_assert_equals( false, wp_has_agent( 'duplicate-agent' ), 'wp_unregister_agent removes registered slug', $failures, $passes );
agents_api_smoke_assert_equals( null, wp_get_agent( 'duplicate-agent' ), 'wp_get_agent returns null after unregister', $failures, $passes );

agents_api_smoke_finish( 'Agents API registration', $failures, $passes );
