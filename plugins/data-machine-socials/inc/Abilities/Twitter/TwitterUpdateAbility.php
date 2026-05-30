<?php
/**
 * Twitter Update Ability
 *
 * Abilities API primitive for updating Twitter content.
 * Supports deleting tweets, retweeting, and liking tweets.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Twitter
 * @since      0.3.0
 */

namespace DataMachineSocials\Abilities\Twitter;

use DataMachine\Abilities\PermissionHelper;
use DataMachineSocials\Handlers\Twitter\TwitterAuth;
use DataMachineSocials\Abilities\AbstractSocialAbility;

defined( 'ABSPATH' ) || exit;

class TwitterUpdateAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/twitter-update',
				array(
					'label'               => __( 'Update Twitter', 'data-machine-socials' ),
					'description'         => __( 'Delete tweets, retweet, unretweet, like, or unlike tweets', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'action'   => array(
								'type'        => 'string',
								'enum'        => array( 'delete', 'retweet', 'unretweet', 'like', 'unlike' ),
								'description' => __( 'Action: delete (remove tweet), retweet, unretweet, like, unlike', 'data-machine-socials' ),
							),
							'tweet_id' => array(
								'type'        => 'string',
								'description' => __( 'Tweet ID to operate on', 'data-machine-socials' ),
							),
						),
						'required'   => array( 'action', 'tweet_id' ),
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
		return PermissionHelper::can( 'use_tools' );
	}

	/**
	 * Execute the update ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public function execute( array $input ): array|\WP_Error {
		$action = $input['action'] ?? '';

		// Get auth provider.
		$auth = $this->getAuthProvider();
		if ( ! $auth ) {
			return new \WP_Error( 'missing_auth', 'Twitter auth provider not available', array( 'status' => 401 ) );
		}

		$connection = $auth->get_connection();
		if ( ! $connection ) {
			return new \WP_Error( 'missing_auth', 'Twitter connection not available', array( 'status' => 401 ) );
		}

		if ( empty( $input['tweet_id'] ) ) {
			return new \WP_Error( 'missing_param', 'tweet_id is required', array( 'status' => 400 ) );
		}

		$tweet_id = $input['tweet_id'];

		switch ( $action ) {
			case 'delete':
				return $this->deleteTweet( $connection, $tweet_id );

			case 'retweet':
				return $this->retweet( $connection, $tweet_id );

			case 'unretweet':
				return $this->unretweet( $connection, $tweet_id );

			case 'like':
				return $this->likeTweet( $connection, $tweet_id );

			case 'unlike':
				return $this->unlikeTweet( $connection, $tweet_id );

			default:
				return new \WP_Error( 'api_error', "Unknown action: {$action}. Use delete, retweet, unretweet, like, or unlike.", array( 'status' => 500 ) );
		}
	}

	/**
	 * Get auth provider.
	 *
	 * @return TwitterAuth|null
	 */
	private function getAuthProvider(): ?TwitterAuth {
		if ( ! class_exists( '\DataMachine\Abilities\AuthAbilities' ) ) {
			return null;
		}

		$auth     = new \DataMachine\Abilities\AuthAbilities();
		$provider = $auth->getProvider( 'twitter' );

		if ( ! $provider instanceof TwitterAuth ) {
			return null;
		}

		return $provider;
	}

	/**
	 * Delete a tweet.
	 *
	 * @param object $connection TwitterOAuth connection.
	 * @param string $tweet_id   Tweet ID.
	 * @return array Result.
	 */
	private function deleteTweet( $connection, string $tweet_id ): array|\WP_Error {
		// Set API v2.
		$connection->setApiVersion( '2' );

		$result = $connection->delete( "tweets/{$tweet_id}", array() );

		$http_code = $connection->getLastHttpCode();

		if ( 200 === $http_code ) {
			return array(
				'success' => true,
				'data'    => array(
					'tweet_id' => $tweet_id,
					'deleted'  => true,
				),
			);
		}

		$error = $result['detail'] ?? $result['title'] ?? 'Failed to delete tweet';
		return new \WP_Error( 'api_error', $error, array( 'status' => 500 ) );
	}

	/**
	 * Retweet a tweet.
	 *
	 * @param object $connection TwitterOAuth connection.
	 * @param string $tweet_id   Tweet ID to retweet.
	 * @return array Result.
	 */
	private function retweet( $connection, string $tweet_id ): array|\WP_Error {
		// Need user ID for retweeting.
		$auth    = $this->getAuthProvider();
		$account = $auth->get_account_details();
		$user_id = $account['user_id'] ?? null;

		if ( ! $user_id ) {
			return new \WP_Error( 'missing_auth', 'User ID not available for retweet', array( 'status' => 401 ) );
		}

		$connection->setApiVersion( '2' );

		$result = $connection->post( "users/{$user_id}/retweets", array(
			'tweet_id' => $tweet_id,
		) );

		$http_code = $connection->getLastHttpCode();

		if ( 200 === $http_code ) {
			return array(
				'success' => true,
				'data'    => array(
					'tweet_id'     => $tweet_id,
					'retweeted'    => true,
					'retweet_data' => $result,
				),
			);
		}

		$error = $result['detail'] ?? $result['title'] ?? 'Failed to retweet';
		return new \WP_Error( 'api_error', $error, array( 'status' => 500 ) );
	}

	/**
	 * Unretweet a tweet.
	 *
	 * @param object $connection TwitterOAuth connection.
	 * @param string $tweet_id   Tweet ID to unretweet.
	 * @return array Result.
	 */
	private function unretweet( $connection, string $tweet_id ): array|\WP_Error {
		// Need user ID for unretweeting.
		$auth    = $this->getAuthProvider();
		$account = $auth->get_account_details();
		$user_id = $account['user_id'] ?? null;

		if ( ! $user_id ) {
			return new \WP_Error( 'missing_auth', 'User ID not available for unretweet', array( 'status' => 401 ) );
		}

		$connection->setApiVersion( '2' );

		// Unretweet is same as retweeting your own retweet.
		$result = $connection->delete( "users/{$user_id}/retweets/{$tweet_id}", array() );

		$http_code = $connection->getLastHttpCode();

		if ( 200 === $http_code ) {
			return array(
				'success' => true,
				'data'    => array(
					'tweet_id'    => $tweet_id,
					'unretweeted' => true,
				),
			);
		}

		$error = $result['detail'] ?? $result['title'] ?? 'Failed to unretweet';
		return new \WP_Error( 'api_error', $error, array( 'status' => 500 ) );
	}

	/**
	 * Like a tweet.
	 *
	 * @param object $connection TwitterOAuth connection.
	 * @param string $tweet_id   Tweet ID to like.
	 * @return array Result.
	 */
	private function likeTweet( $connection, string $tweet_id ): array|\WP_Error {
		// Need user ID for liking.
		$auth    = $this->getAuthProvider();
		$account = $auth->get_account_details();
		$user_id = $account['user_id'] ?? null;

		if ( ! $user_id ) {
			return new \WP_Error( 'missing_auth', 'User ID not available for like', array( 'status' => 401 ) );
		}

		$connection->setApiVersion( '2' );

		$result = $connection->post( "users/{$user_id}/likes", array(
			'tweet_id' => $tweet_id,
		) );

		$http_code = $connection->getLastHttpCode();

		if ( 200 === $http_code ) {
			return array(
				'success' => true,
				'data'    => array(
					'tweet_id' => $tweet_id,
					'liked'    => true,
				),
			);
		}

		$error = $result['detail'] ?? $result['title'] ?? 'Failed to like tweet';
		return new \WP_Error( 'api_error', $error, array( 'status' => 500 ) );
	}

	/**
	 * Unlike a tweet.
	 *
	 * @param object $connection TwitterOAuth connection.
	 * @param string $tweet_id   Tweet ID to unlike.
	 * @return array Result.
	 */
	private function unlikeTweet( $connection, string $tweet_id ): array|\WP_Error {
		// Need user ID for unliking.
		$auth    = $this->getAuthProvider();
		$account = $auth->get_account_details();
		$user_id = $account['user_id'] ?? null;

		if ( ! $user_id ) {
			return new \WP_Error( 'missing_auth', 'User ID not available for unlike', array( 'status' => 401 ) );
		}

		$connection->setApiVersion( '2' );

		$result = $connection->delete( "users/{$user_id}/likes/{$tweet_id}", array() );

		$http_code = $connection->getLastHttpCode();

		if ( 200 === $http_code ) {
			return array(
				'success' => true,
				'data'    => array(
					'tweet_id' => $tweet_id,
					'unliked'  => true,
				),
			);
		}

		$error = $result['detail'] ?? $result['title'] ?? 'Failed to unlike tweet';
		return new \WP_Error( 'api_error', $error, array( 'status' => 500 ) );
	}
}
