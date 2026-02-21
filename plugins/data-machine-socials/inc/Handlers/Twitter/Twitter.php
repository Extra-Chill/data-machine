<?php
/**
 * Twitter publishing handler with OAuth 1.0a and AI tool integration.
 *
 * Handles posting content to Twitter with support for:
 * - Text content (280 character limit)
 * - Media uploads (images)
 * - URL handling and link shortening
 * - Thread creation for long content
 *
 * @package DataMachineSocials\Handlers\Twitter
 */

namespace DataMachineSocials\Handlers\Twitter;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Core\EngineData;
use DataMachine\Core\Steps\Publish\Handlers\PublishHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Twitter Publishing Handler
 *
 * Publishes content to Twitter via OAuth 1.0a authentication.
 * Supports media uploads and handles URL shortening automatically.
 */
class Twitter extends PublishHandler {

	use HandlerRegistrationTrait;

	/** @var TwitterAuth OAuth authentication handler */
	private $auth;

	public function __construct() {
		parent::__construct( 'twitter' );

		// Self-register with filters
		self::registerHandler(
			'twitter_publish',
			'publish',
			self::class,
			'Twitter',
			'Post content to Twitter with media support',
			true,
			TwitterAuth::class,
			TwitterSettings::class,
			function ( $tools, $handler_slug, $handler_config ) {
				if ( 'twitter_publish' === $handler_slug ) {
					$tools['twitter_publish'] = array(
						'class' => self::class,
						'method' => 'handle_tool_call',
						'handler' => 'twitter_publish',
						'description' => 'Post content to Twitter. Supports text (280 chars), images, and URL handling.',
						'parameters' => array(
							'type' => 'object',
							'properties' => array(
								'content' => array(
									'type' => 'string',
									'description' => 'The text content to post to Twitter',
								),
							),
							'required' => array( 'content' ),
						),
					);
				}
				return $tools;
			},
			'twitter'
		);
	}

	/**
	 * Lazy-load auth provider when needed.
	 *
	 * @return TwitterAuth|null Auth provider instance or null if unavailable
	 */
	private function get_auth() {
		if ( $this->auth === null ) {
			$auth_abilities = new AuthAbilities();
			$this->auth = $auth_abilities->getProvider( 'twitter' );

			if ( $this->auth === null ) {
				$this->log(
					'error',
					'Twitter Handler: Authentication service not available',
					array(
						'handler' => 'twitter',
						'missing_service' => 'twitter',
						'available_providers' => array_keys( $auth_abilities->getAllProviders() ),
					)
				);
			}
		}
		return $this->auth;
	}

