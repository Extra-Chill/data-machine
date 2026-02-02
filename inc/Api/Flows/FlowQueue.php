<?php
/**
 * REST API Flow Queue Endpoints
 *
 * Provides REST API access to flow prompt queue operations.
 * Requires WordPress manage_options capability.
 *
 * @package DataMachine\Api\Flows
 */

namespace DataMachine\Api\Flows;

use WP_REST_Server;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class FlowQueue {

	/**
	 * Register REST API routes
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register flow queue endpoints
	 */
	public static function register_routes() {
		// GET /flows/{id}/queue - List queue
		// POST /flows/{id}/queue - Add to queue
		// DELETE /flows/{id}/queue - Clear queue
		register_rest_route(
			'datamachine/v1',
			'/flows/(?P<flow_id>\d+)/queue',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'handle_list_queue' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'flow_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Flow ID', 'data-machine' ),
						),
						'flow_step_id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => __( 'Flow step ID', 'data-machine' ),
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'handle_add_to_queue' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'flow_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Flow ID', 'data-machine' ),
						),
						'flow_step_id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => __( 'Flow step ID', 'data-machine' ),
						),
						'prompt'  => array(
							'required'          => false,
							'type'              => 'string',
							'description'       => __( 'Single prompt to add', 'data-machine' ),
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'prompts' => array(
							'required'    => false,
							'type'        => 'array',
							'description' => __( 'Array of prompts to add', 'data-machine' ),
							'items'       => array(
								'type' => 'string',
							),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( self::class, 'handle_clear_queue' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'flow_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Flow ID', 'data-machine' ),
						),
						'flow_step_id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => __( 'Flow step ID', 'data-machine' ),
						),
					),
				),
			)
		);

		// DELETE /flows/{id}/queue/{index} - Remove specific item
		// PUT /flows/{id}/queue/{index} - Update specific item
		register_rest_route(
			'datamachine/v1',
			'/flows/(?P<flow_id>\d+)/queue/(?P<index>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( self::class, 'handle_remove_from_queue' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'flow_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Flow ID', 'data-machine' ),
						),
						'flow_step_id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => __( 'Flow step ID', 'data-machine' ),
						),
						'index'   => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Queue index (0-based)', 'data-machine' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'handle_update_queue_item' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'flow_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Flow ID', 'data-machine' ),
						),
						'flow_step_id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => __( 'Flow step ID', 'data-machine' ),
						),
						'index'   => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Queue index (0-based)', 'data-machine' ),
						),
						'prompt'  => array(
							'required'          => true,
							'type'              => 'string',
							'description'       => __( 'New prompt text', 'data-machine' ),
							'sanitize_callback' => 'sanitize_textarea_field',
						),
					),
				),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/flows/(?P<flow_id>\d+)/queue/settings',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( self::class, 'handle_update_queue_settings' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'flow_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Flow ID', 'data-machine' ),
					),
					'flow_step_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Flow step ID', 'data-machine' ),
					),
					'queue_enabled' => array(
						'required'          => true,
						'type'              => 'boolean',
						'description'       => __( 'Whether queue pop is enabled for this step', 'data-machine' ),
					),
				),
			)
		);
	}

	/**
	 * Check if user has permission to manage queue
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool|\WP_Error
	 */
	public static function check_permission( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage flow queues.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle list queue request
	 *
	 * GET /flows/{id}/queue
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_list_queue( $request ) {
		$ability = wp_get_ability( 'datamachine/queue-list' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability not found', array( 'status' => 500 ) );
		}

		$result = $ability->execute(
			array(
				'flow_id'      => (int) $request->get_param( 'flow_id' ),
				'flow_step_id' => sanitize_text_field( $request->get_param( 'flow_step_id' ) ),
			)
		);

		if ( ! $result['success'] ) {
			$status = 400;
			if ( false !== strpos( $result['error'] ?? '', 'not found' ) ) {
				$status = 404;
			}

			return new \WP_Error(
				'queue_list_failed',
				$result['error'] ?? __( 'Failed to list queue.', 'data-machine' ),
				array( 'status' => $status )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'flow_id'       => $result['flow_id'],
					'flow_step_id'  => $result['flow_step_id'],
					'queue'         => $result['queue'],
					'count'         => $result['count'],
					'queue_enabled' => $result['queue_enabled'],
				),
			)
		);
	}

	/**
	 * Handle add to queue request
	 *
	 * POST /flows/{id}/queue
	 * Accepts either single 'prompt' or array of 'prompts'
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_add_to_queue( $request ) {
		$ability = wp_get_ability( 'datamachine/queue-add' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability not found', array( 'status' => 500 ) );
		}

		$flow_id      = (int) $request->get_param( 'flow_id' );
		$prompt       = $request->get_param( 'prompt' );
		$prompts      = $request->get_param( 'prompts' );
		$flow_step_id = sanitize_text_field( $request->get_param( 'flow_step_id' ) );

		// Build list of prompts to add
		$prompts_to_add = array();
		if ( ! empty( $prompt ) ) {
			$prompts_to_add[] = $prompt;
		}
		if ( is_array( $prompts ) ) {
			foreach ( $prompts as $p ) {
				if ( is_string( $p ) && ! empty( trim( $p ) ) ) {
					$prompts_to_add[] = sanitize_textarea_field( $p );
				}
			}
		}

		if ( empty( $prompts_to_add ) ) {
			return new \WP_Error(
				'no_prompts',
				__( 'No prompts provided. Use "prompt" for single or "prompts" for multiple.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		$added_count  = 0;
		$queue_length = 0;

		// Add each prompt
		foreach ( $prompts_to_add as $p ) {
			$result = $ability->execute(
				array(
					'flow_id'      => $flow_id,
					'flow_step_id' => $flow_step_id,
					'prompt'       => $p,
				)
			);

			if ( $result['success'] ) {
				++$added_count;
				$queue_length = $result['queue_length'];
			} else {
				// If first one fails with flow not found, return error
				if ( 0 === $added_count && false !== strpos( $result['error'] ?? '', 'not found' ) ) {
					return new \WP_Error(
						'flow_not_found',
						$result['error'],
						array( 'status' => 404 )
					);
				}
			}
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'flow_id'      => $flow_id,
					'flow_step_id' => $flow_step_id,
					'added_count'  => $added_count,
					'queue_length' => $queue_length,
				),
				'message' => sprintf(
					/* translators: %1$d: number of prompts added, %2$d: total queue length */
					__( 'Added %1$d prompt(s). Queue now has %2$d item(s).', 'data-machine' ),
					$added_count,
					$queue_length
				),
			)
		);
	}

	/**
	 * Handle clear queue request
	 *
	 * DELETE /flows/{id}/queue
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_clear_queue( $request ) {
		$ability = wp_get_ability( 'datamachine/queue-clear' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability not found', array( 'status' => 500 ) );
		}

		$result = $ability->execute(
			array(
				'flow_id'      => (int) $request->get_param( 'flow_id' ),
				'flow_step_id' => sanitize_text_field( $request->get_param( 'flow_step_id' ) ),
			)
		);

		if ( ! $result['success'] ) {
			$status = 400;
			if ( false !== strpos( $result['error'] ?? '', 'not found' ) ) {
				$status = 404;
			}

			return new \WP_Error(
				'queue_clear_failed',
				$result['error'] ?? __( 'Failed to clear queue.', 'data-machine' ),
				array( 'status' => $status )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'flow_id'       => $result['flow_id'],
					'flow_step_id'  => $result['flow_step_id'],
					'cleared_count' => $result['cleared_count'],
				),
				'message' => $result['message'],
			)
		);
	}

	/**
	 * Handle remove from queue request
	 *
	 * DELETE /flows/{id}/queue/{index}
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_remove_from_queue( $request ) {
		$ability = wp_get_ability( 'datamachine/queue-remove' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability not found', array( 'status' => 500 ) );
		}

		$result = $ability->execute(
			array(
				'flow_id'      => (int) $request->get_param( 'flow_id' ),
				'flow_step_id' => sanitize_text_field( $request->get_param( 'flow_step_id' ) ),
				'index'        => (int) $request->get_param( 'index' ),
			)
		);

		if ( ! $result['success'] ) {
			$status = 400;
			if ( false !== strpos( $result['error'] ?? '', 'not found' ) ) {
				$status = 404;
			}

			return new \WP_Error(
				'queue_remove_failed',
				$result['error'] ?? __( 'Failed to remove from queue.', 'data-machine' ),
				array( 'status' => $status )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'flow_id'        => $result['flow_id'],
					'flow_step_id'   => $result['flow_step_id'],
					'removed_prompt' => $result['removed_prompt'],
					'queue_length'   => $result['queue_length'],
				),
				'message' => $result['message'],
			)
		);
	}

	/**
	 * Handle update queue item request
	 *
	 * PUT /flows/{id}/queue/{index}
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_update_queue_item( $request ) {
		$ability = wp_get_ability( 'datamachine/queue-update' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability not found', array( 'status' => 500 ) );
		}

		$result = $ability->execute(
			array(
				'flow_id'      => (int) $request->get_param( 'flow_id' ),
				'flow_step_id' => sanitize_text_field( $request->get_param( 'flow_step_id' ) ),
				'index'        => (int) $request->get_param( 'index' ),
				'prompt'       => $request->get_param( 'prompt' ),
			)
		);

		if ( ! $result['success'] ) {
			$status = 400;
			if ( false !== strpos( $result['error'] ?? '', 'not found' ) ) {
				$status = 404;
			}

			return new \WP_Error(
				'queue_update_failed',
				$result['error'] ?? __( 'Failed to update queue item.', 'data-machine' ),
				array( 'status' => $status )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'flow_id'      => $result['flow_id'],
					'flow_step_id' => $result['flow_step_id'],
					'index'        => $result['index'],
					'queue_length' => $result['queue_length'],
				),
				'message' => $result['message'],
			)
		);
	}

	/**
	 * Handle queue settings update.
	 *
	 * PUT /flows/{id}/queue/settings
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_update_queue_settings( $request ) {
		$ability = wp_get_ability( 'datamachine/queue-settings' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Ability not found', array( 'status' => 500 ) );
		}

		$result = $ability->execute(
			array(
				'flow_id'       => (int) $request->get_param( 'flow_id' ),
				'flow_step_id'  => sanitize_text_field( $request->get_param( 'flow_step_id' ) ),
				'queue_enabled' => (bool) $request->get_param( 'queue_enabled' ),
			)
		);

		if ( ! $result['success'] ) {
			$status = 400;
			if ( false !== strpos( $result['error'] ?? '', 'not found' ) ) {
				$status = 404;
			}

			return new \WP_Error(
				'queue_settings_failed',
				$result['error'] ?? __( 'Failed to update queue settings.', 'data-machine' ),
				array( 'status' => $status )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'flow_id'       => $result['flow_id'],
					'flow_step_id'  => $result['flow_step_id'],
					'queue_enabled' => $result['queue_enabled'],
				),
				'message' => $result['message'] ?? __( 'Queue settings updated.', 'data-machine' ),
			)
		);
	}
}
