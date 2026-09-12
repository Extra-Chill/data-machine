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

use DataMachine\Core\HttpClient;
use DataMachineSocials\PublishAuthorization;
use DataMachineSocials\Handlers\Facebook\FacebookAuth;
use DataMachineSocials\Handlers\Instagram\InstagramAuth;
use DataMachineSocials\Abilities\AbstractSocialAbility;

defined( 'ABSPATH' ) || exit;

class InstagramReadAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	/**
	 * Uses Facebook Graph API because our OAuth flow issues FB-flavored tokens.
	 * graph.instagram.com rejects these with "Cannot parse access token" (code 190).
	 *
	 * @see InstagramPublishAbility::GRAPH_API_URL for full rationale.
	 */
	const GRAPH_API_URL = 'https://graph.facebook.com/' . FacebookAuth::GRAPH_API_VERSION;

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

	/**
	 * Fields to request when listing direct-message conversations.
	 */
	const CONVERSATION_FIELDS = 'id,updated_time,participants,unread_count';

	/**
	 * Fields to request for messages in a conversation.
	 *
	 * Meta constraint: only the 20 most recent messages in a conversation
	 * have detail; older message IDs error as deleted.
	 */
	const MESSAGE_FIELDS = 'messages{id,created_time,from,to,message,attachments,is_unsupported}';

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
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
							'action'          => array(
								'type'        => 'string',
								'enum'        => array( 'list', 'get', 'comments', 'comments_all', 'conversations', 'messages' ),
								'default'     => 'list',
								'description' => __( 'Action: list (recent posts), get (single post), comments (one page), comments_all (all pages, normalized), conversations (DM threads), messages (messages in one DM thread)', 'data-machine-socials' ),
							),
							'media_id'        => array(
								'type'        => 'string',
								'description' => __( 'Instagram media ID (required for get and comments actions)', 'data-machine-socials' ),
							),
							'conversation_id' => array(
								'type'        => 'string',
								'description' => __( 'Instagram conversation ID (required for the messages action)', 'data-machine-socials' ),
							),
							'user_id'         => array(
								'type'        => 'string',
								'description' => __( 'Instagram-scoped user ID (IGSID) to fetch a single conversation thread (optional, conversations action only)', 'data-machine-socials' ),
							),
							'limit'           => array(
								'type'        => 'integer',
								'default'     => 25,
								'description' => __( 'Number of items to return (max 100)', 'data-machine-socials' ),
							),
							'after'           => array(
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
	}

	/**
	 * Permission callback.
	 *
	 * @return bool
	 */
	public function checkPermission(): bool {
		return PublishAuthorization::can_edit();
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

			case 'conversations':
				return $this->getConversations( $auth, $input );

			case 'messages':
				if ( empty( $input['conversation_id'] ) ) {
					return new \WP_Error( 'missing_param', 'conversation_id is required for the messages action', array( 'status' => 400 ) );
				}
				return $this->getMessages( $auth, $input['conversation_id'] );

			default:
				return new \WP_Error( 'api_error', "Unknown action: {$action}. Use list, get, comments, conversations, or messages.", array( 'status' => 500 ) );
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
			++$page;
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
							'comments' => $all_comments,
							'count'    => count( $all_comments ),
							'platform' => 'instagram',
							'partial'  => true,
							'error'    => 'Pagination interrupted: ' . ( $result['error'] ?? 'unknown' ),
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
							'comments' => $all_comments,
							'count'    => count( $all_comments ),
							'platform' => 'instagram',
							'partial'  => true,
							'error'    => $data['error']['message'] ?? 'Pagination error',
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
				'comments' => $all_comments,
				'count'    => count( $all_comments ),
				'platform' => 'instagram',
				'partial'  => false,
				'pages'    => $page,
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
	 * List direct-message conversations on the connected Instagram Business account.
	 *
	 * Uses the Facebook Graph Messaging API, which requires the Page access
	 * token and page_id stored on the account.
	 *
	 * @param InstagramAuth $auth  Auth provider.
	 * @param array         $input Input parameters.
	 * @return array Result with normalized conversations.
	 */
	private function getConversations( InstagramAuth $auth, array $input ): array|\WP_Error {
		$page = $auth->get_page_context();
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		$limit  = min( absint( $input['limit'] ?? 25 ), 100 );
		$params = array(
			'platform'     => 'instagram',
			'fields'       => self::CONVERSATION_FIELDS,
			'limit'        => $limit,
			'access_token' => $page['access_token'],
		);

		if ( ! empty( $input['user_id'] ) ) {
			$params['user_id'] = sanitize_text_field( (string) $input['user_id'] );
		}

		if ( ! empty( $input['after'] ) ) {
			$params['after'] = sanitize_text_field( (string) $input['after'] );
		}

		$url    = self::GRAPH_API_URL . '/' . rawurlencode( $page['id'] ) . '/conversations?' . http_build_query( $params );
		$result = HttpClient::get( $url, array( 'context' => 'Instagram Messages' ) );

		if ( ! $result['success'] ) {
			return new \WP_Error( 'api_error', 'Instagram API request failed: ' . ( $result['error'] ?? 'unknown' ), array( 'status' => 500 ) );
		}

		$data = json_decode( $result['data'], true );

		if ( 200 !== $result['status_code'] || isset( $data['error'] ) ) {
			$error_msg = $data['error']['message'] ?? 'Failed to fetch Instagram conversations';
			return new \WP_Error( 'api_error', $error_msg, array( 'status' => 500 ) );
		}

		$ig_user_id = (string) ( $auth->get_user_id() ?? '' );
		$items      = array();

		foreach ( $data['data'] ?? array() as $conversation ) {
			$items[] = self::normalizeConversation( $conversation, $ig_user_id );
		}

		$paging = $data['paging'] ?? array();

		return array(
			'success' => true,
			'data'    => array(
				'conversations' => $items,
				'count'         => count( $items ),
				'cursors'       => $paging['cursors'] ?? null,
				'has_next'      => ! empty( $paging['next'] ),
			),
		);
	}

	/**
	 * Get the messages in a single direct-message conversation.
	 *
	 * Meta constraint: only the 20 most recent messages in a conversation
	 * have detail; older message IDs error as deleted on Instagram.
	 *
	 * @param InstagramAuth $auth            Auth provider.
	 * @param string        $conversation_id Instagram conversation ID.
	 * @return array Result with normalized messages.
	 */
	private function getMessages( InstagramAuth $auth, string $conversation_id ): array|\WP_Error {
		$page = $auth->get_page_context();
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		$params = array(
			'fields'       => self::MESSAGE_FIELDS,
			'access_token' => $page['access_token'],
		);

		$url    = self::GRAPH_API_URL . '/' . rawurlencode( $conversation_id ) . '?' . http_build_query( $params );
		$result = HttpClient::get( $url, array( 'context' => 'Instagram Messages' ) );

		if ( ! $result['success'] ) {
			return new \WP_Error( 'api_error', 'Instagram API request failed: ' . ( $result['error'] ?? 'unknown' ), array( 'status' => 500 ) );
		}

		$data = json_decode( $result['data'], true );

		if ( 200 !== $result['status_code'] || isset( $data['error'] ) ) {
			$error_msg = $data['error']['message'] ?? 'Failed to fetch Instagram messages';
			return new \WP_Error( 'api_error', $error_msg, array( 'status' => 500 ) );
		}

		$ig_user_id = (string) ( $auth->get_user_id() ?? '' );
		$items      = array();

		foreach ( $data['messages']['data'] ?? array() as $message ) {
			$items[] = self::normalizeMessage( $message, $conversation_id, $ig_user_id );
		}

		return array(
			'success' => true,
			'data'    => array(
				'conversation_id' => $conversation_id,
				'messages'        => $items,
				'count'           => count( $items ),
			),
		);
	}


	/**
	 * Normalize an Instagram conversation into the generic conversation shape.
	 *
	 * Shape: { id, platform, participant: {id, username}, updated_time,
	 *          unread_count, raw }
	 *
	 * The participant is the external party: the first participant whose ID
	 * does not match the connected Instagram Business account.
	 *
	 * @param array  $conversation Raw Instagram API conversation data.
	 * @param string $ig_user_id   Connected Instagram Business account ID.
	 * @return array Normalized conversation.
	 */
	public static function normalizeConversation( array $conversation, string $ig_user_id = '' ): array {
		$participants = $conversation['participants']['data'] ?? array();
		$participant  = array();

		foreach ( $participants as $person ) {
			$person_id = (string) ( $person['id'] ?? '' );
			if ( '' === $person_id ) {
				continue;
			}
			if ( '' === $ig_user_id || $person_id !== $ig_user_id ) {
				$participant = array(
					'id'       => $person_id,
					'username' => (string) ( $person['username'] ?? '' ),
				);
				break;
			}
		}

		if ( empty( $participant ) && ! empty( $participants ) ) {
			$first       = $participants[0];
			$participant = array(
				'id'       => (string) ( $first['id'] ?? '' ),
				'username' => (string) ( $first['username'] ?? '' ),
			);
		}

		return array(
			'id'           => (string) ( $conversation['id'] ?? '' ),
			'platform'     => 'instagram',
			'participant'  => $participant,
			'updated_time' => (string) ( $conversation['updated_time'] ?? '' ),
			'unread_count' => (int) ( $conversation['unread_count'] ?? 0 ),
			'raw'          => $conversation,
		);
	}

	/**
	 * Normalize an Instagram direct message into the generic message shape.
	 *
	 * Shape: { id, platform, conversation_id, from: {id, username},
	 *          is_echo, text, attachments[], created_time, raw }
	 *
	 * @param array  $message         Raw Instagram API message data.
	 * @param string $conversation_id Parent conversation ID.
	 * @param string $ig_user_id      Connected Instagram Business account ID.
	 * @return array Normalized message.
	 */
	public static function normalizeMessage( array $message, string $conversation_id = '', string $ig_user_id = '' ): array {
		$from_id = (string) ( $message['from']['id'] ?? '' );

		return array(
			'id'              => (string) ( $message['id'] ?? '' ),
			'platform'        => 'instagram',
			'conversation_id' => $conversation_id,
			'from'            => array(
				'id'       => $from_id,
				'username' => (string) ( $message['from']['username'] ?? $message['from']['name'] ?? '' ),
			),
			'is_echo'         => ! empty( $message['is_echo'] ) || ( '' !== $ig_user_id && $from_id === $ig_user_id ),
			'text'            => (string) ( $message['message'] ?? '' ),
			'attachments'     => array_values( $message['attachments']['data'] ?? array() ),
			'created_time'    => (string) ( $message['created_time'] ?? '' ),
			'raw'             => $message,
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
