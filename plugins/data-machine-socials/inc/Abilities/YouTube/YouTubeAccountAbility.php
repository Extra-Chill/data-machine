<?php
/**
 * YouTube Account Ability
 *
 * Primitive ability for reading the authenticated YouTube channel's identity
 * and basic statistics via the Data API v3 channels.list (mine=true).
 *
 * @package DataMachineSocials
 * @subpackage Abilities\YouTube
 * @since 0.17.0
 *
 * @link https://developers.google.com/youtube/v3/docs/channels/list
 */

namespace DataMachineSocials\Abilities\YouTube;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachineSocials\Abilities\AbstractSocialAbility;

defined( 'ABSPATH' ) || exit;

/**
 * YouTube Account Ability
 */
class YouTubeAccountAbility extends AbstractSocialAbility {

	/**
	 * Whether the ability has been registered.
	 *
	 * @var bool
	 */
	protected static bool $registered = false;

	const API_BASE = 'https://www.googleapis.com/youtube/v3';

	public function __construct() {
		$this->registerAbility( $this->registerCallback() );
	}

	/**
	 * Build the YouTube account ability registration callback.
	 *
	 * @return callable
	 */
	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/youtube-account',
				array(
					'label'               => __( 'YouTube Account Info', 'data-machine-socials' ),
					'description'         => __( 'Get the authenticated YouTube channel identity and basic statistics', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'    => array( 'type' => 'boolean' ),
							'channel_id' => array( 'type' => 'string' ),
							'title'      => array( 'type' => 'string' ),
							'error'      => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'get_account' ),
					'permission_callback' => fn() => PermissionHelper::can( 'use_tools' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};
	}

	/**
	 * Get the authenticated YouTube channel identity and stats.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error Channel details or error.
	 */
	public static function get_account( array $input ): array|\WP_Error {
		$input;

		$auth     = new AuthAbilities();
		$provider = $auth->getProvider( 'youtube' );

		if ( ! $provider || ! $provider->is_authenticated() ) {
			return new \WP_Error( 'missing_auth', 'YouTube not authenticated', array( 'status' => 401 ) );
		}

		$access_token = $provider->get_valid_access_token();
		if ( empty( $access_token ) ) {
			return new \WP_Error( 'missing_auth', 'YouTube access token unavailable (expired or refresh failed)', array( 'status' => 401 ) );
		}

		$url = self::API_BASE . '/channels?part=snippet,statistics&mine=true';

		$result = HttpClient::get(
			$url,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
				'context' => 'YouTube Account',
			)
		);

		if ( empty( $result['success'] ) ) {
			return new \WP_Error( 'api_error', 'YouTube account fetch failed: ' . ( $result['error'] ?? 'unknown error' ), array( 'status' => 500 ) );
		}

		$data = json_decode( $result['data'], true );
		if ( ! is_array( $data ) || empty( $data['items'][0] ) ) {
			return new \WP_Error( 'api_error', 'No YouTube channel found for this account', array( 'status' => 500 ) );
		}

		$channel    = $data['items'][0];
		$snippet    = $channel['snippet'] ?? array();
		$statistics = $channel['statistics'] ?? array();

		return array(
			'success'          => true,
			'channel_id'       => (string) ( $channel['id'] ?? '' ),
			'title'            => (string) ( $snippet['title'] ?? '' ),
			'description'      => (string) ( $snippet['description'] ?? '' ),
			'custom_url'       => (string) ( $snippet['customUrl'] ?? '' ),
			'published_at'     => (string) ( $snippet['publishedAt'] ?? '' ),
			'view_count'       => (string) ( $statistics['viewCount'] ?? '' ),
			'subscriber_count' => (string) ( $statistics['subscriberCount'] ?? '' ),
			'video_count'      => (string) ( $statistics['videoCount'] ?? '' ),
		);
	}
}
