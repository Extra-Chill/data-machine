<?php
/**
 * Pure-PHP smoke test for the wp_agent_can_access_agent bridge.
 *
 * Verifies that Data Machine's `datamachine_can_access_agent` host filter is
 * applied on the Agents API ability path, with tighten + widen semantics,
 * a user-principal guard, and slug↔numeric ID translation.
 *
 * Run with: php tests/agents-api-access-filter-bridge-smoke.php
 *
 * @package DataMachine\Tests
 */

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

require_once dirname( __DIR__ ) . '/inc/Core/Database/BaseRepository.php';
require_once dirname( __DIR__ ) . '/inc/Core/Database/Agents/Agents.php';
require_once dirname( __DIR__ ) . '/inc/Core/Auth/AgentAccessFilterBridge.php';

use DataMachine\Core\Auth\AgentAccessFilterBridge;
use DataMachine\Core\Database\Agents\Agents;

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

$GLOBALS['wpdb'] = (object) array(
	'base_prefix' => 'wp_',
	'prefix'      => 'wp_',
);

$failures = array();
$passes   = 0;

/**
 * Fake agent identity repository for slug→ID resolution.
 */
class AgentAccessFilterBridgeFakeAgentsRepository extends Agents {

	/** @var array<int,array<string,mixed>> */
	private array $rows;

	/**
	 * @param array<int,array<string,mixed>> $rows Agent rows keyed by ID.
	 */
	public function __construct( array $rows ) {
		$this->rows = $rows;
	}

	public function get_by_slug( string $agent_slug ): ?array {
		foreach ( $this->rows as $row ) {
			if ( ( $row['agent_slug'] ?? '' ) === $agent_slug ) {
				return $row;
			}
		}

		return null;
	}
}

/**
 * Clear any hooked datamachine_can_access_agent callbacks between scenarios.
 */
function agent_access_filter_bridge_clear_hooks(): void {
	unset( $GLOBALS['__agents_api_smoke_filters']['datamachine_can_access_agent'] );
}

echo "agents-api-access-filter-bridge-smoke\n";

$agents = new AgentAccessFilterBridgeFakeAgentsRepository(
	array(
		42 => array(
			'agent_id'   => 42,
			'agent_slug' => 'wiki-brain',
		),
		77 => array(
			'agent_id'   => 77,
			'agent_slug' => 'summarizer',
		),
	)
);

$bridge = new AgentAccessFilterBridge( $agents );

$user_principal    = \AgentsAPI\AI\WP_Agent_Execution_Principal::user_session( 7, '__wordpress_user__' );
$audience_principal = \AgentsAPI\AI\WP_Agent_Execution_Principal::audience( 'audience:public', '__wordpress_user__' );

// ---- 1. Grant hook widens access with no store row (seed false → true) ----
agent_access_filter_bridge_clear_hooks();
add_filter(
	'datamachine_can_access_agent',
	static function ( $can_access, $agent_id, $user_id, $minimum_role ) {
		return 42 === (int) $agent_id && 7 === (int) $user_id;
	},
	10,
	4
);

agents_api_smoke_assert_equals( true, $bridge->bridge_access_decision( false, $user_principal, 'wiki-brain', \WP_Agent_Access_Grant::ROLE_VIEWER, array() ), 'grant hook widens denied store decision to true', $failures, $passes );

$last_hook_args = null;
agent_access_filter_bridge_clear_hooks();
add_filter(
	'datamachine_can_access_agent',
	static function ( $can_access, $agent_id, $user_id, $minimum_role ) use ( &$last_hook_args ) {
		$last_hook_args = array( $can_access, (int) $agent_id, (int) $user_id, (string) $minimum_role );
		return $can_access;
	},
	10,
	4
);
$bridge->bridge_access_decision( false, $user_principal, 'wiki-brain', \WP_Agent_Access_Grant::ROLE_VIEWER, array() );
agents_api_smoke_assert_equals( array( false, 42, 7, \WP_Agent_Access_Grant::ROLE_VIEWER ), $last_hook_args, 'hook receives numeric agent ID, user ID, and seed decision', $failures, $passes );

