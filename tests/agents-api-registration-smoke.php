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

echo "\n[1] Direct registration normalizes definitions without side effects:\n";
WP_Agents_Registry::reset_for_tests();
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

$definitions = WP_Agents_Registry::get_all();
agents_api_smoke_assert_equals( array( 'example-agent' ), array_keys( $definitions ), 'definition slug is normalized', $failures, $passes );
agents_api_smoke_assert_equals( 'Example Agent', $definitions['example-agent']['label'] ?? '', 'definition label is preserved', $failures, $passes );
agents_api_smoke_assert_equals( 'Standalone module smoke', $definitions['example-agent']['description'] ?? '', 'definition description is preserved', $failures, $passes );
agents_api_smoke_assert_equals( array( 'SOUL.md' => '/tmp/seed-soul.md' ), $definitions['example-agent']['memory_seeds'] ?? array(), 'memory seed filenames are sanitized', $failures, $passes );
agents_api_smoke_assert_equals( true, is_callable( $definitions['example-agent']['owner_resolver'] ?? null ), 'callable owner resolver is preserved', $failures, $passes );
agents_api_smoke_assert_equals( array( 'default_provider' => 'openai' ), $definitions['example-agent']['default_config'] ?? array(), 'default config is preserved', $failures, $passes );

echo "\n[2] Public registration hook fires once on first read:\n";
WP_Agents_Registry::reset_for_tests();
$hook_calls = 0;
add_action(
	'wp_agents_api_init',
	static function () use ( &$hook_calls ): void {
		++$hook_calls;
		wp_register_agent( 'Hook Agent', array( 'label' => 'Hook Agent' ) );
	}
);

$definitions = WP_Agents_Registry::get_all();
agents_api_smoke_assert_equals( 1, $hook_calls, 'registration hook fires on first get_all call', $failures, $passes );
agents_api_smoke_assert_equals( array( 'hook-agent' ), array_keys( $definitions ), 'hook-registered definition is collected', $failures, $passes );
agents_api_smoke_assert_equals( array( 'hook-agent' ), array_keys( WP_Agents_Registry::get_all() ), 'registration hook does not refire on subsequent reads', $failures, $passes );
agents_api_smoke_assert_equals( 1, $hook_calls, 'hook call count remains stable after second read', $failures, $passes );

echo "\n[3] Invalid definitions are ignored or normalized predictably:\n";
WP_Agents_Registry::reset_for_tests();
$GLOBALS['__agents_api_smoke_actions'] = array();
wp_register_agent( '!!!', array( 'label' => 'Invalid' ) );
wp_register_agent( 'Minimal Agent' );
wp_register_agent(
	'Messy Seeds',
	array(
		'memory_seeds'   => array(
			'../MEMORY.md' => '/tmp/MEMORY.md',
			''             => '/tmp/empty.md',
			'empty-path.md' => '',
		),
		'owner_resolver' => 'not callable',
	)
);

$definitions = WP_Agents_Registry::get_all();
agents_api_smoke_assert_equals( array( 'minimal-agent', 'messy-seeds' ), array_keys( $definitions ), 'empty slugs are ignored while valid slugs are kept', $failures, $passes );
agents_api_smoke_assert_equals( 'minimal-agent', $definitions['minimal-agent']['label'] ?? '', 'missing label falls back to slug', $failures, $passes );
agents_api_smoke_assert_equals( array( 'MEMORY.md' => '/tmp/MEMORY.md' ), $definitions['messy-seeds']['memory_seeds'] ?? array(), 'empty memory seed keys and paths are dropped', $failures, $passes );
agents_api_smoke_assert_equals( null, $definitions['messy-seeds']['owner_resolver'] ?? null, 'non-callable owner resolver is dropped', $failures, $passes );

agents_api_smoke_finish( 'Agents API registration', $failures, $passes );
