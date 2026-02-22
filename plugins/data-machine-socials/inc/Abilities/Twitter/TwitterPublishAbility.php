<?php
/**
 * Twitter Publish Ability
 *
 * Primitive ability for publishing content to Twitter/X.
 *
 * @package DataMachineSocials\Abilities\Twitter
 * @since 0.1.0
 */

namespace DataMachineSocials\Abilities\Twitter;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\EngineData;

defined( 'ABSPATH' ) || exit;

/**
 * Twitter Publish Ability
 *
 * Self-contained ability class for Twitter publishing.
 */
class TwitterPublishAbility {

	/**
	 * Whether the ability has been registered.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	/**
	 * Register Twitter publish ability.
	 *
	 * @return void
	 */
	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/twitter-publish',
				array(
					'label' => __( 'Publish to Twitter', 'data-machine-socials' ),
					'description' => __( 'Post content to Twitter/X with optional media', 'data-machine-socials' ),
					'category' => 'datamachine',
					'input_schema' => array(
						'type' => 'object',
						'required' => array( 'content' ),
						'properties' => array(
							'content' => array(
								'type' => 'string',
								'description' => 'Tweet content (max 280 characters)',
								'maxLength' => 280,
							),
							'image_path' => array(
								'type' => 'string',
								'description' => 'Path to image file for upload',
							),
							'source_url' => array(
								'type' => 'string',
								'description' => 'Source URL to append/reply with',
								'format' => 'uri',
							),
							'link_handling' => array(
								'type' => 'string',
								'enum' => array( 'none', 'append', 'reply' ),
								'description' => 'How to handle source URL',
								'default' => 'append',
							),
						),
					),
					'output_schema' => array(
						'type' => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'tweet_id' => array( 'type' => 'string' ),
							'tweet_url' => array( 'type' => 'string', 'format' => 'uri' ),
							'reply_tweet_id' => array( 'type' => 'string' ),
							'reply_tweet_url' => array( 'type' => 'string', 'format' => 'uri' ),
							'error' => array( 'type' => 'string' ),
						),
					),
					'execute_callback' => array( self::class, 'execute_publish' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta' => array( 'show_in_rest' => true ),
				)
			);

			// Get account details ability
			wp_register_ability(
				'datamachine/twitter-account',
				array(
					'label' => __( 'Twitter Account Info', 'data-machine-socials' ),
					'description' => __( 'Get authenticated Twitter account details', 'data-machine-socials' ),
					'category' => 'datamachine',
					'input_schema' => array(
						'type' => 'object',
						'properties' => array(),
					),
					'output_schema' => array(
						'type' => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'screen_name' => array( 'type' => 'string' ),
							'user_id' => array( 'type' => 'string' ),
							'error' => array( 'type' => 'string' ),
						),
					),
					'execute_callback' => array( self::class, 'get_account' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta' => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute Twitter publish.
	 *
	 * @param array $input Ability input with publish parameters.
	 * @return array Response with tweet details or error.
	 */
	public static function execute_publish( array $input ): array {
		$content = $input['content'] ?? '';
		$image_path = $input['image_path'] ?? '';
		$source_url = $input['source_url'] ?? '';
		$link_handling = $input['link_handling'] ?? 'append';

		if ( empty( $content ) ) {
			return array(
				'success' => false,
				'error' => 'Content is required',
			);
		}

		$auth = new AuthAbilities();
		$provider = $auth->getProvider( 'twitter' );

		if ( ! $provider || ! $provider->is_authenticated() ) {
			return array(
				'success' => false,
				'error' => 'Twitter not authenticated',
			);
		}

		$connection = $provider->get_connection();
		if ( is_wp_error( $connection ) ) {
			return array(
				'success' => false,
				'error' => $connection->get_error_message(),
			);
		}

		// Format tweet text with link handling
		$tweet_text = self::format_tweet_text( $content, $source_url, $link_handling );

		try {
			$connection->setApiVersion( '2' );

			$v2_payload = array( 'text' => $tweet_text );
			$media_id = null;

			// Handle image upload if provided
			if ( ! empty( $image_path ) && file_exists( $image_path ) ) {
				$media_id = self::upload_media( $connection, $image_path );
				if ( ! $media_id ) {
					return array(
						'success' => false,
						'error' => 'Failed to upload image',
					);
				}
			}

			if ( $media_id ) {
				$v2_payload['media'] = array( 'media_ids' => array( $media_id ) );
			}

			$response = $connection->post( 'tweets', $v2_payload, array( 'json' => true ) );
			$http_code = $connection->getLastHttpCode();

			if ( 201 == $http_code && isset( $response->data->id ) ) {
				$tweet_id = $response->data->id;
				$account = $provider->get_account_details();
				$screen_name = $account['screen_name'] ?? 'twitter';
				$tweet_url = "https://twitter.com/{$screen_name}/status/{$tweet_id}";

				$result = array(
					'success' => true,
					'tweet_id' => $tweet_id,
					'tweet_url' => $tweet_url,
				);

				// Handle reply tweet for source URL
				if ( 'reply' === $link_handling && ! empty( $source_url ) ) {
					$reply = self::post_reply( $connection, $tweet_id, $source_url, $screen_name );
					if ( $reply['success'] ) {
						$result['reply_tweet_id'] = $reply['reply_tweet_id'];
						$result['reply_tweet_url'] = $reply['reply_tweet_url'];
					}
				}

				return $result;
			}

			$error_msg = 'Twitter API error';
			if ( isset( $response->title ) ) {
				$error_msg = $response->title;
			}

			return array(
				'success' => false,
				'error' => $error_msg,
			);
		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'error' => $e->getMessage(),
			);
		}
	}

	/**
	 * Get Twitter account details.
	 *
	 * @param array $input Ability input.
	 * @return array Account details or error.
	 */
	public static function get_account( array $input ): array {
		$auth = new AuthAbilities();
		$provider = $auth->getProvider( 'twitter' );

		if ( ! $provider || ! $provider->is_authenticated() ) {
			return array(
				'success' => false,
				'error' => 'Twitter not authenticated',
			);
		}

		$account = $provider->get_account_details();

		return array(
			'success' => true,
			'screen_name' => $account['screen_name'] ?? '',
			'user_id' => $account['user_id'] ?? '',
		);
	}

	/**
	 * Format tweet text with link handling.
	 *
	 * @param string $content Original content.
	 * @param string $source_url Source URL.
	 * @param string $link_handling How to handle URL.
	 * @return string Formatted tweet text.
	 */
	private static function format_tweet_text( string $content, string $source_url, string $link_handling ): string {
		$ellipsis = '…';
		$ellipsis_len = mb_strlen( $ellipsis, 'UTF-8' );

		$should_append_url = 'append' === $link_handling && ! empty( $source_url ) && filter_var( $source_url, FILTER_VALIDATE_URL );
		$link = $should_append_url ? ' ' . $source_url : '';
		$link_length = $link ? 24 : 0;
		$available_chars = 280 - $link_length;

		$tweet_text = $content;

		if ( $available_chars < $ellipsis_len ) {
			$tweet_text = mb_substr( $link, 0, 280 );
		} else {
			if ( mb_strlen( $tweet_text, 'UTF-8' ) > $available_chars ) {
				$tweet_text = mb_substr( $tweet_text, 0, $available_chars - $ellipsis_len ) . $ellipsis;
			}
			$tweet_text .= $link;
		}

		return trim( $tweet_text );
	}

	/**
	 * Upload media to Twitter.
	 *
	 * @param object $connection TwitterOAuth connection.
	 * @param string $image_path Path to image file.
	 * @return string|null Media ID or null on failure.
	 */
	private static function upload_media( $connection, string $image_path ): ?string {
		if ( ! file_exists( $image_path ) ) {
			return null;
		}

		$finfo = new \finfo( FILEINFO_MIME_TYPE );
		$mime_type = $finfo->file( $image_path );

		if ( ! in_array( $mime_type, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
			return null;
		}

		$file_size = filesize( $image_path );

		// INIT
		$connection->setApiVersion( '1.1' );
		$init_response = $connection->post(
			'media/upload',
			array(
				'command' => 'INIT',
				'media_type' => $mime_type,
				'media_category' => 'tweet_image',
				'total_bytes' => $file_size,
			)
		);

		if ( ! isset( $init_response->media_id_string ) ) {
			return null;
		}

		$media_id = $init_response->media_id_string;

		// APPEND
		$handle = fopen( $image_path, 'rb' );
		$segment_index = 0;
		$chunk_size = 1048576; // 1MB

		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, $chunk_size );
			$connection->post(
				'media/upload',
				array(
					'command' => 'APPEND',
					'media_id' => $media_id,
					'segment_index' => $segment_index,
					'media_data' => base64_encode( $chunk ),
				)
			);
			$segment_index++;
		}
		fclose( $handle );

		// FINALIZE
		$finalize_response = $connection->post(
			'media/upload',
			array(
				'command' => 'FINALIZE',
				'media_id' => $media_id,
			)
		);

		if ( isset( $finalize_response->media_id_string ) ) {
			$connection->setApiVersion( '2' );
			return $finalize_response->media_id_string;
		}

		return null;
	}

	/**
	 * Post reply tweet with source URL.
	 *
	 * @param object $connection TwitterOAuth connection.
	 * @param string $original_tweet_id Tweet to reply to.
	 * @param string $source_url URL to post.
	 * @param string $screen_name Twitter screen name.
	 * @return array Reply tweet details.
	 */
	private static function post_reply( $connection, string $original_tweet_id, string $source_url, string $screen_name ): array {
		try {
			$reply_payload = array(
				'text' => $source_url,
				'reply' => array(
					'in_reply_to_tweet_id' => $original_tweet_id,
				),
			);

			$response = $connection->post( 'tweets', $reply_payload, array( 'json' => true ) );
			$http_code = $connection->getLastHttpCode();

			if ( 201 == $http_code && isset( $response->data->id ) ) {
				$reply_tweet_id = $response->data->id;
				$reply_tweet_url = "https://twitter.com/{$screen_name}/status/{$reply_tweet_id}";

				return array(
					'success' => true,
					'reply_tweet_id' => $reply_tweet_id,
					'reply_tweet_url' => $reply_tweet_url,
				);
			}

			return array(
				'success' => false,
				'error' => 'Failed to post reply',
			);
		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'error' => $e->getMessage(),
			);
		}
	}
}
