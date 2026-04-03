<?php
/**
 * Threads Update Ability
 *
 * Abilities API primitive for updating Threads posts.
 * Supports deleting posts.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Threads
 * @since      0.3.0
 */

namespace DataMachineSocials\Abilities\Threads;

use DataMachine\Abilities\PermissionHelper;
use DataMachineSocials\Handlers\Threads\ThreadsAuth;
use DataMachineSocials\Abilities\Traits\HasCheckPermission;
use DataMachineSocials\Abilities\Threads\ThreadsDeleteAbility;

defined( 'ABSPATH' ) || exit;

class ThreadsUpdateAbility {
	use HasCheckPermission;


	private static bool $registered = false;

	const GRAPH_API_URL = 'https://graph.threads.net/v1.0';

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/threads-update',
				array(
					'label'               => __( 'Update Threads Posts', 'data-machine-socials' ),
					'description'         => __( 'Delete Threads posts', 'data-machine-socials' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'action'    => array(
								'type'        => 'string',
								'enum'        => array( 'delete' ),
								'description' => __( 'Action: delete (remove post)', 'data-machine-socials' ),
							),
							'thread_id' => array(
								'type'        => 'string',
								'description' => __( 'Threads thread ID', 'data-machine-socials' ),
							),
						),
						'required'   => array( 'action', 'thread_id' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'data'    => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	public function execute( array $input ): array|\WP_Error {
		$action = $input['action'] ?? '';

		$auth = $this->getAuthProvider();
		if ( ! $auth ) {
			return new \WP_Error( 'missing_auth', 'Threads auth provider not available', array( 'status' => 401 ) );
		}

		$access_token = $auth->get_valid_access_token();
		if ( empty( $access_token ) ) {
			return new \WP_Error( 'missing_auth', 'Threads access token unavailable', array( 'status' => 401 ) );
		}

		if ( empty( $input['thread_id'] ) ) {
			return new \WP_Error( 'missing_param', 'thread_id is required', array( 'status' => 400 ) );
		}

		$thread_id = $input['thread_id'];

		switch ( $action ) {
			case 'delete':
				return $this->deleteThread( $access_token, $thread_id );

			default:
				return new \WP_Error( 'api_error', "Unknown action: {$action}. Use delete.", array( 'status' => 500 ) );
		}
	}

	private function deleteThread( string $access_token, string $thread_id ): array|\WP_Error {
		$url = self::GRAPH_API_URL . '/' . rawurlencode( $thread_id );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'body'    => array(
					'access_token' => $access_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'api_error', $response->get_error_message(), array( 'status' => 500 ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $status_code || 204 === $status_code ) {
			return array(
				'success' => true,
				'data'    => array(
					'thread_id' => $thread_id,
					'deleted'   => true,
				),
			);
		}

		return new \WP_Error( 'api_error', $body['error']['message'] ?? 'Failed to delete thread', array( 'status' => 500 ) );
	}
}