	/**
	 * Execute Twitter publishing.
	 *
	 * @param array $parameters Tool parameters including content and configuration
	 * @param array $handler_config Handler configuration
	 * @return array {
	 * @type bool $success Whether the post was successful
	 * @type string $error Error message if failed
	 * @type string $tool_name Tool identifier
	 * @type string $url Twitter post URL if successful
	 * @type string $id Twitter post ID if successful
	 * }
	 */
	protected function executePublish( array $parameters, array $handler_config ): array {
		// handler_config is ALWAYS flat structure - no nesting
		$content = $parameters['content'] ?? '';

		$engine = $parameters['engine'] ?? null;
		if ( ! $engine instanceof EngineData ) {
			$engine = new EngineData( $parameters['engine_data'] ?? array(), $parameters['job_id'] ?? null );
		}
		$source_url = $engine->getSourceUrl();
		$image_file_path = $engine->getImagePath();

		$include_images = $handler_config['include_images'] ?? false;
		$link_handling = $handler_config['link_handling'] ?? 'append';

		$auth = $this->get_auth();
		if ( ! $auth ) {
			return $this->errorResponse( 'Twitter authentication not configured', array(), 'critical' );
		}

		$connection = $auth->get_connection();
		if ( is_wp_error( $connection ) ) {
			return $this->errorResponse(
				'Twitter authentication failed: ' . $connection->get_error_message(),
				array( 'error_code' => $connection->get_error_code() ),
				'critical'
			);
		}

		$tweet_text = $content;
		$ellipsis = '…';
		$ellipsis_len = mb_strlen( $ellipsis, 'UTF-8' );

		$should_append_url = 'append' === $link_handling && ! empty( $source_url ) && filter_var( $source_url, FILTER_VALIDATE_URL );
		$link = $should_append_url ? ' ' . $source_url : '';
		$link_length = $link ? 24 : 0;
		$available_chars = 280 - $link_length;

		if ( $available_chars < $ellipsis_len ) {
			$tweet_text = mb_substr( $link, 0, 280 );
		} else {
			if ( mb_strlen( $tweet_text, 'UTF-8' ) > $available_chars ) {
				$tweet_text = mb_substr( $tweet_text, 0, $available_chars - $ellipsis_len ) . $ellipsis;
			}
			$tweet_text .= $link;
		}
		$tweet_text = trim( $tweet_text );

		try {
			$connection->setApiVersion( '2' );

			$v2_payload = array( 'text' => $tweet_text );
			$media_id = null;

			if ( $include_images && ! empty( $image_file_path ) ) {
				$validation = $this->validateImage( $image_file_path );

				if ( ! $validation['valid'] ) {
					return $this->errorResponse(
						implode( ', ', $validation['errors'] ),
						array(
							'file_path' => $image_file_path,
							'errors' => $validation['errors'],
						)
					);
				}

				$media_id = $this->upload_image_file_to_twitter( $connection, $image_file_path, substr( $content, 0, 50 ) );
				if ( ! $media_id ) {
					return $this->errorResponse( 'Failed to upload image' );
				}
			}

			if ( $media_id ) {
				$v2_payload['media'] = array( 'media_ids' => array( $media_id ) );
			}

			$response = $connection->post( 'tweets', $v2_payload, array( 'json' => true ) );
			$http_code = $connection->getLastHttpCode();

			if ( 201 == $http_code && isset( $response->data->id ) ) {
				$tweet_id = $response->data->id;
				$account_details = $auth->get_account_details();
				$screen_name = $account_details['screen_name'] ?? 'twitter';
				$tweet_url = "https://twitter.com/{$screen_name}/status/{$tweet_id}";

				$reply_result = null;
				if ( 'reply' === $link_handling && ! empty( $source_url ) && filter_var( $source_url, FILTER_VALIDATE_URL ) ) {
					$reply_result = $this->post_reply_tweet( $connection, $tweet_id, $source_url, $screen_name );
				}

				$result_data = array(
					'tweet_id' => $tweet_id,
					'tweet_url' => $tweet_url,
					'content' => $tweet_text,
				);

				if ( $reply_result && $reply_result['success'] ) {
					$result_data['reply_tweet_id'] = $reply_result['reply_tweet_id'];
					$result_data['reply_tweet_url'] = $reply_result['reply_tweet_url'];
				} elseif ( $reply_result && ! $reply_result['success'] ) {
					$this->log(
						'warning',
						'Twitter Tool: Main tweet posted but reply failed',
						array(
							'tweet_id' => $tweet_id,
							'reply_error' => $reply_result['error'],
						)
					);
				}

				return $this->successResponse( $result_data );
			} else {
				$error_msg = 'Twitter API error: Failed to post tweet';
				if ( isset( $response->title ) ) {
					$error_msg = "Twitter API error: {$response->title}";
				}

				return $this->errorResponse(
					$error_msg,
					array(
						'http_code' => $http_code,
						'raw_api_response' => wp_json_encode( $response, JSON_PRETTY_PRINT ),
						'response_headers' => $connection->getLastXHeaders() ?? 'unavailable',
					)
				);
			}
		} catch ( \Exception $e ) {
			return $this->errorResponse(
				$e->getMessage(),
				array(
					'exception_type' => get_class( $e ),
				)
			);
		}
	}

