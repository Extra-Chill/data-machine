<?php
/**
 * Processed Items REST API Endpoint
 *
 * Provides REST API access to clear processed items tracking for deduplication.
 * Delegates to ProcessedItemsAbilities for core logic.
 * Requires WordPress manage_options capability for all operations.
 *
 * Endpoints:
 * - DELETE /datamachine/v1/processed-items - Clear processed items by pipeline or flow
 *
 * @package DataMachine\Api
 */

namespace DataMachine\Api;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Abilities\ProcessedItemsAbilities;
use DataMachine\Api\Traits\HasRegister;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class ProcessedItems {
	use HasRegister;


	private static ?ProcessedItemsAbilities $abilities = null;

	private static function getAbilities(): ProcessedItemsAbilities {
		if ( null === self::$abilities ) {
			self::$abilities = new ProcessedItemsAbilities();
		}
		return self::$abilities;
	}

	/**
	 * Register all processed items related REST endpoints
	 */
	public static function register_routes() {

		// DELETE /datamachine/v1/processed-items - Clear processed items
		register_rest_route(
			'datamachine/v1',
			'/processed-items',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( self::class, 'handle_clear' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'clear_type' => array(
						'required'    => true,
						'type'        => 'string',
						'enum'        => array( 'pipeline', 'flow' ),
						'description' => __( 'Clear by pipeline or flow', 'data-machine' ),
					),
					'target_id'  => array(
						'required'    => true,
						'type'        => 'integer',
						'description' => __( 'Pipeline ID or Flow ID', 'data-machine' ),
					),
				),
			)
		);
	}

	/**
	 * Check if user has permission to manage processed items
	 */
	public static function check_permission( $request ) {
		$request;
		if ( ! PermissionHelper::can( 'manage_flows' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage processed items.', 'data-machine' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle clear processed items request
	 *
	 * DELETE /datamachine/v1/processed-items
	 */
	public static function handle_clear( $request ) {
		$clear_type = $request->get_param( 'clear_type' );
		$target_id  = (int) $request->get_param( 'target_id' );

		$result = self::getAbilities()->executeClearProcessedItems(
			array(
				'clear_type' => $clear_type,
				'target_id'  => $target_id,
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'delete_failed',
				$result['error'] ?? __( 'Failed to delete processed items.', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success'       => true,
				'data'          => null,
				'message'       => $result['message'],
				'items_deleted' => $result['deleted_count'],
			)
		);
	}
}
