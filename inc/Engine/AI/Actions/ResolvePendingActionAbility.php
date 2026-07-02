<?php
/**
 * ResolvePendingActionAbility — accept or reject a pending tool invocation.
 *
 * When a tool runs under WP_Agent_Action_Policy::POLICY_PREVIEW, it stages an invocation
 * via PendingActionHelper::stage() instead of executing directly. This ability
 * is the generic resolver that replays (accept) or discards (reject) the
 * stored payload, dispatching to the correct handler by `kind`.
 *
 * Handlers register themselves via the `datamachine_pending_action_handlers`
 * filter:
 *
 *   add_filter( 'datamachine_pending_action_handlers', function ( $handlers ) {
 *       $handlers['socials_publish_instagram'] = array(
 *           'apply'       => array( InstagramPublishAbility::class, 'execute_publish' ),
 *           'can_resolve' => array( __CLASS__, 'canResolveInstagram' ), // optional
 *       );
 *       return $handlers;
 *   } );
 *
 * Each handler entry:
 *
 *   - apply       (callable, required): invoked with the stored apply_input
 *                 array on 'accepted'. Return value is included in the
 *                 response. Return a WP_Error or an array with `success=>false`
 *                 to surface failure.
 *   - can_resolve (callable, optional): invoked with ($payload, $decision, $user_id, $context)
 *                 before apply. Must return true. Return a WP_Error (or false)
 *                 to deny. Defaults to "anyone with access to the ability".
 *
 * REST surface: POST /datamachine/v1/actions/resolve
 * Ability slug: datamachine/resolve-pending-action
 * Chat tool:    resolve_pending_action (registered separately by ResolvePendingAction BaseTool)
 *
 * @package DataMachine\Engine\AI\Actions
 * @since   0.72.0
 */

namespace DataMachine\Engine\AI\Actions;

