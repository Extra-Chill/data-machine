<?php
/**
 * Instagram Read Ability
 *
 * Abilities API primitive for reading Instagram media (posts, reels, carousels).
 * Supports listing recent media and fetching single post details with engagement metrics.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Instagram
 * @since      0.3.0
 */

namespace DataMachineSocials\Abilities\Instagram;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachineSocials\Handlers\Instagram\InstagramAuth;

defined( 'ABSPATH' ) || exit;

class InstagramReadAbility {

	private static bool $registered = false;

	const GRAPH_API_URL = 'https://graph.instagram.com';

	/**
	 * Fields to request when listing media.
	 */
	const LIST_FIELDS = 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,like_count,comments_count';

	/**
	 * Fields to request for single media detail.
	 */
	const DETAIL_FIELDS = 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,like_count,comments_count,media_product_type,is_shared_to_feed';

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
			// List media ability.
			wp_register_ability(
				'datamachine/instagram-read',
				array(
					'label'               => __( 'Read Instagram Media', 'data-machine-socials' ),
					'description'         => __( 'List recent Instagram posts or get details for a specific post', 'data-machine-socials' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'action'   => array(
								'type'        => 'string',
								'enum'        => array( 'list', 'get', 'comments' ),
								'default'     => 'list',
								'description' => __( 'Action: list (recent posts), get (single post), comments (post comments)', 'data-machine-socials' ),
							),
							'media_id' => array(
								'type'        => 'string',
								'description' => __( 'Instagram media ID (required for get and comments actions)', 'data-machine-socials' ),
							),
							'limit'    => array(
								'type'        => 'integer',
								'default'     => 25,
								'description' => __( 'Number of items to return (max 100)', 'data-machine-socials' ),
							),
							'after'    => array(
								'type'        => 'string',
								'description' => __( 'Pagination cursor for next page', 'data-machine-socials' ),
							),
						),
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

	/**
	 * Permission callback.
	 *
	 * @return bool
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Execute the read ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public function execute( array $input ): array {
		$action = $input['action'] ?? 'list';

		// Get auth provider.
		$auth = $this->getAuthProvider();
		if ( ! $auth ) {
			return array(
				'success' => false,
				'error'   => 'Instagram auth provider not available',
			);
		}

		$access_token = $auth->get_valid_access_token();
		if ( empty( $access_token ) ) {
			return array(
				'success' => false,
				'error'   => 'Instagram access token unavailable (expired or refresh failed)',
			);
		}

		switch ( $action ) {
			case 'list':
				return $this->listMedia( $auth, $access_token, $input );

			case 'get':
				if ( empty( $input['media_id'] ) ) {
					return array(
						'success' => false,
						'error'   => 'media_id is required for the get action',
					);
				}
				return $this->getMedia( $access_token, $input['media_id'] );

			case 'comments':
				if ( empty( $input['media_id'] ) ) {
					return array(
						'success' => false,
						'error'   => 'media_id is required for the comments action',
					);
				}
				return $this->getComments( $access_token, $input['media_id'], $input );

			default:
				return array(
					'success' => false,
					'error'   => "Unknown action: {$action}. Use list, get, or comments.",
				);
		}
	}

	/**
	 * List recent media for the authenticated user.
	 *
	 * @param InstagramAuth $auth         Auth provider.
	 * @param string        $access_token Valid access token.
	 * @param array         $input        Input parameters.
	 * @return array Result.
	 */
	private function listMedia( InstagramAuth $auth, string $access_token, array $input ): array {
		$user_id = $auth->get_user_id();
		if ( empty( $user_id ) ) {
			return array(
				'success' => false,
				'error'   => 'Instagram user ID not available. Try re-authenticating.',
			);
		}

		$limit  = min( absint( $input['limit'] ?? 25 ), 100 );
		$params = array(
			'fields'       => self::LIST_FIELDS,
			'limit'        => $limit,
			'access_token' => $access_token,
		);

		if ( ! empty( $input['after'] ) ) {
			$params['after'] = $input['after'];
		}

		$url    = self::GRAPH_API_URL . "/{$user_id}/media?" . http_build_query( $params );
		$result = HttpClient::get( $url, array( 'context' => 'Instagram Read' ) );

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'error'   => 'Instagram API request failed: ' . ( $result['error'] ?? 'unknown' ),
			);
		}

		$data      = json_decode( $result['data'], true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || isset( $data['error'] ) ) {
			$error_msg = $data['error']['message'] ?? 'Failed to fetch Instagram media';
			return array(
				'success' => false,
				'error'   => $error_msg,
			);
		}

		$media  = $data['data'] ?? array();
		$paging = $data['paging'] ?? array();

		return array(
			'success' => true,
			'data'    => array(
				'media'    => $media,
				'count'    => count( $media ),
				'cursors'  => $paging['cursors'] ?? null,
				'has_next' => ! empty( $paging['next'] ),
			),
		);
	}

