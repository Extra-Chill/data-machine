<?php
/**
* Bluesky publisher with AT Protocol authentication
*/

namespace DataMachineSocials\Handlers\Bluesky;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Core\EngineData;
use DataMachine\Core\Steps\Publish\Handlers\PublishHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bluesky extends PublishHandler {

	use HandlerRegistrationTrait;

	private $auth;

	public function __construct() {
		parent::__construct( 'bluesky' );

		// Self-register with filters
		self::registerHandler(
			'bluesky_publish',
			'publish',
			self::class,
			'Bluesky',
			'Post content to Bluesky social network',
			true,
			BlueskyAuth::class,
			null,
			function ( $tools, $handler_slug, $handler_config ) {
				if ( 'bluesky_publish' === $handler_slug ) {
					$tools['bluesky_publish'] = array(
						'class' => self::class,
						'method' => 'handle_tool_call',
						'handler' => 'bluesky_publish',
						'description' => 'Post content to Bluesky. Supports text and images.',
						'parameters' => array(
							'type' => 'object',
							'properties' => array(
								'content' => array(
									'type' => 'string',
									'description' => 'The text content to post to Bluesky',
								),
							),
							'required' => array( 'content' ),
						),
					);
				}
				return $tools;
			},
			'bluesky'
		);
	}

	/**
	* Lazy-load auth provider when needed.
	*
	* @return BlueskyAuth|null Auth provider instance or null if unavailable
	*/
	private function get_auth() {
		if ( $this->auth === null ) {
			$auth_abilities = new AuthAbilities();
			$this->auth = $auth_abilities->getProvider( 'bluesky' );

			if ( $this->auth === null ) {
				$this->log(
					'error',
					'Bluesky Handler: Authentication service not available',
					array(
						'handler' => 'bluesky',
						'missing_service' => 'bluesky',
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
			'Bluesky Tool: Handling tool call',
			array(
				'parameters' => $parameters,
				'parameter_keys' => array_keys( $parameters ),
				'has_handler_config' => ! empty( $handler_config ),
				'handler_config_keys' => array_keys( $handler_config ),
			)
		);

		if ( empty( $parameters['content'] ) ) {
			return $this->errorResponse(
				'Bluesky tool call missing required content parameter',
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
			return $this->errorResponse( 'Bluesky authentication not configured', array(), 'critical' );
		}

		$session = $auth->get_session();
		if ( is_wp_error( $session ) ) {
			return $this->errorResponse(
				'Bluesky authentication failed: ' . $session->get_error_message(),
				array( 'error_code' => $session->get_error_code() ),
				'critical'
			);
		}

		$access_token = $session['accessJwt'] ?? null;
		$did = $session['did'] ?? null;
		$pds_url = $session['pds_url'] ?? null;

		if ( empty( $access_token ) || empty( $did ) || empty( $pds_url ) ) {
			return $this->errorResponse( 'Bluesky session data incomplete' );
		}

		$post_text = $title ? $title . ': ' . $content : $content;
		$ellipsis = '…';
		$ellipsis_len = mb_strlen( $ellipsis, 'UTF-8' );
		$link = ( 'append' === $link_handling && ! empty( $source_url ) && filter_var( $source_url, FILTER_VALIDATE_URL ) ) ? "\n\n" . $source_url : '';
		$link_length = $link ? mb_strlen( $link, 'UTF-8' ) : 0; // Bluesky counts full URL length
		$available_chars = 300 - $link_length;

		if ( $available_chars < $ellipsis_len ) {
			$post_text = mb_substr( $link, 0, 300 );
		} else {
			if ( mb_strlen( $post_text, 'UTF-8' ) > $available_chars ) {
				$post_text = mb_substr( $post_text, 0, $available_chars - $ellipsis_len ) . $ellipsis;
			}
			$post_text .= $link;
		}
		$post_text = trim( $post_text );

		if ( empty( $post_text ) ) {
			return $this->errorResponse( 'Formatted post content is empty' );
		}

		try {
			$facets = $this->detect_link_facets( $post_text );

			$current_time = gmdate( 'Y-m-d\TH:i:s.v\Z' );
			$record = array(
				'$type' => 'app.bsky.feed.post',
				'text' => $post_text,
				'createdAt' => $current_time,
				'langs' => array( 'en' ),
			);

			if ( ! empty( $facets ) ) {
				$record['facets'] = $facets;
			}

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

				$image_alt_text = $title ? $title : substr( $content, 0, 50 );
				$uploaded_image_blob = $this->upload_bluesky_image_from_file( $pds_url, $access_token, $did, $image_file_path, $image_alt_text );

				if ( ! is_wp_error( $uploaded_image_blob ) && isset( $uploaded_image_blob['blob'] ) ) {
					$record['embed'] = array(
						'$type' => 'app.bsky.embed.images',
						'images' => array(
							array(
								'alt' => $image_alt_text,
								'image' => $uploaded_image_blob['blob'],
							),
						),
					);
				} else {
					return $this->errorResponse(
						'Failed to upload image',
						array( 'error' => is_wp_error( $uploaded_image_blob ) ? $uploaded_image_blob->get_error_message() : 'Unknown error' )
					);
				}
			}

			$post_result = $this->create_bluesky_post( $pds_url, $access_token, $did, $record );

			if ( is_wp_error( $post_result ) ) {
				return $this->errorResponse(
					'Bluesky API error: ' . $post_result->get_error_message(),
					array( 'error_code' => $post_result->get_error_code() )
				);
			}

			$post_uri = $post_result['uri'] ?? '';
			$post_url = $this->build_post_url( $post_uri, $session['handle'] ?? '' );

			if ( is_wp_error( $post_url ) ) {
				$post_url = 'https://bsky.app/';
			}

			$this->log(
				'debug',
				'Bluesky Tool: Post created successfully',
				array(
					'post_uri' => $post_uri,
					'post_url' => $post_url,
				)
			);

			return $this->successResponse(
				array(
					'post_uri' => $post_uri,
					'post_url' => $post_url,
					'content' => $post_text,
				)
			);
		} catch ( \Exception $e ) {
			return $this->errorResponse( $e->getMessage() );
		}
	}


	/**
	* Returns the user-friendly label for this publish handler.
	*
	* @return string The label.
	*/
	public static function get_label(): string {
		return __( 'Post to Bluesky', 'data-machine-socials' );
	}

	/**
	* Detect link facets in post text for proper Bluesky formatting.
	*
	* @param string $text The post text to analyze.
	* @return array Array of facet objects.
	*/
	private function detect_link_facets( string $text ): array {
		$facets = array();

		// Simple URL regex pattern
		$url_pattern = '/https?:\/\/[^\s]+/i';

		if ( preg_match_all( $url_pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $match ) {
				$url = $match[0];
				$start = $match[1];
				$end = $start + mb_strlen( $url, 'UTF-8' );

				$facets[] = array(
					'index' => array(
						'byteStart' => $start,
						'byteEnd' => $end,
					),
					'features' => array(
						array(
							'$type' => 'app.bsky.richtext.facet#link',
							'uri' => $url,
						),
					),
				);
			}
		}

		return $facets;
	}



	/**
	* Upload image to Bluesky blob storage from repository file.
	*
	* @param string $pds_url PDS URL
	* @param string $access_token Access token
	* @param string $did User DID
	* @param string $image_file_path Repository file path to upload
	* @param string $alt_text Alt text for image
	* @return array|WP_Error Upload result or error
	*/
	private function upload_bluesky_image_from_file( string $pds_url, string $access_token, string $did, string $image_file_path, string $alt_text ) {

		$file_size = @filesize( $image_file_path );
		if ( false === $file_size || $file_size > 1000000 ) {
			return new \WP_Error( 'bluesky_image_too_large', __( 'Image exceeds Bluesky size limit.', 'data-machine-socials' ) );
		}

		$mime_type = mime_content_type( $image_file_path );
		if ( ! $mime_type || strpos( $mime_type, 'image/' ) !== 0 ) {
			return new \WP_Error( 'bluesky_invalid_image_type', __( 'Invalid image type.', 'data-machine-socials' ) );
		}

		$image_content = file_get_contents( $image_file_path );
		if ( false === $image_content ) {
			return new \WP_Error( 'bluesky_image_read_failed', __( 'Could not read image file.', 'data-machine-socials' ) );
		}

		$upload_url = rtrim( $pds_url, '/' ) . '/xrpc/com.atproto.repo.uploadBlob';
		$result = $this->httpPost(
			$upload_url,
			array(
				'headers' => array(
					'Content-Type' => $mime_type,
					'Authorization' => 'Bearer ' . $access_token,
				),
				'body' => $image_content,
				'context' => 'Bluesky API',
			)
		);

		unset( $image_content );

		if ( ! $result['success'] ) {
			return new \WP_Error( 'bluesky_upload_request_failed', $result['error'] );
		}

		$response_code = $result['status_code'];
		$response_body = $result['data'];

		if ( 200 !== $response_code ) {
			return new \WP_Error( 'bluesky_upload_failed', __( 'Image upload failed.', 'data-machine-socials' ) );
		}

		$upload_result = json_decode( $response_body, true );
		if ( empty( $upload_result['blob'] ) ) {
			return new \WP_Error( 'bluesky_upload_decode_error', __( 'Missing blob data in response.', 'data-machine-socials' ) );
		}

		return $upload_result;
	}

	/**
	* Create a post record on Bluesky.
	*
	* @param string $pds_url PDS URL
	* @param string $access_token Access token
	* @param string $repo_did Repository DID
	* @param array $record Post record data
	* @return array|WP_Error Post result or error
	*/
	private function create_bluesky_post( string $pds_url, string $access_token, string $repo_did, array $record ) {
		$url = rtrim( $pds_url, '/' ) . '/xrpc/com.atproto.repo.createRecord';

		$body = wp_json_encode(
			array(
				'repo' => $repo_did,
				'collection' => 'app.bsky.feed.post',
				'record' => $record,
			)
		);

		$result = $this->httpPost(
			$url,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'Authorization' => 'Bearer ' . $access_token,
				),
				'body' => $body,
				'context' => 'Bluesky API',
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error( 'bluesky_post_request_failed', $result['error'] );
		}

		$response_code = $result['status_code'];
		$response_body = $result['data'];

		if ( 200 !== $response_code ) {
			return new \WP_Error( 'bluesky_post_failed', __( 'Failed to create Bluesky post.', 'data-machine-socials' ) );
		}

		$result = json_decode( $response_body, true );
		return $result ? $result : new \WP_Error( 'bluesky_post_decode_error', __( 'Could not decode post response.', 'data-machine-socials' ) );
	}

	/**
	* Build user-friendly post URL from AT Protocol URI.
	*
	* @param string $uri AT Protocol URI
	* @param string $handle User handle
	* @return string|WP_Error User-friendly URL or error if URL construction fails
	*/
	private function build_post_url( string $uri, string $handle ) {
		// Extract post ID from AT URI (format: at://did:plc:xxx/app.bsky.feed.post/postid)
		if ( preg_match( '/\/app\.bsky\.feed\.post\/(.+)$/', $uri, $matches ) ) {
			$post_id = $matches[1];
			return "https://bsky.app/profile/{$handle}/post/{$post_id}";
		}

		$this->log(
			'error',
			'Failed to extract post ID from AT Protocol URI.',
			array(
				'uri' => $uri,
				'handle' => $handle,
			)
		);
		return new \WP_Error( 'bluesky_url_construction_failed', __( 'Failed to construct post URL from AT Protocol URI.', 'data-machine-socials' ) );
	}
}
