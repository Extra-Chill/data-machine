<?php
/**
 * Facebook Read Ability
 *
 * Abilities API primitive for reading Facebook Page posts and comments.
 * Uses the page_access_token for page-level operations.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Facebook
 * @since      0.3.0
 */

namespace DataMachineSocials\Abilities\Facebook;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachineSocials\Handlers\Facebook\FacebookAuth;

defined( 'ABSPATH' ) || exit;

class FacebookReadAbility {

	private static bool $registered = false;

	const LIST_FIELDS = 'id,message,created_time,permalink_url,full_picture,shares,likes.summary(true),comments.summary(true)';

	const DETAIL_FIELDS = 'id,message,created_time,permalink_url,full_picture,shares,likes.summary(true),comments.summary(true),type,status_type';

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
				'datamachine/facebook-read',
				array(
					'label'               => __( 'Read Facebook Page Posts', 'data-machine-socials' ),
					'description'         => __( 'List recent Facebook Page posts or get details for a specific post', 'data-machine-socials' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'action'  => array(
								'type'        => 'string',
								'enum'        => array( 'list', 'get', 'comments' ),
								'default'     => 'list',
								'description' => __( 'Action: list (recent posts), get (single post), comments (post comments)', 'data-machine-socials' ),
							),
							'post_id' => array(
								'type'        => 'string',
								'description' => __( 'Facebook post ID (required for get and comments actions)', 'data-machine-socials' ),
							),
							'limit'   => array(
								'type'        => 'integer',
								'default'     => 25,
								'description' => __( 'Number of items to return (max 100)', 'data-machine-socials' ),
							),
							'after'   => array(
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

	public function checkPermission(): bool {
		return PermissionHelper::can( 'use_tools' );
	}

	public function execute( array $input ): array|\WP_Error {
		$action = $input['action'] ?? 'list';

		$auth = $this->getAuthProvider();
		if ( ! $auth ) {
			return new \WP_Error( 'missing_auth', 'Facebook auth provider not available', array( 'status' => 401 ) );
		}

		// Facebook uses page_access_token for page operations.
		$page_token = $auth->get_page_access_token();
		if ( empty( $page_token ) ) {
			return new \WP_Error( 'missing_auth', 'Facebook page access token unavailable (expired or refresh failed)', array( 'status' => 401 ) );
		}

		switch ( $action ) {
			case 'list':
				return $this->listPosts( $auth, $page_token, $input );

			case 'get':
				if ( empty( $input['post_id'] ) ) {
					return new \WP_Error( 'missing_param', 'post_id is required for the get action', array( 'status' => 400 ) );
				}
				return $this->getPost( $page_token, $input['post_id'] );

			case 'comments':
				if ( empty( $input['post_id'] ) ) {
					return new \WP_Error( 'missing_param', 'post_id is required for the comments action', array( 'status' => 400 ) );
				}
				return $this->getComments( $page_token, $input['post_id'], $input );

			default:
				return new \WP_Error( 'api_error', "Unknown action: {$action}. Use list, get, or comments.", array( 'status' => 500 ) );
		}
	}

	private function listPosts( FacebookAuth $auth, string $page_token, array $input ): array|\WP_Error {
		$page_id = $auth->get_page_id();
		if ( empty( $page_id ) ) {
			return new \WP_Error( 'missing_auth', 'Facebook Page ID not available. Try re-authenticating.', array( 'status' => 401 ) );
		}

		$limit  = min( absint( $input['limit'] ?? 25 ), 100 );
		$params = array(
			'fields'       => self::LIST_FIELDS,
			'limit'        => $limit,
			'access_token' => $page_token,
		);

		if ( ! empty( $input['after'] ) ) {
			$params['after'] = $input['after'];
		}

		$url    = FacebookAuth::GRAPH_API_URL . "/{$page_id}/feed?" . http_build_query( $params );
		$result = HttpClient::get( $url, array( 'context' => 'Facebook Read' ) );

		if ( ! $result['success'] ) {
			return new \WP_Error( 'api_error', 'Facebook API request failed: ' . ( $result['error'] ?? 'unknown' ), array( 'status' => 500 ) );
		}

		$data      = json_decode( $result['data'], true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || isset( $data['error'] ) ) {
			$error_msg = $data['error']['message'] ?? 'Failed to fetch Facebook posts';
			return new \WP_Error( 'api_error', $error_msg, array( 'status' => 500 ) );
		}

		$posts  = $data['data'] ?? array();
		$paging = $data['paging'] ?? array();

		return array(
			'success' => true,
			'data'    => array(
				'posts'    => $posts,
				'count'    => count( $posts ),
				'cursors'  => $paging['cursors'] ?? null,
				'has_next' => ! empty( $paging['next'] ),
			),
		);
	}

	private function getPost( string $page_token, string $post_id ): array|\WP_Error {
		$params = array(
			'fields'       => self::DETAIL_FIELDS,
			'access_token' => $page_token,
		);

		$url    = FacebookAuth::GRAPH_API_URL . "/{$post_id}?" . http_build_query( $params );
		$result = HttpClient::get( $url, array( 'context' => 'Facebook Read' ) );

		if ( ! $result['success'] ) {
			return new \WP_Error( 'api_error', 'Facebook API request failed: ' . ( $result['error'] ?? 'unknown' ), array( 'status' => 500 ) );
		}

		$data      = json_decode( $result['data'], true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || isset( $data['error'] ) ) {
			$error_msg = $data['error']['message'] ?? 'Failed to fetch Facebook post details';
			return new \WP_Error( 'api_error', $error_msg, array( 'status' => 500 ) );
		}

		return array(
			'success' => true,
			'data'    => $data,
		);
	}

	private function getComments( string $page_token, string $post_id, array $input ): array|\WP_Error {
		$limit  = min( absint( $input['limit'] ?? 25 ), 100 );
		$params = array(
			'fields'       => 'id,message,created_time,from,like_count',
			'limit'        => $limit,
			'access_token' => $page_token,
		);

		if ( ! empty( $input['after'] ) ) {
			$params['after'] = $input['after'];
		}

		$url    = FacebookAuth::GRAPH_API_URL . "/{$post_id}/comments?" . http_build_query( $params );
		$result = HttpClient::get( $url, array( 'context' => 'Facebook Read' ) );

		if ( ! $result['success'] ) {
			return new \WP_Error( 'api_error', 'Facebook API request failed: ' . ( $result['error'] ?? 'unknown' ), array( 'status' => 500 ) );
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

	private function getAuthProvider(): ?FacebookAuth {
		$auth_abilities = new \DataMachine\Abilities\AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'facebook' );

		if ( $provider instanceof FacebookAuth ) {
			return $provider;
		}

		return null;
	}
}
