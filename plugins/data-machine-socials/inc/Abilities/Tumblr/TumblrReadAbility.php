<?php
/**
 * Tumblr Read Ability
 *
 * Abilities API primitive for reading Tumblr content and discovering tagged
 * posts. Supports listing a blog's posts, fetching a single post, retrieving
 * blog info, and tag-based discovery via the `/tagged` endpoint.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Tumblr
 * @since      0.17.0
 */

namespace DataMachineSocials\Abilities\Tumblr;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachineSocials\Handlers\Tumblr\TumblrAuth;
use DataMachineSocials\Abilities\AbstractSocialAbility;

defined( 'ABSPATH' ) || exit;

class TumblrReadAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	const API_URL = 'https://api.tumblr.com/v2';

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/tumblr-read',
				array(
					'label'               => __( 'Read Tumblr Posts', 'data-machine-socials' ),
					'description'         => __( 'List a blog\'s posts, get a single post, get blog info, or discover tagged posts. Tagged discovery surfaces public posts across Tumblr for a given tag.', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'action'          => array(
								'type'        => 'string',
								'enum'        => array( 'posts', 'post', 'info', 'tagged' ),
								'default'     => 'posts',
								'description' => __( 'Action: posts (blog posts), post (single post), info (blog info), tagged (discover posts by tag across Tumblr)', 'data-machine-socials' ),
							),
							'blog_identifier' => array(
								'type'        => 'string',
								'description' => __( 'Tumblr blog identifier (required for posts, post, info)', 'data-machine-socials' ),
							),
							'post_id'         => array(
								'type'        => 'string',
								'description' => __( 'Post ID (required for post action)', 'data-machine-socials' ),
							),
							'tag'             => array(
								'type'        => 'string',
								'description' => __( 'Tag to discover (required for tagged action)', 'data-machine-socials' ),
							),
							'before'          => array(
								'type'        => 'integer',
								'description' => __( 'Unix timestamp — return posts older than this (pagination). Used by posts and tagged.', 'data-machine-socials' ),
							),
							'limit'           => array(
								'type'        => 'integer',
								'default'     => 20,
								'description' => __( 'Number of items to return (tagged max 20; posts max 100)', 'data-machine-socials' ),
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

	public function checkPermission(): bool {
		return PermissionHelper::can( 'use_tools' );
	}

	public function execute( array $input ): array|\WP_Error {
		$action = $input['action'] ?? 'posts';

		$auth = $this->getAuthProvider();
		if ( ! $auth ) {
			return new \WP_Error( 'missing_auth', 'Tumblr auth provider not available', array( 'status' => 401 ) );
		}

		$token = $auth->get_valid_access_token();
		if ( empty( $token ) ) {
			return new \WP_Error( 'missing_auth', 'Tumblr access token is missing or expired — re-authorize in WP Admin > Data Machine > Settings', array( 'status' => 401 ) );
		}

		switch ( $action ) {
			case 'posts':
				if ( empty( $input['blog_identifier'] ) ) {
					return new \WP_Error( 'missing_param', 'blog_identifier is required for the posts action', array( 'status' => 400 ) );
				}
				return $this->listPosts( $token, $input );

			case 'post':
				if ( empty( $input['blog_identifier'] ) ) {
					return new \WP_Error( 'missing_param', 'blog_identifier is required for the post action', array( 'status' => 400 ) );
				}
				if ( empty( $input['post_id'] ) ) {
					return new \WP_Error( 'missing_param', 'post_id is required for the post action', array( 'status' => 400 ) );
				}
				return $this->getPost( $token, $input['blog_identifier'], $input['post_id'] );

			case 'info':
				if ( empty( $input['blog_identifier'] ) ) {
					return new \WP_Error( 'missing_param', 'blog_identifier is required for the info action', array( 'status' => 400 ) );
				}
				return $this->getInfo( $token, $input['blog_identifier'] );

			case 'tagged':
				if ( empty( $input['tag'] ) ) {
					return new \WP_Error( 'missing_param', 'tag is required for the tagged action', array( 'status' => 400 ) );
				}
				return $this->getTagged( $token, $input );

			default:
				return new \WP_Error( 'api_error', "Unknown action: {$action}. Use posts, post, info, or tagged.", array( 'status' => 500 ) );
		}
	}

	private function listPosts( string $token, array $input ): array|\WP_Error {
		$params = array(
			'limit' => min( max( absint( $input['limit'] ?? 20 ), 1 ), 100 ),
			'npf'   => 'true',
		);
		if ( ! empty( $input['before'] ) ) {
			$params['before'] = absint( $input['before'] );
		}

		$url = self::API_URL . '/blog/' . rawurlencode( $input['blog_identifier'] ) . '/posts?' . http_build_query( $params );

		return $this->apiGet( $token, $url, 'posts' );
	}

	private function getPost( string $token, string $blog_identifier, string $post_id ): array|\WP_Error {
		$url    = self::API_URL . '/blog/' . rawurlencode( $blog_identifier ) . '/posts/' . rawurlencode( $post_id ) . '?post_format=npf';
		$result = HttpClient::get(
			$url,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'context' => 'Tumblr Read',
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error( 'api_error', 'Tumblr API request failed: ' . ( $result['error'] ?? 'unknown' ), array( 'status' => 500 ) );
		}

		$data      = json_decode( $result['data'], true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || empty( $data['response'] ) ) {
			$error_msg = $data['meta']['msg'] ?? 'Failed to fetch post';
			return new \WP_Error( 'api_error', $error_msg, array( 'status' => 500 ) );
		}

		return array(
			'success' => true,
			'data'    => $data['response'],
		);
	}

	private function getInfo( string $token, string $blog_identifier ): array|\WP_Error {
		$url = self::API_URL . '/blog/' . rawurlencode( $blog_identifier ) . '/info';

		return $this->apiGet( $token, $url, 'blog' );
	}

	private function getTagged( string $token, array $input ): array|\WP_Error {
		$params = array(
			'tag'   => $input['tag'],
			'limit' => min( max( absint( $input['limit'] ?? 20 ), 1 ), 20 ),
		);
		if ( ! empty( $input['before'] ) ) {
			$params['before'] = absint( $input['before'] );
		}

		$url = self::API_URL . '/tagged?' . http_build_query( $params );

		return $this->apiGet( $token, $url, 'posts' );
	}

	/**
	 * Generic Tumblr GET request against the standard {meta,response} envelope.
	 *
	 * @param string $token Bearer token.
	 * @param string $url   Full API URL with query string.
	 * @param string $data_key Key under response to return (e.g. 'posts', 'blog').
	 * @return array|\WP_Error
	 */
	private function apiGet( string $token, string $url, string $data_key ): array|\WP_Error {
		$result = HttpClient::get(
			$url,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'context' => 'Tumblr Read',
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error( 'api_error', 'Tumblr API request failed: ' . ( $result['error'] ?? 'unknown' ), array( 'status' => 500 ) );
		}

		$data      = json_decode( $result['data'], true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || ! isset( $data['response'] ) ) {
			$error_msg = $data['meta']['msg'] ?? 'Tumblr API error';
			return new \WP_Error( 'api_error', $error_msg, array( 'status' => 500 ) );
		}

		$response = $data['response'];
		$items    = $response[ $data_key ] ?? array();

		/*
		 * Normalize a tagged/posts list: response.posts is the array for list endpoints,
		 * but for blog info response.blog is the object. Detect accordingly.
		 */
		if ( 'blog' === $data_key ) {
			return array(
				'success' => true,
				'data'    => array(
					'blog' => $items,
				),
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'posts' => $items,
				'count' => count( $items ),
				'total' => $response['total_posts'] ?? count( $items ),
			),
		);
	}

	private function getAuthProvider(): ?TumblrAuth {
		$auth_abilities = new \DataMachine\Abilities\AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'tumblr' );

		if ( $provider instanceof TumblrAuth ) {
			return $provider;
		}

		return null;
	}
}
