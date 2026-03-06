<?php
/**
 * REST API Settings Endpoint
 *
 * Provides REST API access to settings operations.
 * Delegates to SettingsAbilities for core logic.
 * Requires WordPress manage_options capability.
 *
 * @package DataMachine\Api
 */

namespace DataMachine\Api;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Abilities\SettingsAbilities;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Settings {

	private static ?SettingsAbilities $abilities = null;

	private static function getAbilities(): SettingsAbilities {
		if ( null === self::$abilities ) {
			self::$abilities = new SettingsAbilities();
		}
		return self::$abilities;
	}

	/**
	 * Register REST API routes
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register /datamachine/v1/settings endpoints
	 */
	public static function register_routes() {
		// Get all settings
		register_rest_route(
			'datamachine/v1',
			'/settings',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'handle_get_settings' ),
				'permission_callback' => array( self::class, 'check_permission' ),
			)
		);

		// Update settings (partial update)
		register_rest_route(
			'datamachine/v1',
			'/settings',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( self::class, 'handle_update_settings' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'ai_settings' => array(
						'type'        => 'object',
						'description' => __( 'AI-specific settings', 'data-machine' ),
					),
				),
			)
		);

		// Scheduling intervals endpoint
		register_rest_route(
			'datamachine/v1',
			'/settings/scheduling-intervals',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'handle_get_scheduling_intervals' ),
				'permission_callback' => array( self::class, 'check_permission' ),
			)
		);

		// Tool configuration endpoints
		register_rest_route(
			'datamachine/v1',
			'/settings/tools/(?P<tool_id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'handle_get_tool_config' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'tool_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Tool identifier', 'data-machine' ),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/settings/tools/(?P<tool_id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_save_tool_config' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'tool_id'     => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Tool identifier', 'data-machine' ),
					),
					'config_data' => array(
						'required'    => true,
						'type'        => 'object',
						'description' => __( 'Tool configuration data', 'data-machine' ),
					),
				),
			)
		);

		// Handler defaults endpoints
		register_rest_route(
			'datamachine/v1',
			'/settings/handler-defaults',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'handle_get_handler_defaults' ),
				'permission_callback' => array( self::class, 'check_permission' ),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/settings/generate-ping-secret',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_generate_ping_secret' ),
				'permission_callback' => array( self::class, 'check_permission' ),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/settings/handler-defaults/(?P<handler_slug>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => 'PUT',
				'callback'            => array( self::class, 'handle_update_handler_defaults' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'handler_slug' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'description'       => __( 'Handler slug', 'data-machine' ),
					),
					'defaults'     => array(
						'required'    => true,
						'type'        => 'object',
						'description' => __( 'Default configuration values', 'data-machine' ),
					),
				),
			)
		);
	}

	/**
	 * Handle chat ping secret generation/regeneration.
	 *
	 * @since 0.24.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response with new secret.
	 */
	public static function handle_generate_ping_secret( $request ) {
		$secret   = wp_generate_password( 32, false );
		$settings = get_option( 'datamachine_settings', array() );

		$settings['chat_ping_secret'] = $secret;
		update_option( 'datamachine_settings', $settings );

		\DataMachine\Core\PluginSettings::clearCache();

		return rest_ensure_response(
			array(
				'success' => true,
				'secret'  => $secret,
			)
		);
	}

	/**
	 * Check if user has permission to manage settings
	 */
	public static function check_permission( $request ) {
		if ( ! PermissionHelper::can( 'manage_settings' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage settings.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle get tool configuration request
	 */
	public static function handle_get_tool_config( $request ) {
		$tool_id = $request->get_param( 'tool_id' );

		$result = self::getAbilities()->executeGetToolConfig(
			array( 'tool_id' => $tool_id )
		);

		if ( ! $result['success'] ) {
			$status = 400;
			if ( false !== strpos( $result['error'] ?? '', 'Unknown tool' ) ) {
				$status = 404;
			}

			return new \WP_Error(
				'get_tool_config_error',
				$result['error'],
				array( 'status' => $status )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'tool_id'                => $result['tool_id'],
					'label'                  => $result['label'],
					'description'            => $result['description'],
					'requires_configuration' => $result['requires_configuration'],
					'is_configured'          => $result['is_configured'],
					'fields'                 => $result['fields'],
					'config'                 => $result['config'],
				),
			)
		);
	}

	/**
	 * Handle tool configuration save request
	 */
	public static function handle_save_tool_config( $request ) {
		$tool_id     = $request->get_param( 'tool_id' );
		$config_data = $request->get_param( 'config_data' );

		$ability = wp_get_ability( 'datamachine/save-tool-config' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability not found', array( 'status' => 500 ) );
		}

		$result = $ability->execute(
			array(
				'tool_id'     => $tool_id,
				'config_data' => $config_data,
			)
		);

		if ( ! $result['success'] ) {
			$status = 400;
			if ( false !== strpos( $result['error'] ?? '', 'Unknown tool' ) ) {
				$status = 404;
			} elseif ( false !== strpos( $result['error'] ?? '', 'No configuration handler' ) ) {
				$status = 500;
			}

			return new \WP_Error(
				'save_tool_config_error',
				$result['error'] ?? __( 'Failed to save tool configuration', 'data-machine' ),
				array( 'status' => $status )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'tool_id' => $result['tool_id'] ?? $tool_id,
					'message' => $result['message'] ?? __( 'Configuration saved', 'data-machine' ),
				),
			)
		);
	}

	/**
	 * Handle get settings request
	 *
	 * Returns all settings needed for the React settings page.
	 *
	 * @param \WP_REST_Request $request
	 * @return WP_REST_Response|\WP_Error Settings data or error
	 */
	public static function handle_get_settings( $request ) {
		$result = self::getAbilities()->executeGetSettings( array() );

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'get_settings_error',
				$result['error'] ?? __( 'Failed to get settings', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'settings'     => $result['settings'],
					'global_tools' => $result['global_tools'],
				),
			)
		);
	}

	/**
	 * Handle update settings request (partial update)
	 *
	 * Accepts any settings fields and merges them with existing settings.
	 *
	 * @param \WP_REST_Request $request
	 * @return WP_REST_Response|\WP_Error Updated settings or error
	 */
	public static function handle_update_settings( $request ) {
		$params = $request->get_json_params();

		$result = self::getAbilities()->executeUpdateSettings( $params );

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'update_settings_error',
				$result['error'] ?? __( 'Failed to update settings', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => $result['message'],
			)
		);
	}

	/**
	 * Handle get scheduling intervals request
	 */
	public static function handle_get_scheduling_intervals( $request ) {
		$result = self::getAbilities()->executeGetSchedulingIntervals( array() );

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'get_intervals_error',
				$result['error'] ?? __( 'Failed to get scheduling intervals', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result['intervals'],
			)
		);
	}

	/**
	 * Get all handler defaults grouped by step type.
	 *
	 * Auto-populates from schema defaults on first access.
	 *
	 * @return \WP_REST_Response Handler defaults response
	 */
	public static function handle_get_handler_defaults() {
		$result = self::getAbilities()->executeGetHandlerDefaults( array() );

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'get_handler_defaults_error',
				$result['error'] ?? __( 'Failed to get handler defaults', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result['defaults'],
			)
		);
	}

	/**
	 * Update defaults for a specific handler.
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error Update response
	 */
	public static function handle_update_handler_defaults( $request ) {
		$handler_slug = $request->get_param( 'handler_slug' );
		$new_defaults = $request->get_param( 'defaults' );

		$result = self::getAbilities()->executeUpdateHandlerDefaults(
			array(
				'handler_slug' => $handler_slug,
				'defaults'     => $new_defaults,
			)
		);

		if ( ! $result['success'] ) {
			$status = 400;
			if ( false !== strpos( $result['error'] ?? '', 'not found' ) ) {
				$status = 404;
			} elseif ( false !== strpos( $result['error'] ?? '', 'Failed to update' ) ) {
				$status = 500;
			}

			return new \WP_Error(
				'update_handler_defaults_error',
				$result['error'],
				array( 'status' => $status )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'handler_slug' => $result['handler_slug'],
					'defaults'     => $result['defaults'],
					'message'      => $result['message'],
				),
			)
		);
	}
}