// ---- 2. Deny hook tightens access despite a store grant (seed true → false) ----
agent_access_filter_bridge_clear_hooks();
add_filter(
	'datamachine_can_access_agent',
	static function ( $can_access, $agent_id, $user_id, $minimum_role ) {
		return false;
	},
	10,
	4
);

agents_api_smoke_assert_equals( false, $bridge->bridge_access_decision( true, $user_principal, 'wiki-brain', \WP_Agent_Access_Grant::ROLE_VIEWER, array() ), 'deny hook tightens granted store decision to false', $failures, $passes );

// ---- 3. No hook = unchanged store behavior (both directions) ----
agent_access_filter_bridge_clear_hooks();

agents_api_smoke_assert_equals( true, $bridge->bridge_access_decision( true, $user_principal, 'wiki-brain', \WP_Agent_Access_Grant::ROLE_VIEWER, array() ), 'no hook passes granted store decision through unchanged', $failures, $passes );
agents_api_smoke_assert_equals( false, $bridge->bridge_access_decision( false, $user_principal, 'wiki-brain', \WP_Agent_Access_Grant::ROLE_VIEWER, array() ), 'no hook passes denied store decision through unchanged', $failures, $passes );

// ---- 4. Audience principals unaffected (hook must not run) ----
$audience_hook_ran = false;
agent_access_filter_bridge_clear_hooks();
add_filter(
	'datamachine_can_access_agent',
	static function ( $can_access ) use ( &$audience_hook_ran ) {
		$audience_hook_ran = true;
		return false;
	},
	10,
	4
);

agents_api_smoke_assert_equals( true, $bridge->bridge_access_decision( true, $audience_principal, 'wiki-brain', \WP_Agent_Access_Grant::ROLE_VIEWER, array() ), 'audience principal passes granted decision through unchanged', $failures, $passes );
agents_api_smoke_assert_equals( false, $audience_hook_ran, 'datamachine_can_access_agent hook is not invoked for audience principals', $failures, $passes );

// ---- 5. Unresolvable slug passes through unchanged (hook must not run) ----
$unresolved_hook_ran = false;
agent_access_filter_bridge_clear_hooks();
add_filter(
	'datamachine_can_access_agent',
	static function ( $can_access ) use ( &$unresolved_hook_ran ) {
		$unresolved_hook_ran = true;
		return true;
	},
	10,
	4
);

agents_api_smoke_assert_equals( false, $bridge->bridge_access_decision( false, $user_principal, 'ghost-agent', \WP_Agent_Access_Grant::ROLE_VIEWER, array() ), 'unresolvable slug passes denied decision through unchanged', $failures, $passes );
agents_api_smoke_assert_equals( false, $unresolved_hook_ran, 'hook is not invoked when slug cannot be resolved to a numeric ID', $failures, $passes );

// ---- 6. Numeric agent identifier bypasses slug resolution ----
agent_access_filter_bridge_clear_hooks();
add_filter(
	'datamachine_can_access_agent',
	static function ( $can_access, $agent_id, $user_id, $minimum_role ) {
		return 77 === (int) $agent_id && 7 === (int) $user_id;
	},
	10,
	4
);

agents_api_smoke_assert_equals( true, $bridge->bridge_access_decision( false, $user_principal, '77', \WP_Agent_Access_Grant::ROLE_VIEWER, array() ), 'numeric agent identifier is forwarded to hook without slug resolution', $failures, $passes );

// ---- 7. Register wires the bridge into the substrate filter ----
$GLOBALS['__agents_api_smoke_filters'] = array();
AgentAccessFilterBridge::register();
$registered = $GLOBALS['__agents_api_smoke_filters']['wp_agent_can_access_agent'][10] ?? array();
agents_api_smoke_assert_equals( true, ! empty( $registered ), 'register() hooks wp_agent_can_access_agent', $failures, $passes );

// ---- 8. Non-principal value passes through unchanged ----
agent_access_filter_bridge_clear_hooks();
add_filter(
	'datamachine_can_access_agent',
	static function () {
		return true;
	},
	10,
	4
);

agents_api_smoke_assert_equals( true, $bridge->bridge_access_decision( true, null, 'wiki-brain', \WP_Agent_Access_Grant::ROLE_VIEWER, array() ), 'non-principal value passes through unchanged', $failures, $passes );

agents_api_smoke_finish( 'access-filter bridge smoke', $failures, $passes );
