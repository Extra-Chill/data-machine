<?php
/**
 * Bridges the Agents API `wp_agent_can_access_agent` substrate filter into
 * Data Machine's existing `datamachine_can_access_agent` host filter.
 *
 * @package DataMachine\Core\Auth
 * @since   0.159.12
 */

namespace DataMachine\Core\Auth;

use DataMachine\Core\Database\Agents\Agents;

defined( 'ABSPATH' ) || exit;

/**
 * Converges Data Machine's two agent-access resolution paths.
 *
 * Without this bridge, the agents-api ability path (`agents/can-access-agent`
 * and `agents/list-accessible-agents`) resolves access exclusively through the
 * access-store contract, while Data Machine's internal path applies the
 * `datamachine_can_access_agent` filter. The two paths silently disagree:
 * host plugins that widen access via the filter are invisible to every
 * ability-driven consumer (chat widgets, MCP, REST).
 *
 * The bridge forwards the substrate's final store-derived decision — which
 * already includes the effective-agent short-circuit — into Data Machine's
 * filter so both tighten and widen semantics are honored, then returns
 * whatever the filter produces.
 */
class AgentAccessFilterBridge {

	/**
	 * Agent identity repository used to map Agents API slugs to numeric IDs.
	 *
	 * @var Agents
	 */
	private Agents $agents_repository;

	/**
	 * @param Agents|null $agents_repository Optional repository for tests.
	 */
	public function __construct( ?Agents $agents_repository = null ) {
		$this->agents_repository = $agents_repository ?? new Agents();
	}

	/**
	 * Register the substrate access-filter bridge.
	 */
	public static function register(): void {
		static $bridge = null;

		if ( null === $bridge ) {
			$bridge = new self();
		}

		add_filter( 'wp_agent_can_access_agent', array( $bridge, 'bridge_access_decision' ), 10, 5 );
	}

	/**
	 * Forward the substrate access decision into Data Machine's host filter.
	 *
	 * Only user principals (`acting_user_id > 0`) are forwarded: Data Machine's
	 * filter is user-centric, so audience/anonymous principals pass through with
	 * the substrate's store-derived decision unchanged. The substrate's current
	 * `$allowed` value is seeded into the host filter so hooks can tighten as
	 * well as widen the decision (including the effective-agent short-circuit).
	 *
	 * @param bool                                       $allowed      Store-derived decision.
	 * @param \AgentsAPI\AI\WP_Agent_Execution_Principal $principal    Execution principal.
	 * @param string                                     $agent_id     Agents API agent identifier (slug or numeric).
	 * @param string                                     $minimum_role Minimum access role.
	 * @param array<string,mixed>                        $context      Host-owned authorization context.
	 * @return bool
	 */
	public function bridge_access_decision( $allowed, $principal, $agent_id, $minimum_role, $context = array() ) {
		unset( $context );

		if ( ! $principal instanceof \AgentsAPI\AI\WP_Agent_Execution_Principal ) {
			return $allowed;
		}

		if ( $principal->acting_user_id <= 0 ) {
			return $allowed;
		}

		$numeric_agent_id = $this->resolve_numeric_agent_id( (string) $agent_id );
		if ( null === $numeric_agent_id ) {
			return $allowed;
		}

		/**
		 * Filters whether a user can access an agent.
		 *
		 * Host plugins (e.g. team-membership bridges) hook this to widen or
		 * tighten access based on live state (capabilities, roles, memberships)
		 * without materializing grant rows. The substrate decision is seeded as
		 * the initial value so hooks can both grant and revoke.
		 *
		 * @since 0.62.0
		 *
		 * @param bool   $can_access   Whether the user can access the agent.
		 * @param int    $agent_id     Agent ID.
		 * @param int    $user_id      User ID.
		 * @param string $minimum_role Minimum role required.
		 */
		return (bool) apply_filters( 'datamachine_can_access_agent', (bool) $allowed, $numeric_agent_id, $principal->acting_user_id, (string) $minimum_role );
	}

	/**
	 * Resolve an Agents API agent identifier to Data Machine's numeric ID.
	 *
	 * Numeric identifiers are returned verbatim; slugs are resolved through the
	 * agent identity repository. Unresolvable slugs yield null so the caller
	 * can pass the substrate decision through unchanged.
	 */
	private function resolve_numeric_agent_id( string $agent_id ): ?int {
		if ( is_numeric( $agent_id ) ) {
			return (int) $agent_id;
		}

		$row = $this->agents_repository->get_by_slug( $agent_id );
		if ( $row && ! empty( $row['agent_id'] ) ) {
			return (int) $row['agent_id'];
		}

		return null;
	}
}
