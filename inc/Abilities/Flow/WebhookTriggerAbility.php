<?php
/**
 * Webhook Trigger Ability
 *
 * Manages per-flow webhook trigger tokens: enable, disable, regenerate, and status.
 * Tokens are stored in the flow's scheduling_config JSON field.
 *
 * @package DataMachine\Abilities\Flow
 * @since 0.30.0
 * @see https://github.com/Extra-Chill/data-machine/issues/342
 */

namespace DataMachine\Abilities\Flow;

defined( 'ABSPATH' ) || exit;

/**
 * Webhook Trigger Ability handler.
 *
 * Registers and executes abilities for managing per-flow webhook
 * trigger tokens: enable, disable, regenerate, and status.
 */
class WebhookTriggerAbility {

	use FlowHelpers;

	/**
	 * Initialize database connections and register abilities.
	 */
	public function __construct() {
		$this->initDatabases();

		$this->registerAbilities();
	}

	/**
	 * Register all webhook trigger abilities.
	 */
	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/webhook-trigger-enable',
				array(
					'label'               => __( 'Enable Webhook Trigger', 'data-machine' ),
					'description'         => __( 'Enable webhook trigger for a flow. Supports Bearer token (default) or HMAC-SHA256 authentication. External services can POST to the trigger URL to start flow executions.', 'data-machine' ),
					'category'            => 'datamachine-flow',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'flow_id' ),
						'properties' => array(
							'flow_id'          => array(
								'type'        => 'integer',
								'description' => __( 'Flow ID to enable webhook trigger for', 'data-machine' ),
							),
							'auth_mode'        => array(
								'type'        => 'string',
								'enum'        => array( 'bearer', 'hmac_sha256' ),
								'description' => __( 'Authentication mode. Defaults to bearer for backward compatibility.', 'data-machine' ),
							),
							'signature_header' => array(
								'type'        => 'string',
								'description' => __( 'HMAC signature header name (e.g. X-Hub-Signature-256). Only used when auth_mode is hmac_sha256.', 'data-machine' ),
							),
							'signature_format' => array(
								'type'        => 'string',
								'enum'        => array( 'sha256=hex', 'hex', 'base64' ),
								'description' => __( 'HMAC signature encoding. Only used when auth_mode is hmac_sha256.', 'data-machine' ),
							),
							'generate_secret'  => array(
								'type'        => 'boolean',
								'description' => __( 'When auth_mode is hmac_sha256, auto-generate a random 32-byte hex secret.', 'data-machine' ),
							),
							'secret'           => array(
								'type'        => 'string',
								'description' => __( 'When auth_mode is hmac_sha256, use this secret value (takes precedence over generate_secret).', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'          => array( 'type' => 'boolean' ),
							'flow_id'          => array( 'type' => 'integer' ),
							'webhook_url'      => array( 'type' => 'string' ),
							'auth_mode'        => array( 'type' => 'string' ),
							'token'            => array( 'type' => 'string' ),
							'secret'           => array( 'type' => 'string' ),
							'signature_header' => array( 'type' => 'string' ),
							'signature_format' => array( 'type' => 'string' ),
							'message'          => array( 'type' => 'string' ),
							'error'            => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeEnable' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/webhook-trigger-set-secret',
				array(
					'label'               => __( 'Set Webhook HMAC Secret', 'data-machine' ),
					'description'         => __( 'Set or rotate the HMAC shared secret for a flow webhook. The secret is returned once and never retrievable via status.', 'data-machine' ),
					'category'            => 'datamachine-flow',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'flow_id' ),
						'properties' => array(
							'flow_id'  => array(
								'type'        => 'integer',
								'description' => __( 'Flow ID to set the HMAC secret for', 'data-machine' ),
							),
							'secret'   => array(
								'type'        => 'string',
								'description' => __( 'Secret value provided by the upstream service (e.g. GitHub webhook secret).', 'data-machine' ),
							),
							'generate' => array(
								'type'        => 'boolean',
								'description' => __( 'Generate a random 32-byte hex secret and display it once.', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'   => array( 'type' => 'boolean' ),
							'flow_id'   => array( 'type' => 'integer' ),
							'secret'    => array( 'type' => 'string' ),
							'auth_mode' => array( 'type' => 'string' ),
							'message'   => array( 'type' => 'string' ),
							'error'     => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeSetSecret' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/webhook-trigger-disable',
				array(
					'label'               => __( 'Disable Webhook Trigger', 'data-machine' ),
					'description'         => __( 'Disable webhook trigger for a flow. Revokes the token and stops accepting inbound webhooks.', 'data-machine' ),
					'category'            => 'datamachine-flow',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'flow_id' ),
						'properties' => array(
							'flow_id' => array(
								'type'        => 'integer',
								'description' => __( 'Flow ID to disable webhook trigger for', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'flow_id' => array( 'type' => 'integer' ),
							'message' => array( 'type' => 'string' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeDisable' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/webhook-trigger-regenerate',
				array(
					'label'               => __( 'Regenerate Webhook Token', 'data-machine' ),
					'description'         => __( 'Regenerate the webhook trigger token for a flow. The old token is immediately invalidated.', 'data-machine' ),
					'category'            => 'datamachine-flow',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'flow_id' ),
						'properties' => array(
							'flow_id' => array(
								'type'        => 'integer',
								'description' => __( 'Flow ID to regenerate webhook token for', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'     => array( 'type' => 'boolean' ),
							'flow_id'     => array( 'type' => 'integer' ),
							'webhook_url' => array( 'type' => 'string' ),
							'token'       => array( 'type' => 'string' ),
							'message'     => array( 'type' => 'string' ),
							'error'       => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeRegenerate' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/webhook-trigger-rate-limit',
				array(
					'label'               => __( 'Configure Webhook Rate Limit', 'data-machine' ),
					'description'         => __( 'Set rate limiting for a flow webhook trigger. Limits the number of requests per time window.', 'data-machine' ),
					'category'            => 'datamachine-flow',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'flow_id' ),
						'properties' => array(
							'flow_id' => array(
								'type'        => 'integer',
								'description' => __( 'Flow ID to configure rate limit for', 'data-machine' ),
							),
							'max'     => array(
								'type'        => 'integer',
								'description' => __( 'Maximum requests per window. Set to 0 to disable rate limiting.', 'data-machine' ),
							),
							'window'  => array(
								'type'        => 'integer',
								'description' => __( 'Time window in seconds.', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'    => array( 'type' => 'boolean' ),
							'flow_id'    => array( 'type' => 'integer' ),
							'rate_limit' => array(
								'type'       => 'object',
								'properties' => array(
									'max'    => array( 'type' => 'integer' ),
									'window' => array( 'type' => 'integer' ),
								),
							),
							'message'    => array( 'type' => 'string' ),
							'error'      => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeSetRateLimit' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/webhook-trigger-status',
				array(
					'label'               => __( 'Webhook Trigger Status', 'data-machine' ),
					'description'         => __( 'Get the webhook trigger status for a flow, including URL and whether it is enabled.', 'data-machine' ),
					'category'            => 'datamachine-flow',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'flow_id' ),
						'properties' => array(
							'flow_id' => array(
								'type'        => 'integer',
								'description' => __( 'Flow ID to check webhook trigger status for', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'          => array( 'type' => 'boolean' ),
							'flow_id'          => array( 'type' => 'integer' ),
							'flow_name'        => array( 'type' => 'string' ),
							'webhook_enabled'  => array( 'type' => 'boolean' ),
							'webhook_url'      => array( 'type' => 'string' ),
							'created_at'       => array( 'type' => 'string' ),
							'auth_mode'        => array( 'type' => 'string' ),
							'signature_header' => array( 'type' => 'string' ),
							'signature_format' => array( 'type' => 'string' ),
							'max_body_bytes'   => array( 'type' => 'integer' ),
							'error'            => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeStatus' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Enable webhook trigger for a flow.
	 *
	 * Supports two auth modes:
	 * - `bearer` (default): generates a 32-byte hex token.
	 * - `hmac_sha256`:       stores a shared secret plus signature header/format.
	 *
	 * If already enabled in the same mode, returns the existing config.
	 * Switching modes requires disabling and re-enabling.
	 *
	 * @param array $input Input with flow_id and optional auth_mode / header / format / secret.
	 * @return array Result with token or secret and webhook URL.
	 */
	public function executeEnable( array $input ): array {
		$flow_id = (int) ( $input['flow_id'] ?? 0 );

		if ( $flow_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'flow_id must be a positive integer',
			);
		}

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Flow %d not found', $flow_id ),
			);
		}

		$scheduling_config = $flow['scheduling_config'] ?? array();

		$requested_mode = isset( $input['auth_mode'] ) ? (string) $input['auth_mode'] : '';
		if ( '' === $requested_mode ) {
			$requested_mode = $scheduling_config['webhook_auth_mode'] ?? 'bearer';
		}
		if ( ! in_array( $requested_mode, array( 'bearer', 'hmac_sha256' ), true ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Unknown auth_mode "%s". Expected bearer or hmac_sha256.', $requested_mode ),
			);
		}

		// If already enabled in the same mode with valid auth material, return existing config.
		$existing_mode = $scheduling_config['webhook_auth_mode'] ?? 'bearer';
		if ( ! empty( $scheduling_config['webhook_enabled'] ) && $existing_mode === $requested_mode ) {
			if ( 'bearer' === $requested_mode && ! empty( $scheduling_config['webhook_token'] ) ) {
				return array(
					'success'     => true,
					'flow_id'     => $flow_id,
					'webhook_url' => self::get_webhook_url( $flow_id ),
					'auth_mode'   => 'bearer',
					'token'       => $scheduling_config['webhook_token'],
					'message'     => 'Webhook trigger already enabled.',
				);
			}
			if ( 'hmac_sha256' === $requested_mode && ! empty( $scheduling_config['webhook_secret'] ) ) {
				return array(
					'success'          => true,
					'flow_id'          => $flow_id,
					'webhook_url'      => self::get_webhook_url( $flow_id ),
					'auth_mode'        => 'hmac_sha256',
					'signature_header' => $scheduling_config['webhook_signature_header'] ?? 'X-Hub-Signature-256',
					'signature_format' => $scheduling_config['webhook_signature_format'] ?? 'sha256=hex',
					'message'          => 'Webhook trigger already enabled.',
				);
			}
		}

		$scheduling_config['webhook_enabled']    = true;
		$scheduling_config['webhook_auth_mode']  = $requested_mode;
		$scheduling_config['webhook_created_at'] = $scheduling_config['webhook_created_at'] ?? gmdate( 'Y-m-d\TH:i:s\Z' );

		$response = array(
			'success'     => true,
			'flow_id'     => $flow_id,
			'webhook_url' => self::get_webhook_url( $flow_id ),
			'auth_mode'   => $requested_mode,
		);

		if ( 'bearer' === $requested_mode ) {
			if ( empty( $scheduling_config['webhook_token'] ) ) {
				$scheduling_config['webhook_token'] = self::generate_token();
			}
			// Clear HMAC-specific fields when switching to bearer.
			unset( $scheduling_config['webhook_secret'] );
			unset( $scheduling_config['webhook_signature_header'] );
			unset( $scheduling_config['webhook_signature_format'] );

			$response['token']   = $scheduling_config['webhook_token'];
			$response['message'] = sprintf( 'Webhook trigger enabled for flow %d (bearer).', $flow_id );
		} else {
			// HMAC mode — resolve secret from input (explicit > generate > existing).
			$explicit_secret = isset( $input['secret'] ) ? (string) $input['secret'] : '';
			$generate        = ! empty( $input['generate_secret'] );

			if ( '' !== $explicit_secret ) {
				$scheduling_config['webhook_secret'] = $explicit_secret;
				$response['secret']                  = $explicit_secret;
			} elseif ( $generate || empty( $scheduling_config['webhook_secret'] ) ) {
				$new_secret                          = self::generate_secret();
				$scheduling_config['webhook_secret'] = $new_secret;
				$response['secret']                  = $new_secret;
			}

			if ( empty( $scheduling_config['webhook_secret'] ) ) {
				return array(
					'success' => false,
					'error'   => 'HMAC auth_mode requires a secret. Pass --generate-secret or --secret=<value>.',
				);
			}

			$header = isset( $input['signature_header'] ) ? trim( (string) $input['signature_header'] ) : '';
			if ( '' !== $header ) {
				$scheduling_config['webhook_signature_header'] = $header;
			} elseif ( empty( $scheduling_config['webhook_signature_header'] ) ) {
				$scheduling_config['webhook_signature_header'] = 'X-Hub-Signature-256';
			}

			$format = isset( $input['signature_format'] ) ? (string) $input['signature_format'] : '';
			if ( '' !== $format ) {
				if ( ! in_array( $format, \DataMachine\Api\WebhookSignatureVerifier::supported_formats(), true ) ) {
					return array(
						'success' => false,
						'error'   => sprintf( 'Unsupported signature_format "%s".', $format ),
					);
				}
				$scheduling_config['webhook_signature_format'] = $format;
			} elseif ( empty( $scheduling_config['webhook_signature_format'] ) ) {
				$scheduling_config['webhook_signature_format'] = 'sha256=hex';
			}

			// Clear Bearer-specific field when switching to HMAC.
			unset( $scheduling_config['webhook_token'] );

			$response['signature_header'] = $scheduling_config['webhook_signature_header'];
			$response['signature_format'] = $scheduling_config['webhook_signature_format'];
			$response['message']          = sprintf( 'Webhook trigger enabled for flow %d (hmac_sha256).', $flow_id );
		}

		$updated = $this->db_flows->update_flow( $flow_id, array( 'scheduling_config' => $scheduling_config ) );

		if ( ! $updated ) {
			return array(
				'success' => false,
				'error'   => 'Failed to update flow scheduling config',
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Webhook trigger enabled for flow',
			array(
				'flow_id'   => $flow_id,
				'auth_mode' => $requested_mode,
			)
		);

		return $response;
	}

	/**
	 * Set or rotate the HMAC shared secret for a flow.
	 *
	 * Accepts either an explicit `secret` value or `generate=true` to produce
	 * a random 32-byte hex secret. The secret is returned once in the result
	 * and never exposed via `executeStatus`.
	 *
	 * Also flips `webhook_auth_mode` to `hmac_sha256` if not already set, so
	 * this command can be used as a one-liner for new HMAC flows.
	 *
	 * @param array $input Input with flow_id and either secret or generate=true.
	 * @return array Result with the new secret on success.
	 */
	public function executeSetSecret( array $input ): array {
		$flow_id = (int) ( $input['flow_id'] ?? 0 );

		if ( $flow_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'flow_id must be a positive integer',
			);
		}

		$explicit_secret = isset( $input['secret'] ) ? (string) $input['secret'] : '';
		$generate        = ! empty( $input['generate'] );

		if ( '' === $explicit_secret && ! $generate ) {
			return array(
				'success' => false,
				'error'   => 'Provide either secret=<value> or generate=true.',
			);
		}

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Flow %d not found', $flow_id ),
			);
		}

		$scheduling_config = $flow['scheduling_config'] ?? array();

		$secret = '' !== $explicit_secret ? $explicit_secret : self::generate_secret();

		$scheduling_config['webhook_secret']     = $secret;
		$scheduling_config['webhook_auth_mode']  = 'hmac_sha256';
		$scheduling_config['webhook_enabled']    = true;
		$scheduling_config['webhook_created_at'] = $scheduling_config['webhook_created_at'] ?? gmdate( 'Y-m-d\TH:i:s\Z' );

		if ( empty( $scheduling_config['webhook_signature_header'] ) ) {
			$scheduling_config['webhook_signature_header'] = 'X-Hub-Signature-256';
		}
		if ( empty( $scheduling_config['webhook_signature_format'] ) ) {
			$scheduling_config['webhook_signature_format'] = 'sha256=hex';
		}
		// Clear Bearer-specific field when switching into HMAC mode.
		unset( $scheduling_config['webhook_token'] );

		$updated = $this->db_flows->update_flow( $flow_id, array( 'scheduling_config' => $scheduling_config ) );

		if ( ! $updated ) {
			return array(
				'success' => false,
				'error'   => 'Failed to update flow scheduling config',
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Webhook HMAC secret updated for flow',
			array( 'flow_id' => $flow_id )
		);

		return array(
			'success'   => true,
			'flow_id'   => $flow_id,
			'secret'    => $secret,
			'auth_mode' => 'hmac_sha256',
			'message'   => sprintf( 'HMAC secret updated for flow %d. Old secret is invalidated.', $flow_id ),
		);
	}

	/**
	 * Disable webhook trigger for a flow.
	 *
	 * Removes the token and disables webhook triggering.
	 *
	 * @param array $input Input with flow_id.
	 * @return array Result with success status.
	 */
	public function executeDisable( array $input ): array {
		$flow_id = (int) ( $input['flow_id'] ?? 0 );

		if ( $flow_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'flow_id must be a positive integer',
			);
		}

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Flow %d not found', $flow_id ),
			);
		}

		$scheduling_config = $flow['scheduling_config'] ?? array();

		unset( $scheduling_config['webhook_enabled'] );
		unset( $scheduling_config['webhook_token'] );
		unset( $scheduling_config['webhook_created_at'] );
		unset( $scheduling_config['webhook_auth_mode'] );
		unset( $scheduling_config['webhook_secret'] );
		unset( $scheduling_config['webhook_signature_header'] );
		unset( $scheduling_config['webhook_signature_format'] );
		unset( $scheduling_config['webhook_max_body_bytes'] );

		$updated = $this->db_flows->update_flow( $flow_id, array( 'scheduling_config' => $scheduling_config ) );

		if ( ! $updated ) {
			return array(
				'success' => false,
				'error'   => 'Failed to update flow scheduling config',
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Webhook trigger disabled for flow',
			array( 'flow_id' => $flow_id )
		);

		return array(
			'success' => true,
			'flow_id' => $flow_id,
			'message' => sprintf( 'Webhook trigger disabled for flow %d.', $flow_id ),
		);
	}

	/**
	 * Regenerate webhook token for a flow.
	 *
	 * The old token is immediately invalidated.
	 *
	 * @param array $input Input with flow_id.
	 * @return array Result with new token and webhook URL.
	 */
	public function executeRegenerate( array $input ): array {
		$flow_id = (int) ( $input['flow_id'] ?? 0 );

		if ( $flow_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'flow_id must be a positive integer',
			);
		}

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Flow %d not found', $flow_id ),
			);
		}

		$scheduling_config = $flow['scheduling_config'] ?? array();

		if ( empty( $scheduling_config['webhook_enabled'] ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Webhook trigger is not enabled for flow %d. Enable it first.', $flow_id ),
			);
		}

		$auth_mode = $scheduling_config['webhook_auth_mode'] ?? 'bearer';
		if ( 'bearer' !== $auth_mode ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'regenerate only applies to bearer auth_mode (flow %d is %s). Use set-secret for HMAC flows.', $flow_id, $auth_mode ),
			);
		}

		// Generate new token — old one is immediately invalidated.
		$token = self::generate_token();

		$scheduling_config['webhook_token']      = $token;
		$scheduling_config['webhook_created_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );

		$updated = $this->db_flows->update_flow( $flow_id, array( 'scheduling_config' => $scheduling_config ) );

		if ( ! $updated ) {
			return array(
				'success' => false,
				'error'   => 'Failed to update flow scheduling config',
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Webhook trigger token regenerated for flow',
			array( 'flow_id' => $flow_id )
		);

		return array(
			'success'     => true,
			'flow_id'     => $flow_id,
			'webhook_url' => self::get_webhook_url( $flow_id ),
			'token'       => $token,
			'message'     => sprintf( 'Webhook token regenerated for flow %d. Old token is invalidated.', $flow_id ),
		);
	}

	/**
	 * Set rate limit configuration for a flow webhook trigger.
	 *
	 * @param array $input Input with flow_id, max, window.
	 * @return array Result with updated rate limit config.
	 */
	public function executeSetRateLimit( array $input ): array {
		$flow_id = (int) ( $input['flow_id'] ?? 0 );

		if ( $flow_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'flow_id must be a positive integer',
			);
		}

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Flow %d not found', $flow_id ),
			);
		}

		$scheduling_config = $flow['scheduling_config'] ?? array();

		if ( empty( $scheduling_config['webhook_enabled'] ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Webhook trigger is not enabled for flow %d. Enable it first.', $flow_id ),
			);
		}

		$rate_config = $scheduling_config['webhook_rate_limit'] ?? array();

		if ( isset( $input['max'] ) ) {
			$max = (int) $input['max'];
			if ( $max < 0 ) {
				return array(
					'success' => false,
					'error'   => 'max must be a non-negative integer (0 to disable)',
				);
			}
			$rate_config['max'] = $max;
		}

		if ( isset( $input['window'] ) ) {
			$window = (int) $input['window'];
			if ( $window < 1 ) {
				return array(
					'success' => false,
					'error'   => 'window must be a positive integer (seconds)',
				);
			}
			$rate_config['window'] = $window;
		}

		$scheduling_config['webhook_rate_limit'] = $rate_config;

		$updated = $this->db_flows->update_flow( $flow_id, array( 'scheduling_config' => $scheduling_config ) );

		if ( ! $updated ) {
			return array(
				'success' => false,
				'error'   => 'Failed to update flow scheduling config',
			);
		}

		// Clear any existing rate limit counter so new config takes effect immediately.
		delete_transient( 'dm_webhook_rate_' . $flow_id );

		$effective_max    = $rate_config['max'] ?? \DataMachine\Api\WebhookTrigger::DEFAULT_RATE_LIMIT_MAX;
		$effective_window = $rate_config['window'] ?? \DataMachine\Api\WebhookTrigger::DEFAULT_RATE_LIMIT_WINDOW;

		do_action(
			'datamachine_log',
			'info',
			'Webhook rate limit updated for flow',
			array(
				'flow_id' => $flow_id,
				'max'     => $effective_max,
				'window'  => $effective_window,
			)
		);

		$message = 0 === $effective_max
			? sprintf( 'Rate limiting disabled for flow %d.', $flow_id )
			: sprintf( 'Rate limit set to %d requests per %d seconds for flow %d.', $effective_max, $effective_window, $flow_id );

		return array(
			'success'    => true,
			'flow_id'    => $flow_id,
			'rate_limit' => array(
				'max'    => $effective_max,
				'window' => $effective_window,
			),
			'message'    => $message,
		);
	}

	/**
	 * Get webhook trigger status for a flow.
	 *
	 * Does NOT return the token — use enable/regenerate for that.
	 *
	 * @param array $input Input with flow_id.
	 * @return array Result with webhook status including rate limit config.
	 */
	public function executeStatus( array $input ): array {
		$flow_id = (int) ( $input['flow_id'] ?? 0 );

		if ( $flow_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'flow_id must be a positive integer',
			);
		}

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Flow %d not found', $flow_id ),
			);
		}

		$scheduling_config = $flow['scheduling_config'] ?? array();
		$enabled           = ! empty( $scheduling_config['webhook_enabled'] );

		$result = array(
			'success'         => true,
			'flow_id'         => $flow_id,
			'flow_name'       => $flow['flow_name'] ?? '',
			'webhook_enabled' => $enabled,
		);

		if ( $enabled ) {
			$auth_mode             = $scheduling_config['webhook_auth_mode'] ?? 'bearer';
			$result['webhook_url'] = self::get_webhook_url( $flow_id );
			$result['created_at']  = $scheduling_config['webhook_created_at'] ?? '';
			$result['auth_mode']   = $auth_mode;

			if ( 'hmac_sha256' === $auth_mode ) {
				$result['signature_header'] = $scheduling_config['webhook_signature_header'] ?? 'X-Hub-Signature-256';
				$result['signature_format'] = $scheduling_config['webhook_signature_format'] ?? 'sha256=hex';
				$result['max_body_bytes']   = (int) ( $scheduling_config['webhook_max_body_bytes']
					?? \DataMachine\Api\WebhookTrigger::DEFAULT_MAX_BODY_BYTES );
				// NEVER include the secret.
			}

			$rate_config          = $scheduling_config['webhook_rate_limit'] ?? array();
			$result['rate_limit'] = array(
				'max'    => $rate_config['max'] ?? \DataMachine\Api\WebhookTrigger::DEFAULT_RATE_LIMIT_MAX,
				'window' => $rate_config['window'] ?? \DataMachine\Api\WebhookTrigger::DEFAULT_RATE_LIMIT_WINDOW,
			);
		}

		return $result;
	}

	/**
	 * Generate a cryptographically secure webhook token.
	 *
	 * @return string 64-character hex token.
	 */
	public static function generate_token(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Generate a cryptographically secure HMAC shared secret.
	 *
	 * Returned as a 64-character hex string so it can be safely pasted into
	 * provider webhook configuration UIs (GitHub, Shopify, etc.).
	 *
	 * @return string 64-character hex secret.
	 */
	public static function generate_secret(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Get the webhook trigger URL for a flow.
	 *
	 * @param int $flow_id Flow ID.
	 * @return string Full webhook trigger URL.
	 */
	public static function get_webhook_url( int $flow_id ): string {
		return rest_url( "datamachine/v1/trigger/{$flow_id}" );
	}
}
