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
use DataMachineSocials\Handlers\Threads\ThreadsAuth;

defined( 'ABSPATH' ) || exit;

class ThreadsDeleteAbility {

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
				'datamachine/threads-delete',
				array(
					'label'               => __( 'Delete Threads Posts', 'data-machine-socials' ),
					'description'         => __( 'Delete your Threads posts', 'data-machine-socials' ),
					'category'            => 'datamachine',
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

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	public function execute( array $input ): array {
		$auth = $this->getAuthProvider();
		if ( ! $auth ) {
			return array(
				'success' => false,
				'error'   => 'Threads auth provider not available',
			);
		}

		$access_token = $auth->get_valid_access_token();
		if ( empty( $access_token ) ) {
			return array(
				'success' => false,
				'error'   => 'Threads access token unavailable',
			);
		}

		if ( empty( $input['thread_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'thread_id is required',
			);
		}

		$url = self::GRAPH_API_URL . '/' . rawurlencode( $input['thread_id'] );

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
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $status_code || 204 === $status_code ) {
			return array(
				'success' => true,
				'data'    => array(
					'thread_id' => $input['thread_id'],
					'deleted'   => true,
				),
			);
		}

		return array(
			'success' => false,
			'error'   => $body['error']['message'] ?? 'Failed to delete thread',
		);
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
