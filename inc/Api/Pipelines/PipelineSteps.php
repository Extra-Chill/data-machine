<?php
/**
 * REST API Pipeline Steps Endpoint
 *
 * Provides REST API access to pipeline step management operations.
 * Delegates to Abilities API for core CRUD operations.
 * Requires WordPress manage_options capability.
 *
 * @package DataMachine\Api\Pipelines
 */

namespace DataMachine\Api\Pipelines;

use DataMachine\Abilities\PipelineStepAbilities;
use DataMachine\Abilities\StepTypeAbilities;
use WP_REST_Server;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class PipelineSteps {

	/**
	 * Register REST API routes
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register pipeline step management endpoints
	 */
	public static function register_routes() {
		register_rest_route(
			'datamachine/v1',
			'/pipelines/(?P<pipeline_id>\d+)/steps',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_create_step' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'pipeline_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Pipeline ID to add step to', 'data-machine' ),
					),
					'step_type'   => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => function ( $param ) {
							return ( new StepTypeAbilities() )->stepTypeExists( $param );
						},
						'description'       => __( 'Step type (supports custom step types)', 'data-machine' ),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/pipelines/(?P<pipeline_id>\d+)/steps/(?P<step_id>[A-Za-z0-9\-_]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( self::class, 'handle_delete_pipeline_step' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'pipeline_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Pipeline ID containing the step', 'data-machine' ),
						),
						'step_id'     => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => __( 'Pipeline step ID to delete', 'data-machine' ),
						),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/pipelines/(?P<pipeline_id>\d+)/steps/reorder',
			array(
				'methods'             => 'PUT',
				'callback'            => array( self::class, 'handle_reorder_steps' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'pipeline_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Pipeline ID to reorder steps for', 'data-machine' ),
					),
					'step_order'  => array(
						'required'          => true,
						'type'              => 'array',
						'description'       => __( 'Array of step IDs in new execution order', 'data-machine' ),
						'validate_callback' => array( self::class, 'validate_step_order' ),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/pipelines/steps/(?P<pipeline_step_id>[A-Za-z0-9_\-]+)/system-prompt',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( self::class, 'handle_update_system_prompt' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'pipeline_step_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Pipeline step ID (UUID4)', 'data-machine' ),
					),
					'system_prompt'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
						'description'       => __( 'System prompt for AI step', 'data-machine' ),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/pipelines/steps/(?P<pipeline_step_id>[A-Za-z0-9_\-]+)/config',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( self::class, 'handle_update_step_config' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => self::get_step_config_args( false ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( self::class, 'handle_update_step_config' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => self::get_step_config_args( true ),
				),
			)
		);
	}

	/**
	 * Shared REST arg definition for AI step configuration.
	 */
	private static function get_step_config_args( bool $is_patch = false ): array {
		return array(
			'pipeline_step_id' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __( 'Pipeline step ID (UUID4)', 'data-machine' ),
			),
			'step_type'        => array(
				'required'          => ! $is_patch,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __( 'Step type (ai)', 'data-machine' ),
			),
			'pipeline_id'      => array(
				'required'          => ! $is_patch,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'description'       => __( 'Pipeline ID for context', 'data-machine' ),
			),
			'provider'         => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __( 'AI provider slug', 'data-machine' ),
			),
			'model'            => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __( 'AI model identifier', 'data-machine' ),
			),
			'ai_api_key'       => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __( 'AI API key', 'data-machine' ),
			),
			'enabled_tools'    => array(
				'required'    => false,
				'type'        => 'array',
				'description' => __( 'Array of enabled tool IDs', 'data-machine' ),
			),
			'system_prompt'    => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'description'       => __( 'System prompt for AI processing', 'data-machine' ),
			),
		);
	}

	/**
	 * Check if user has permission to manage pipeline steps
	 */
	public static function check_permission( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage pipeline steps.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle pipeline step creation request
	 */
	public static function handle_create_step( $request ) {
		$pipeline_id = (int) $request->get_param( 'pipeline_id' );
		$step_type   = $request->get_param( 'step_type' );

		$abilities = new PipelineStepAbilities();
		$result    = $abilities->executeAddPipelineStep(
			array(
				'pipeline_id' => $pipeline_id,
				'step_type'   => $step_type,
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'step_creation_failed',
				$result['error'] ?? __( 'Failed to create step.', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		$step_config    = ( new StepTypeAbilities() )->getStepType( $step_type ) ?? array();
		$db_pipelines   = new \DataMachine\Core\Database\Pipelines\Pipelines();
		$pipeline_steps = $db_pipelines->get_pipeline_config( $pipeline_id );

		$step_data = null;
		foreach ( $pipeline_steps as $step ) {
			if ( $step['pipeline_step_id'] === $result['pipeline_step_id'] ) {
				$step_data = $step;
				break;
			}
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'step_type'        => $step_type,
					'step_config'      => $step_config,
					'pipeline_id'      => $pipeline_id,
					'pipeline_step_id' => $result['pipeline_step_id'],
					'step_data'        => $step_data,
					'created_type'     => 'step',
				),
				'message' => sprintf(
					/* translators: %s: pipeline step label */
					esc_html__( 'Step "%s" added successfully', 'data-machine' ),
					$step_config['label'] ?? $step_type
				),
			)
		);
	}

	/**
	 * Handle pipeline step deletion request
	 */
	public static function handle_delete_pipeline_step( $request ) {
		$pipeline_id = (int) $request->get_param( 'pipeline_id' );
		$step_id     = (string) $request->get_param( 'step_id' );

		$abilities = new PipelineStepAbilities();
		$result    = $abilities->executeDeletePipelineStep(
			array(
				'pipeline_id'      => $pipeline_id,
				'pipeline_step_id' => $step_id,
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'step_deletion_failed',
				$result['error'] ?? __( 'Failed to delete step.', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	}

	/**
	 * Validate step_order parameter structure
	 */
	public static function validate_step_order( $param, $request, $key ) {
		if ( ! is_array( $param ) || empty( $param ) ) {
			return new \WP_Error(
				'invalid_step_order',
				__( 'Step order must be a non-empty array', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		foreach ( $param as $item ) {
			if ( ! is_array( $item ) ) {
				return new \WP_Error(
					'invalid_step_order_item',
					__( 'Each step order item must be an object', 'data-machine' ),
					array( 'status' => 400 )
				);
			}

			if ( ! isset( $item['pipeline_step_id'] ) || ! isset( $item['execution_order'] ) ) {
				return new \WP_Error(
					'invalid_step_order_structure',
					__( 'Each step order item must have pipeline_step_id and execution_order', 'data-machine' ),
					array( 'status' => 400 )
				);
			}

			if ( ! is_string( $item['pipeline_step_id'] ) || ! is_numeric( $item['execution_order'] ) ) {
				return new \WP_Error(
					'invalid_step_order_types',
					__( 'pipeline_step_id must be string and execution_order must be numeric', 'data-machine' ),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}

	/**
	 * Handle pipeline step reordering request
	 */
	public static function handle_reorder_steps( $request ) {
		$pipeline_id = (int) $request->get_param( 'pipeline_id' );
		$step_order  = $request->get_param( 'step_order' );

		$abilities = new PipelineStepAbilities();
		$result    = $abilities->executeReorderPipelineSteps(
			array(
				'pipeline_id' => $pipeline_id,
				'step_order'  => $step_order,
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'reorder_failed',
				$result['error'] ?? __( 'Failed to reorder steps.', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
				'message' => __( 'Step order saved successfully', 'data-machine' ),
			)
		);
	}

	/**
	 * Handle system prompt update for AI pipeline steps
	 *
	 * PATCH /datamachine/v1/pipelines/steps/{pipeline_step_id}/system-prompt
	 */
	public static function handle_update_system_prompt( $request ) {
		$pipeline_step_id = sanitize_text_field( $request->get_param( 'pipeline_step_id' ) );
		$system_prompt    = sanitize_textarea_field( $request->get_param( 'system_prompt' ) );

		$abilities = new PipelineStepAbilities();
		$result    = $abilities->executeUpdatePipelineStep(
			array(
				'pipeline_step_id' => $pipeline_step_id,
				'system_prompt'    => $system_prompt,
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'update_failed',
				__( 'Failed to save system prompt', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(),
				'message' => __( 'System prompt saved successfully', 'data-machine' ),
			)
		);
	}

	/**
	 * Handle AI step configuration update
	 *
	 * PUT /datamachine/v1/pipelines/steps/{pipeline_step_id}/config
	 */
	public static function handle_update_step_config( $request ) {
		// Validate permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Insufficient permissions.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		// Extract pipeline_step_id from URL parameter
		$pipeline_step_id = sanitize_text_field( $request->get_param( 'pipeline_step_id' ) );
		$is_patch         = strtoupper( $request->get_method() ) === 'PATCH';

		$db_pipelines = new \DataMachine\Core\Database\Pipelines\Pipelines();

		// Validate pipeline_step_id format
		$parsed_step_id = apply_filters( 'datamachine_split_pipeline_step_id', null, $pipeline_step_id );
		if ( null === $parsed_step_id ) {
			return new \WP_Error(
				'invalid_pipeline_step_id',
				__( 'Pipeline step ID format invalid - expected {pipeline_id}_{uuid4}', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		$pipeline_id = (int) $request->get_param( 'pipeline_id' );
		if ( empty( $pipeline_id ) && ! empty( $parsed_step_id['pipeline_id'] ) ) {
			$pipeline_id = (int) $parsed_step_id['pipeline_id'];
		}

		if ( empty( $pipeline_id ) ) {
			return new \WP_Error(
				'missing_pipeline_id',
				__( 'Pipeline ID is required for configuration updates.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		$step_type = sanitize_text_field( $request->get_param( 'step_type' ) );
		if ( empty( $step_type ) ) {
			$step_type = 'ai';
		}

		// Validate step type supports configuration
		$configurable_step_types = array( 'ai' );
		if ( ! in_array( $step_type, $configurable_step_types, true ) ) {
			return new \WP_Error(
				'invalid_step_type',
				__( 'This step type does not support configuration updates.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		$pipeline = $db_pipelines->get_pipeline( $pipeline_id );
		if ( ! $pipeline ) {
			return new \WP_Error(
				'pipeline_not_found',
				__( 'Pipeline not found', 'data-machine' ),
				array( 'status' => 404 )
			);
		}

		$pipeline_config = $pipeline['pipeline_config'] ?? array();
		$existing_config = $pipeline_config[ $pipeline_step_id ] ?? array();

		// Build step configuration data for AI steps
		$step_config_data = array();
		$api_key_saved    = false;

		{
			// Handle AI step configuration
			$has_provider      = $request->has_param( 'provider' );
			$has_model         = $request->has_param( 'model' );
			$has_system_prompt = $request->has_param( 'system_prompt' );
			$has_enabled_tools = $request->has_param( 'enabled_tools' );
			$has_api_key       = $request->has_param( 'ai_api_key' );

			$effective_provider = $has_provider
				? sanitize_text_field( $request->get_param( 'provider' ) )
				: ( $existing_config['provider'] ?? '' );
			$effective_model    = $has_model
				? sanitize_text_field( $request->get_param( 'model' ) )
				: ( $existing_config['model'] ?? '' );
			$system_prompt      = $has_system_prompt
				? sanitize_textarea_field( $request->get_param( 'system_prompt' ) )
				: null;

			if ( $has_provider ) {
				$step_config_data['provider'] = $effective_provider;
			}

			if ( $has_model ) {
				$step_config_data['model'] = $effective_model;

				$provider_for_model = $has_provider ? $effective_provider : ( $existing_config['provider'] ?? '' );

				if ( ! empty( $provider_for_model ) && ! empty( $effective_model ) ) {
					if ( ! isset( $step_config_data['providers'] ) ) {
						$step_config_data['providers'] = array();
					}
					if ( ! isset( $step_config_data['providers'][ $provider_for_model ] ) ) {
						$step_config_data['providers'][ $provider_for_model ] = array();
					}
					$step_config_data['providers'][ $provider_for_model ]['model'] = $effective_model;
				}
			}

			if ( $has_system_prompt ) {
				$step_config_data['system_prompt'] = $system_prompt;
			}

			if ( $has_enabled_tools ) {
				$enabled_tools_raw  = $request->get_param( 'enabled_tools' );
				$sanitized_tool_ids = array();
				if ( is_array( $enabled_tools_raw ) ) {
					$sanitized_tool_ids = array_map( 'sanitize_text_field', $enabled_tools_raw );
				}

				$tools_manager                     = new \DataMachine\Engine\AI\Tools\ToolManager();
				$step_config_data['enabled_tools'] = $tools_manager->save_step_tool_selections( $pipeline_step_id, $sanitized_tool_ids );
			}

			if ( empty( $step_config_data ) && ! $has_api_key ) {
				return new \WP_Error(
					'no_config_values',
					__( 'No configuration values were provided.', 'data-machine' ),
					array( 'status' => 400 )
				);
			}
		}

		// Check for API key (AI steps only)
		$has_api_key = $request->has_param( 'ai_api_key' );

		if ( 'ai' === $step_type && empty( $step_config_data ) && ! $has_api_key ) {
			return new \WP_Error(
				'no_config_values',
				__( 'No configuration values were provided.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		// Store API key if provided
		if ( $has_api_key && ! empty( $effective_provider ) ) {
			$ai_api_key = sanitize_text_field( $request->get_param( 'ai_api_key' ) );
			try {
				// Use AI HTTP Client library's filters directly to save API key
				$all_keys                        = apply_filters( 'chubes_ai_provider_api_keys', null );
				$all_keys[ $effective_provider ] = $ai_api_key;
				apply_filters( 'chubes_ai_provider_api_keys', $all_keys );
				$api_key_saved = true;
			} catch ( \Exception $e ) {
			}
		}

		// Preserve provider-specific models by merging with existing config
		if ( ! empty( $existing_config ) ) {
			if ( isset( $existing_config['providers'] ) && isset( $step_config_data['providers'] ) ) {
				$step_config_data['providers'] = array_merge(
					$existing_config['providers'],
					$step_config_data['providers']
				);
			} elseif ( isset( $existing_config['providers'] ) && ! isset( $step_config_data['providers'] ) ) {
				$step_config_data['providers'] = $existing_config['providers'];
			}

			$pipeline_config[ $pipeline_step_id ] = array_merge( $existing_config, $step_config_data );
		} else {
			$pipeline_config[ $pipeline_step_id ] = $step_config_data;
		}

		// Save updated pipeline configuration
		$success = $db_pipelines->update_pipeline(
			$pipeline_id,
			array(
				'pipeline_config' => $pipeline_config,
			)
		);

		if ( ! $success ) {
			return new \WP_Error(
				'save_failed',
				__( 'Error saving AI configuration', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		$provider_for_log = $step_config_data['provider'] ?? ( $existing_config['provider'] ?? null );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'pipeline_step_id' => $pipeline_step_id,
					'debug_info'       => array(
						'api_key_saved'     => $api_key_saved,
						'step_config_saved' => true,
						'provider'          => $provider_for_log,
					),
				),
				'message' => __( 'AI step configuration saved successfully', 'data-machine' ),
			)
		);
	}
}
