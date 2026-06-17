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

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Api\RestAbilityExecutor;
use DataMachine\Api\RestResultSpec;
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
						'flow_id'      => array(
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
						'flow_id'      => array(
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
						'prompt'       => array(
							'required'          => false,
							'type'              => 'string',
							'description'       => __( 'Single prompt to add', 'data-machine' ),
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'prompts'      => array(
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
						'flow_id'      => array(
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
						'flow_id'      => array(
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
						'index'        => array(
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
						'flow_id'      => array(
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
						'index'        => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Queue index (0-based)', 'data-machine' ),
						),
						'prompt'       => array(
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
			'/flows/(?P<flow_id>\d+)/queue/mode',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( self::class, 'handle_update_queue_mode' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'flow_id'      => array(
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
					'mode'         => array(
						'required'    => true,
						'type'        => 'string',
						'enum'        => array( 'drain', 'loop', 'static' ),
						'description' => __( 'Queue access mode: drain | loop | static.', 'data-machine' ),
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
		$request;
		if ( ! PermissionHelper::can( 'manage_flows' ) ) {
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
		return RestAbilityExecutor::execute(
			'datamachine/queue-list',
			array(
				'flow_id'      => (int) $request->get_param( 'flow_id' ),
				'flow_step_id' => sanitize_text_field( $request->get_param( 'flow_step_id' ) ),
			),
			RestResultSpec::item(
				static function ( array $result ): array {
					return array(
						'flow_id'      => $result['flow_id'],
						'flow_step_id' => $result['flow_step_id'],
						'queue'        => $result['queue'],
						'count'        => $result['count'],
						'queue_mode'   => $result['queue_mode'],
					);
				},
				null,
				'queue_list_failed',
				__( 'Failed to list queue.', 'data-machine' ),
				400,
				array( self::class, 'queue_failure_status' )
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

			if ( is_wp_error( $result ) ) {
				if ( 0 === $added_count ) {
					return $result;
				}
				continue;
			}

			if ( $result['success'] ) {
				++$added_count;
				$queue_length = $result['queue_length'];
				// If first one fails with flow not found, return error
			} elseif ( 0 === $added_count && false !== strpos( $result['error'] ?? '', 'not found' ) ) {
				return new \WP_Error(
					'flow_not_found',
					$result['error'],
					array( 'status' => 404 )
				);
			}
		}

		return RestResultSpec::item(
			static function () use ( $flow_id, $flow_step_id, $added_count, $queue_length ): array {
				return array(
					'flow_id'      => $flow_id,
					'flow_step_id' => $flow_step_id,
					'added_count'  => $added_count,
					'queue_length' => $queue_length,
				);
			},
			static function () use ( $added_count, $queue_length ): array {
				return array(
					'message' => sprintf(
					/* translators: %1$d: number of prompts added, %2$d: total queue length */
					__( 'Added %1$d prompt(s). Queue now has %2$d item(s).', 'data-machine' ),
					$added_count,
					$queue_length
					),
				);
			}
		)->response( array( 'success' => true ) );
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
		return RestAbilityExecutor::execute(
			'datamachine/queue-clear',
			array(
				'flow_id'      => (int) $request->get_param( 'flow_id' ),
				'flow_step_id' => sanitize_text_field( $request->get_param( 'flow_step_id' ) ),
			),
			RestResultSpec::item(
				static function ( array $result ): array {
					return array(
						'flow_id'       => $result['flow_id'],
						'flow_step_id'  => $result['flow_step_id'],
						'cleared_count' => $result['cleared_count'],
					);
				},
				static function ( array $result ): array {
					return array( 'message' => $result['message'] );
				},
				'queue_clear_failed',
				__( 'Failed to clear queue.', 'data-machine' ),
				400,
				array( self::class, 'queue_failure_status' )
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
		return RestAbilityExecutor::execute(
			'datamachine/queue-remove',
			array(
				'flow_id'      => (int) $request->get_param( 'flow_id' ),
				'flow_step_id' => sanitize_text_field( $request->get_param( 'flow_step_id' ) ),
				'index'        => (int) $request->get_param( 'index' ),
			),
			RestResultSpec::item(
				static function ( array $result ): array {
					return array(
						'flow_id'        => $result['flow_id'],
						'flow_step_id'   => $result['flow_step_id'],
						'removed_prompt' => $result['removed_prompt'],
						'queue_length'   => $result['queue_length'],
					);
				},
				static function ( array $result ): array {
					return array( 'message' => $result['message'] );
				},
				'queue_remove_failed',
				__( 'Failed to remove from queue.', 'data-machine' ),
				400,
				array( self::class, 'queue_failure_status' )
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
		return RestAbilityExecutor::execute(
			'datamachine/queue-update',
			array(
				'flow_id'      => (int) $request->get_param( 'flow_id' ),
				'flow_step_id' => sanitize_text_field( $request->get_param( 'flow_step_id' ) ),
				'index'        => (int) $request->get_param( 'index' ),
				'prompt'       => $request->get_param( 'prompt' ),
			),
			RestResultSpec::item(
				static function ( array $result ): array {
					return array(
						'flow_id'      => $result['flow_id'],
						'flow_step_id' => $result['flow_step_id'],
						'index'        => $result['index'],
						'queue_length' => $result['queue_length'],
					);
				},
				static function ( array $result ): array {
					return array( 'message' => $result['message'] );
				},
				'queue_update_failed',
				__( 'Failed to update queue item.', 'data-machine' ),
				400,
				array( self::class, 'queue_failure_status' )
			)
		);
	}

	/**
	 * Handle queue mode update.
	 *
	 * PUT /flows/{id}/queue/mode
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_update_queue_mode( $request ) {
		return RestAbilityExecutor::execute(
			'datamachine/queue-mode',
			array(
				'flow_id'      => (int) $request->get_param( 'flow_id' ),
				'flow_step_id' => sanitize_text_field( $request->get_param( 'flow_step_id' ) ),
				'mode'         => sanitize_text_field( $request->get_param( 'mode' ) ),
			),
			RestResultSpec::item(
				static function ( array $result ): array {
					return array(
						'flow_id'      => $result['flow_id'],
						'flow_step_id' => $result['flow_step_id'],
						'queue_mode'   => $result['queue_mode'],
					);
				},
				static function ( array $result ): array {
					return array( 'message' => $result['message'] ?? __( 'Queue mode updated.', 'data-machine' ) );
				},
				'queue_mode_failed',
				__( 'Failed to update queue mode.', 'data-machine' ),
				400,
				array( self::class, 'queue_failure_status' )
			)
		);
	}

	/**
	 * Preserve queue endpoints' historical not-found status mapping.
	 */
	public static function queue_failure_status( array $result ): int {
		return false !== strpos( $result['error'] ?? '', 'not found' ) ? 404 : 400;
	}
}
