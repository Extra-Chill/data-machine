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

	/**
	 * Uses Facebook Graph API because our OAuth flow issues FB-flavored tokens.
	 * graph.instagram.com rejects these with "Cannot parse access token" (code 190).
	 *
	 * @see InstagramPublishAbility::GRAPH_API_URL for full rationale.
	 */
	const GRAPH_API_URL = 'https://graph.facebook.com/v18.0';

	/**
	 * Regex for extracting @mentions from comment text.
	 */
	const MENTION_REGEX = '/@([a-zA-Z0-9._]{1,30})/';

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
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
						'action'   => array(
							'type'        => 'string',
							'enum'        => array( 'list', 'get', 'comments', 'comments_all' ),
							'default'     => 'list',
							'description' => __( 'Action: list (recent posts), get (single post), comments (one page), comments_all (all pages, normalized)', 'data-machine-socials' ),
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
		return PermissionHelper::can( 'use_tools' );
	}

	/**
	 * Execute the read ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public function execute( array $input ): array|\WP_Error {
		$action = $input['action'] ?? 'list';

		// Get auth provider.
		$auth = $this->getAuthProvider();
		if ( ! $auth ) {
			return new \WP_Error( 'missing_auth', 'Instagram auth provider not available', array( 'status' => 401 ) );
		}

		$access_token = $auth->get_valid_access_token();
		if ( empty( $access_token ) ) {
			return new \WP_Error( 'missing_auth', 'Instagram access token unavailable (expired or refresh failed)', array( 'status' => 401 ) );
		}

		switch ( $action ) {
			case 'list':
				return $this->listMedia( $auth, $access_token, $input );

			case 'get':
				if ( empty( $input['media_id'] ) ) {
					return new \WP_Error( 'missing_param', 'media_id is required for the get action', array( 'status' => 400 ) );
				}
				return $this->getMedia( $access_token, $input['media_id'] );

			case 'comments':
				if ( empty( $input['media_id'] ) ) {
					return new \WP_Error( 'missing_param', 'media_id is required for the comments action', array( 'status' => 400 ) );
				}
				return $this->getComments( $access_token, $input['media_id'], $input );

			case 'comments_all':
				if ( empty( $input['media_id'] ) ) {
					return new \WP_Error( 'missing_param', 'media_id is required for the comments_all action', array( 'status' => 400 ) );
				}
				return $this->getAllComments( $access_token, $input['media_id'] );

			default:
				return new \WP_Error( 'api_error', "Unknown action: {$action}. Use list, get, or comments.", array( 'status' => 500 ) );
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
	private function listMedia( InstagramAuth $auth, string $access_token, array $input ): array|\WP_Error {
		$user_id = $auth->get_user_id();
		if ( empty( $user_id ) ) {
			return new \WP_Error( 'missing_auth', 'Instagram user ID not available. Try re-authenticating.', array( 'status' => 401 ) );
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
			return new \WP_Error( 'api_error', 'Instagram API request failed: ' . ( $result['error'] ?? 'unknown' ), array( 'status' => 500 ) );
		}

		$data      = json_decode( $result['data'], true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || isset( $data['error'] ) ) {
			$error_msg = $data['error']['message'] ?? 'Failed to fetch Instagram media';
			return new \WP_Error( 'api_error', $error_msg, array( 'status' => 500 ) );
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
	private function getMedia( string $access_token, string $media_id ): array|\WP_Error {
		$params = array(
			'fields'       => self::DETAIL_FIELDS,
			'access_token' => $access_token,
		);

		$url    = self::GRAPH_API_URL . "/{$media_id}?" . http_build_query( $params );
		$result = HttpClient::get( $url, array( 'context' => 'Instagram Read' ) );

		if ( ! $result['success'] ) {
			return new \WP_Error( 'api_error', 'Instagram API request failed: ' . ( $result['error'] ?? 'unknown' ), array( 'status' => 500 ) );
		}

		$data      = json_decode( $result['data'], true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || isset( $data['error'] ) ) {
			$error_msg = $data['error']['message'] ?? 'Failed to fetch Instagram media details';
			return new \WP_Error( 'api_error', $error_msg, array( 'status' => 500 ) );
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
	private function getComments( string $access_token, string $media_id, array $input ): array|\WP_Error {
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
			return new \WP_Error( 'api_error', 'Instagram API request failed: ' . ( $result['error'] ?? 'unknown' ), array( 'status' => 500 ) );
		}

		$data      = json_decode( $result['data'], true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || isset( $data['error'] ) ) {
			$error_msg = $data['error']['message'] ?? 'Failed to fetch comments';
			return new \WP_Error( 'api_error', $error_msg, array( 'status' => 500 ) );
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
	 * Fetch ALL comments for a media item, auto-paginating through every page.
	 *
	 * Returns comments in the normalized SocialComment shape used across all
	 * platforms. This enables generic consumers (giveaway picker, CLI, pipelines)
	 * to work without platform-specific knowledge.
	 *
	 * @param string $access_token Valid access token.
	 * @param string $media_id     Instagram media ID.
	 * @return array Result with normalized comments.
	 */
	private function getAllComments( string $access_token, string $media_id ): array|\WP_Error {
		$all_comments = array();
		$after        = '';
		$page         = 0;
		$max_pages    = 200; // Safety limit: 200 pages × 50 comments = 10,000 comments max.

		do {
			$page++;
			$params = array(
				'fields'       => 'id,text,timestamp,username,like_count',
				'limit'        => 50, // Max per page for Instagram API.
				'access_token' => $access_token,
			);

			if ( ! empty( $after ) ) {
				$params['after'] = $after;
			}

			$url    = self::GRAPH_API_URL . "/{$media_id}/comments?" . http_build_query( $params );
			$result = HttpClient::get( $url, array( 'context' => 'Instagram Comments All' ) );

			if ( ! $result['success'] ) {
				// If we already have some comments, return them with a warning.
				if ( ! empty( $all_comments ) ) {
					return array(
						'success' => true,
						'data'    => array(
							'comments'  => $all_comments,
							'count'     => count( $all_comments ),
							'platform'  => 'instagram',
							'partial'   => true,
							'error'     => 'Pagination interrupted: ' . ( $result['error'] ?? 'unknown' ),
						),
					);
				}

				return new \WP_Error( 'api_error', 'Instagram API request failed: ' . ( $result['error'] ?? 'unknown' ), array( 'status' => 500 ) );
			}

			$data      = json_decode( $result['data'], true );
			$http_code = $result['status_code'];

			if ( 200 !== $http_code || isset( $data['error'] ) ) {
				if ( ! empty( $all_comments ) ) {
					return array(
						'success' => true,
						'data'    => array(
							'comments'  => $all_comments,
							'count'     => count( $all_comments ),
							'platform'  => 'instagram',
							'partial'   => true,
							'error'     => $data['error']['message'] ?? 'Pagination error',
						),
					);
				}

				return new \WP_Error( 'api_error', $data['error']['message'] ?? 'Failed to fetch comments', array( 'status' => 500 ) );
			}

			$page_comments = $data['data'] ?? array();

			foreach ( $page_comments as $comment ) {
				$all_comments[] = self::normalizeComment( $comment );
			}

			// Check for next page.
			$paging = $data['paging'] ?? array();
			$after  = $paging['cursors']['after'] ?? '';

			$has_next = ! empty( $paging['next'] ) && ! empty( $after );

		} while ( $has_next && $page < $max_pages );

		return array(
			'success' => true,
			'data'    => array(
				'comments'  => $all_comments,
				'count'     => count( $all_comments ),
				'platform'  => 'instagram',
				'partial'   => false,
				'pages'     => $page,
			),
		);
	}

	/**
	 * Normalize an Instagram comment into the generic SocialComment shape.
	 *
	 * Shape: { id, platform, author_username, text, timestamp, like_count,
	 *          reply_count, mentions, parent_id, raw }
	 *
	 * @param array $comment Raw Instagram API comment data.
	 * @return array Normalized comment.
	 */
	public static function normalizeComment( array $comment ): array|\WP_Error {
		$text     = $comment['text'] ?? '';
		$mentions = array();

		if ( preg_match_all( self::MENTION_REGEX, $text, $matches ) ) {
			$mentions = array_values( array_unique( $matches[1] ) );
		}

		return array(
			'id'              => $comment['id'] ?? '',
			'platform'        => 'instagram',
			'author_username' => $comment['username'] ?? '',
			'text'            => $text,
			'timestamp'       => $comment['timestamp'] ?? '',
			'like_count'      => (int) ( $comment['like_count'] ?? 0 ),
			'reply_count'     => 0, // Instagram top-level comments endpoint doesn't include reply count.
			'mentions'        => $mentions,
			'parent_id'       => null, // Top-level comments; replies would be fetched separately.
			'raw'             => $comment,
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
