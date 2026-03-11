<?php
/**
 * Twitter Read Ability
 *
 * Abilities API primitive for reading tweets via Twitter API v2.
 * Uses the TwitterOAuth library for authenticated requests.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Twitter
 * @since      0.3.0
 */

namespace DataMachineSocials\Abilities\Twitter;

use DataMachine\Abilities\PermissionHelper;
use DataMachineSocials\Handlers\Twitter\TwitterAuth;

defined( 'ABSPATH' ) || exit;

class TwitterReadAbility {

	private static bool $registered = false;

	const TWEET_FIELDS = 'id,text,created_at,public_metrics,source,lang,conversation_id';

	const USER_FIELDS = 'id,name,username,profile_image_url,public_metrics,description';

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
				'datamachine/twitter-read',
				array(
					'label'               => __( 'Read Tweets', 'data-machine-socials' ),
					'description'         => __( 'List recent tweets, get a specific tweet, or get mentions', 'data-machine-socials' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'action'           => array(
								'type'        => 'string',
								'enum'        => array( 'list', 'get', 'mentions' ),
								'default'     => 'list',
								'description' => __( 'Action: list (user timeline), get (single tweet), mentions (user mentions)', 'data-machine-socials' ),
							),
							'tweet_id'         => array(
								'type'        => 'string',
								'description' => __( 'Tweet ID (required for get action)', 'data-machine-socials' ),
							),
							'limit'            => array(
								'type'        => 'integer',
								'default'     => 25,
								'description' => __( 'Number of tweets to return (max 100)', 'data-machine-socials' ),
							),
							'pagination_token' => array(
								'type'        => 'string',
								'description' => __( 'Pagination token for next page', 'data-machine-socials' ),
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

	public function execute( array $input ): array {
		$action = $input['action'] ?? 'list';

		$auth = $this->getAuthProvider();
		if ( ! $auth ) {
			return array(
				'success' => false,
				'error'   => 'Twitter auth provider not available',
			);
		}

		$connection = $auth->get_connection();
		if ( is_wp_error( $connection ) ) {
			return array(
				'success' => false,
				'error'   => 'Twitter connection failed: ' . $connection->get_error_message(),
			);
		}

		$connection->setApiVersion( '2' );

		switch ( $action ) {
			case 'list':
				return $this->listTweets( $auth, $connection, $input );

			case 'get':
				if ( empty( $input['tweet_id'] ) ) {
					return array(
						'success' => false,
						'error'   => 'tweet_id is required for the get action',
					);
				}
				return $this->getTweet( $connection, $input['tweet_id'] );

			case 'mentions':
				return $this->getMentions( $auth, $connection, $input );

			default:
				return array(
					'success' => false,
					'error'   => "Unknown action: {$action}. Use list, get, or mentions.",
				);
		}
	}

	private function listTweets( TwitterAuth $auth, $connection, array $input ): array {
		$account = $auth->get_account_details();
		$user_id = $account['user_id'] ?? null;
		if ( empty( $user_id ) ) {
			return array(
				'success' => false,
				'error'   => 'Twitter user ID not available. Try re-authenticating.',
			);
		}

		$limit  = min( absint( $input['limit'] ?? 25 ), 100 );
		$params = array(
			'tweet.fields' => self::TWEET_FIELDS,
			'max_results'  => max( $limit, 5 ), // Twitter API v2 minimum is 5.
		);

		if ( ! empty( $input['pagination_token'] ) ) {
			$params['pagination_token'] = $input['pagination_token'];
		}

		$response  = $connection->get( "users/{$user_id}/tweets", $params );
		$http_code = $connection->getLastHttpCode();

		if ( 200 !== $http_code ) {
			$error_msg = $this->extractError( $response, 'Failed to fetch tweets' );
			return array(
				'success' => false,
				'error'   => $error_msg,
			);
		}

		$response = (array) $response;
		$tweets   = isset( $response['data'] ) ? array_map( function ( $t ) { return (array) $t;
		}, $response['data'] ) : array();
		$meta     = isset( $response['meta'] ) ? (array) $response['meta'] : array();

		return array(
			'success' => true,
			'data'    => array(
				'tweets'       => $tweets,
				'count'        => count( $tweets ),
				'next_token'   => $meta['next_token'] ?? null,
				'has_next'     => ! empty( $meta['next_token'] ),
				'result_count' => $meta['result_count'] ?? count( $tweets ),
			),
		);
	}

	private function getTweet( $connection, string $tweet_id ): array {
		$params = array(
			'tweet.fields' => self::TWEET_FIELDS,
			'expansions'   => 'author_id',
			'user.fields'  => self::USER_FIELDS,
		);

		$response  = $connection->get( "tweets/{$tweet_id}", $params );
		$http_code = $connection->getLastHttpCode();

		if ( 200 !== $http_code ) {
			$error_msg = $this->extractError( $response, 'Failed to fetch tweet' );
			return array(
				'success' => false,
				'error'   => $error_msg,
			);
		}

		$response = (array) $response;
		$data     = isset( $response['data'] ) ? (array) $response['data'] : array();

		// Flatten public_metrics if present.
		if ( isset( $data['public_metrics'] ) ) {
			$data['public_metrics'] = (array) $data['public_metrics'];
		}

		return array(
			'success' => true,
			'data'    => $data,
		);
	}

	private function getMentions( TwitterAuth $auth, $connection, array $input ): array {
		$account = $auth->get_account_details();
		$user_id = $account['user_id'] ?? null;
		if ( empty( $user_id ) ) {
			return array(
				'success' => false,
				'error'   => 'Twitter user ID not available. Try re-authenticating.',
			);
		}

		$limit  = min( absint( $input['limit'] ?? 25 ), 100 );
		$params = array(
			'tweet.fields' => self::TWEET_FIELDS,
			'max_results'  => max( $limit, 5 ),
			'expansions'   => 'author_id',
			'user.fields'  => 'id,name,username',
		);

		if ( ! empty( $input['pagination_token'] ) ) {
			$params['pagination_token'] = $input['pagination_token'];
		}

		$response  = $connection->get( "users/{$user_id}/mentions", $params );
		$http_code = $connection->getLastHttpCode();

		if ( 200 !== $http_code ) {
			$error_msg = $this->extractError( $response, 'Failed to fetch mentions' );
			return array(
				'success' => false,
				'error'   => $error_msg,
			);
		}

		$response = (array) $response;
		$tweets   = isset( $response['data'] ) ? array_map( function ( $t ) { return (array) $t;
		}, $response['data'] ) : array();
		$meta     = isset( $response['meta'] ) ? (array) $response['meta'] : array();

		return array(
			'success' => true,
			'data'    => array(
				'mentions'     => $tweets,
				'count'        => count( $tweets ),
				'next_token'   => $meta['next_token'] ?? null,
				'has_next'     => ! empty( $meta['next_token'] ),
				'result_count' => $meta['result_count'] ?? count( $tweets ),
			),
		);
	}

	/**
	 * Extract error message from Twitter API response.
	 *
	 * @param mixed  $response API response (stdClass or array).
	 * @param string $fallback Fallback message.
	 * @return string
	 */
	private function extractError( $response, string $fallback ): string {
		$response = (array) $response;

		if ( isset( $response['detail'] ) ) {
			return $response['detail'];
		}

		if ( isset( $response['errors'] ) && is_array( $response['errors'] ) ) {
			$first = (array) $response['errors'][0];
			return $first['message'] ?? $fallback;
		}

		if ( isset( $response['title'] ) ) {
			return $response['title'];
		}

		return $fallback;
	}

	private function getAuthProvider(): ?TwitterAuth {
		$auth_abilities = new \DataMachine\Abilities\AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'twitter' );

		if ( $provider instanceof TwitterAuth ) {
			return $provider;
		}

		return null;
	}
}
