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
					'description'         => __( 'Enable webhook trigger for a flow. Supports Bearer token (default) or HMAC (via a registered preset or an explicit template config).', 'data-machine' ),
					'category'            => 'datamachine-flow',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'flow_id' ),
						'properties' => array(
							'flow_id'            => array(
								'type'        => 'integer',
								'description' => __( 'Flow ID to enable webhook trigger for.', 'data-machine' ),
							),
							'auth_mode'          => array(
								'type'        => 'string',
								'enum'        => array( 'bearer', 'hmac' ),
								'description' => __( 'Authentication primitive. Defaults to bearer.', 'data-machine' ),
							),
							'preset'             => array(
								'type'        => 'string',
								'description' => __( 'Name of a preset registered via the datamachine_webhook_auth_presets filter. Expands to a full template at enable-time; implies HMAC mode.', 'data-machine' ),
							),
							'template'           => array(
								'type'        => 'object',
								'description' => __( 'Explicit template config (v2 webhook_auth shape). Implies HMAC mode.', 'data-machine' ),
							),
							'template_overrides' => array(
								'type'        => 'object',
								'description' => __( 'Deep-merged overrides applied on top of the preset or template.', 'data-machine' ),
							),
							'generate_secret'    => array(
								'type'        => 'boolean',
								'description' => __( 'Generate a random 32-byte hex secret (HMAC mode only).', 'data-machine' ),
							),
							'secret'             => array(
								'type'        => 'string',
								'description' => __( 'Explicit secret value (HMAC mode only; takes precedence over generate_secret).', 'data-machine' ),
							),
							'secret_id'          => array(
								'type'        => 'string',
								'description' => __( 'Secret id for multi-secret rotation (default: current).', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'     => array( 'type' => 'boolean' ),
							'flow_id'     => array( 'type' => 'integer' ),
							'webhook_url' => array( 'type' => 'string' ),
							'auth_mode'   => array( 'type' => 'string' ),
							'token'       => array( 'type' => 'string' ),
							'secret'      => array( 'type' => 'string' ),
							'secret_ids'  => array( 'type' => 'array' ),
							'message'     => array( 'type' => 'string' ),
							'error'       => array( 'type' => 'string' ),
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
							'success'         => array( 'type' => 'boolean' ),
							'flow_id'         => array( 'type' => 'integer' ),
							'flow_name'       => array( 'type' => 'string' ),
							'webhook_enabled' => array( 'type' => 'boolean' ),
							'webhook_url'     => array( 'type' => 'string' ),
							'created_at'      => array( 'type' => 'string' ),
							'auth_mode'       => array( 'type' => 'string' ),
							'template'        => array( 'type' => 'object' ),
							'secret_ids'      => array( 'type' => 'array' ),
							'error'           => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeStatus' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/webhook-trigger-rotate-secret',
				array(
					'label'               => __( 'Rotate Webhook HMAC Secret', 'data-machine' ),
					'description'         => __( 'Zero-downtime rotation. Demotes current → previous with a TTL, installs a fresh current. Both verify until previous expires.', 'data-machine' ),
					'category'            => 'datamachine-flow',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'flow_id' ),
						'properties' => array(
							'flow_id'              => array( 'type' => 'integer' ),
							'secret'               => array( 'type' => 'string' ),
							'generate'             => array( 'type' => 'boolean' ),
							'previous_ttl_seconds' => array( 'type' => 'integer' ),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'             => array( 'type' => 'boolean' ),
							'flow_id'             => array( 'type' => 'integer' ),
							'new_secret'          => array( 'type' => 'string' ),
							'previous_expires_at' => array( 'type' => 'string' ),
							'secret_ids'          => array( 'type' => 'array' ),
							'message'             => array( 'type' => 'string' ),
							'error'               => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeRotateSecret' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/webhook-trigger-forget-secret',
				array(
					'label'               => __( 'Forget Webhook HMAC Secret', 'data-machine' ),
					'description'         => __( 'Immediately remove a specific secret by id from the rotation list.', 'data-machine' ),
					'category'            => 'datamachine-flow',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'flow_id', 'secret_id' ),
						'properties' => array(
							'flow_id'   => array( 'type' => 'integer' ),
							'secret_id' => array( 'type' => 'string' ),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'    => array( 'type' => 'boolean' ),
							'flow_id'    => array( 'type' => 'integer' ),
							'secret_ids' => array( 'type' => 'array' ),
							'message'    => array( 'type' => 'string' ),
							'error'      => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeForgetSecret' ),
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
	 * Auth modes:
	 * - `bearer` (default): generate a 32-byte hex token.
	 * - `hmac`:              require a preset name OR an explicit template.
	 *                        No provider-specific defaults; no silent fallbacks.
	 *
	 * Re-calling enable with the same mode and no new secret material returns
	 * the existing config unchanged. Passing `--preset`, `--template`, or a
	 * fresh secret always re-configures the flow.
	 *
	 * @param array $input
	 * @return array
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

		$preset_name = isset( $input['preset'] ) ? trim( (string) $input['preset'] ) : '';
		$template_in = isset( $input['template'] ) && is_array( $input['template'] ) ? $input['template'] : null;
		$overrides   = isset( $input['template_overrides'] ) && is_array( $input['template_overrides'] ) ? $input['template_overrides'] : array();

		// A preset or explicit template implies HMAC.
		$requested_mode = isset( $input['auth_mode'] ) ? (string) $input['auth_mode'] : '';
		if ( '' === $requested_mode ) {
			if ( '' !== $preset_name || null !== $template_in ) {
				$requested_mode = 'hmac';
			} else {
				$requested_mode = $scheduling_config['webhook_auth_mode'] ?? 'bearer';
			}
		}
		if ( ! in_array( $requested_mode, array( 'bearer', 'hmac' ), true ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Unknown auth_mode "%s". Expected bearer or hmac.', $requested_mode ),
			);
		}

		$has_new_secret   = isset( $input['secret'] ) || ! empty( $input['generate_secret'] );
		$has_new_template = ( '' !== $preset_name ) || ( null !== $template_in );
		$existing_mode    = $scheduling_config['webhook_auth_mode'] ?? 'bearer';

		// No-change short-circuit.
		if ( ! empty( $scheduling_config['webhook_enabled'] )
			&& $existing_mode === $requested_mode
			&& ! $has_new_secret
			&& ! $has_new_template
		) {
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
			if ( 'hmac' === $requested_mode && ! empty( $scheduling_config['webhook_auth'] ) ) {
				return array(
					'success'     => true,
					'flow_id'     => $flow_id,
					'webhook_url' => self::get_webhook_url( $flow_id ),
					'auth_mode'   => 'hmac',
					'secret_ids'  => self::summarize_secrets( $scheduling_config['webhook_secrets'] ?? array() ),
					'message'     => 'Webhook trigger already enabled.',
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
			// Clear every HMAC field when switching to bearer.
			unset(
				$scheduling_config['webhook_auth'],
				$scheduling_config['webhook_secrets']
			);

			$response['token']   = $scheduling_config['webhook_token'];
			$response['message'] = sprintf( 'Webhook trigger enabled for flow %d (bearer).', $flow_id );
		} else {
			// HMAC mode — must resolve a template, either from preset or explicit input.
			if ( '' !== $preset_name ) {
				$presets = \DataMachine\Api\WebhookAuthResolver::get_presets();
				if ( ! isset( $presets[ $preset_name ] ) ) {
					return array(
						'success' => false,
						'error'   => sprintf(
							'Unknown preset "%s". Register presets via the datamachine_webhook_auth_presets filter.',
							$preset_name
						),
					);
				}
				$template = $presets[ $preset_name ];
			} elseif ( null !== $template_in ) {
				$template = $template_in;
			} elseif ( ! empty( $scheduling_config['webhook_auth'] ) ) {
				$template = $scheduling_config['webhook_auth'];
			} else {
				return array(
					'success' => false,
					'error'   => 'HMAC mode requires a preset (--preset=<name>) or an explicit template (--template=...).',
				);
			}
			if ( ! empty( $overrides ) ) {
				$template = \DataMachine\Api\WebhookAuthResolver::deep_merge( $template, $overrides );
			}

			// Normalise the template: force mode=hmac so filter-registered presets
			// can't accidentally escape into other modes without an explicit decision.
			$template['mode'] = 'hmac';

			// Secret resolution: explicit > generate > existing in secrets roster.
			$explicit_secret = isset( $input['secret'] ) ? (string) $input['secret'] : '';
			$generate        = ! empty( $input['generate_secret'] );
			$secret_id       = isset( $input['secret_id'] ) ? (string) $input['secret_id'] : 'current';
			if ( '' === $secret_id ) {
				$secret_id = 'current';
			}

			$existing_secrets = $scheduling_config['webhook_secrets'] ?? array();
			$new_secret       = null;
			if ( '' !== $explicit_secret ) {
				$new_secret = $explicit_secret;
			} elseif ( $generate || empty( $existing_secrets ) ) {
				$new_secret = self::generate_secret();
			}

			if ( null !== $new_secret ) {
				$scheduling_config['webhook_secrets'] = self::upsert_secret(
					$existing_secrets,
					$secret_id,
					$new_secret
				);
				$response['secret']                   = $new_secret;
			} else {
				$scheduling_config['webhook_secrets'] = $existing_secrets;
			}

			if ( empty( $scheduling_config['webhook_secrets'] ) ) {
				return array(
					'success' => false,
					'error'   => 'HMAC mode requires a secret. Pass --generate-secret or --secret=<value>.',
				);
			}

			$scheduling_config['webhook_auth'] = $template;
			unset( $scheduling_config['webhook_token'] );

			$response['secret_ids'] = self::summarize_secrets( $scheduling_config['webhook_secrets'] );
			$response['message']    = sprintf( 'Webhook trigger enabled for flow %d (hmac).', $flow_id );
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
	 * Set or replace an HMAC secret for a flow.
	 *
	 * Requires the flow to already be in HMAC mode — it won't guess a template
	 * for you (no GitHub-style defaults). Use `enable --preset=<name>` or
	 * `enable --template=...` first to establish a template, then rotate
	 * secrets with this command or with `rotate` for a grace window.
	 *
	 * @param array $input flow_id, secret|generate, optional secret_id.
	 * @return array
	 */
	public function executeSetSecret( array $input ): array {
		$flow_id = (int) ( $input['flow_id'] ?? 0 );
		if ( $flow_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'flow_id must be a positive integer',
			);
		}

		$explicit = isset( $input['secret'] ) ? (string) $input['secret'] : '';
		$generate = ! empty( $input['generate'] );
		if ( '' === $explicit && ! $generate ) {
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

		if ( empty( $scheduling_config['webhook_auth'] ) ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					'Flow %d has no HMAC template yet. Run `enable --preset=<name>` or `enable --template=...` first.',
					$flow_id
				),
			);
		}

		$secret    = '' !== $explicit ? $explicit : self::generate_secret();
		$secret_id = isset( $input['secret_id'] ) ? (string) $input['secret_id'] : 'current';
		if ( '' === $secret_id ) {
			$secret_id = 'current';
		}

		$scheduling_config['webhook_secrets']    = self::upsert_secret(
			$scheduling_config['webhook_secrets'] ?? array(),
			$secret_id,
			$secret
		);
		$scheduling_config['webhook_auth_mode']  = 'hmac';
		$scheduling_config['webhook_enabled']    = true;
		$scheduling_config['webhook_created_at'] = $scheduling_config['webhook_created_at'] ?? gmdate( 'Y-m-d\TH:i:s\Z' );
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
			'Webhook HMAC secret updated',
			array(
				'flow_id'   => $flow_id,
				'secret_id' => $secret_id,
			)
		);

		return array(
			'success'    => true,
			'flow_id'    => $flow_id,
			'secret'     => $secret,
			'secret_ids' => self::summarize_secrets( $scheduling_config['webhook_secrets'] ),
			'auth_mode'  => 'hmac',
			'message'    => sprintf( 'HMAC secret "%s" updated for flow %d.', $secret_id, $flow_id ),
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

		unset(
			$scheduling_config['webhook_enabled'],
			$scheduling_config['webhook_token'],
			$scheduling_config['webhook_created_at'],
			$scheduling_config['webhook_auth_mode'],
			$scheduling_config['webhook_auth'],
			$scheduling_config['webhook_secrets']
		);

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
				'error'   => sprintf(
					'regenerate only applies to bearer flows (flow %d is %s). Use rotate / set-secret for HMAC flows.',
					$flow_id,
					$auth_mode
				),
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

		$enabled = ! empty( $scheduling_config['webhook_enabled'] );
		$result  = array(
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

			if ( 'bearer' !== $auth_mode ) {
				// Surface the template so a flow owner can see exactly what's configured,
				// but never the secrets. The template isn't sensitive; secrets are.
				if ( ! empty( $scheduling_config['webhook_auth'] ) ) {
					$result['template'] = $scheduling_config['webhook_auth'];
				}
				$result['secret_ids'] = self::summarize_secrets( $scheduling_config['webhook_secrets'] ?? array() );
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
	 * @return string 64-character hex secret.
	 */
	public static function generate_secret(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Get the webhook trigger URL for a flow.
	 *
	 * @param int $flow_id
	 * @return string
	 */
	public static function get_webhook_url( int $flow_id ): string {
		return rest_url( "datamachine/v1/trigger/{$flow_id}" );
	}

	/**
	 * Zero-downtime secret rotation.
	 *
	 * Demotes `current` → `previous` (keeps verifying for --previous-ttl-seconds),
	 * installs a fresh `current`. Use before updating the upstream provider;
	 * then `forget previous` once the upstream swap is confirmed.
	 *
	 * @param array $input flow_id, optional secret|generate|previous_ttl_seconds.
	 * @return array
	 */
	public function executeRotateSecret( array $input ): array {
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

		if ( empty( $scheduling_config['webhook_auth'] ) ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					'Flow %d has no HMAC template yet. Run `enable --preset=<name>` first.',
					$flow_id
				),
			);
		}

		$explicit = isset( $input['secret'] ) ? (string) $input['secret'] : '';
		$generate = ! empty( $input['generate'] );
		if ( '' === $explicit && ! $generate ) {
			return array(
				'success' => false,
				'error'   => 'Provide either secret=<value> or generate=true.',
			);
		}

		$ttl = isset( $input['previous_ttl_seconds'] ) ? (int) $input['previous_ttl_seconds'] : WEEK_IN_SECONDS;
		if ( $ttl < 0 ) {
			$ttl = 0;
		}
		$now        = time();
		$expires_at = gmdate( 'Y-m-d\TH:i:s\Z', $now + $ttl );

		$new_secret = '' !== $explicit ? $explicit : self::generate_secret();
		$secrets    = $scheduling_config['webhook_secrets'] ?? array();
		if ( ! is_array( $secrets ) ) {
			$secrets = array();
		}

		$demoted = array();
		foreach ( $secrets as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( 'current' === ( $entry['id'] ?? '' ) ) {
				$entry['id']         = 'previous';
				$entry['expires_at'] = $expires_at;
			} elseif ( 'previous' === ( $entry['id'] ?? '' ) && empty( $entry['expires_at'] ) ) {
				$entry['expires_at'] = $expires_at;
			}
			$demoted[] = $entry;
		}
		$demoted[] = array(
			'id'    => 'current',
			'value' => $new_secret,
		);

		$scheduling_config['webhook_secrets']   = $demoted;
		$scheduling_config['webhook_auth_mode'] = 'hmac';
		$scheduling_config['webhook_enabled']   = true;
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
			'Webhook HMAC secret rotated',
			array(
				'flow_id'    => $flow_id,
				'expires_at' => $expires_at,
			)
		);

		return array(
			'success'             => true,
			'flow_id'             => $flow_id,
			'new_secret'          => $new_secret,
			'previous_expires_at' => $expires_at,
			'secret_ids'          => self::summarize_secrets( $demoted ),
			'message'             => sprintf(
				'HMAC secret rotated for flow %d. Previous secret valid until %s.',
				$flow_id,
				$expires_at
			),
		);
	}

	/**
	 * Immediately forget a specific secret by id.
	 *
	 * @param array $input flow_id, secret_id.
	 * @return array
	 */
	public function executeForgetSecret( array $input ): array {
		$flow_id   = (int) ( $input['flow_id'] ?? 0 );
		$secret_id = isset( $input['secret_id'] ) ? (string) $input['secret_id'] : '';

		if ( $flow_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'flow_id must be a positive integer',
			);
		}
		if ( '' === $secret_id ) {
			return array(
				'success' => false,
				'error'   => 'secret_id is required',
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

		$secrets = $scheduling_config['webhook_secrets'] ?? array();
		if ( ! is_array( $secrets ) ) {
			$secrets = array();
		}

		$filtered = array();
		$found    = false;
		foreach ( $secrets as $entry ) {
			if ( is_array( $entry ) && ( $entry['id'] ?? '' ) === $secret_id ) {
				$found = true;
				continue;
			}
			$filtered[] = $entry;
		}

		if ( ! $found ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'No secret with id "%s" on flow %d.', $secret_id, $flow_id ),
			);
		}

		$scheduling_config['webhook_secrets'] = $filtered;

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
			'Webhook HMAC secret forgotten',
			array(
				'flow_id'   => $flow_id,
				'secret_id' => $secret_id,
			)
		);

		return array(
			'success'    => true,
			'flow_id'    => $flow_id,
			'secret_ids' => self::summarize_secrets( $filtered ),
			'message'    => sprintf( 'Secret "%s" removed from flow %d.', $secret_id, $flow_id ),
		);
	}

	/**
	 * Insert or replace a secret entry in the rotation list.
	 *
	 * @param array  $existing
	 * @param string $id
	 * @param string $value
	 * @return array
	 */
	public static function upsert_secret( array $existing, string $id, string $value ): array {
		$replaced = false;
		$out      = array();
		foreach ( $existing as $entry ) {
			if ( is_array( $entry ) && ( $entry['id'] ?? '' ) === $id ) {
				$out[]    = array(
					'id'    => $id,
					'value' => $value,
				);
				$replaced = true;
				continue;
			}
			$out[] = $entry;
		}
		if ( ! $replaced ) {
			$out[] = array(
				'id'    => $id,
				'value' => $value,
			);
		}
		return $out;
	}

	/**
	 * Summarise a secrets list for a response: ids + expiry only, never values.
	 *
	 * @param mixed $secrets
	 * @return array<int,array{id:string,expires_at:?string}>
	 */
	public static function summarize_secrets( $secrets ): array {
		$out = array();
		if ( ! is_array( $secrets ) ) {
			return $out;
		}
		foreach ( $secrets as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['value'] ) ) {
				$out[] = array(
					'id'         => (string) ( $entry['id'] ?? '' ),
					'expires_at' => isset( $entry['expires_at'] ) ? (string) $entry['expires_at'] : null,
				);
			}
		}
		return $out;
	}
}
