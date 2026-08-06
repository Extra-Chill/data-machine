<?php
/**
 * Durable social publish abilities.
 *
 * @package DataMachineSocials\Abilities
 */

namespace DataMachineSocials\Abilities;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Abilities\ExecutionScope;
use DataMachineSocials\Operations\DelegatedCrossPostAction;

defined( 'ABSPATH' ) || exit;

/** Expose the Socials-owned publish lifecycle without leaking scheduler details. */
class SocialPublishAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	private const RETRYABLE_DELIVERY_ERRORS = array( 'channel_unavailable', 'undelivered' );

	public function __construct() {
		$this->registerAbility( array( $this, 'registerAbilities' ), true );
	}

	/** Register enqueue, state, and retry as one public owner contract. */
	public function registerAbilities(): void {
		$output   = array(
			'type'                 => 'object',
			'required'             => array( 'success' ),
			'properties'           => array(
				'success'  => array( 'type' => 'boolean' ),
				'delivery' => array( 'type' => 'object' ),
				'error'    => array( 'type' => 'object' ),
			),
			'additionalProperties' => false,
		);
		$identity = array(
			'type'                 => 'object',
			'required'             => array( 'delivery_ref' ),
			'properties'           => array(
				'delivery_ref' => array(
					'type'    => 'string',
					'pattern' => '^dop_[a-f0-9]{64}$',
				),
			),
			'additionalProperties' => false,
		);

		wp_register_ability(
			'datamachine/enqueue-social-publish',
			array(
				'label'               => __( 'Enqueue Social Publish', 'data-machine-socials' ),
				'description'         => __( 'Idempotently enqueue an authorized publish of canonical content to a bounded target policy.', 'data-machine-socials' ),
				'category'            => 'datamachine-socials',
				'input_schema'        => $this->enqueueSchema(),
				'output_schema'       => $output,
				'execute_callback'    => array( $this, 'enqueue' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);

		foreach (
			array(
				'get'   => 'getState',
				'retry' => 'retry',
			) as $verb => $callback
		) {
			wp_register_ability(
				'datamachine/' . $verb . '-social-publish',
				array(
					/* translators: %s: Get or Retry. */
					'label'               => sprintf( __( '%s Social Publish', 'data-machine-socials' ), ucfirst( $verb ) ),
					'description'         => __( 'Read or explicitly retry one owner-authorized social delivery.', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => $identity,
					'output_schema'       => $output,
					'execute_callback'    => array( $this, $callback ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		}
	}

	/** Require an authenticated actor; owner policy performs resource authorization. */
	public function checkPermission(): bool {
		$scope = ExecutionScope::current( 'manage_flows' );
		return $scope->acting_user_id() > 0 || (int) ( $scope->acting_agent_id() ?? 0 ) > 0;
	}

	/** Submit canonical content through Data Machine's public delegated operation ability. */
	public function enqueue( array $input ): array {
		$content = is_array( $input['content_ref'] ?? null ) ? $input['content_ref'] : array();
		$policy  = is_array( $input['target_policy'] ?? null ) ? $input['target_policy'] : array();
		$targets = is_array( $policy['channels'] ?? null ) ? $policy['channels'] : array();

		$unavailable = array();
		foreach ( $targets as $target ) {
			$status = $this->providerStatus( (string) $target );
			if ( 'ready' !== $status ) {
				$unavailable[] = array(
					'channel' => sanitize_key( (string) $target ),
					'status'  => $status,
				);
			}
		}
		if ( $unavailable ) {
			return $this->failure( 'social_publish_provider_unavailable', __( 'One or more target providers are unavailable.', 'data-machine-socials' ), false, array( 'providers' => $unavailable ) );
		}

		$result = $this->invokeDelegated(
			'submit',
			array(
				'action'       => DelegatedCrossPostAction::ACTION_ID,
				'operation_id' => (string) ( $input['idempotency_key'] ?? '' ),
				'input'        => array(
					'post_id'      => $content['post_id'] ?? null,
					'source_url'   => $content['source_url'] ?? null,
					'caption'      => $content['caption'] ?? null,
					'content_hash' => $content['content_hash'] ?? null,
					'channels'     => $targets,
					'media_kind'   => $policy['media_kind'] ?? null,
					'asset_refs'   => $content['asset_refs'] ?? null,
				),
			)
		);

		return $this->normalizeResult( $result );
	}

	/** Read durable delivery state by opaque Socials receipt. */
	public function getState( array $input ): array {
		return $this->normalizeResult(
			$this->invokeDelegated(
				'get',
				array(
					'action'        => DelegatedCrossPostAction::ACTION_ID,
					'operation_ref' => (string) ( $input['delivery_ref'] ?? '' ),
				)
			)
		);
	}

	/** Explicitly retry a failed delivery after owner authorization and reconciliation. */
	public function retry( array $input ): array {
		return $this->normalizeResult(
			$this->invokeDelegated(
				'retry',
				array(
					'action'        => DelegatedCrossPostAction::ACTION_ID,
					'operation_ref' => (string) ( $input['delivery_ref'] ?? '' ),
				)
			)
		);
	}

	/** Resolve provider registration and local configuration without contacting it. */
	protected function providerStatus( string $channel ): string {
		if ( ! wp_get_ability( 'datamachine/' . $channel . '-publish' ) ) {
			return 'publish_ability_missing';
		}

		$auth     = new AuthAbilities();
		$provider = $auth->getProviderForHandler( $channel );
		if ( ! $provider ) {
			return 'provider_missing';
		}
		if ( method_exists( $provider, 'is_configured' ) && ! $provider->is_configured() ) {
			return 'provider_not_configured';
		}
		if ( ! method_exists( $provider, 'is_authenticated' ) || ! $provider->is_authenticated() ) {
			return 'provider_not_authenticated';
		}

		return 'ready';
	}

	/** Invoke only Data Machine's bounded public delegated-operation abilities. */
	protected function invokeDelegated( string $verb, array $input ): array {
		$ability = wp_get_ability( 'datamachine/' . $verb . '-delegated-operation' );
		if ( ! $ability ) {
			return array(
				'success'    => false,
				'error_code' => 'delegated_ability_unavailable',
				'error'      => __( 'The durable operation service is unavailable.', 'data-machine-socials' ),
				'retryable'  => true,
			);
		}

		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			return array(
				'success'    => false,
				'error_code' => $result->get_error_code(),
				'error'      => $result->get_error_message(),
			);
		}

		return is_array( $result ) ? $result : array(
			'success'    => false,
			'error_code' => 'delegated_response_invalid',
			'error'      => __( 'The durable operation service returned an invalid response.', 'data-machine-socials' ),
			'retryable'  => true,
		);
	}

	/** Project generic durable operation state into a stable Socials delivery schema. */
	private function normalizeResult( array $result ): array {
		if ( empty( $result['success'] ) ) {
			$code      = (string) ( $result['error_code'] ?? 'delegated_response_invalid' );
			$retryable = ! empty( $result['retryable'] );
			if ( 'delegated_operation_conflict' === $code ) {
				return $this->failure( 'social_publish_idempotency_conflict', __( 'The idempotency key is already bound to different content or policy.', 'data-machine-socials' ) );
			}
			if ( in_array( $code, array( 'delegated_enqueue_failed', 'delegated_operation_create_failed', 'delegated_operation_persist_failed', 'delegated_operation_load_failed', 'delegated_ability_unavailable', 'delegated_response_invalid' ), true ) ) {
				return $this->failure( 'social_publish_scheduler_unavailable', __( 'The social delivery could not be scheduled.', 'data-machine-socials' ), true );
			}
			if ( in_array( $code, array( 'social_cross_post_forbidden', 'delegated_action_forbidden' ), true ) ) {
				return $this->failure( 'social_publish_forbidden', __( 'The actor is not authorized for this social delivery.', 'data-machine-socials' ) );
			}
			if ( in_array( $code, array( 'delegated_operation_not_retryable', 'social_cross_post_retry_unsafe', 'delegated_operation_retry_unsafe' ), true ) ) {
				return $this->failure( 'social_publish_terminal_failure', __( 'This social delivery cannot be retried safely.', 'data-machine-socials' ) );
			}

			return $this->failure( sanitize_key( $code ), (string) ( $result['error'] ?? __( 'The social delivery request failed.', 'data-machine-socials' ) ), $retryable );
		}

		$projection = is_array( $result['projection'] ?? null ) ? $result['projection'] : array();
		$errors     = is_array( $projection['error_codes'] ?? null ) ? array_values( $projection['error_codes'] ) : array();
		$status     = $this->deliveryStatus( (string) ( $result['status'] ?? '' ) );
		$retryable  = 'failed' === $status && ! empty( $errors );
		foreach ( $errors as $error ) {
			if ( ! is_array( $error ) || ! in_array( $error['code'] ?? '', self::RETRYABLE_DELIVERY_ERRORS, true ) ) {
				$retryable = false;
				break;
			}
		}

		$delivery = array(
			'delivery_ref' => (string) ( $result['operation_ref'] ?? '' ),
			'status'       => $status,
			'duplicate'    => ! empty( $result['replayed'] ),
			'retryable'    => $retryable,
			'deliveries'   => is_array( $projection['share_refs'] ?? null ) ? array_values( $projection['share_refs'] ) : array(),
			'errors'       => $errors,
		);
		if ( 'failed' === $status ) {
			$delivery['failure_kind'] = $retryable ? 'transient' : 'terminal';
		}
		if ( is_array( $result['retry'] ?? null ) ) {
			$delivery['retry'] = $result['retry'];
		}

		return array(
			'success'  => true,
			'delivery' => $delivery,
		);
	}

	private function deliveryStatus( string $status ): string {
		return array(
			'submitted' => 'queued',
			'executing' => 'delivering',
			'executed'  => 'delivered',
			'no-op'     => 'no_op',
			'failed'    => 'failed',
			'cancelled' => 'cancelled',
			'retrying'  => 'retrying',
		)[ $status ] ?? 'unknown';
	}

	private function failure( string $code, string $message, bool $retryable = false, array $details = array() ): array {
		$error = array(
			'code'      => $code,
			'message'   => $message,
			'retryable' => $retryable,
		);
		if ( $details ) {
			$error['details'] = $details;
		}

		return array(
			'success' => false,
			'error'   => $error,
		);
	}

	private function enqueueSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'content_ref', 'target_policy', 'idempotency_key' ),
			'properties'           => array(
				'content_ref'     => array(
					'type'                 => 'object',
					'required'             => array( 'post_id', 'source_url', 'caption', 'content_hash', 'asset_refs' ),
					'properties'           => array(
						'post_id'      => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'source_url'   => array(
							'type'   => 'string',
							'format' => 'uri',
						),
						'caption'      => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'content_hash' => array(
							'type'    => 'string',
							'pattern' => '^[a-f0-9]{64}$',
						),
						'asset_refs'   => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'required'             => array( 'attachment_id', 'role' ),
								'properties'           => array(
									'attachment_id' => array(
										'type'    => 'integer',
										'minimum' => 1,
									),
									'role'          => array(
										'type' => 'string',
										'enum' => array( 'image', 'video', 'cover' ),
									),
								),
								'additionalProperties' => false,
							),
						),
					),
					'additionalProperties' => false,
				),
				'target_policy'   => array(
					'type'                 => 'object',
					'required'             => array( 'channels', 'media_kind' ),
					'properties'           => array(
						'channels'   => array(
							'type'     => 'array',
							'minItems' => 1,
							'maxItems' => 6,
							'items'    => array(
								'type' => 'string',
								'enum' => array( 'bluesky', 'facebook', 'instagram', 'pinterest', 'threads', 'twitter' ),
							),
						),
						'media_kind' => array(
							'type' => 'string',
							'enum' => array( 'image', 'carousel', 'reel', 'story' ),
						),
					),
					'additionalProperties' => false,
				),
				'idempotency_key' => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 191,
				),
			),
			'additionalProperties' => false,
		);
	}
}