use AgentsAPI\AI\Approvals\WP_Agent_Approval_Decision;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Handler;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Status;
use DataMachine\Abilities\AbilityRegistration;
use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class ResolvePendingActionAbility {

	/**
	 * Ensure the ability registers exactly once.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Agents API resolver adapter singleton.
	 *
	 * @var PendingActionResolverAdapter|null
	 */
	private static ?PendingActionResolverAdapter $adapter = null;

	/**
	 * Return the Agents API resolver adapter.
	 */
	public static function adapter(): PendingActionResolverAdapter {
		if ( null === self::$adapter ) {
			self::$adapter = new PendingActionResolverAdapter();
		}

		return self::$adapter;
	}

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->register_ability();
		$this->register_rest_route();
		self::$registered = true;
	}

	/**
	 * Register the WordPress ability.
	 */
	private function register_ability(): void {
		$register = function () {
			wp_register_ability(
				'datamachine/resolve-pending-action',
				array(
					'label'               => __( 'Resolve Pending Action (Data Machine Alias)', 'data-machine' ),
					'description'         => __( 'Deprecated compatibility alias for agents/resolve-pending-action.', 'data-machine' ),
					'category'            => 'datamachine-actions',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'action_id', 'decision' ),
						'properties' => array(
							'action_id' => array(
								'type'        => 'string',
								'description' => __( 'The pending action identifier.', 'data-machine' ),
							),
							'decision'  => array(
								'type'        => 'string',
								'enum'        => array( 'accepted', 'rejected' ),
								'description' => __( 'Whether to apply or discard the pending action.', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'   => array( 'type' => 'boolean' ),
							'decision'  => array( 'type' => 'string' ),
							'action_id' => array( 'type' => 'string' ),
							'kind'      => array( 'type' => 'string' ),
							'result'    => array( 'type' => 'object' ),
							'error'     => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'execute' ),
					'permission_callback' => fn() => PermissionHelper::can( 'chat' ),
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'deprecated'  => true,
							'replacement' => 'agents/resolve-pending-action',
							'destructive' => true,
							'idempotent'  => false,
						),
					),
				)
			);
		};

		AbilityRegistration::on_abilities_api_init( $register );
	}

	/**
	 * Register the REST route.
	 */
	private function register_rest_route(): void {
		add_action(
			'rest_api_init',
			function () {
				register_rest_route(
					'datamachine/v1',
					'/actions/resolve',
					array(
						'methods'             => 'POST',
						'callback'            => array( self::class, 'handle_rest' ),
						'permission_callback' => fn() => PermissionHelper::can( 'chat' ),
						'args'                => array(
							'action_id' => array(
								'required'          => true,
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_text_field',
							),
							'decision'  => array(
								'required'          => true,
								'type'              => 'string',
								'enum'              => array( 'accepted', 'rejected' ),
								'sanitize_callback' => 'sanitize_text_field',
							),
						),
					)
				);
			}
		);
	}

	/**
	 * REST handler — delegates to execute().
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public static function handle_rest( \WP_REST_Request $request ): \WP_REST_Response {
		$result = self::execute(
			array(
				'action_id' => $request->get_param( 'action_id' ),
				'decision'  => $request->get_param( 'decision' ),
			)
		);

		return new \WP_REST_Response( $result, ! empty( $result['success'] ) ? 200 : 400 );
	}

	/**
	 * Execute the deprecated Data Machine alias via the canonical Agents API ability.
	 *
	 * @param array $input { action_id, decision }.
	 * @return array
	 */
	public static function execute( array $input ): array {
		$action_id      = isset( $input['action_id'] ) ? sanitize_text_field( $input['action_id'] ) : '';
		$decision_value = isset( $input['decision'] ) ? sanitize_text_field( $input['decision'] ) : '';
		$resolver       = isset( $input['resolver'] ) ? sanitize_text_field( (string) $input['resolver'] ) : self::resolverFromCurrentUser();

		if ( '' === $action_id || '' === $decision_value || '' === $resolver ) {
			return array(
				'success' => false,
				'error'   => 'action_id, decision, and resolver are required.',
			);
		}

		$result = \AgentsAPI\AI\Approvals\agents_resolve_pending_action(
			array(
				'action_id' => $action_id,
				'decision'  => $decision_value,
				'resolver'  => $resolver,
				'payload'   => isset( $input['payload'] ) && is_array( $input['payload'] ) ? $input['payload'] : array(),
				'context'   => isset( $input['context'] ) && is_array( $input['context'] ) ? $input['context'] : array(),
			)
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'success'   => false,
				'error'     => $result->get_error_message(),
				'action_id' => $action_id,
			);
		}

		$compat_result = is_array( $result['result'] ?? null ) ? $result['result'] : array();
		if ( ! empty( $compat_result ) ) {
			return $compat_result;
		}

		return array(
			'success'   => true,
			'action_id' => $action_id,
			'decision'  => (string) ( $result['decision'] ?? $decision_value ),
			'result'    => $result['result'] ?? null,
		);
	}

	/**
	 * Resolve through Data Machine's concrete handler map.
	 *
	 * This is the internal adapter target used by the canonical Agents API
	 * resolver. Public Data Machine clients should call the Agents API ability.
	 *
	 * @param array $input { action_id, decision, resolver, payload, context }.
	 * @return array
	 */
	public static function resolve_with_datamachine_handlers( array $input ): array {
		$action_id      = isset( $input['action_id'] ) ? sanitize_text_field( $input['action_id'] ) : '';
		$decision_value = isset( $input['decision'] ) ? sanitize_text_field( $input['decision'] ) : '';

		if ( '' === $action_id || '' === $decision_value ) {
			return array(
				'success' => false,
				'error'   => 'action_id and decision are required.',
			);
		}

		$decision = self::approvalDecisionFromValue( $decision_value );
		if ( null === $decision ) {
			return array(
				'success' => false,
				'error'   => 'decision must be "accepted" or "rejected".',
			);
		}

		$decision_value = $decision->value();

		$payload = PendingActionStore::get( $action_id );
		if ( null === $payload ) {
			return array(
				'success'   => false,
				'error'     => 'Pending action not found or expired.',
				'action_id' => $action_id,
			);
		}

		if ( ! PendingActionScope::can_access_payload( $payload, $input ) ) {
			return array(
				'success'   => false,
				'error'     => 'You do not have permission to resolve this pending action.',
				'action_id' => $action_id,
			);
		}

		$kind             = (string) ( $payload['kind'] ?? '' );
		$user_id          = get_current_user_id();
		$apply_input      = isset( $payload['apply_input'] ) && is_array( $payload['apply_input'] ) ? $payload['apply_input'] : array();
		$resolver_payload = isset( $input['payload'] ) && is_array( $input['payload'] ) ? $input['payload'] : array();
		$resolver_context = isset( $input['context'] ) && is_array( $input['context'] ) ? $input['context'] : array();
		$resolver         = isset( $input['resolver'] ) ? sanitize_text_field( $input['resolver'] ) : self::resolverFromCurrentUser();
		$resolver_context = array_merge( $resolver_context, array( 'resolver' => $resolver ) );
		$pending_action   = PendingActionStore::get_action( $action_id );

		if ( '' === $kind ) {
			PendingActionStore::delete( $action_id );
			return array(
				'success'   => false,
				'error'     => 'Stored pending action has no kind; cannot resolve.',
				'action_id' => $action_id,
			);
		}

		$handlers = self::getKindHandlers();
		$handler  = $handlers[ $kind ] ?? null;
		$grant    = self::canResolveWithGrant( $payload, $decision, $resolver, $resolver_payload, $resolver_context );
		if ( is_wp_error( $grant ) ) {
			return array(
				'success'   => false,
				'error'     => $grant->get_error_message(),
				'action_id' => $action_id,
				'kind'      => $kind,
			);
		}

		if ( false === $grant ) {
			return array(
				'success'   => false,
				'error'     => 'This resolver is not granted permission to resolve this pending action.',
				'action_id' => $action_id,
				'kind'      => $kind,
			);
		}

		if ( ! is_array( $handler ) || empty( $handler['apply'] ) || ! self::isApplyHandler( $handler['apply'] ) ) {
			// No handler registered — can't apply, but reject is still safe.
			if ( $decision->is_rejected() ) {
				PendingActionStore::record_resolution( $action_id, WP_Agent_Pending_Action_Status::REJECTED, null, null, $resolver, array( 'reason' => 'no_handler_rejected' ) );
				return array(
					'success'   => true,
					'decision'  => 'rejected',
					'action_id' => $action_id,
					'kind'      => $kind,
				);
			}

			return array(
				'success'   => false,
				'error'     => sprintf( 'No handler registered for pending action kind "%s".', $kind ),
				'action_id' => $action_id,
				'kind'      => $kind,
			);
		}

		// Optional permission hook per kind.
		$contract_allowed = self::canResolveWithHandlerContract( $handler, $pending_action, $decision, $resolver_payload, $resolver_context );
		if ( is_wp_error( $contract_allowed ) ) {
			return array(
				'success'   => false,
				'error'     => $contract_allowed->get_error_message(),
				'action_id' => $action_id,
				'kind'      => $kind,
			);
		}
		if ( false === $contract_allowed ) {
			return array(
				'success'   => false,
				'error'     => 'You do not have permission to resolve this pending action.',
				'action_id' => $action_id,
				'kind'      => $kind,
			);
		}

		if ( null === $contract_allowed && ! empty( $handler['can_resolve'] ) && is_callable( $handler['can_resolve'] ) ) {
			$allowed = call_user_func( $handler['can_resolve'], $payload, $decision_value, $user_id, $resolver_context );
			if ( is_wp_error( $allowed ) ) {
				return array(
					'success'   => false,
					'error'     => $allowed->get_error_message(),
					'action_id' => $action_id,
					'kind'      => $kind,
				);
			}
			if ( true !== $allowed ) {
				return array(
					'success'   => false,
					'error'     => 'You do not have permission to resolve this pending action.',
					'action_id' => $action_id,
					'kind'      => $kind,
				);
			}
		}

		if ( $decision->is_rejected() ) {
			PendingActionStore::record_resolution( $action_id, WP_Agent_Pending_Action_Status::REJECTED, null, null, $resolver );
			return array(
				'success'   => true,
				'decision'  => 'rejected',
				'action_id' => $action_id,
				'kind'      => $kind,
			);
		}

		// Accepted: invoke the apply handler with the stored input.
		$result = self::applyHandler( $handler, $decision, $apply_input, $payload, $resolver_payload, $resolver_context, $pending_action );

		if ( is_wp_error( $result ) ) {
			PendingActionStore::record_resolution( $action_id, WP_Agent_Pending_Action_Status::ACCEPTED, null, $result->get_error_message(), $resolver );
			return array(
				'success'   => false,
				'decision'  => 'accepted',
				'action_id' => $action_id,
				'kind'      => $kind,
				'error'     => $result->get_error_message(),
			);
		}

		if ( is_array( $result ) && array_key_exists( 'success', $result ) && false === $result['success'] ) {
			PendingActionStore::record_resolution( $action_id, WP_Agent_Pending_Action_Status::ACCEPTED, $result, $result['error'] ?? 'Apply handler reported failure.', $resolver );
			return array(
				'success'   => false,
				'decision'  => 'accepted',
				'action_id' => $action_id,
				'kind'      => $kind,
				'result'    => $result,
				'error'     => $result['error'] ?? 'Apply handler reported failure.',
			);
		}

		PendingActionStore::record_resolution( $action_id, WP_Agent_Pending_Action_Status::ACCEPTED, $result, null, $resolver );

		return array(
			'success'   => true,
			'decision'  => 'accepted',
			'action_id' => $action_id,
			'kind'      => $kind,
			'result'    => is_array( $result ) ? $result : array( 'value' => $result ),
		);
	}

	/**
	 * Read the registered kind => handler map.
	 *
	 * @return array
	 */
	private static function getKindHandlers(): array {
		/**
		 * Filter the map of pending-action-kind => handler config.
		 *
		 * Handlers should return:
		 *
		 *   array(
		 *       'apply'       => callable ( array $apply_input, array $payload ): mixed,
		 *       'can_resolve' => callable ( array $payload, string $decision, int $user_id, array $context ): bool|WP_Error,
		 *   )
		 *
		 * @since 0.72.0
		 *
		 * @param array<string, array{apply: callable, can_resolve?: callable}> $handlers Current map.
		 */
		$handlers = apply_filters( 'datamachine_pending_action_handlers', array() );
		return is_array( $handlers ) ? $handlers : array();
	}

	/**
	 * Apply a pending-action handler.
	 *
	 * The Data Machine handler map remains the product extension surface today.
	 * When Agents API PR #51's handler contract is installed, object handlers can
	 * implement it and be placed under the same `apply` key without introducing a
	 * parallel Data Machine primitive.
	 *
	 * @param array            $handler          Handler configuration.
	 * @param WP_Agent_Approval_Decision $decision         Accepted/rejected decision.
	 * @param array            $apply_input      Stored apply input.
	 * @param array            $payload          Stored pending action payload.
	 * @param array            $resolver_payload Fresh resolver payload.
	 * @param array            $resolver_context Optional resolver context.
	 * @return mixed
	 */
	private static function applyHandler( array $handler, WP_Agent_Approval_Decision $decision, array $apply_input, array $payload, array $resolver_payload = array(), array $resolver_context = array(), ?WP_Agent_Pending_Action $pending_action = null ) {
		$apply = $handler['apply'];
		if ( $apply instanceof WP_Agent_Pending_Action_Handler ) {
			if ( null === $pending_action ) {
				return new \WP_Error( 'invalid_pending_action', 'Stored pending action could not be normalized.' );
			}

			return $apply->handle_pending_action( $pending_action, $decision, $resolver_payload, $resolver_context );
		}

		return call_user_func( $apply, $apply_input, $payload );
	}

	/**
	 * Determine if a handler entry can apply accepted actions.
	 *
	 * @param mixed $apply Handler entry.
	 * @return bool
	 */
	private static function isApplyHandler( $apply ): bool {
		if ( is_callable( $apply ) ) {
			return true;
		}

		return $apply instanceof WP_Agent_Pending_Action_Handler;
	}

	/**
	 * Run the Agents API handler-level permission contract when present.
	 *
	 * @return bool|\WP_Error|null Null means no contract handler was provided.
	 */
	private static function canResolveWithHandlerContract( array $handler, ?WP_Agent_Pending_Action $pending_action, WP_Agent_Approval_Decision $decision, array $resolver_payload, array $resolver_context ) {
		$apply = $handler['apply'] ?? null;
		if ( ! $apply instanceof WP_Agent_Pending_Action_Handler ) {
			return null;
		}

		if ( null === $pending_action ) {
			return new \WP_Error( 'invalid_pending_action', 'Stored pending action could not be normalized.' );
		}

		return $apply->can_resolve_pending_action( $pending_action, $decision, $resolver_payload, $resolver_context );
	}

	/**
	 * Enforce explicit resolver grants for agent/system-led resolutions.
	 *
	 * Human user and signed-token approvals remain valid by default. Machine
	 * resolvers must be represented by a stored grant so the policy is auditable.
	 *
	 * @return bool|\WP_Error True when allowed, false when denied.
	 */
	private static function canResolveWithGrant( array $payload, WP_Agent_Approval_Decision $decision, string $resolver, array $resolver_payload, array $resolver_context ) {
		$resolver_type = self::resolverType( $resolver, $resolver_context );
		if ( in_array( $resolver_type, array( 'user', 'signed_url' ), true ) ) {
			return true;
		}

		$grants = self::resolverGrants( $payload );
		foreach ( $grants as $grant ) {
			if ( ! self::grantMatchesResolverType( $grant, $resolver_type ) ) {
				continue;
			}

			if ( ! self::grantMatchesDecision( $grant, $decision->value() ) ) {
				continue;
			}

			if ( ! self::grantMatchesResolver( $grant, $resolver ) ) {
				continue;
			}

			if ( ! self::grantMatchesKind( $grant, (string) ( $payload['kind'] ?? '' ) ) ) {
				continue;
			}

			if ( ! self::grantMatchesAgent( $grant, (int) ( $payload['agent_id'] ?? 0 ) ) ) {
				continue;
			}

			if ( ! self::grantMatchesSession( $grant, $payload, $resolver_context ) ) {
				continue;
			}

			$missing = self::missingRequiredGrantFields( $grant, $resolver_payload, $resolver_context );
			if ( ! empty( $missing ) ) {
				return new \WP_Error( 'missing_resolver_evidence', sprintf( 'Resolver grant requires %s.', implode( ', ', $missing ) ) );
			}

			return true;
		}

		return false;
	}

	/**
	 * Normalize stored resolver grants from the pending action payload.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function resolverGrants( array $payload ): array {
		$metadata             = isset( $payload['metadata'] ) && is_array( $payload['metadata'] ) ? $payload['metadata'] : array();
		$datamachine_metadata = isset( $metadata['datamachine'] ) && is_array( $metadata['datamachine'] ) ? $metadata['datamachine'] : array();
		$grants               = isset( $payload['resolver_grants'] ) && is_array( $payload['resolver_grants'] ) ? $payload['resolver_grants'] : array();
		$metadata_grants      = isset( $datamachine_metadata['resolver_grants'] ) && is_array( $datamachine_metadata['resolver_grants'] ) ? $datamachine_metadata['resolver_grants'] : array();
		$legacy               = isset( $payload['allowed_resolvers'] ) && is_array( $payload['allowed_resolvers'] ) ? $payload['allowed_resolvers'] : array();

		$grants = array_merge( $grants, $metadata_grants );

		foreach ( $legacy as $resolver ) {
			$grants[] = array( 'resolver' => $resolver );
		}

		if ( ! empty( $payload['agent_resolvable'] ) ) {
			$grants[] = array( 'type' => 'agent' );
		}

		if ( ! empty( $payload['system_resolvable'] ) ) {
			$grants[] = array( 'type' => 'system_task' );
		}

		return array_values( array_filter( $grants, 'is_array' ) );
	}

	private static function grantMatchesResolverType( array $grant, string $resolver_type ): bool {
		$types = self::stringList( $grant['types'] ?? ( $grant['type'] ?? ( $grant['resolver_type'] ?? null ) ) );
		if ( empty( $types ) ) {
			return true;
		}

		return in_array( $resolver_type, $types, true );
	}

	private static function grantMatchesDecision( array $grant, string $decision ): bool {
		$decisions = self::stringList( $grant['decisions'] ?? ( $grant['decision'] ?? null ) );
		if ( empty( $decisions ) ) {
			return true;
		}

		return in_array( $decision, $decisions, true );
	}

	private static function grantMatchesResolver( array $grant, string $resolver ): bool {
		$resolvers = self::stringList( $grant['resolvers'] ?? ( $grant['resolver'] ?? null ) );
		if ( empty( $resolvers ) ) {
			return true;
		}

		return in_array( $resolver, $resolvers, true );
	}

	private static function grantMatchesKind( array $grant, string $kind ): bool {
		$kinds = self::stringList( $grant['kinds'] ?? ( $grant['kind'] ?? null ) );
		if ( empty( $kinds ) ) {
			return true;
		}

		return in_array( $kind, $kinds, true );
	}

	private static function grantMatchesAgent( array $grant, int $agent_id ): bool {
		if ( ! isset( $grant['agent_id'] ) ) {
			return true;
		}

		return (int) $grant['agent_id'] === $agent_id;
	}

	private static function grantMatchesSession( array $grant, array $payload, array $resolver_context ): bool {
		if ( ! isset( $grant['session_id'] ) ) {
			return true;
		}

		$payload_context = isset( $payload['context'] ) && is_array( $payload['context'] ) ? $payload['context'] : array();
		$session_id      = (string) ( $resolver_context['session_id'] ?? ( $payload_context['session_id'] ?? '' ) );

		return (string) $grant['session_id'] === $session_id;
	}

	/**
	 * Check required evidence fields declared by a machine resolver grant.
	 *
	 * @return string[] Missing field labels.
	 */
	private static function missingRequiredGrantFields( array $grant, array $resolver_payload, array $resolver_context ): array {
		$missing = array();
		foreach ( self::stringList( $grant['required_payload_fields'] ?? null ) as $field ) {
			if ( ! array_key_exists( $field, $resolver_payload ) || null === $resolver_payload[ $field ] || '' === $resolver_payload[ $field ] ) {
				$missing[] = 'payload.' . $field;
			}
		}

		foreach ( self::stringList( $grant['required_context_fields'] ?? null ) as $field ) {
			if ( ! array_key_exists( $field, $resolver_context ) || null === $resolver_context[ $field ] || '' === $resolver_context[ $field ] ) {
				$missing[] = 'context.' . $field;
			}
		}

		return $missing;
	}

	/**
	 * Identify the broad resolver class from the audit identifier/context.
	 */
	private static function resolverType( string $resolver, array $resolver_context ): string {
		if ( 'signed_url' === (string) ( $resolver_context['resolution_transport'] ?? '' ) ) {
			return 'signed_url';
		}

		if ( str_starts_with( $resolver, 'user:' ) ) {
			return 'user';
		}

		if ( str_starts_with( $resolver, 'agent:' ) ) {
			return 'agent';
		}

		if ( str_starts_with( $resolver, 'system_task:' ) || str_starts_with( $resolver, 'system:' ) ) {
			return 'system_task';
		}

		if ( str_starts_with( $resolver, 'cli:' ) || str_starts_with( $resolver, 'operator:' ) ) {
			return 'operator';
		}

		return 'unknown';
	}

	/**
	 * Normalize grant scalar/list fields to strings.
	 *
	 * @return string[]
	 */
	private static function stringList( $value ): array {
		if ( null === $value ) {
			return array();
		}

		$values = is_array( $value ) ? $value : array( $value );
		$values = array_map( 'strval', $values );
		$values = array_map( 'sanitize_text_field', $values );

		return array_values( array_filter( $values, static fn ( string $item ): bool => '' !== $item ) );
	}

	/**
	 * Normalize an external decision value to the Agents API approval contract.
	 *
	 * @param string $value Request decision value.
	 * @return WP_Agent_Approval_Decision|null
	 */
	private static function approvalDecisionFromValue( string $value ): ?WP_Agent_Approval_Decision {
		try {
			return WP_Agent_Approval_Decision::from_string( $value );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
	}

	/**
	 * Build a resolver audit identifier for the active user.
	 */
	public static function resolver_from_current_user(): string {
		$user_id = get_current_user_id();
		return $user_id > 0 ? 'user:' . $user_id : 'system:anonymous';
	}

	/**
	 * Back-compat shim for existing internal callers.
	 */
	private static function resolverFromCurrentUser(): string {
		return self::resolver_from_current_user();
	}
}
