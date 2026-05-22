<?php
/**
 * Pure-PHP smoke test for projecting persisted Data Machine agents into Agents API.
 *
 * Run with: php tests/agents-api-persisted-agent-registry-smoke.php
 *
 * @package DataMachine\Tests
 */

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

require_once dirname( __DIR__ ) . '/inc/Core/Database/BaseRepository.php';
require_once dirname( __DIR__ ) . '/inc/Core/Database/Agents/Agents.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Agents/PersistedAgentProjector.php';

use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Engine\Agents\PersistedAgentProjector;

$failures = array();
$passes   = 0;

echo "agents-api-persisted-agent-registry-smoke\n";

class DataMachinePersistedAgentProjectorFakeRepository extends Agents {
	/** @var array<int,array<string,mixed>> */
	private array $rows;

	/**
	 * @param array<int,array<string,mixed>> $rows Agent rows.
	 */
	public function __construct( array $rows ) {
		$this->rows = $rows;
	}

	public function get_all( array $args = array() ): array {
		unset( $args );
		return $this->rows;
	}
}

function datamachine_persisted_agent_registry_reset(): void {
	WP_Agents_Registry::reset_for_tests();
	$GLOBALS['__agents_api_smoke_actions'] = array();
	$GLOBALS['__agents_api_smoke_wrong']   = array();
	$GLOBALS['__agents_api_smoke_current'] = array();
	$GLOBALS['__agents_api_smoke_done']    = array();
	add_action( 'init', array( 'WP_Agents_Registry', 'init' ), 10 );
}

echo "\n[1] persisted Data Machine rows register as WP_Agent definitions:\n";
datamachine_persisted_agent_registry_reset();
$repository = new DataMachinePersistedAgentProjectorFakeRepository(
	array(
		array(
			'agent_id'     => 101,
			'agent_slug'   => 'wordpress-com-wiki',
			'agent_name'   => 'WordPress.com Wiki',
			'owner_id'     => 7,
			'agent_config' => array(
				'default_model'           => 'gpt-5.5',
				'intelligence_wiki_brain' => array(
					'description' => 'Maintains the WordPress.com wiki brain.',
					'wiki_slug'   => 'wordpress-com',
				),
				'datamachine_bundle'      => array(
					'bundle_slug'     => 'wordpress-com-wiki',
					'bundle_version'  => '1.2.3',
					'source_revision' => 'abc123',
				),
			),
		),
		array(
			'agent_id'     => 102,
			'agent_slug'   => 'woocommerce-wiki',
			'agent_name'   => 'WooCommerce Wiki',
			'owner_id'     => 8,
			'agent_config' => array(
				'description' => 'Maintains WooCommerce knowledge.',
			),
		),
		array(
			'agent_id'     => 103,
			'agent_slug'   => 'roadie',
			'agent_name'   => 'Roadie',
			'owner_id'     => 9,
			'agent_config' => array(),
		),
	)
);

add_action(
	'wp_agents_api_init',
	static function () use ( $repository ): void {
		PersistedAgentProjector::register_persisted_agents( $repository );
	}
);
do_action( 'init' );

$agents       = wp_get_agents();
$agent        = wp_get_agent( 'wordpress-com-wiki' );
$roadie_agent = wp_get_agent( 'roadie' );
agents_api_smoke_assert_equals( array( 'wordpress-com-wiki', 'woocommerce-wiki', 'roadie' ), array_keys( $agents ), 'persisted rows are visible through wp_get_agents()', $failures, $passes );
agents_api_smoke_assert_equals( true, $agent instanceof WP_Agent, 'persisted row resolves through wp_get_agent()', $failures, $passes );
agents_api_smoke_assert_equals( 'WordPress.com Wiki', $agent ? $agent->get_label() : '', 'row agent_name becomes label', $failures, $passes );
agents_api_smoke_assert_equals( 'Maintains the WordPress.com wiki brain.', $agent ? $agent->get_description() : '', 'wiki brain description is surfaced', $failures, $passes );
agents_api_smoke_assert_equals( '', $roadie_agent ? $roadie_agent->get_description() : 'missing', 'missing config description stays empty instead of synthesizing a runtime-branded fallback', $failures, $passes );
agents_api_smoke_assert_equals( 7, $agent && is_callable( $agent->get_owner_resolver() ) ? call_user_func( $agent->get_owner_resolver() ) : 0, 'owner_resolver returns persisted owner_id', $failures, $passes );
agents_api_smoke_assert_equals( 'gpt-5.5', $agent ? $agent->get_default_config()['default_model'] ?? '' : '', 'agent_config becomes default_config', $failures, $passes );
agents_api_smoke_assert_equals( 'wordpress-com-wiki', $agent ? $agent->get_meta()['source_package'] ?? '' : '', 'bundle slug is exposed as source package', $failures, $passes );
agents_api_smoke_assert_equals( 'wordpress-com', $agent ? $agent->get_meta()['intelligence_wiki_brain_wiki_slug'] ?? '' : '', 'wiki brain hint is exposed in metadata', $failures, $passes );

echo "\n[2] persisted projection preserves earlier declarative registrations:\n";
datamachine_persisted_agent_registry_reset();
add_action(
	'wp_agents_api_init',
	static function () use ( $repository ): void {
		wp_register_agent( 'wordpress-com-wiki', array( 'label' => 'Declarative Definition' ) );
		PersistedAgentProjector::register_persisted_agents( $repository );
	}
);
do_action( 'init' );

$agents = wp_get_agents();
agents_api_smoke_assert_equals( array( 'wordpress-com-wiki', 'woocommerce-wiki', 'roadie' ), array_keys( $agents ), 'projection skips duplicate slugs without dropping other rows', $failures, $passes );
agents_api_smoke_assert_equals( 'Declarative Definition', $agents['wordpress-com-wiki']->get_label(), 'first registered definition wins', $failures, $passes );
agents_api_smoke_assert_equals( array(), $GLOBALS['__agents_api_smoke_wrong'], 'duplicate skip avoids doing-it-wrong notices', $failures, $passes );

agents_api_smoke_finish( 'persisted agent registry smoke', $failures, $passes );
