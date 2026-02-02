<?php
/**
 * REST API Flow Steps Endpoint
 *
 * Provides REST API access to flow step configuration operations.
 * Delegates to FlowStepAbilities for core logic.
 * Requires WordPress manage_options capability.
 *
 * @package DataMachine\Api\Flows
 */

namespace DataMachine\Api\Flows;

use DataMachine\Abilities\FlowStepAbilities;
use DataMachine\Abilities\HandlerAbilities;
use DataMachine\Abilities\StepTypeAbilities;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FlowSteps {

	/**
	 * Register REST API routes
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register flow step configuration endpoints
	 */
	public static function register_routes() {
		register_rest_route(
			'datamachine/v1',
			'/flows/(?P<flow_id>\d+)/config',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_get_flow_config' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'flow_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Flow ID to retrieve configuration for', 'data-machine' ),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/flows/steps/(?P<flow_step_id>[A-Za-z0-9_\-]+)/config',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_get_flow_step_config' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'flow_step_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Flow step ID (composite key: pipeline_step_id_flow_id)', 'data-machine' ),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/flows/steps/(?P<flow_step_id>[A-Za-z0-9_\-]+)/handler',
			array(
				'methods'             => 'PUT',
				'callback'            => array( self::class, 'handle_update_flow_step_handler' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'flow_step_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Flow step ID (composite key: pipeline_step_id_flow_id)', 'data-machine' ),
					),
					'handler_slug' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Handler identifier', 'data-machine' ),
					),
					'pipeline_id'  => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Pipeline ID for context', 'data-machine' ),
					),
					'step_type'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => function ( $param ) {
							return ( new StepTypeAbilities() )->stepTypeExists( $param );
						},
						'description'       => __( 'Step type', 'data-machine' ),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/flows/steps/(?P<flow_step_id>[A-Za-z0-9_\-]+)/user-message',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( self::class, 'handle_update_user_message' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'flow_step_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Flow step ID (composite key: pipeline_step_id_flow_id)', 'data-machine' ),
					),
					'user_message' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
						'description'       => __( 'User message for AI step', 'data-machine' ),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/flows/steps/(?P<flow_step_id>[A-Za-z0-9_\-]+)/config',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( self::class, 'handle_patch_flow_step_config' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'flow_step_id'   => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Flow step ID (composite key: pipeline_step_id_flow_id)', 'data-machine' ),
					),
					'handler_slug'   => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Handler identifier', 'data-machine' ),
					),
					'handler_config' => array(
						'required'    => false,
						'type'        => 'object',
						'description' => __( 'Handler configuration settings to merge', 'data-machine' ),
					),
					'user_message'   => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
						'description'       => __( 'User message for AI step', 'data-machine' ),
					),
				),
			)
		);
	}

	/**
	 * Handle flow step configuration patch
	 *
	 * PATCH /datamachine/v1/flows/steps/{flow_step_id}/config
	 */
	public static function handle_patch_flow_step_config( $request ) {
		$flow_step_id   = sanitize_text_field( $request->get_param( 'flow_step_id' ) );
		$handler_slug   = $request->get_param( 'handler_slug' );
		$handler_config = $request->get_param( 'handler_config' ) ?? array();
		$user_message   = $request->get_param( 'user_message' );

		$abilities = new FlowStepAbilities();
		$input     = array( 'flow_step_id' => $flow_step_id );

		if ( null !== $handler_slug ) {
			$input['handler_slug'] = $handler_slug;
		}

		if ( is_array( $handler_config ) ) {
			$input['handler_config'] = $handler_config;
		}

		if ( null !== $user_message ) {
			$input['user_message'] = $user_message;
		}

		$result = $abilities->executeUpdateFlowStep( $input );

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'update_failed',
				$result['error'] ?? __( 'Failed to update flow step', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => $result['message'] ?? __( 'Flow step updated successfully', 'data-machine' ),
			)
		);
	}

	/**
	 * Check if user has permission to manage flow steps
	 */
	public static function check_permission( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage flow steps.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle flow configuration retrieval request
	 */
	public static function handle_get_flow_config( $request ) {
		$flow_id = (int) $request->get_param( 'flow_id' );

		$abilities = new FlowStepAbilities();
		$result    = $abilities->executeGetFlowSteps( array( 'flow_id' => $flow_id ) );

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'flow_not_found',
				$result['error'] ?? __( 'Flow not found.', 'data-machine' ),
				array( 'status' => 404 )
			);
		}

		$flow_config = array();
		foreach ( $result['steps'] as $step ) {
			$flow_step_id                 = $step['flow_step_id'];
			$flow_config[ $flow_step_id ] = $step;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'flow_id'     => $flow_id,
					'flow_config' => $flow_config,
				),
			)
		);
	}

	/**
	 * Handle flow step configuration retrieval request
	 */
	public static function handle_get_flow_step_config( $request ) {
		$flow_step_id = sanitize_text_field( $request->get_param( 'flow_step_id' ) );

		if ( empty( $flow_step_id ) ) {
			return new \WP_Error(
				'invalid_flow_step_id',
				__( 'Flow step ID is required.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		$abilities = new FlowStepAbilities();
		$result    = $abilities->executeGetFlowSteps( array( 'flow_step_id' => $flow_step_id ) );

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'flow_step_not_found',
				$result['error'] ?? __( 'Flow step configuration not found.', 'data-machine' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'flow_step_id' => $flow_step_id,
					'step_config'  => $result['steps'][0] ?? array(),
				),
			)
		);
	}

	/**
	 * Handle flow step handler settings update
	 *
	 * PUT /datamachine/v1/flows/steps/{flow_step_id}/handler
	 */
	public static function handle_update_flow_step_handler( $request ) {
		$flow_step_id = sanitize_text_field( $request->get_param( 'flow_step_id' ) );
		$handler_slug = sanitize_text_field( $request->get_param( 'handler_slug' ) );
		$step_type    = sanitize_text_field( $request->get_param( 'step_type' ) );

		if ( empty( $handler_slug ) || empty( $flow_step_id ) ) {
			return new \WP_Error(
				'missing_required_fields',
				__( 'Handler slug and flow step ID are required.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		$handler_abilities = new HandlerAbilities();
		$handler_info      = $handler_abilities->getHandler( $handler_slug, $step_type );

		if ( ! $handler_info ) {
			return new \WP_Error(
				'handler_not_found',
				__( 'Handler not found.', 'data-machine' ),
				array( 'status' => 404 )
			);
		}

		$handler_settings = self::process_handler_settings( $handler_slug, $request->get_params() );

		$parts = apply_filters( 'datamachine_split_flow_step_id', null, $flow_step_id );
		if ( ! isset( $parts['flow_id'] ) || ! isset( $parts['pipeline_step_id'] ) ) {
			return new \WP_Error(
				'invalid_flow_step_id',
				__( 'Invalid flow step ID format.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}
		$flow_id          = $parts['flow_id'];
		$pipeline_step_id = $parts['pipeline_step_id'];

		try {
			$abilities = new FlowStepAbilities();
			$result    = $abilities->executeUpdateFlowStep(
				array(
					'flow_step_id'   => $flow_step_id,
					'handler_slug'   => $handler_slug,
					'handler_config' => $handler_settings,
				)
			);

			if ( ! $result['success'] ) {
				return new \WP_Error(
					'update_failed',
					$result['error'] ?? __( 'Failed to update handler for flow step', 'data-machine' ),
					array( 'status' => 500 )
				);
			}

			$step_config = array(
				'step_type'        => $step_type,
				'handler_slug'     => $handler_slug,
				'handler_config'   => $handler_settings,
				'enabled'          => true,
				'flow_id'          => $flow_id,
				'pipeline_step_id' => $pipeline_step_id,
				'flow_step_id'     => $flow_step_id,
			);

			$db_flows      = new \DataMachine\Core\Database\Flows\Flows();
			$flow          = $db_flows->get_flow( $flow_id );
			$flow_config   = $flow['flow_config'] ?? array();
			$existing_step = $flow_config[ $flow_step_id ] ?? array();
			if ( isset( $existing_step['execution_order'] ) ) {
				$step_config['execution_order'] = $existing_step['execution_order'];
			}

			$service                  = new \DataMachine\Core\Steps\Settings\SettingsDisplayService();
			$handler_settings_display = $service->getDisplaySettings( $flow_step_id, $step_type );

			$message = sprintf(
				/* translators: %s: handler label */
				esc_html__( 'Handler "%s" settings saved successfully.', 'data-machine' ),
				$handler_info['label'] ?? $handler_slug
			);

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array(
						'handler_slug'             => $handler_slug,
						'step_type'                => $step_type,
						'flow_step_id'             => $flow_step_id,
						'flow_id'                  => $flow_id,
						'pipeline_step_id'         => $pipeline_step_id,
						'step_config'              => $step_config,
						'handler_settings_display' => $handler_settings_display,
					),
					'message' => $message,
				)
			);
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'handler_update_failed',
				__( 'Failed to save handler settings due to server error.', 'data-machine' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Handle user message update for AI flow steps
	 *
	 * PATCH /datamachine/v1/flows/steps/{flow_step_id}/user-message
	 */
	public static function handle_update_user_message( $request ) {
		$flow_step_id = sanitize_text_field( $request->get_param( 'flow_step_id' ) );
		$user_message = sanitize_textarea_field( $request->get_param( 'user_message' ) );

		$abilities = new FlowStepAbilities();
		$result    = $abilities->executeUpdateFlowStep(
			array(
				'flow_step_id' => $flow_step_id,
				'user_message' => $user_message,
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'update_failed',
				$result['error'] ?? __( 'Failed to update user message.', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(),
				'message' => __( 'User message saved successfully', 'data-machine' ),
			)
		);
	}

	/**
	 * Process handler settings from request parameters
	 *
	 * @param string $handler_slug Handler identifier
	 * @param array  $params Request parameters
	 * @return array Sanitized handler settings
	 */
	private static function process_handler_settings( $handler_slug, $params ) {
		$handler_abilities = new HandlerAbilities();
		$handler_settings  = $handler_abilities->getSettingsClass( $handler_slug );

		if ( ! $handler_settings || ! method_exists( $handler_settings, 'sanitize' ) ) {
			return array();
		}

		$raw_settings = $params['settings'] ?? array();

		if ( ! is_array( $raw_settings ) ) {
			$raw_settings = array();
		}

		try {
			return $handler_settings->sanitize( $raw_settings );
		} catch ( \Exception $e ) {
			return array();
		}
	}
}
