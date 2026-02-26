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

defined( 'ABSPATH' ) || exit;

class TwitterUpdateAbility {

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
				'datamachine/twitter-update',
				array(
					'label'               => __( 'Update Twitter', 'data-machine-socials' ),
					'description'         => __( 'Delete tweets, retweet, unretweet, like, or unlike tweets', 'data-machine-socials' ),
					'category'            => 'datamachine',
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
						'required' => array( 'action', 'tweet_id' ),
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
	 * Execute the update ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public function execute( array $input ): array {
		$action = $input['action'] ?? '';

		// Get auth provider.
		$auth = $this->getAuthProvider();
		if ( ! $auth ) {
			return array(
				'success' => false,
				'error'   => 'Twitter auth provider not available',
			);
		}

		$connection = $auth->get_connection();
		if ( ! $connection ) {
			return array(
				'success' => false,
				'error'   => 'Twitter connection not available',
			);
		}

		if ( empty( $input['tweet_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'tweet_id is required',
			);
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
				return array(
					'success' => false,
					'error'   => "Unknown action: {$action}. Use delete, retweet, unretweet, like, or unlike.",
				);
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

		$auth = new \DataMachine\Abilities\AuthAbilities();
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
	private function deleteTweet( $connection, string $tweet_id ): array {
		// Set API v2.
		$connection->setApiVersion( '2' );

		$result = $connection->delete( "tweets/{$tweet_id}", array() );

		$http_code = $connection->getLastHttpCode();

		if ( $http_code === 200 ) {
			return array(
				'success' => true,
				'data'    => array(
					'tweet_id' => $tweet_id,
					'deleted'  => true,
				),
			);
		}

		$error = $result['detail'] ?? $result['title'] ?? 'Failed to delete tweet';
		return array(
			'success' => false,
			'error'   => $error,
		);
	}

	/**
	 * Retweet a tweet.
	 *
	 * @param object $connection TwitterOAuth connection.
	 * @param string $tweet_id   Tweet ID to retweet.
	 * @return array Result.
	 */
	private function retweet( $connection, string $tweet_id ): array {
		// Need user ID for retweeting.
		$auth = $this->getAuthProvider();
		$account = $auth->get_account_details();
		$user_id = $account['user_id'] ?? null;

		if ( ! $user_id ) {
			return array(
				'success' => false,
				'error'   => 'User ID not available for retweet',
			);
		}

		$connection->setApiVersion( '2' );

		$result = $connection->post( "users/{$user_id}/retweets", array(
			'tweet_id' => $tweet_id,
		) );

		$http_code = $connection->getLastHttpCode();

		if ( $http_code === 200 ) {
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
		return array(
			'success' => false,
			'error'   => $error,
		);
	}

	/**
	 * Unretweet a tweet.
	 *
	 * @param object $connection TwitterOAuth connection.
	 * @param string $tweet_id   Tweet ID to unretweet.
	 * @return array Result.
	 */
	private function unretweet( $connection, string $tweet_id ): array {
		// Need user ID for unretweeting.
		$auth = $this->getAuthProvider();
		$account = $auth->get_account_details();
		$user_id = $account['user_id'] ?? null;

		if ( ! $user_id ) {
			return array(
				'success' => false,
				'error'   => 'User ID not available for unretweet',
			);
		}

		$connection->setApiVersion( '2' );

		// Unretweet is same as retweeting your own retweet.
		$result = $connection->delete( "users/{$user_id}/retweets/{$tweet_id}", array() );

		$http_code = $connection->getLastHttpCode();

		if ( $http_code === 200 ) {
			return array(
				'success' => true,
				'data'    => array(
					'tweet_id'      => $tweet_id,
					'unretweeted'   => true,
				),
			);
		}

		$error = $result['detail'] ?? $result['title'] ?? 'Failed to unretweet';
		return array(
			'success' => false,
			'error'   => $error,
		);
	}

	/**
	 * Like a tweet.
	 *
	 * @param object $connection TwitterOAuth connection.
	 * @param string $tweet_id   Tweet ID to like.
	 * @return array Result.
	 */
	private function likeTweet( $connection, string $tweet_id ): array {
		// Need user ID for liking.
		$auth = $this->getAuthProvider();
		$account = $auth->get_account_details();
		$user_id = $account['user_id'] ?? null;

		if ( ! $user_id ) {
			return array(
				'success' => false,
				'error'   => 'User ID not available for like',
			);
		}

		$connection->setApiVersion( '2' );

		$result = $connection->post( "users/{$user_id}/likes", array(
			'tweet_id' => $tweet_id,
		) );

		$http_code = $connection->getLastHttpCode();

		if ( $http_code === 200 ) {
			return array(
				'success' => true,
				'data'    => array(
					'tweet_id' => $tweet_id,
					'liked'    => true,
				),
			);
		}

		$error = $result['detail'] ?? $result['title'] ?? 'Failed to like tweet';
		return array(
			'success' => false,
			'error'   => $error,
		);
	}

	/**
	 * Unlike a tweet.
	 *
	 * @param object $connection TwitterOAuth connection.
	 * @param string $tweet_id   Tweet ID to unlike.
	 * @return array Result.
	 */
	private function unlikeTweet( $connection, string $tweet_id ): array {
		// Need user ID for unliking.
		$auth = $this->getAuthProvider();
		$account = $auth->get_account_details();
		$user_id = $account['user_id'] ?? null;

		if ( ! $user_id ) {
			return array(
				'success' => false,
				'error'   => 'User ID not available for unlike',
			);
		}

		$connection->setApiVersion( '2' );

		$result = $connection->delete( "users/{$user_id}/likes/{$tweet_id}", array() );

		$http_code = $connection->getLastHttpCode();

		if ( $http_code === 200 ) {
			return array(
				'success' => true,
				'data'    => array(
					'tweet_id'  => $tweet_id,
					'unliked'   => true,
				),
			);
		}

		$error = $result['detail'] ?? $result['title'] ?? 'Failed to unlike tweet';
		return array(
			'success' => false,
			'error'   => $error,
		);
	}
}
