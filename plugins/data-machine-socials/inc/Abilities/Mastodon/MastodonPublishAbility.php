<?php
/**
 * Mastodon Publish Ability
 *
 * Primitive ability for publishing content to a Mastodon / Fediverse instance.
 * Uses the Mastodon REST API with OAuth2 bearer token authentication.
 *
 * @package DataMachineSocials\Abilities\Mastodon
 * @since 0.17.0
 */

namespace DataMachineSocials\Abilities\Mastodon;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachineSocials\Abilities\AbstractSocialAbility;
use DataMachineSocials\Handlers\Mastodon\MastodonAuth;
use DataMachineSocials\PublishAuthorization;

defined( 'ABSPATH' ) || exit;

/**
 * Mastodon Publish Ability
 */
class MastodonPublishAbility extends AbstractSocialAbility {

	/**
	 * Whether the ability has been registered.
	 *
	 * @var bool
	 */
	protected static bool $registered = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->registerAbility( $this->registerCallback() );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/mastodon-publish',
				array(
					'label'               => __( 'Publish to Mastodon', 'data-machine-socials' ),
					'description'         => __( 'Post a status to Mastodon with optional media and source URL', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'content' ),
						'properties' => array(
							'content'    => array(
								'type'        => 'string',
								'description' => 'Status text content',
							),
							'title'      => array(
								'type'        => 'string',
								'description' => 'Optional content warning / spoiler text (shown before the status)',
							),
							'image_url'  => array(
								'type'        => 'string',
								'description' => 'URL of an image to attach',
								'format'      => 'uri',
							),
							'source_url' => array(
								'type'        => 'string',
								'description' => 'Source URL to append to the status',
								'format'      => 'uri',
							),
							'visibility' => array(
								'type'        => 'string',
								'enum'        => array( 'public', 'unlisted', 'private' ),
								'description' => 'Status visibility (default: public)',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'  => array( 'type' => 'boolean' ),
							'post_id'  => array( 'type' => 'string' ),
							'post_url' => array(
								'type'   => 'string',
								'format' => 'uri',
							),
							'error'    => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'execute_publish' ),
					'permission_callback' => array( PublishAuthorization::class, 'can_publish' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			// Account info ability.
			wp_register_ability(
				'datamachine/mastodon-account',
				array(
					'label'               => __( 'Mastodon Account Info', 'data-machine-socials' ),
					'description'         => __( 'Get authenticated Mastodon account details', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'acct'    => array( 'type' => 'string' ),
							'url'     => array( 'type' => 'string' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'get_account' ),
					'permission_callback' => fn() => PermissionHelper::can( 'use_tools' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};
	}

	/**
	 * Execute Mastodon publish.
	 *
	 * @param array $input Ability input with publish parameters.
	 * @return array Response with post details or error.
	 */
	public static function execute_publish( array $input ): array|\WP_Error {
		$content = $input['content'] ?? '';

		if ( empty( $content ) && empty( $input['image_url'] ) ) {
			return new \WP_Error( 'missing_param', 'Content or image is required', array( 'status' => 400 ) );
		}

		$auth     = new AuthAbilities();
		$provider = $auth->getProvider( 'mastodon' );

		if ( ! $provider || ! $provider->is_authenticated() ) {
			return new \WP_Error( 'missing_auth', 'Mastodon not authenticated', array( 'status' => 401 ) );
		}

		$instance = $provider->get_instance();
		$token    = $provider->get_access_token();

		if ( empty( $instance ) || empty( $token ) ) {
			return new \WP_Error( 'missing_auth', 'Mastodon instance or access token not configured', array( 'status' => 401 ) );
		}

		$visibility   = $input['visibility'] ?? 'public';
		$source_url   = $input['source_url'] ?? '';
		$link_handling = $input['link_handling'] ?? 'append';

		// Build status text: append source URL if configured.
		$status_text = $content;
		if ( ! empty( $source_url ) && 'none' !== $link_handling ) {
			$status_text = trim( $content . "\n\n" . $source_url );
		}

		// Upload media if provided.
		$media_ids = array();
		if ( ! empty( $input['image_url'] ) && filter_var( $input['image_url'], FILTER_VALIDATE_URL ) ) {
			$media_id = self::upload_media( $instance, $token, $input['image_url'], $input['title'] ?? '' );

			if ( is_string( $media_id ) ) {
				$media_ids[] = $media_id;
			}
		}

		// Build form data for status creation.
		$form = array(
			'status'     => $status_text,
			'visibility' => $visibility,
		);

		// Content warning / spoiler text.
		if ( ! empty( $input['title'] ) ) {
			$form['spoiler_text'] = $input['title'];
		}

		if ( ! empty( $media_ids ) ) {
			$form['media_ids'] = $media_ids;
		}

		$result = HttpClient::post(
			$instance . '/api/v1/statuses',
			array(
				'context' => 'Mastodon Publish',
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => http_build_query( $form ),
				'timeout' => 30,
			)
		);

		if ( ! empty( $result['success'] ) ) {
			$data = json_decode( $result['data'], true );

			if ( isset( $data['id'] ) ) {
				$post_url = $data['url'] ?? '';

				// Fallback: construct URL from instance + acct + id if url is missing.
				if ( empty( $post_url ) && ! empty( $data['account']['acct'] ) ) {
					$post_url = $instance . '/@' . $data['account']['acct'] . '/' . $data['id'];
				}

				return array(
					'success'  => true,
					'post_id'  => (string) $data['id'],
					'post_url' => $post_url,
				);
			}

			$error_msg = $data['error'] ?? 'Mastodon API returned unexpected response';
			return new \WP_Error( 'api_error', $error_msg, array( 'status' => 500 ) );
		}

		$error_msg = $result['error'] ?? 'Mastodon API error';

		// Try to extract API error message from the response body.
		if ( ! empty( $result['data'] ) ) {
			$body = json_decode( $result['data'], true );
			if ( is_array( $body ) && isset( $body['error'] ) ) {
				$error_msg = is_string( $body['error'] ) ? $body['error'] : $error_msg;
			}
		}

		return new \WP_Error( 'api_error', $error_msg, array( 'status' => 500 ) );
	}

	/**
	 * Get Mastodon account details.
	 *
	 * @param array $input Ability input.
	 * @return array Account details or error.
	 */
	public static function get_account( array $input ): array|\WP_Error {
		$input;
		$auth     = new AuthAbilities();
		$provider = $auth->getProvider( 'mastodon' );

		if ( ! $provider || ! $provider->is_authenticated() ) {
			return new \WP_Error( 'missing_auth', 'Mastodon not authenticated', array( 'status' => 401 ) );
		}

		$details = $provider->get_account_details();

		if ( ! $details ) {
			return new \WP_Error( 'api_error', 'Could not verify Mastodon credentials', array( 'status' => 500 ) );
		}

		return array(
			'success' => true,
			'acct'    => $details['acct'] ?? '',
			'url'     => $details['url'] ?? '',
		);
	}

	/**
	 * Upload media to the Mastodon instance.
	 *
	 * Uses POST /api/v2/media (async, returns media id). For images, the
	 * processing is typically synchronous (200). For video/audio, the response
	 * is 202 (accepted) and processing continues in the background; we still
	 * get a media id that can be attached to a status immediately.
	 *
	 * @param string $instance   Instance base URL.
	 * @param string $token      OAuth access token.
	 * @param string $image_url  URL of the image to download and upload.
	 * @param string $description Alt text for the media (accessibility).
	 * @return string|\WP_Error Media attachment ID or error.
	 */
	private static function upload_media( string $instance, string $token, string $image_url, string $description = '' ) {
		// Download the image.
		$download = HttpClient::get(
			$image_url,
			array(
				'context' => 'Mastodon Media Download',
				'timeout' => 30,
			)
		);

		if ( empty( $download['success'] ) ) {
			return new \WP_Error( 'media_download_failed', 'Could not download media from ' . $image_url );
		}

		$image_data   = $download['data'];
		$content_type = 'image/jpeg';

		if ( ! empty( $download['headers'] ) ) {
			$headers = $download['headers'];
			$ct      = is_object( $headers ) && method_exists( $headers, 'offsetGet' )
				? (string) ( $headers['content-type'] ?? '' )
				: (string) ( is_array( $headers ) ? ( $headers['content-type'] ?? '' ) : '' );

			if ( ! empty( $ct ) ) {
				$content_type = $ct;
			}
		}

		// Determine file extension from content type for the multipart filename.
		$extension = self::extension_from_content_type( $content_type );
		$filename  = 'upload.' . $extension;

		// Build multipart/form-data body manually.
		$boundary = wp_generate_password( 24, false );
		$body     = '';

		// File field.
		$body .= "--{$boundary}\r\n";
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . "\"\r\n";
		$body .= "Content-Type: {$content_type}\r\n\r\n";
		$body .= $image_data . "\r\n";

		// Description field (alt text).
		if ( ! empty( $description ) ) {
			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"description\"\r\n\r\n";
			$body .= $description . "\r\n";
		}

		$body .= "--{$boundary}--\r\n";

		$result = HttpClient::post(
			$instance . '/api/v2/media',
			array(
				'context' => 'Mastodon Media Upload',
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $body,
				'timeout' => 60,
			)
		);

		if ( empty( $result['success'] ) ) {
			return new \WP_Error( 'media_upload_failed', 'Media upload failed: ' . ( $result['error'] ?? 'unknown error' ) );
		}

		$data = json_decode( $result['data'], true );

		if ( empty( $data['id'] ) ) {
			return new \WP_Error( 'media_upload_failed', 'Media upload response did not include an id' );
		}

		return (string) $data['id'];
	}

	/**
	 * Map a MIME content type to a file extension.
	 *
	 * @param string $content_type MIME type.
	 * @return string File extension without leading dot.
	 */
	private static function extension_from_content_type( string $content_type ): string {
		$map = array(
			'image/jpeg' => 'jpg',
			'image/jpg'  => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
			'image/avif' => 'avif',
			'video/mp4'  => 'mp4',
			'video/webm' => 'webm',
			'audio/mpeg' => 'mp3',
			'audio/ogg'  => 'ogg',
			'audio/wav'  => 'wav',
		);

		$content_type = strtolower( trim( explode( ';', $content_type )[0] ) );

		return $map[ $content_type ] ?? 'jpg';
	}
}
