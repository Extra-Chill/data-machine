<?php
/**
 * Bluesky Read Ability
 *
 * Abilities API primitive for reading Bluesky posts via the AT Protocol.
 * Uses session-based authentication (app password).
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Bluesky
 * @since      0.3.0
 */

namespace DataMachineSocials\Abilities\Bluesky;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachineSocials\Handlers\Bluesky\BlueskyAuth;

defined( 'ABSPATH' ) || exit;

class BlueskyReadAbility {

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
				'datamachine/bluesky-read',
				array(
					'label'               => __( 'Read Bluesky Posts', 'data-machine-socials' ),
					'description'         => __( 'List recent Bluesky posts, get a post thread, or view profile', 'data-machine-socials' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'action'   => array(
								'type'        => 'string',
								'enum'        => array( 'list', 'get', 'profile' ),
								'default'     => 'list',
								'description' => __( 'Action: list (author feed), get (post thread), profile (user profile)', 'data-machine-socials' ),
							),
							'post_uri' => array(
								'type'        => 'string',
								'description' => __( 'AT Protocol post URI (required for get action, e.g. at://did:plc:.../app.bsky.feed.post/...)', 'data-machine-socials' ),
							),
							'limit'    => array(
								'type'        => 'integer',
								'default'     => 25,
								'description' => __( 'Number of posts to return (max 100)', 'data-machine-socials' ),
							),
							'cursor'   => array(
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
			return new \WP_Error( 'missing_auth', 'Bluesky auth provider not available', array( 'status' => 401 ) );
		}

		$session = $auth->get_session();
		if ( is_wp_error( $session ) ) {
			return new \WP_Error( 'api_error', 'Bluesky session creation failed: ' . $session->get_error_message(), array( 'status' => 500 ) );
		}

		if ( empty( $session['accessJwt'] ) ) {
			return new \WP_Error( 'missing_auth', 'Bluesky authentication failed (no access token in session)', array( 'status' => 401 ) );
		}

		$pds_url      = $session['pds_url'] ?? 'https://bsky.social';
		$access_token = $session['accessJwt'];
		$did          = $session['did'] ?? '';
		$handle       = $session['handle'] ?? '';

		switch ( $action ) {
			case 'list':
				return $this->listPosts( $pds_url, $access_token, $did ? $did : $handle, $input );

			case 'get':
				if ( empty( $input['post_uri'] ) ) {
					return new \WP_Error( 'missing_param', 'post_uri is required for the get action', array( 'status' => 400 ) );
				}
				return $this->getPostThread( $pds_url, $access_token, $input['post_uri'] );

			case 'profile':
				return $this->getProfile( $pds_url, $access_token, $did ? $did : $handle );

			default:
				return new \WP_Error( 'api_error', "Unknown action: {$action}. Use list, get, or profile.", array( 'status' => 500 ) );
		}
	}

	private function listPosts( string $pds_url, string $access_token, string $actor, array $input ): array|\WP_Error {
		if ( empty( $actor ) ) {
			return new \WP_Error( 'missing_auth', 'Bluesky DID/handle not available. Try re-authenticating.', array( 'status' => 401 ) );
		}

		$limit  = min( absint( $input['limit'] ?? 25 ), 100 );
		$params = array(
			'actor' => $actor,
			'limit' => $limit,
		);

		if ( ! empty( $input['cursor'] ) ) {
			$params['cursor'] = $input['cursor'];
		}

		$url    = $pds_url . '/xrpc/app.bsky.feed.getAuthorFeed?' . http_build_query( $params );
		$result = HttpClient::get(
			$url,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
				'context' => 'Bluesky Read',
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error( 'api_error', 'Bluesky API request failed: ' . ( $result['error'] ?? 'unknown' ), array( 'status' => 500 ) );
		}

		$data      = json_decode( $result['data'], true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || isset( $data['error'] ) ) {
			$error_msg = $data['message'] ?? $data['error'] ?? 'Failed to fetch Bluesky feed';
			return new \WP_Error( 'api_error', $error_msg, array( 'status' => 500 ) );
		}

		$feed   = $data['feed'] ?? array();
		$cursor = $data['cursor'] ?? null;

		return array(
			'success' => true,
			'data'    => array(
				'posts'    => $feed,
				'count'    => count( $feed ),
				'cursor'   => $cursor,
				'has_next' => ! empty( $cursor ),
			),
		);
	}

	private function getPostThread( string $pds_url, string $access_token, string $post_uri ): array|\WP_Error {
		$params = array(
			'uri'   => $post_uri,
			'depth' => 6,
		);

		$url    = $pds_url . '/xrpc/app.bsky.feed.getPostThread?' . http_build_query( $params );
		$result = HttpClient::get(
			$url,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
				'context' => 'Bluesky Read',
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error( 'api_error', 'Bluesky API request failed: ' . ( $result['error'] ?? 'unknown' ), array( 'status' => 500 ) );
		}

		$data      = json_decode( $result['data'], true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || isset( $data['error'] ) ) {
			$error_msg = $data['message'] ?? $data['error'] ?? 'Failed to fetch post thread';
			return new \WP_Error( 'api_error', $error_msg, array( 'status' => 500 ) );
		}

		return array(
			'success' => true,
			'data'    => $data['thread'] ?? $data,
		);
	}

	private function getProfile( string $pds_url, string $access_token, string $actor ): array|\WP_Error {
		$params = array( 'actor' => $actor );

		$url    = $pds_url . '/xrpc/app.bsky.actor.getProfile?' . http_build_query( $params );
		$result = HttpClient::get(
			$url,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
				'context' => 'Bluesky Read',
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error( 'api_error', 'Bluesky API request failed: ' . ( $result['error'] ?? 'unknown' ), array( 'status' => 500 ) );
		}

		$data      = json_decode( $result['data'], true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || isset( $data['error'] ) ) {
			$error_msg = $data['message'] ?? $data['error'] ?? 'Failed to fetch profile';
			return new \WP_Error( 'api_error', $error_msg, array( 'status' => 500 ) );
		}

		return array(
			'success' => true,
			'data'    => $data,
		);
	}

	private function getAuthProvider(): ?BlueskyAuth {
		$auth_abilities = new \DataMachine\Abilities\AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'bluesky' );

		if ( $provider instanceof BlueskyAuth ) {
			return $provider;
		}

		return null;
	}
}