	/**
	 * Get details for a single media item.
	 *
	 * @param string $access_token Valid access token.
	 * @param string $media_id     Instagram media ID.
	 * @return array Result.
	 */
	private function getMedia( string $access_token, string $media_id ): array {
		$params = array(
			'fields'       => self::DETAIL_FIELDS,
			'access_token' => $access_token,
		);

		$url    = self::GRAPH_API_URL . "/{$media_id}?" . http_build_query( $params );
		$result = HttpClient::get( $url, array( 'context' => 'Instagram Read' ) );

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'error'   => 'Instagram API request failed: ' . ( $result['error'] ?? 'unknown' ),
			);
		}

		$data      = json_decode( $result['data'], true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || isset( $data['error'] ) ) {
			$error_msg = $data['error']['message'] ?? 'Failed to fetch Instagram media details';
			return array(
				'success' => false,
				'error'   => $error_msg,
			);
		}

		return array(
			'success' => true,
			'data'    => $data,
		);
	}

	/**
	 * Get comments for a media item.
	 *
	 * @param string $access_token Valid access token.
	 * @param string $media_id     Instagram media ID.
	 * @param array  $input        Input parameters.
	 * @return array Result.
	 */
	private function getComments( string $access_token, string $media_id, array $input ): array {
		$limit  = min( absint( $input['limit'] ?? 25 ), 100 );
		$params = array(
			'fields'       => 'id,text,timestamp,username,like_count',
			'limit'        => $limit,
			'access_token' => $access_token,
		);

		if ( ! empty( $input['after'] ) ) {
			$params['after'] = $input['after'];
		}

		$url    = self::GRAPH_API_URL . "/{$media_id}/comments?" . http_build_query( $params );
		$result = HttpClient::get( $url, array( 'context' => 'Instagram Read' ) );

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'error'   => 'Instagram API request failed: ' . ( $result['error'] ?? 'unknown' ),
			);
		}

		$data      = json_decode( $result['data'], true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || isset( $data['error'] ) ) {
			$error_msg = $data['error']['message'] ?? 'Failed to fetch comments';
			return array(
				'success' => false,
				'error'   => $error_msg,
			);
		}

		$comments = $data['data'] ?? array();
		$paging   = $data['paging'] ?? array();

		return array(
			'success' => true,
			'data'    => array(
				'comments' => $comments,
				'count'    => count( $comments ),
				'cursors'  => $paging['cursors'] ?? null,
				'has_next' => ! empty( $paging['next'] ),
			),
		);
	}

	/**
	 * Get the Instagram auth provider.
	 *
	 * @return InstagramAuth|null
	 */
	private function getAuthProvider(): ?InstagramAuth {
		$auth_abilities = new \DataMachine\Abilities\AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'instagram' );

		if ( $provider instanceof InstagramAuth ) {
			return $provider;
		}

		return null;
	}
}
