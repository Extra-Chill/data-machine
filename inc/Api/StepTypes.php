<?php
/**
 * Step Types REST API Endpoint
 *
 * Exposes registered step types via REST API for frontend discovery.
 * Enables dynamic UI rendering based on step type metadata.
 *
 * @package DataMachine\Api
 * @since 0.1.2
 */

namespace DataMachine\Api;

use DataMachine\Abilities\HandlerAbilities;
use DataMachine\Abilities\StepTypeAbilities;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Step Types API Handler
 *
 * Provides REST endpoint for step type discovery and metadata.
 */
class StepTypes {

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
			'/step-types',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'handle_get_step_types' ),
				'permission_callback' => '__return_true', // Public endpoint - step type info is not sensitive
				'args'                => array(),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/step-types/(?P<step_type>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'handle_get_step_type_detail' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'step_type' => array(
						'required'          => true,
						'type'              => 'string',
						'description'       => __( 'Step type slug', 'data-machine' ),
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Get all registered step types
	 *
	 * Returns step type metadata including labels, descriptions, positions,
	 * handler requirements, and handler counts for UI rendering.
	 *
	 * @since 0.1.2
	 * @return \WP_REST_Response Step types response
	 */
	public static function handle_get_step_types() {
		$step_type_abilities = new StepTypeAbilities();
		$handler_abilities   = new HandlerAbilities();

		$step_types    = $step_type_abilities->getAllStepTypes();
		$enriched_data = array();

		foreach ( $step_types as $slug => $config ) {
			$uses_handler  = $config['uses_handler'] ?? true;
			$handler_count = 0;

			if ( $uses_handler ) {
				$handlers      = $handler_abilities->getAllHandlers( $slug );
				$handler_count = count( $handlers );
			}

			$enriched_data[ $slug ] = array_merge(
				$config,
				array(
					'handler_count' => $handler_count,
				)
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $enriched_data,
			)
		);
	}

	/**
	 * Get full metadata for a specific step type
	 *
	 * Exposes the registered step definition along with pipeline-level
	 * configuration metadata (if provided by the step).
	 *
	 * @since 0.1.2
	 * @param \WP_REST_Request $request Request instance
	 * @return \WP_REST_Response|\WP_Error Step type detail response
	 */
	public static function handle_get_step_type_detail( $request ) {
		$step_type = $request->get_param( 'step_type' );

		$step_type_abilities = new StepTypeAbilities();
		$definition          = $step_type_abilities->getStepType( $step_type );

		if ( ! $definition ) {
			return new \WP_Error(
				'step_type_not_found',
				__( 'Step type not found', 'data-machine' ),
				array( 'status' => 404 )
			);
		}

		$step_settings = apply_filters( 'datamachine_step_settings', array() );
		$config        = $step_settings[ $step_type ] ?? null;

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'step_type'  => $step_type,
					'definition' => $definition,
					'config'     => $config,
				),
			)
		);
	}
}
