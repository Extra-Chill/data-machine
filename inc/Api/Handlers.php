<?php
/**
 * Handlers REST API Endpoint
 *
 * Exposes registered handlers via REST API for frontend discovery.
 * Enables dynamic UI rendering based on available handlers per step type.
 *
 * @package DataMachine\Api
 * @since 0.1.2
 */

namespace DataMachine\Api;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Abilities\HandlerAbilities;
use DataMachine\Abilities\StepTypeAbilities;
use WP_REST_Server;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handlers API Handler
 *
 * Provides REST endpoint for handler discovery and metadata.
 */
class Handlers {

	/**
	 * Register REST API routes
	 *
	 * @since 0.1.2
	 */
	public static function register_routes() {
		// List all handlers (basic info)
		register_rest_route(
			'datamachine/v1',
			'/handlers',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'handle_get_handlers' ),
				'permission_callback' => '__return_true', // Public endpoint - handler info is not sensitive
				'args'                => array(
					'step_type' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => function ( $param ) {
							// Allow empty param for no filtering
							if ( empty( $param ) ) {
								return true;
							}
							return ( new StepTypeAbilities() )->stepTypeExists( $param );
						},
						'description'       => __( 'Filter handlers by step type (supports custom step types)', 'data-machine' ),
					),
				),
			)
		);

		// Get complete handler details including settings schema and AI tool definition
		register_rest_route(
			'datamachine/v1',
			'/handlers/(?P<handler_slug>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'handle_get_handler_detail' ),
				'permission_callback' => '__return_true', // Public endpoint
				'args'                => array(
					'handler_slug' => array(
						'required'    => true,
						'type'        => 'string',
						'description' => __( 'Handler slug (e.g., twitter, rss, wordpress_publish)', 'data-machine' ),
					),
				),
			)
		);
	}

	/**
	 * Get all registered handlers
	 *
	 * Returns handler metadata including types, labels, descriptions, and auth requirements.
	 * Optionally filter by step type using the step_type query parameter.
	 *
	 * @since 0.1.2
	 * @param WP_REST_Request $request Request object
	 * @return \WP_REST_Response Handlers response
	 */
	public static function handle_get_handlers( $request ) {
		// Get optional step_type filter
		$step_type = $request->get_param( 'step_type' );

		// Get handlers via abilities
		// If step_type provided, returns only handlers for that type
		// If null, returns all handlers across all types
		$handlers = ( new HandlerAbilities() )->getAllHandlers( $step_type );

		// Get auth providers via cached abilities
		$auth_abilities = new AuthAbilities();

		// Enrich handler data with auth_type, auth_fields, and authentication status
		foreach ( $handlers as $slug => &$handler ) {
			$auth_key      = $handler['auth_provider_key'] ?? $slug;
			$auth_instance = $auth_abilities->getProvider( $auth_key );
			if ( $handler['requires_auth'] && $auth_instance ) {
				$auth_type            = self::detect_auth_type( $auth_instance );
				$handler['auth_type'] = $auth_type;

				// Add auth fields if available (regardless of auth type)
				if ( method_exists( $auth_instance, 'get_config_fields' ) ) {
					$handler['auth_fields'] = $auth_instance->get_config_fields();
				}

				// Add callback URL for OAuth providers (user must configure this externally)
				if ( ( 'oauth1' === $auth_type || 'oauth2' === $auth_type ) && method_exists( $auth_instance, 'get_callback_url' ) ) {
					$handler['callback_url'] = $auth_instance->get_callback_url();
				}

				// Check if already authenticated
				$handler['is_authenticated'] = false;
				if ( method_exists( $auth_instance, 'is_authenticated' ) ) {
					$handler['is_authenticated'] = $auth_instance->is_authenticated();
				}

				// Get account details if authenticated
				if ( $handler['is_authenticated'] && method_exists( $auth_instance, 'get_account_details' ) ) {
					$handler['account_details'] = $auth_instance->get_account_details();
				}
			}
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $handlers,
			)
		);
	}

	/**
	 * Detect authentication type from auth class instance.
	 *
	 * @param object $auth_instance Auth provider instance.
	 * @return string Auth type: 'oauth2', 'oauth1', or 'simple'.
	 */
	private static function detect_auth_type( $auth_instance ): string {
		if ( $auth_instance instanceof \DataMachine\Core\OAuth\BaseOAuth2Provider ) {
			return 'oauth2';
		}
		if ( $auth_instance instanceof \DataMachine\Core\OAuth\BaseOAuth1Provider ) {
			return 'oauth1';
		}

		// Default to simple auth for any other provider type (API Key, Basic Auth, etc.)
		return 'simple';
	}

	/**
	 * Get complete handler details
	 *
	 * Returns comprehensive handler information including:
	 * - Basic info (type, label, description, requires_auth)
	 * - Settings schema (field definitions for configuration forms)
	 * - AI tool definition (parameters for AI integration)
	 *
	 * @since 0.1.2
	 * @param WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error Handler details or error
	 */
	public static function handle_get_handler_detail( $request ) {
		$handler_slug = $request->get_param( 'handler_slug' );

		// Get handler info via abilities
		$handler_abilities = new HandlerAbilities();
		$handler_info      = $handler_abilities->getHandler( $handler_slug );

		// Fall back to step type settings if not a handler.
		// Step types like agent_ping, webhook_gate register their own settings
		// via datamachine_handler_settings but are not in the handlers list.
		if ( ! $handler_info ) {
			$settings_display_service = new \DataMachine\Core\Steps\Settings\SettingsDisplayService();
			$field_state              = $settings_display_service->getFieldState( $handler_slug, [] );

			if ( ! empty( $field_state ) ) {
				// Resolve label from step types registry.
				$step_types = apply_filters( 'datamachine_step_types', [] );
				$step_label = $step_types[ $handler_slug ]['label'] ?? $handler_slug;

				return rest_ensure_response(
					[
						'success' => true,
						'data'    => [
							'slug'     => $handler_slug,
							'info'     => [
								'label'       => $step_label,
								'description' => $step_types[ $handler_slug ]['description'] ?? '',
								'type'        => 'step_type',
							],
							'settings' => $field_state,
							'ai_tool'  => null,
						],
					]
				);
			}

			return new \WP_Error(
				'handler_not_found',
				__( 'Handler not found', 'data-machine' ),
				[ 'status' => 404 ]
			);
		}

		// Get site-wide handler defaults for this handler
		$site_defaults    = $handler_abilities->getSiteDefaults();
		$handler_defaults = $site_defaults[ $handler_slug ] ?? [];

		// Get field state, using site-wide defaults as base settings
		$settings_display_service = new \DataMachine\Core\Steps\Settings\SettingsDisplayService();
		$field_state              = $settings_display_service->getFieldState( $handler_slug, $handler_defaults );

		// Get AI tool definition
		$ai_tool = null;
		$tools   = apply_filters( 'chubes_ai_tools', array(), $handler_slug, array() );

		// Find the tool associated with this handler
		foreach ( $tools as $tool_name => $tool_def ) {
			if ( ( $tool_def['handler'] ?? '' ) === $handler_slug ) {
				$ai_tool = array(
					'tool_name'   => $tool_name,
					'description' => $tool_def['description'] ?? '',
					'parameters'  => $tool_def['parameters'] ?? array(),
				);
				break;
			}
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'slug'     => $handler_slug,
					'info'     => $handler_info,
					'settings' => $field_state,
					'ai_tool'  => $ai_tool,
				),
			)
		);
	}
}

// Register routes on WordPress REST API initialization
add_action( 'rest_api_init', array( Handlers::class, 'register_routes' ) );
