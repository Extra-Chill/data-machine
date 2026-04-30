<?php
/**
 * Providers REST API Endpoint
 *
 * Exposes AI provider metadata for frontend discovery.
 * Enables dynamic provider/model selection in AI configuration.
 *
 * @package DataMachine\Api
 * @since 0.1.2
 */

namespace DataMachine\Api;

use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\WpAiClientProviderAdmin;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Providers API Handler
 *
 * Provides REST endpoint for AI provider discovery and metadata.
 */
class Providers {

	/**
	 * Register the API endpoint.
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register REST API routes
	 *
	 * @since 0.1.2
	 */
	public static function register_routes() {
		register_rest_route(
			'datamachine/v1',
			'/providers',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'handle_get_providers' ),
				'permission_callback' => '__return_true', // Public endpoint
				'args'                => array(),
			)
		);
	}

	/**
	 * Get all registered AI providers
	 *
	 * Returns provider metadata including labels and available models.
	 *
	 * @since 0.1.2
	 * @return \WP_REST_Response Providers response
	 */
	public static function handle_get_providers() {
		try {
			$providers = WpAiClientProviderAdmin::getProviders();

			// Get default settings
			$defaults = array(
				'provider' => PluginSettings::get( 'default_provider', '' ),
				'model'    => PluginSettings::get( 'default_model', '' ),
			);

			$modes       = PluginSettings::getAgentModes();
			$mode_models = PluginSettings::get( 'mode_models', array() );

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array(
						'providers'   => $providers,
						'defaults'    => $defaults,
						'modes'       => $modes,
						'mode_models' => $mode_models,
					),
				)
			);
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'providers_api_error',
				__( 'Failed to communicate with wp-ai-client.', 'data-machine' ),
				array( 'status' => 500 )
			);
		}
	}
}
