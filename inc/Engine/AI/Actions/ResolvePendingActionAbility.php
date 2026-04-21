<?php
/**
 * ResolvePendingActionAbility — accept or reject a pending tool invocation.
 *
 * When a tool runs under ActionPolicy::POLICY_PREVIEW, it stages an invocation
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
 *   - can_resolve (callable, optional): invoked with ($payload, $decision, $user_id)
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

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class ResolvePendingActionAbility {

	/**
	 * Ensure the ability registers exactly once.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) || self::$registered ) {
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
					'label'               => __( 'Resolve Pending Action', 'data-machine' ),
					'description'         => __( 'Accept or reject a pending tool invocation staged by ActionPolicy.', 'data-machine' ),
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
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register );
		}
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
	 * Execute: accept or reject a pending action.
	 *
	 * @param array $input { action_id, decision }.
	 * @return array
	 */
	public static function execute( array $input ): array {
		$action_id = isset( $input['action_id'] ) ? sanitize_text_field( $input['action_id'] ) : '';
		$decision  = isset( $input['decision'] ) ? sanitize_text_field( $input['decision'] ) : '';

		if ( '' === $action_id || '' === $decision ) {
			return array(
				'success' => false,
				'error'   => 'action_id and decision are required.',
			);
		}

		if ( ! in_array( $decision, array( 'accepted', 'rejected' ), true ) ) {
			return array(
				'success' => false,
				'error'   => 'decision must be "accepted" or "rejected".',
			);
		}

		$payload = PendingActionStore::get( $action_id );
		if ( null === $payload ) {
			return array(
				'success'   => false,
				'error'     => 'Pending action not found or expired.',
				'action_id' => $action_id,
			);
		}

		$kind        = (string) ( $payload['kind'] ?? '' );
		$user_id     = get_current_user_id();
		$apply_input = isset( $payload['apply_input'] ) && is_array( $payload['apply_input'] ) ? $payload['apply_input'] : array();

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

		if ( ! is_array( $handler ) || empty( $handler['apply'] ) || ! is_callable( $handler['apply'] ) ) {
			// No handler registered — can't apply, but reject is still safe.
			if ( 'rejected' === $decision ) {
				PendingActionStore::delete( $action_id );
				self::fireResolvedAction( $decision, $action_id, $kind, $payload, null );
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
		if ( ! empty( $handler['can_resolve'] ) && is_callable( $handler['can_resolve'] ) ) {
			$allowed = call_user_func( $handler['can_resolve'], $payload, $decision, $user_id );
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

		// Always clean up the stored payload after a decision is made.
		PendingActionStore::delete( $action_id );

		if ( 'rejected' === $decision ) {
			self::fireResolvedAction( $decision, $action_id, $kind, $payload, null );
			return array(
				'success'   => true,
				'decision'  => 'rejected',
				'action_id' => $action_id,
				'kind'      => $kind,
			);
		}

		// Accepted: invoke the apply handler with the stored input.
		$result = call_user_func( $handler['apply'], $apply_input, $payload );

		self::fireResolvedAction( $decision, $action_id, $kind, $payload, $result );

		if ( is_wp_error( $result ) ) {
			return array(
				'success'   => false,
				'decision'  => 'accepted',
				'action_id' => $action_id,
				'kind'      => $kind,
				'error'     => $result->get_error_message(),
			);
		}

		if ( is_array( $result ) && array_key_exists( 'success', $result ) && false === $result['success'] ) {
			return array(
				'success'   => false,
				'decision'  => 'accepted',
				'action_id' => $action_id,
				'kind'      => $kind,
				'result'    => $result,
				'error'     => $result['error'] ?? 'Apply handler reported failure.',
			);
		}

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
		 *       'can_resolve' => callable ( array $payload, string $decision, int $user_id ): bool|WP_Error,
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
	 * Fire the post-resolution action.
	 *
	 * @param string     $decision  accepted|rejected.
	 * @param string     $action_id Action ID.
	 * @param string     $kind      Kind.
	 * @param array      $payload   Stored payload.
	 * @param mixed|null $result    Apply result (for accepted) or null.
	 * @return void
	 */
	private static function fireResolvedAction( string $decision, string $action_id, string $kind, array $payload, $result ): void {
		/**
		 * Fires after a pending action has been resolved.
		 *
		 * @since 0.72.0
		 *
		 * @param string     $decision  accepted|rejected.
		 * @param string     $action_id Action ID.
		 * @param string     $kind      Kind.
		 * @param array      $payload   Stored payload (pre-deletion).
		 * @param mixed|null $result    Apply result (for accepted) or null.
		 */
		do_action( 'datamachine_pending_action_resolved', $decision, $action_id, $kind, $payload, $result );
	}
}
