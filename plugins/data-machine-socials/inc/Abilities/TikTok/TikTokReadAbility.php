<?php
/**
 * TikTok Read Ability
 *
 * Reads a creator's own public videos via the TikTok Display API.
 * Implements List Videos (POST /v2/video/list/) and Get User Info
 * (GET /v2/user/info/). Requires the video.list scope.
 *
 * Official docs:
 *   List Videos    — https://developers.tiktok.com/doc/tiktok-api-v2-video-list
 *   Get User Info  — https://developers.tiktok.com/doc/tiktok-api-v2-get-user-info
 *
 * @package DataMachineSocials
 * @subpackage Abilities\TikTok
 * @since 0.17.0
 */

namespace DataMachineSocials\Abilities\TikTok;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachineSocials\Abilities\AbstractSocialAbility;
use DataMachineSocials\Handlers\TikTok\TikTokAuth;

defined( 'ABSPATH' ) || exit;

/**
 * TikTok Read Ability
 */
class TikTokReadAbility extends AbstractSocialAbility {

	/**
	 * Whether the ability has been registered.
	 *
	 * @var bool
	 */
	protected static bool $registered = false;

	const VIDEO_LIST_ENDPOINT = TikTokAuth::API_BASE . '/v2/video/list/';
	const USER_INFO_ENDPOINT  = TikTokAuth::API_BASE . '/v2/user/info/';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->registerAbility( $this->registerCallback() );
	}

	/**
	 * Build the TikTok read ability registration callback.
	 *
	 * @return callable
	 */
	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/tiktok-read',
				array(
					'label'               => __( 'Read TikTok Videos', 'data-machine-socials' ),
					'description'         => __( 'List a creator\'s own public TikTok videos and profile info via the Display API (video.list scope)', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'action'   => array(
								'type'        => 'string',
								'description' => 'Action to perform: list (default) or profile.',
								'enum'        => array( 'list', 'profile' ),
								'default'     => 'list',
							),
							'limit'    => array(
								'type'        => 'integer',
								'description' => 'Number of videos to return (max 20 per page).',
								'default'     => 10,
							),
							'cursor'   => array(
								'type'        => 'integer',
								'description' => 'Pagination cursor (0 for first page).',
								'default'     => 0,
							),
							'fields'   => array(
								'type'        => 'array',
								'description' => 'Video fields to request. Defaults to id,create_time,share_url,title,view_count,like_count,comment_count.',
								'items'       => array( 'type' => 'string' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'  => array( 'type' => 'boolean' ),
							'data'     => array( 'type' => 'object' ),
							'error'    => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'execute' ),
					'permission_callback' => fn() => PermissionHelper::can( 'use_tools' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};
	}

	/**
	 * Execute the read ability.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error Result or error.
	 */
	public static function execute( array $input ): array|\WP_Error {
		$action = $input['action'] ?? 'list';

		$auth     = new AuthAbilities();
		$provider = $auth->getProvider( 'tiktok' );

		if ( ! $provider || ! $provider->is_authenticated() ) {
			return new \WP_Error( 'missing_auth', 'TikTok not authenticated', array( 'status' => 401 ) );
		}

		$access_token = $provider->get_valid_access_token();
		if ( empty( $access_token ) ) {
			return new \WP_Error( 'missing_auth', 'TikTok access token unavailable', array( 'status' => 401 ) );
		}

		if ( 'profile' === $action ) {
			return self::get_profile( $access_token );
		}

		return self::list_videos( $access_token, $input );
	}

	/**
	 * List the creator's own public videos.
	 *
	 * @param string $access_token Valid access token.
	 * @param array  $input        Ability input.
	 * @return array|\WP_Error Result or error.
	 */
	private static function list_videos( string $access_token, array $input ): array|\WP_Error {
		$limit  = min( max( (int) ( $input['limit'] ?? 10 ), 1 ), 20 );
		$cursor = (int) ( $input['cursor'] ?? 0 );

		$fields = $input['fields'] ?? array( 'id', 'create_time', 'share_url', 'title', 'view_count', 'like_count', 'comment_count' );

		$body = array(
			'fields'  => implode( ',', $fields ),
			'max_count'  => $limit,
			'cursor'     => $cursor,
		);

		$result = HttpClient::post(
			self::VIDEO_LIST_ENDPOINT,
			array(
				'context' => 'TikTok Video List',
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json; charset=UTF-8',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			)
		);

		return self::parse_response( $result, 'TikTok video list' );
	}

	/**
	 * Get the creator's profile info via the Display API.
	 *
	 * @param string $access_token Valid access token.
	 * @return array|\WP_Error Result or error.
	 */
	private static function get_profile( string $access_token ): array|\WP_Error {
		$fields   = array( 'open_id', 'display_name', 'avatar_url', 'follower_count', 'following_count', 'likes_count', 'video_count' );
		$url      = self::USER_INFO_ENDPOINT . '?fields=' . implode( ',', $fields );

		$result = HttpClient::get(
			$url,
			array(
				'context' => 'TikTok User Info',
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
				'timeout' => 30,
			)
		);

		return self::parse_response( $result, 'TikTok user info' );
	}

	/**
	 * Parse a TikTok JSON API response.
	 *
	 * @param array  $result HttpClient result.
	 * @param string $context Context label.
	 * @return array|\WP_Error Normalized result or error.
	 */
	private static function parse_response( array $result, string $context ): array|\WP_Error {
		if ( empty( $result['success'] ) ) {
			return array(
				'success' => false,
				'error'   => $context . ': ' . ( $result['error'] ?? 'HTTP request failed' ),
			);
		}

		$decoded = json_decode( $result['data'], true );
		if ( ! is_array( $decoded ) ) {
			return array(
				'success' => false,
				'error'   => $context . ': invalid JSON response',
			);
		}

		$error = $decoded['error'] ?? array();
		if ( isset( $error['code'] ) && 'ok' !== $error['code'] ) {
			return array(
				'success' => false,
				'error'   => $error['message'] ?? $error['code'],
			);
		}

		return array(
			'success' => true,
			'data'    => $decoded['data'] ?? array(),
		);
	}
}
