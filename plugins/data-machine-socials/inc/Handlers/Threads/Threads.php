<?php
/**
* Threads publish handler.
*
* Handles publishing content to Meta's Threads platform.
*
* @package DataMachineSocials
* @subpackage Handlers\Threads
* @since 1.0.0
*/

namespace DataMachineSocials\Handlers\Threads;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Core\EngineData;
use DataMachine\Core\Steps\Publish\Handlers\PublishHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Threads extends PublishHandler {

	use HandlerRegistrationTrait;

	/**
	* @var ThreadsAuth Authentication handler instance
	*/
	private $auth;

	public function __construct() {
		parent::__construct( 'threads' );

		// Self-register with filters
		self::registerHandler(
			'threads_publish',
			'publish',
			self::class,
			'Threads',
			'Post content to Meta Threads',
			true,
			ThreadsAuth::class,
			ThreadsSettings::class,
			function ( $tools, $handler_slug, $handler_config ) {
				if ( 'threads_publish' === $handler_slug ) {
					$tools['threads_publish'] = array(
						'class' => self::class,
						'method' => 'handle_tool_call',
						'handler' => 'threads_publish',
						'description' => 'Post content to Meta Threads. Supports text and images.',
						'parameters' => array(
							'type' => 'object',
							'properties' => array(
								'content' => array(
									'type' => 'string',
									'description' => 'The text content to post to Threads',
								),
							),
							'required' => array( 'content' ),
						),
					);
				}
				return $tools;
			},
			'threads'
		);
	}

	/**
	* Lazy-load auth provider when needed.
	*
	* @return ThreadsAuth|null Auth provider instance or null if unavailable
	*/
	private function get_auth() {
		if ( $this->auth === null ) {
			$auth_abilities = new AuthAbilities();
			$this->auth = $auth_abilities->getProvider( 'threads' );

			if ( $this->auth === null ) {
				$this->log(
					'error',
					'Threads Handler: Authentication service not available',
					array(
						'handler' => 'threads',
						'missing_service' => 'threads',
						'available_providers' => array_keys( $auth_abilities->getAllProviders() ),
					)
				);
			}
		}
		return $this->auth;
	}

	protected function executePublish( array $parameters, array $handler_config ): array {
		$this->log(
			'debug',
			'Threads Tool: Handling tool call',
			array(
				'parameters' => $parameters,
				'parameter_keys' => array_keys( $parameters ),
				'has_handler_config' => ! empty( $handler_config ),
				'handler_config_keys' => array_keys( $handler_config ),
			)
		);

		if ( empty( $parameters['content'] ) ) {
			return $this->errorResponse(
				'Threads tool call missing required content parameter',
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

		$title = $parameters['title'] ?? '';
		$content = $parameters['content'] ?? '';
		$source_url = $engine->getSourceUrl();
		$image_file_path = $engine->getImagePath();

		$include_images = $handler_config['include_images'] ?? false;
		$link_handling = $handler_config['link_handling'] ?? 'append';

		$auth = $this->get_auth();
		if ( ! $auth ) {
			return $this->errorResponse( 'Threads authentication not configured', array(), 'critical' );
		}

		$access_token = $auth->get_access_token();
		if ( empty( $access_token ) ) {
			return $this->errorResponse( 'Threads authentication failed - no access token', array(), 'critical' );
		}

		// Get page ID for posting
		$page_id = $auth->get_page_id();
		if ( empty( $page_id ) ) {
			return $this->errorResponse( 'Threads page ID not available' );
		}

		// Format post content (Threads' character limit is 500)
		$post_text = $title ? $title . "\n\n" . $content : $content;

		// Handle source URL based on consolidated link_handling setting
		$link = ( 'append' === $link_handling && ! empty( $source_url ) && filter_var( $source_url, FILTER_VALIDATE_URL ) ) ? "\n\n" . $source_url : '';
		$ellipsis = '...';
		$max_length = 500 - strlen( $link );

		if ( strlen( $post_text ) > $max_length ) {
			$post_text = substr( $post_text, 0, $max_length - strlen( $ellipsis ) ) . $ellipsis;
		}

		$post_text .= $link;

		if ( empty( $post_text ) ) {
			return $this->errorResponse( 'Formatted post content is empty' );
		}

		try {
			// Prepare media container data
			$container_data = array(
				'media_type' => 'TEXT',
				'text' => $post_text,
			);

			// Handle image if provided and enabled
			if ( $include_images && ! empty( $image_file_path ) ) {
				$validation = $this->validateImage( $image_file_path );

				if ( ! $validation['valid'] ) {
					return $this->errorResponse(
						implode( ', ', $validation['errors'] ),
						array(
							'image_file_path' => $image_file_path,
							'errors' => $validation['errors'],
						)
					);
				}

				// Convert repository file path to public URL
				$upload_dir = wp_upload_dir();
				$image_url = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $image_file_path );

				if ( ! filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
					return $this->errorResponse(
						'Could not generate image URL from repository file',
						array(
							'image_file_path' => $image_file_path,
							'generated_url' => $image_url,
						)
					);
				}

				$container_data['media_type'] = 'IMAGE';
				$container_data['image_url'] = $image_url;
			}

			// Step 1: Create media container
			$container_response = $this->create_media_container( $page_id, $container_data, $access_token );
			if ( ! $container_response['success'] ) {
				return $this->errorResponse( 'Threads API error: Failed to create media container - ' . $container_response['error'] );
			}

			$creation_id = $container_response['creation_id'];

			// Step 2: Publish the media container
			$publish_response = $this->publish_media_container( $page_id, $creation_id, $access_token );
			if ( ! $publish_response['success'] ) {
				return $this->errorResponse( 'Threads API error: Failed to publish - ' . $publish_response['error'] );
			}

			$media_id = $publish_response['media_id'];
			$post_url = "https://www.threads.net/t/{$media_id}";

			$this->log(
				'debug',
				'Threads Tool: Post created successfully',
				array(
					'media_id' => $media_id,
					'post_url' => $post_url,
				)
			);

			return $this->successResponse(
				array(
					'media_id' => $media_id,
					'post_url' => $post_url,
					'content' => $post_text,
				)
			);
		} catch ( \Exception $e ) {
			return $this->errorResponse( $e->getMessage() );
		}
	}

	/**
	* Step 1 of Threads 2-step publishing: creates media container (TEXT or IMAGE type).
	*/
	private function create_media_container( string $user_id_threads, array $container_data, string $access_token ): array {
		$endpoint = "https://graph.threads.net/v1.0/{$user_id_threads}/threads";

		$result = $this->httpPost(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type' => 'application/json',
				),
				'body' => wp_json_encode( $container_data ),
				'context' => 'Threads API',
			)
		);

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'error' => $result['error'],
			);
		}

		$response_code = $result['status_code'];
		$response_body = $result['data'];
		$data = json_decode( $response_body, true );

		if ( 200 !== $response_code || empty( $data['id'] ) ) {
			$error_message = $data['error']['message'] ?? 'Unknown error';
			return array(
				'success' => false,
				'error' => $error_message,
			);
		}

		return array(
			'success' => true,
			'creation_id' => $data['id'],
		);
	}

	/**
	* Step 2 of Threads 2-step publishing: publishes created media container.
	*/
	private function publish_media_container( string $user_id_threads, string $creation_id, string $access_token ): array {
		$endpoint = "https://graph.threads.net/v1.0/{$user_id_threads}/threads_publish";

		$result = $this->httpPost(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type' => 'application/json',
				),
				'body' => wp_json_encode(
					array(
						'creation_id' => $creation_id,
					)
				),
				'context' => 'Threads API',
			)
		);

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'error' => $result['error'],
			);
		}

		$response_code = $result['status_code'];
		$response_body = $result['data'];
		$data = json_decode( $response_body, true );

		if ( 200 !== $response_code || empty( $data['id'] ) ) {
			$error_message = $data['error']['message'] ?? 'Unknown error';
			return array(
				'success' => false,
				'error' => $error_message,
			);
		}

		return array(
			'success' => true,
			'media_id' => $data['id'],
		);
	}

	public static function get_label(): string {
		return __( 'Threads', 'data-machine-socials' );
	}
}
