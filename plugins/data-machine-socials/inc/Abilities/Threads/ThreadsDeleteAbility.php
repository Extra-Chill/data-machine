<?php
/**
 * Threads Delete Ability
 *
 * Abilities API primitive for deleting Threads posts.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Threads
 * @since      0.3.0
 */

namespace DataMachineSocials\Abilities\Threads;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachineSocials\Abilities\AbstractSocialAbility;
use DataMachineSocials\Handlers\Threads\ThreadsAuth;

defined( 'ABSPATH' ) || exit;

class ThreadsDeleteAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	const GRAPH_API_URL = 'https://graph.threads.net/v1.0';

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/threads-delete',
				array(
					'label'               => __( 'Delete Threads Posts', 'data-machine-socials' ),
					'description'         => __( 'Delete your Threads posts', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'thread_id' => array(
								'type'        => 'string',
								'description' => __( 'Threads thread ID to delete', 'data-machine-socials' ),
							),
						),
						'required'   => array( 'thread_id' ),
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

		$url = self::GRAPH_API_URL . '/' . rawurlencode( $input['thread_id'] );

		$result = HttpClient::post(
			$url,
			array(
				'context' => 'Threads Delete',
				'timeout' => 30,
				'body'    => array(
					'access_token' => $access_token,
				),
			)
		);

		if ( ! empty( $result['success'] ) ) {
			return array(
				'success' => true,
				'data'    => array(
					'thread_id' => $input['thread_id'],
					'deleted'   => true,
				),
			);
		}

		return $this->apiError( $result['error'] ?? 'Failed to delete thread' );
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
}
