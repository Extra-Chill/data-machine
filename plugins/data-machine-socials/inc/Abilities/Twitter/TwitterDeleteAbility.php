<?php
/**
 * Twitter Delete Ability
 *
 * Abilities API primitive for deleting Twitter tweets.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Twitter
 * @since      0.3.0
 */

namespace DataMachineSocials\Abilities\Twitter;

use DataMachine\Abilities\PermissionHelper;
use DataMachineSocials\Handlers\Twitter\TwitterAuth;

defined( 'ABSPATH' ) || exit;

class TwitterDeleteAbility {

	private static bool $registered = false;

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
				'datamachine/twitter-delete',
				array(
					'label'               => __( 'Delete Tweets', 'data-machine-socials' ),
					'description'         => __( 'Delete tweets from your account', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'tweet_id' => array(
								'type'        => 'string',
								'description' => __( 'Tweet ID to delete', 'data-machine-socials' ),
							),
						),
						'required'   => array( 'tweet_id' ),
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
		return PermissionHelper::can( 'use_tools' );
	}

	public function execute( array $input ): array|\WP_Error {
		$auth = $this->getAuthProvider();
		if ( ! $auth ) {
			return new \WP_Error( 'missing_auth', 'Twitter auth provider not available', array( 'status' => 401 ) );
		}

		$connection = $auth->get_connection();
		if ( ! $connection ) {
			return new \WP_Error( 'missing_auth', 'Twitter connection not available', array( 'status' => 401 ) );
		}

		if ( empty( $input['tweet_id'] ) ) {
			return new \WP_Error( 'missing_param', 'tweet_id is required', array( 'status' => 400 ) );
		}

		$connection->setApiVersion( '2' );
		$result = $connection->delete( 'tweets/' . $input['tweet_id'], array() );

		$http_code = $connection->getLastHttpCode();

		if ( 200 === $http_code ) {
			return array(
				'success' => true,
				'data'    => array(
					'tweet_id' => $input['tweet_id'],
					'deleted'  => true,
				),
			);
		}

		$error = $result['detail'] ?? $result['title'] ?? 'Failed to delete tweet';
		return new \WP_Error( 'api_error', $error, array( 'status' => 500 ) );
	}

	private function getAuthProvider(): ?TwitterAuth {
		if ( ! class_exists( '\DataMachine\Abilities\AuthAbilities' ) ) {
			return null;
		}

		$auth     = new \DataMachine\Abilities\AuthAbilities();
		$provider = $auth->getProvider( 'twitter' );

		if ( ! $provider instanceof TwitterAuth ) {
			return null;
		}

		return $provider;
	}
}
