<?php
/**
 * Threads Read Ability
 *
 * Abilities API primitive for reading Threads posts and replies.
 * Supports listing recent threads and fetching single thread details.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Threads
 * @since      0.3.0
 */

namespace DataMachineSocials\Abilities\Threads;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachineSocials\Handlers\Threads\ThreadsAuth;

defined( 'ABSPATH' ) || exit;

class ThreadsReadAbility {

	private static bool $registered = false;

	const API_URL = 'https://graph.threads.net/v1.0';

	const LIST_FIELDS = 'id,text,timestamp,media_type,media_url,permalink,like_count,is_quote_post';

	const DETAIL_FIELDS = 'id,text,timestamp,media_type,media_url,permalink,like_count,is_quote_post,shortcode';

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
				'datamachine/threads-read',
				array(
					'label'               => __( 'Read Threads Posts', 'data-machine-socials' ),
					'description'         => __( 'List recent Threads posts or get details for a specific thread', 'data-machine-socials' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'action'    => array(
								'type'        => 'string',
								'enum'        => array( 'list', 'get', 'replies' ),
								'default'     => 'list',
								'description' => __( 'Action: list (recent threads), get (single thread), replies (thread replies)', 'data-machine-socials' ),
							),
							'thread_id' => array(
								'type'        => 'string',
								'description' => __( 'Threads media ID (required for get and replies actions)', 'data-machine-socials' ),
							),
							'limit'     => array(
								'type'        => 'integer',
								'default'     => 25,
								'description' => __( 'Number of items to return (max 100)', 'data-machine-socials' ),
							),
							'after'     => array(
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
		return PermissionHelper::can_manage();
	}

	public function execute( array $input ): array|\WP_Error {
		$action = $input['action'] ?? 'list';

		$auth = $this->getAuthProvider();
		if ( ! $auth ) {
			return new \WP_Error( 'missing_auth', 'Threads auth provider not available', array( 'status' => 401 ) );
		}

		$access_token = $auth->get_valid_access_token();
		if ( empty( $access_token ) ) {
			return new \WP_Error( 'missing_auth', 'Threads access token unavailable (expired or refresh failed)', array( 'status' => 401 ) );
		}

		switch ( $action ) {
			case 'list':
				return $this->listThreads( $auth, $access_token, $input );

			case 'get':
				if ( empty( $input['thread_id'] ) ) {
					return new \WP_Error( 'missing_param', 'thread_id is required for the get action', array( 'status' => 400 ) );
				}
				return $this->getThread( $access_token, $input['thread_id'] );

			case 'replies':
				if ( empty( $input['thread_id'] ) ) {
					return new \WP_Error( 'missing_param', 'thread_id is required for the replies action', array( 'status' => 400 ) );
				}
				return $this->getReplies( $access_token, $input['thread_id'], $input );

			default:
				return new \WP_Error( 'api_error', "Unknown action: {$action}. Use list, get, or replies.", array( 'status' => 500 ) );
		}
	}

	private function listThreads( ThreadsAuth $auth, string $access_token, array $input ): array|\WP_Error {
		$user_id = $auth->get_page_id();
		if ( empty( $user_id ) ) {
			return new \WP_Error( 'missing_auth', 'Threads user ID not available. Try re-authenticating.', array( 'status' => 401 ) );
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

		$url    = self::API_URL . "/{$user_id}/threads?" . http_build_query( $params );
		$result = HttpClient::get( $url, array( 'context' => 'Threads Read' ) );

		if ( ! $result['success'] ) {
			return new \WP_Error( 'api_error', 'Threads API request failed: ' . ( $result['error'] ?? 'unknown' ), array( 'status' => 500 ) );
		}

		$data      = json_decode( $result['data'], true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || isset( $data['error'] ) ) {
			$error_msg = $data['error']['message'] ?? 'Failed to fetch Threads posts';
			return new \WP_Error( 'api_error', $error_msg, array( 'status' => 500 ) );
		}

		$threads = $data['data'] ?? array();
		$paging  = $data['paging'] ?? array();

		return array(
			'success' => true,
			'data'    => array(
				'threads'  => $threads,
				'count'    => count( $threads ),
				'cursors'  => $paging['cursors'] ?? null,
				'has_next' => ! empty( $paging['next'] ),
			),
		);
	}

	private function getThread( string $access_token, string $thread_id ): array|\WP_Error {
		$params = array(
			'fields'       => self::DETAIL_FIELDS,
			'access_token' => $access_token,
		);

		$url    = self::API_URL . "/{$thread_id}?" . http_build_query( $params );
		$result = HttpClient::get( $url, array( 'context' => 'Threads Read' ) );

		if ( ! $result['success'] ) {
			return new \WP_Error( 'api_error', 'Threads API request failed: ' . ( $result['error'] ?? 'unknown' ), array( 'status' => 500 ) );
		}

		$data      = json_decode( $result['data'], true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || isset( $data['error'] ) ) {
			$error_msg = $data['error']['message'] ?? 'Failed to fetch thread details';
			return new \WP_Error( 'api_error', $error_msg, array( 'status' => 500 ) );
		}

		return array(
			'success' => true,
			'data'    => $data,
		);
	}

	private function getReplies( string $access_token, string $thread_id, array $input ): array|\WP_Error {
		$limit  = min( absint( $input['limit'] ?? 25 ), 100 );
		$params = array(
			'fields'       => 'id,text,timestamp,username,like_count',
			'limit'        => $limit,
			'access_token' => $access_token,
		);

		if ( ! empty( $input['after'] ) ) {
			$params['after'] = $input['after'];
		}

		$url    = self::API_URL . "/{$thread_id}/replies?" . http_build_query( $params );
		$result = HttpClient::get( $url, array( 'context' => 'Threads Read' ) );

		if ( ! $result['success'] ) {
			return new \WP_Error( 'api_error', 'Threads API request failed: ' . ( $result['error'] ?? 'unknown' ), array( 'status' => 500 ) );
		}

		$data      = json_decode( $result['data'], true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || isset( $data['error'] ) ) {
			$error_msg = $data['error']['message'] ?? 'Failed to fetch replies';
			return new \WP_Error( 'api_error', $error_msg, array( 'status' => 500 ) );
		}

		$replies = $data['data'] ?? array();
		$paging  = $data['paging'] ?? array();

		return array(
			'success' => true,
			'data'    => array(
				'replies'  => $replies,
				'count'    => count( $replies ),
				'cursors'  => $paging['cursors'] ?? null,
				'has_next' => ! empty( $paging['next'] ),
			),
		);
	}

	private function getAuthProvider(): ?ThreadsAuth {
		$auth_abilities = new \DataMachine\Abilities\AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'threads' );

		if ( $provider instanceof ThreadsAuth ) {
			return $provider;
		}

		return null;
	}
}
