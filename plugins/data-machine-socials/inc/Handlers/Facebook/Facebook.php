<?php
/**
* Modular Facebook publish handler.
*
* Posts content to a specified Facebook Page using the self-contained
* FacebookAuth class for authentication. This modular approach separates
* concerns between main handler logic and authentication functionality.
*
* @package Data_Machine_Socials
* @subpackage Handlers\Facebook
* @since 0.1.0
*/

namespace DataMachineSocials\Handlers\Facebook;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Core\EngineData;
use DataMachine\Core\Steps\Publish\Handlers\PublishHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Facebook extends PublishHandler {

	use HandlerRegistrationTrait;

	/**
	* @var FacebookAuth Authentication handler instance
	*/
	private $auth;

	public function __construct() {
		parent::__construct( 'facebook' );

		// Self-register with filters
		self::registerHandler(
			'facebook_publish',
			'publish',
			self::class,
			'Facebook',
			'Post content to Facebook Pages',
			true,
			FacebookAuth::class,
			FacebookSettings::class,
			function ( $tools, $handler_slug, $handler_config ) {
				if ( 'facebook_publish' === $handler_slug ) {
					$tools['facebook_publish'] = array(
						'class' => self::class,
						'method' => 'handle_tool_call',
						'handler' => 'facebook_publish',
						'description' => 'Post content to a Facebook Page. Supports text and images.',
						'parameters' => array(
							'type' => 'object',
							'properties' => array(
								'content' => array(
									'type' => 'string',
									'description' => 'The text content to post to Facebook',
								),
							),
							'required' => array( 'content' ),
						),
					);
				}
				return $tools;
			},
			'facebook'
		);
	}

	/**
	* Lazy-load auth provider when needed.
	*
	* @return FacebookAuth|null Auth provider instance or null if unavailable
	*/
	private function get_auth() {
		if ( $this->auth === null ) {
			$auth_abilities = new AuthAbilities();
			$this->auth = $auth_abilities->getProvider( 'facebook' );

			if ( $this->auth === null ) {
				$this->log(
					'error',
					'Facebook Handler: Authentication service not available',
					array(
						'handler' => 'facebook',
						'missing_service' => 'facebook',
						'available_providers' => array_keys( $auth_abilities->getAllProviders() ),
					)
				);
			}
		}
		return $this->auth;
	}

	protected function executePublish( array $parameters, array $handler_config ): array {
		if ( empty( $parameters['content'] ) ) {
			return $this->errorResponse(
				'Facebook tool call missing required content parameter',
				array(
					'provided_parameters' => array_keys( $parameters ),
					'required_parameters' => array( 'content' ),
				)
			);
		}

		// handler_config is ALWAYS flat structure - no nesting

		$engine = $parameters['engine'] ?? null;
		if ( ! $engine instanceof EngineData ) {
			$engine = new EngineData( $parameters['engine_data'] ?? array(), $parameters['job_id'] ?? null );
		}

		// Extract parameters from flat structure
		$title = $parameters['title'] ?? '';
		$content = $parameters['content'] ?? '';
		$source_url = $engine->getSourceUrl();
		$image_file_path = $engine->getImagePath();

		$include_images = $handler_config['include_images'] ?? false;
		$link_handling = $handler_config['link_handling'] ?? 'append';

		// Debug logging to verify parameter flow
		$this->log(
			'debug',
			'Facebook Handler: Parameter extraction complete',
			array(
				'source_url' => $source_url,
				'include_images' => $include_images,
				'handler_config_keys' => array_keys( $handler_config ),
			)
		);

		$auth = $this->get_auth();
		if ( ! $auth ) {
			return $this->errorResponse( 'Facebook authentication not configured', array(), 'critical' );
		}

		// Get authenticated credentials
		$page_id = $auth->get_page_id();
		$page_access_token = $auth->get_page_access_token();

		// Validate auto-discovered page ID
		if ( empty( $page_id ) ) {
			return $this->errorResponse( 'Facebook page not found. Please re-authenticate your Facebook account.', array(), 'critical' );
		}

		if ( empty( $page_access_token ) ) {
			return $this->errorResponse( 'Facebook authentication failed - no access token', array(), 'critical' );
		}

		try {
			// Format post content
			$post_text = $title ? $title . "\n\n" . $content : $content;

			// Handle source URL based on consolidated link_handling setting
			if ( 'append' === $link_handling && ! empty( $source_url ) && filter_var( $source_url, FILTER_VALIDATE_URL ) ) {
				$post_text .= "\n\n" . $source_url;
			}

			// Prepare Facebook API request
			$post_data = array(
				'message' => $post_text,
				'access_token' => $page_access_token,
			);

			// Handle image upload if provided and enabled
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

				$image_result = $this->upload_image_file_to_facebook( $image_file_path, $page_access_token, $page_id );
				if ( ! $image_result || ! isset( $image_result['id'] ) ) {
					return $this->errorResponse( 'Failed to upload image' );
				}

				// Use the correct parameter name for Facebook API
				$post_data['attached_media'] = wp_json_encode( array( array( 'media_fbid' => $image_result['id'] ) ) );
			}

			// Make API request to Facebook
			$api_url = $this->buildGraphUrl( "{$page_id}/feed" );
			$response = $this->httpPost(
				$api_url,
				array(
					'body' => $post_data,
					'timeout' => 30,
					'context' => 'Facebook Feed Publish',
				)
			);

			if ( ! $response['success'] ) {
				return $this->errorResponse( 'Facebook API request failed: ' . ( $response['error'] ?? 'Unknown error' ) );
			}

			$response_body = $response['data'] ?? '';
			$status_code = $response['status_code'] ?? 0;
			$response_data = json_decode( $response_body, true );

			if ( $status_code >= 200 && $status_code < 300 && isset( $response_data['id'] ) ) {
				$post_id = $response_data['id'];
				$post_url = "https://www.facebook.com/{$page_id}/posts/{$post_id}";

				$this->log(
					'debug',
					'Facebook Tool: Post created successfully',
					array(
						'post_id' => $post_id,
						'post_url' => $post_url,
					)
				);

				// Handle URL as comment if configured
				$comment_result = null;
				if ( 'comment' === $link_handling && ! empty( $source_url ) && filter_var( $source_url, FILTER_VALIDATE_URL ) ) {
					// Check if we have comment permissions before attempting to post
					if ( $auth && $auth->has_comment_permission() ) {
						$this->log(
							'debug',
							'Facebook Tool: Attempting to post link as comment',
							array(
								'post_id' => $post_id,
								'source_url' => $source_url,
								'link_handling' => $link_handling,
							)
						);
						$comment_result = $this->post_comment( $post_id, $source_url, $page_access_token );
					} else {
						$comment_result = array(
							'success' => false,
							'error' => 'Facebook comment skipped: Missing pages_manage_engagement permission. Please re-authenticate your Facebook account to enable comment functionality.',
							'requires_reauth' => true,
						);

						$this->log(
							'error',
							'Facebook Tool: Comment skipped due to missing permissions',
							array(
								'post_id' => $post_id,
								'source_url' => $source_url,
								'link_handling' => $link_handling,
								'required_permission' => 'pages_manage_engagement',
								'requires_reauth' => true,
							)
						);
					}
				} else {
					$this->log(
						'debug',
						'Facebook Tool: Comment conditions not met',
						array(
							'link_handling' => $link_handling,
							'has_source_url' => ! empty( $source_url ),
							'source_url_valid' => ! empty( $source_url ) ? filter_var( $source_url, FILTER_VALIDATE_URL ) : false,
							'source_url' => $source_url ?? null,
						)
					);
				}

				$result_data = array(
					'post_id' => $post_id,
					'post_url' => $post_url,
					'content' => $post_text,
				);

				// Add comment information if a comment was posted
				if ( $comment_result && $comment_result['success'] ) {
					$result_data['comment_id'] = $comment_result['comment_id'];
					$result_data['comment_url'] = $comment_result['comment_url'];
				} elseif ( $comment_result && ! $comment_result['success'] ) {
					// Comment failed but main post succeeded - log error but don't fail the whole operation
					$this->log(
						'error',
						'Facebook Tool: Main post created but comment failed',
						array(
							'post_id' => $post_id,
							'post_url' => $post_url,
							'comment_error' => $comment_result['error'],
							'link_handling' => 'comment',
							'source_url' => $source_url,
						)
					);
				}

				return $this->successResponse( $result_data );
			}

			return $this->errorResponse(
				'Facebook API error: ' . ( $response_data['error']['message'] ?? 'Unknown error' ),
				array(
					'response_data' => $response_data,
					'status_code' => $status_code,
				)
			);
		} catch ( \Exception $e ) {
			return $this->errorResponse( $e->getMessage() );
		}
	}

	/**
	* Requires pages_manage_engagement permission for comment posting.
	*/
	private function post_comment( string $post_id, string $source_url, string $access_token ): array {
		$this->log(
			'debug',
			'Facebook Tool: Posting URL as comment',
			array(
				'post_id' => $post_id,
				'source_url' => $source_url,
			)
		);

		try {
			// Post comment using Facebook Graph API
			$api_url = $this->buildGraphUrl( "{$post_id}/comments" );
			$comment_data = array(
				'message' => $source_url,
				'access_token' => $access_token,
			);

			$response = $this->httpPost(
				$api_url,
				array(
					'body' => $comment_data,
					'timeout' => 30,
					'context' => 'Facebook Comment Publish',
				)
			);

			if ( ! $response['success'] ) {
				$error_msg = 'Facebook comment API request failed: ' . ( $response['error'] ?? 'Unknown error' );
				$this->log( 'warning', $error_msg, array( 'post_id' => $post_id ) );

				return array(
					'success' => false,
					'error' => $error_msg,
				);
			}

			$response_body = $response['data'] ?? '';
			$status_code = $response['status_code'] ?? 0;
			$response_data = json_decode( $response_body, true );

			if ( $status_code >= 200 && $status_code < 300 && isset( $response_data['id'] ) ) {
				$comment_id = $response_data['id'];
				$comment_url = "https://www.facebook.com/{$post_id}/?comment_id={$comment_id}";

				$this->log(
					'debug',
					'Facebook Tool: Comment posted successfully',
					array(
						'comment_id' => $comment_id,
						'comment_url' => $comment_url,
						'post_id' => $post_id,
					)
				);

				return array(
					'success' => true,
					'comment_id' => $comment_id,
					'comment_url' => $comment_url,
				);
			} else {
				$error_msg = 'Facebook comment API error: ' . ( $response_data['error']['message'] ?? 'Unknown error' );
				$error_code = $response_data['error']['code'] ?? null;

				// Check if this is a permissions error
				if ( 200 === $error_code && strpos( $error_msg, 'sufficient permissions' ) !== false ) {
					$error_msg = 'Facebook comment failed: Missing pages_manage_engagement permission. Please re-authenticate your Facebook account to enable comment functionality.';

					$this->log(
						'warning',
						'Facebook Tool: Comment failed due to missing permissions',
						array(
							'error_code' => $error_code,
							'error_message' => $response_data['error']['message'] ?? 'No message',
							'post_id' => $post_id,
							'requires_reauth' => true,
						)
					);
				} else {
					$this->log(
						'warning',
						'Facebook Tool: Comment posting failed',
						array(
							'response_data' => $response_data,
							'post_id' => $post_id,
							'error_code' => $error_code,
						)
					);
				}

				return array(
					'success' => false,
					'error' => $error_msg,
					'requires_reauth' => 200 === $error_code,
				);
			}
		} catch ( \Exception $e ) {
			$this->log(
				'warning',
				'Facebook Tool: Exception during comment posting',
				array(
					'exception' => $e->getMessage(),
					'post_id' => $post_id,
				)
			);

			return array(
				'success' => false,
				'error' => $e->getMessage(),
			);
		}
	}

	/**
	* Uploads unpublished photo to Facebook, returns photo object for attachment to post.
	*/
	private function upload_image_file_to_facebook( string $image_file_path, string $page_access_token, string $page_id ): ?array {
		$file_storage = new \DataMachine\Core\FilesRepository\FileStorage();
		$image_url = $file_storage->get_public_url( $image_file_path );

		if ( empty( $image_url ) || ! filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
			$this->log(
				'error',
				'Facebook: Failed to generate public URL for image',
				array(
					'file_path' => $image_file_path,
					'generated_url' => $image_url,
				)
			);
			return null;
		}

		$endpoint = $this->buildGraphUrl( "{$page_id}/photos" );
		$response = $this->httpPost(
			$endpoint,
			array(
				'body' => array(
					'url' => $image_url,
					'published' => 'false',
					'access_token' => $page_access_token,
				),
				'timeout' => 30,
				'context' => 'Facebook Photo Upload',
			)
		);

		if ( ! $response['success'] ) {
			$this->log(
				'error',
				'Facebook: Photo upload failed',
				array(
					'file_path' => $image_file_path,
					'error' => $response['error'] ?? 'Unknown error',
				)
			);
			return null;
		}

		$response_body = $response['data'] ?? '';
		$status_code = $response['status_code'] ?? 0;
		$response_data = json_decode( $response_body, true );

		if ( $status_code >= 200 && $status_code < 300 && isset( $response_data['id'] ) ) {
			$this->log(
				'debug',
				'Facebook: Photo uploaded successfully',
				array(
					'media_id' => $response_data['id'],
					'file_path' => $image_file_path,
				)
			);
			return $response_data;
		}

		$this->log(
			'error',
			'Facebook: Photo upload returned unexpected response',
			array(
				'status_code' => $status_code,
				'response_data' => $response_data,
				'file_path' => $image_file_path,
			)
		);

		return null;
	}

	private function buildGraphUrl( string $path ): string {
		return sprintf( 'https://graph.facebook.com/%s/%s', FacebookAuth::GRAPH_API_VERSION, ltrim( $path, '/' ) );
	}


	public static function get_label(): string {
		return __( 'Facebook', 'data-machine-socials' );
	}
}
