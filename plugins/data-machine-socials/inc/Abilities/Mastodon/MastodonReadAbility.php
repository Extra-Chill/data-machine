<?php
/**
 * Mastodon Read Ability
 *
 * Abilities API primitive for reading Mastodon statuses, threads, timelines,
 * hashtags, search results, and account profiles.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Mastodon
 * @since      0.17.0
 */

namespace DataMachineSocials\Abilities\Mastodon;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachineSocials\Handlers\Mastodon\MastodonAuth;
use DataMachineSocials\Abilities\AbstractSocialAbility;

defined( 'ABSPATH' ) || exit;

class MastodonReadAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/mastodon-read',
				array(
					'label'               => __( 'Read Mastodon', 'data-machine-socials' ),
					'description'         => __( 'Read statuses, threads, timelines, hashtags, search results, and account profiles on Mastodon', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'action'      => array(
								'type'        => 'string',
								'enum'        => array( 'list', 'get', 'context', 'profile', 'timeline', 'hashtag', 'search', 'notifications' ),
								'default'     => 'list',
								'description' => __( 'Action to perform', 'data-machine-socials' ),
							),
							'status_id'   => array(
								'type'        => 'string',
								'description' => __( 'Status ID (required for get and context)', 'data-machine-socials' ),
							),
							'account_id'  => array(
								'type'        => 'string',
								'description' => __( 'Account ID (for list by account, or profile)', 'data-machine-socials' ),
							),
							'timeline'    => array(
								'type'        => 'string',
								'enum'        => array( 'home', 'public' ),
								'default'     => 'home',
								'description' => __( 'Which timeline to read (timeline action)', 'data-machine-socials' ),
							),
							'tag'         => array(
								'type'        => 'string',
								'description' => __( 'Hashtag name without # (hashtag action)', 'data-machine-socials' ),
							),
							'query'       => array(
								'type'        => 'string',
								'description' => __( 'Search query (search action)', 'data-machine-socials' ),
							),
							'search_type' => array(
								'type'        => 'string',
								'enum'        => array( 'accounts', 'statuses', 'hashtags' ),
								'description' => __( 'Filter search to a specific type', 'data-machine-socials' ),
							),
							'limit'       => array(
								'type'        => 'integer',
								'default'     => 20,
								'description' => __( 'Number of results (max 40)', 'data-machine-socials' ),
							),
							'max_id'      => array(
								'type'        => 'string',
								'description' => __( 'Pagination: return results older than this ID', 'data-machine-socials' ),
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
		$action = $input['action'] ?? 'list';

		$provider = $this->getAuthProvider();
		if ( ! $provider ) {
			return new \WP_Error( 'missing_auth', 'Mastodon auth provider not available', array( 'status' => 401 ) );
		}

		$instance = $provider->get_instance();
		$token    = $provider->get_access_token();

		if ( empty( $instance ) || empty( $token ) ) {
			return new \WP_Error( 'missing_auth', 'Mastodon not configured', array( 'status' => 401 ) );
		}

		switch ( $action ) {
			case 'list':
				return $this->list_statuses( $instance, $token, $input );

			case 'get':
				if ( empty( $input['status_id'] ) ) {
					return new \WP_Error( 'missing_param', 'status_id is required for the get action', array( 'status' => 400 ) );
				}
				return $this->get_status( $instance, $token, $input['status_id'] );

			case 'context':
				if ( empty( $input['status_id'] ) ) {
					return new \WP_Error( 'missing_param', 'status_id is required for the context action', array( 'status' => 400 ) );
				}
				return $this->get_context( $instance, $token, $input['status_id'] );

			case 'profile':
				return $this->get_profile( $instance, $token, $input );

			case 'timeline':
				return $this->get_timeline( $instance, $token, $input );

			case 'hashtag':
				if ( empty( $input['tag'] ) ) {
					return new \WP_Error( 'missing_param', 'tag is required for the hashtag action', array( 'status' => 400 ) );
				}
				return $this->get_hashtag( $instance, $token, $input['tag'], $input );

			case 'search':
				if ( empty( $input['query'] ) ) {
					return new \WP_Error( 'missing_param', 'query is required for the search action', array( 'status' => 400 ) );
				}
				return $this->search( $instance, $token, $input );

			case 'notifications':
				return $this->get_notifications( $instance, $token, $input );

			default:
				return new \WP_Error( 'api_error', "Unknown action: {$action}", array( 'status' => 500 ) );
		}
	}

	/**
	 * List statuses for an account (defaults to the authenticated account).
	 */
	private function list_statuses( string $instance, string $token, array $input ): array|\WP_Error {
		$account_id = $input['account_id'] ?? '';

		if ( empty( $account_id ) ) {
			// Default to the authenticated account.
			$provider   = $this->getAuthProvider();
			$account_id = $provider ? $provider->get_account_id() : '';
		}

		if ( empty( $account_id ) ) {
			return new \WP_Error( 'missing_auth', 'Could not resolve account ID. Set account_id or verify credentials.', array( 'status' => 401 ) );
		}

		$limit = min( absint( $input['limit'] ?? 20 ), 40 );

		$params = array( 'limit' => $limit );
		if ( ! empty( $input['max_id'] ) ) {
			$params['max_id'] = $input['max_id'];
		}

		$url = $instance . '/api/v1/accounts/' . rawurlencode( $account_id ) . '/statuses?' . http_build_query( $params );

		return $this->fetch_collection( $url, $token, 'Mastodon Account Statuses', 'statuses' );
	}

	/**
	 * Get a single status by ID.
	 */
	private function get_status( string $instance, string $token, string $status_id ): array|\WP_Error {
		$url = $instance . '/api/v1/statuses/' . rawurlencode( $status_id );

		$result = $this->api_get( $url, $token, 'Mastodon Get Status' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'data'    => $result,
		);
	}

	/**
	 * Get the context (ancestors and descendants) of a status.
	 */
	private function get_context( string $instance, string $token, string $status_id ): array|\WP_Error {
		$url = $instance . '/api/v1/statuses/' . rawurlencode( $status_id ) . '/context';

		$result = $this->api_get( $url, $token, 'Mastodon Status Context' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'data'    => $result,
		);
	}

	/**
	 * Get account profile (own or by ID).
	 */
	private function get_profile( string $instance, string $token, array $input ): array|\WP_Error {
		$account_id = $input['account_id'] ?? '';

		if ( empty( $account_id ) ) {
			// Verify own credentials.
			$url = $instance . '/api/v1/accounts/verify_credentials';
		} else {
			$url = $instance . '/api/v1/accounts/' . rawurlencode( $account_id );
		}

		$result = $this->api_get( $url, $token, 'Mastodon Profile' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'data'    => $result,
		);
	}

	/**
	 * Read a timeline (home or public).
	 */
	private function get_timeline( string $instance, string $token, array $input ): array|\WP_Error {
		$timeline = $input['timeline'] ?? 'home';
		$timeline = in_array( $timeline, array( 'home', 'public' ), true ) ? $timeline : 'home';
		$limit    = min( absint( $input['limit'] ?? 20 ), 40 );

		$params = array( 'limit' => $limit );
		if ( ! empty( $input['max_id'] ) ) {
			$params['max_id'] = $input['max_id'];
		}

		$url = $instance . '/api/v1/timelines/' . $timeline . '?' . http_build_query( $params );

		return $this->fetch_collection( $url, $token, 'Mastodon Timeline', 'statuses' );
	}

	/**
	 * Read statuses for a hashtag.
	 */
	private function get_hashtag( string $instance, string $token, string $tag, array $input ): array|\WP_Error {
		$tag   = ltrim( $tag, '#' );
		$limit = min( absint( $input['limit'] ?? 20 ), 40 );

		$params = array( 'limit' => $limit );
		if ( ! empty( $input['max_id'] ) ) {
			$params['max_id'] = $input['max_id'];
		}

		$url = $instance . '/api/v1/timelines/tag/' . rawurlencode( $tag ) . '?' . http_build_query( $params );

		return $this->fetch_collection( $url, $token, 'Mastodon Hashtag', 'statuses' );
	}

	/**
	 * Search for accounts, statuses, or hashtags.
	 */
	private function search( string $instance, string $token, array $input ): array|\WP_Error {
		$query = $input['query'];
		$type  = $input['search_type'] ?? '';
		$limit = min( absint( $input['limit'] ?? 20 ), 40 );

		$params = array(
			'q'       => $query,
			'limit'   => $limit,
			'resolve' => 'true',
		);

		if ( ! empty( $type ) && in_array( $type, array( 'accounts', 'statuses', 'hashtags' ), true ) ) {
			$params['type'] = $type;
		}

		$url = $instance . '/api/v2/search?' . http_build_query( $params );

		$result = $this->api_get( $url, $token, 'Mastodon Search' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'data'    => $result,
		);
	}

	/**
	 * List notifications.
	 */
	private function get_notifications( string $instance, string $token, array $input ): array|\WP_Error {
		$limit = min( absint( $input['limit'] ?? 15 ), 80 );

		$params = array( 'limit' => $limit );
		if ( ! empty( $input['max_id'] ) ) {
			$params['max_id'] = $input['max_id'];
		}

		$url = $instance . '/api/v1/notifications?' . http_build_query( $params );

		return $this->fetch_collection( $url, $token, 'Mastodon Notifications', 'notifications' );
	}

	/**
	 * Fetch a collection endpoint and normalize the response.
	 */
	private function fetch_collection( string $url, string $token, string $context, string $key ): array|\WP_Error {
		$result = $this->api_get( $url, $token, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_array( $result ) ) {
			$result = array();
		}

		return array(
			'success' => true,
			'data'    => array(
				$key    => $result,
				'count' => count( $result ),
			),
		);
	}

	/**
	 * Perform an authenticated GET and return decoded JSON (array) or WP_Error.
	 *
	 * @return array|\WP_Error
	 */
	private function api_get( string $url, string $token, string $context ) {
		$result = HttpClient::get(
			$url,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'context' => $context,
				'timeout' => 20,
			)
		);

		if ( ! $result['success'] ) {
			$error_msg = $result['error'] ?? 'Mastodon API request failed';

			if ( ! empty( $result['data'] ) ) {
				$body = json_decode( $result['data'], true );
				if ( is_array( $body ) && isset( $body['error'] ) && is_string( $body['error'] ) ) {
					$error_msg = $body['error'];
				}
			}

			return new \WP_Error( 'api_error', $error_msg, array( 'status' => 500 ) );
		}

		$data = json_decode( $result['data'], true );

		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			return new \WP_Error( 'api_error', 'Invalid JSON response from Mastodon', array( 'status' => 500 ) );
		}

		return is_array( $data ) ? $data : array( $data );
	}

	private function getAuthProvider(): ?MastodonAuth {
		$auth_abilities = new \DataMachine\Abilities\AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'mastodon' );

		if ( $provider instanceof MastodonAuth ) {
			return $provider;
		}

		return null;
	}
}
