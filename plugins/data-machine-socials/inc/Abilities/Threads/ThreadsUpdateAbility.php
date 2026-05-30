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
use DataMachineSocials\Abilities\AbstractSocialAbility;
use DataMachineSocials\Handlers\Threads\ThreadsAuth;

defined( 'ABSPATH' ) || exit;

class ThreadsUpdateAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	const GRAPH_API_URL = 'https://graph.threads.net/v1.0';

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/threads-update',
				array(
					'label'               => __( 'Update Threads Posts', 'data-machine-socials' ),
					'description'         => __( 'Delete Threads posts', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
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
	}

	public function checkPermission(): bool {
		return PermissionHelper::can( 'use_tools' );
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

	private function getAuthProvider(): ?ThreadsAuth {
		if ( ! class_exists( '\DataMachine\Abilities\AuthAbilities' ) ) {
			return null;
		}

		$auth     = new \DataMachine\Abilities\AuthAbilities();
		$provider = $auth->getProvider( 'threads' );

		if ( ! $provider instanceof ThreadsAuth ) {
			return null;
		}

		return $provider;
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

		$normalized = $this->normalizeJsonResponse( $response );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$status_code = $normalized['status_code'];
		$body        = $normalized['data'];

		if ( 200 === $status_code || 204 === $status_code ) {
			return array(
				'success' => true,
				'data'    => array(
					'thread_id' => $thread_id,
					'deleted'   => true,
				),
			);
		}

		return $this->apiError( $body['error']['message'] ?? 'Failed to delete thread' );
	}
}