	private function post_reply_tweet( $connection, string $original_tweet_id, string $source_url, string $screen_name ): array {
		try {
			$connection->setApiVersion( '2' );

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
			} else {
				$error_msg = 'Twitter API error: Failed to post reply tweet';
				if ( isset( $response->title ) ) {
					$error_msg = "Twitter API error: {$response->title}";
				}

				$this->log(
					'warning',
					'Twitter Tool: Reply tweet failed',
					array(
						'http_code' => $http_code,
						'api_response' => $response,
						'original_tweet_id' => $original_tweet_id,
					)
				);

				return array(
					'success' => false,
					'error' => $error_msg,
				);
			}
		} catch ( \Exception $e ) {
			$this->log(
				'warning',
				'Twitter Tool: Exception during reply posting',
				array(
					'exception' => $e->getMessage(),
					'original_tweet_id' => $original_tweet_id,
				)
			);

			return array(
				'success' => false,
				'error' => $e->getMessage(),
			);
		}
	}


	private function upload_image_file_to_twitter( $connection, string $image_file_path, string $alt_text ): ?string {
		try {
			$validation = $this->validateImage( $image_file_path );
			if ( ! $validation['valid'] ) {
				$this->log(
					'warning',
					'Twitter: Repository image validation failed',
					array(
						'file_path' => $image_file_path,
						'errors' => $validation['errors'],
					)
				);
				return null;
			}

			$mime_type = $validation['mime_type'];
			$file_size = $validation['size'];

			$connection->setApiVersion( '1.1' );

			$media_id = $this->upload_image_chunked( $connection, $image_file_path, $mime_type );

			$connection->setApiVersion( '2' );

			if ( $media_id ) {
				$this->log(
					'debug',
					'Twitter: Successfully uploaded image from repository',
					array(
						'file_path' => $image_file_path,
						'media_id' => $media_id,
						'file_size' => $file_size,
						'mime_type' => $mime_type,
					)
				);
				return $media_id;
			} else {
				$this->log(
					'error',
					'Twitter media upload failed for repository file.',
					array(
						'file_path' => $image_file_path,
						'file_size' => $file_size,
						'mime_type' => $mime_type,
					)
				);
				return null;
			}
		} catch ( \Exception $e ) {
			$connection->setApiVersion( '2' );

			$this->log(
				'error',
				'Twitter repository image upload exception: ' . $e->getMessage(),
				array(
					'exception_type' => get_class( $e ),
					'file_path' => $image_file_path,
				)
			);
			return null;
		}
	}



	private function upload_image_chunked( $connection, string $temp_image_path, string $mime_type ): ?string {
		try {
			$file_size = filesize( $temp_image_path );

			$init_response = $connection->post(
				'media/upload',
				array(
					'command' => 'INIT',
					'media_type' => $mime_type,
					'media_category' => 'tweet_image',
					'total_bytes' => $file_size,
				)
			);

			$http_code = $connection->getLastHttpCode();
			if ( 200 !== $http_code || ! isset( $init_response->media_id_string ) ) {
				$this->log(
					'error',
					'Twitter: Chunked upload INIT failed.',
					array(
						'http_code' => $http_code,
						'response' => $init_response,
					)
				);
				return null;
			}

			$media_id = $init_response->media_id_string;

			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			$filesystem_init = WP_Filesystem();
			if ( ! $filesystem_init ) {
				$this->log(
					'error',
					'Twitter: WP_Filesystem initialization failed',
					array(
						'temp_image_path' => $temp_image_path,
					)
				);
				return null;
			}

			global $wp_filesystem;

			$file_contents = $wp_filesystem->get_contents( $temp_image_path );
			if ( false === $file_contents ) {
				$this->log(
					'error',
					'Twitter: Cannot read image file for chunked upload.',
					array(
						'temp_image_path' => $temp_image_path,
					)
				);
				return null;
			}

			$segment_index = 0;
			$chunk_size = 1048576; // 1MB chunks
			$file_length = strlen( $file_contents );
			$offset = 0;

			while ( $offset < $file_length ) {
				$chunk = substr( $file_contents, $offset, $chunk_size );

				$append_response = $connection->post(
					'media/upload',
					array(
						'command' => 'APPEND',
						'media_id' => $media_id,
						'segment_index' => $segment_index,
						'media_data' => base64_encode( $chunk ),
					)
				);

				$http_code = $connection->getLastHttpCode();
				if ( 204 !== $http_code ) {
					$this->log(
						'error',
						'Twitter: Chunked upload APPEND failed.',
						array(
							'http_code' => $http_code,
							'segment_index' => $segment_index,
							'response' => $append_response,
						)
					);
					return null;
				}

				$offset += $chunk_size;
				++$segment_index;
			}

			$finalize_response = $connection->post(
				'media/upload',
				array(
					'command' => 'FINALIZE',
					'media_id' => $media_id,
				)
			);

			$http_code = $connection->getLastHttpCode();
			if ( 200 !== $http_code || ! isset( $finalize_response->media_id_string ) ) {
				$this->log(
					'error',
					'Twitter: Chunked upload FINALIZE failed.',
					array(
						'http_code' => $http_code,
						'response' => $finalize_response,
					)
				);
				return null;
			}

			return $finalize_response->media_id_string;
		} catch ( \Exception $e ) {
			$this->log(
				'error',
				'Twitter: Chunked upload exception.',
				array(
					'exception' => $e->getMessage(),
					'exception_type' => get_class( $e ),
				)
			);
			return null;
		}
	}


	public static function get_label(): string {
		return __( 'Post to Twitter', 'data-machine-socials' );
	}


	private function is_image_accessible( string $image_url ): bool {
		$problematic_patterns = array(
			'preview.redd.it', // Reddit preview URLs often have access restrictions
			'i.redd.it', // Reddit image URLs may have restrictions
		);

		foreach ( $problematic_patterns as $pattern ) {
			if ( strpos( $image_url, $pattern ) !== false ) {
				$this->log(
					'warning',
					'Twitter: Skipping problematic image URL pattern',
					array(
						'url' => $image_url,
						'pattern' => $pattern,
					)
				);
				return false;
			}
		}

		$response = wp_remote_head(
			$image_url,
			array(
				'user-agent' => 'Mozilla/5.0 (compatible; DataMachine/1.0; +https://github.com/chubes/datamachine)',
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log(
				'warning',
				'Twitter: Image URL not accessible',
				array(
					'url' => $image_url,
					'error' => $response->get_error_message(),
				)
			);
			return false;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );

		if ( $http_code >= 200 && $http_code < 300 && strpos( $content_type, 'image/' ) === 0 ) {
			return true;
		}

		$this->log(
			'warning',
			'Twitter: Image URL validation failed',
			array(
				'url' => $image_url,
				'http_code' => $http_code,
				'content_type' => $content_type,
			)
		);
		return false;
	}
}
