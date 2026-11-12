<?php
/**
 * Resolve agent scope for authenticated human REST callers.
 *
 * @package DataMachine\Core\Auth
 */

namespace DataMachine\Core\Auth;

use AgentsAPI\AI\WP_Agent_Execution_Principal;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Agents\AgentIdentityResolver;
use DataMachine\Core\PluginSettings;

/**
 * Resolution only: never authenticates a caller or changes their capabilities.
 */
final class UserSessionAgentContext {

	/** @var array Entries owned by this resolver, including nested REST dispatches. */
	private array $contexts = array();

	public function __construct() {
		add_filter( 'rest_request_before_callbacks', array( $this, 'resolve' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'restore' ), 10, 3 );
		add_filter( 'agents_api_execution_principal', array( $this, 'principal' ) );
	}

	/** Resolve after REST authentication (including cookie nonce validation). */
	public function resolve( $response, array $handler, \WP_REST_Request $request ) {
		if ( PermissionHelper::in_agent_context() ) {
			return $response;
		}

		$previous = PermissionHelper::get_execution_principal();
		$entry    = end( $this->contexts );
		if ( null !== $previous && ( ! $entry || $previous !== $entry[1] ) ) {
			// Context established by another execution path is not ours to replace.
			return $response;
		}

		// Each dispatch resolves independently, even when an outer human request
		// already has scope. Inaccessible nested selectors must not inherit it.
		$this->contexts[] = array( $request, null, $previous );
		PermissionHelper::set_execution_principal( null );
		if ( null !== $response || get_current_user_id() <= 0 ) {
			return $response;
		}

		// An explicit invalid/inaccessible selector must never fall back to a default.
		$selector = $request->get_header( 'X-Datamachine-Agent' );
		if ( null === $selector ) {
			$selector = PluginSettings::get( 'default_agent', '' );
		}
		$selector = trim( (string) $selector );
		if ( '' === $selector ) {
			return $response;
		}

		try {
			$agent_id = ( new AgentIdentityResolver() )->resolve_agent_id( $selector );
		} catch ( \InvalidArgumentException $e ) {
			return $response;
		}

		if ( ! PermissionHelper::can_access_agent( $agent_id, 'operator' ) ) {
			return $response;
		}

		$principal = WP_Agent_Execution_Principal::user_session( get_current_user_id(), (string) $agent_id );
		$this->contexts[ array_key_last( $this->contexts ) ][1] = $principal;
		PermissionHelper::set_execution_principal( $principal );

		return $response;
	}

	/** Publish the same principal through the generic Agents API resolver. */
	public function principal( ?WP_Agent_Execution_Principal $principal ): ?WP_Agent_Execution_Principal {
		$entry = end( $this->contexts );
		return $principal ?? ( $entry && PermissionHelper::get_execution_principal() === $entry[1] ? $entry[1] : null );
	}

	/** Do not leak human agent scope into subsequent dispatches. */
	public function restore( $response, array $handler, \WP_REST_Request $request ) {
		$entry = end( $this->contexts );
		if ( $entry && $entry[0] === $request ) {
			array_pop( $this->contexts );
			if ( PermissionHelper::get_execution_principal() === $entry[1] ) {
				PermissionHelper::set_execution_principal( $entry[2] );
			}
		}
		return $response;
	}
}
