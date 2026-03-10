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
			// Use AI HTTP Client library's filters directly
			$library_providers = apply_filters( 'chubes_ai_providers', array() );

			// Get all API keys for model fetching
			$all_api_keys = apply_filters( 'chubes_ai_provider_api_keys', null );

			$providers = array();
			foreach ( $library_providers as $key => $provider_info ) {
				// Get API key for this provider
				$api_key = $all_api_keys[ $key ] ?? '';

				// Get models for this provider via filter with API key
				$models = apply_filters( 'chubes_ai_models', $key, array( 'api_key' => $api_key ) );

				$providers[ $key ] = array(
					'label'  => $provider_info['name'] ?? ucfirst( $key ),
					'models' => $models,
				);
			}

			// Get default settings
			$defaults = array(
				'provider' => PluginSettings::get( 'default_provider', '' ),
				'model'    => PluginSettings::get( 'default_model', '' ),
			);

			$contexts       = PluginSettings::getContexts();
			$context_models = PluginSettings::get( 'context_models', PluginSettings::get( 'agent_models', array() ) );

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array(
						'providers'    => $providers,
						'defaults'     => $defaults,
						'contexts'     => $contexts,
						'context_models' => $context_models,
					),
				)
			);
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'providers_api_error',
				__( 'Failed to communicate with AI HTTP Client library.', 'data-machine' ),
				array( 'status' => 500 )
			);
		}
	}
}
