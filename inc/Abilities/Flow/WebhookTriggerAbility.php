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

		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

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
					'description'         => __( 'Enable webhook trigger for a flow and generate a Bearer token. External services can POST to the trigger URL to start flow executions.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'flow_id' ),
						'properties' => array(
							'flow_id' => array(
								'type'        => 'integer',
								'description' => __( 'Flow ID to enable webhook trigger for', 'data-machine' ),
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
					'execute_callback'    => array( $this, 'executeEnable' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/webhook-trigger-disable',
				array(
					'label'               => __( 'Disable Webhook Trigger', 'data-machine' ),
					'description'         => __( 'Disable webhook trigger for a flow. Revokes the token and stops accepting inbound webhooks.', 'data-machine' ),
					'category'            => 'datamachine',
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
					'category'            => 'datamachine',
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
					'category'            => 'datamachine',
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
					'category'            => 'datamachine',
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
							'error'           => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeStatus' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Enable webhook trigger for a flow.
	 *
	 * Generates a new token and stores it in scheduling_config.
	 * If already enabled, returns the existing token and URL.
	 *
	 * @param array $input Input with flow_id.
	 * @return array Result with token and webhook URL.
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

		// If already enabled with a valid token, return existing.
		if ( ! empty( $scheduling_config['webhook_enabled'] ) && ! empty( $scheduling_config['webhook_token'] ) ) {
			return array(
				'success'     => true,
				'flow_id'     => $flow_id,
				'webhook_url' => self::get_webhook_url( $flow_id ),
				'token'       => $scheduling_config['webhook_token'],
				'message'     => 'Webhook trigger already enabled.',
			);
		}

		// Generate new token.
		$token = self::generate_token();

		$scheduling_config['webhook_enabled']    = true;
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
			'Webhook trigger enabled for flow',
			array( 'flow_id' => $flow_id )
		);

		return array(
			'success'     => true,
			'flow_id'     => $flow_id,
			'webhook_url' => self::get_webhook_url( $flow_id ),
			'token'       => $token,
			'message'     => sprintf( 'Webhook trigger enabled for flow %d.', $flow_id ),
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
			$result['webhook_url'] = self::get_webhook_url( $flow_id );
			$result['created_at']  = $scheduling_config['webhook_created_at'] ?? '';

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
	 * Get the webhook trigger URL for a flow.
	 *
	 * @param int $flow_id Flow ID.
	 * @return string Full webhook trigger URL.
	 */
	public static function get_webhook_url( int $flow_id ): string {
		return rest_url( "datamachine/v1/trigger/{$flow_id}" );
	}
}
