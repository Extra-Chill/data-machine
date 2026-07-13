<?php
/**
 * YouTube Upload Ability
 *
 * Primitive ability for uploading videos to YouTube via the official YouTube
 * Data API v3 resumable upload protocol (videos.insert).
 *
 * Supported and verified against the official YouTube Data API reference:
 *   POST /upload/youtube/v3/videos?uploadType=resumable  → resumable session URI
 *   PUT <session URI>                                    → 201 Created + video resource
 *
 * App verification gate (official, non-negotiable):
 *   API projects created after 28 July 2020 upload videos as PRIVATE ONLY
 *   until the project passes YouTube's compliance audit
 *   (https://support.google.com/youtube/contact/yt_api_form). This ability
 *   defaults to privacy_status=private and never claims public visibility is
 *   available without that audit. See docs/handlers/youtube.md.
 *
 * YouTube Community posts are NOT supported by any public API and are never
 * claimed here.
 *
 * @package DataMachineSocials
 * @subpackage Abilities\YouTube
 * @since 0.17.0
 *
 * @link https://developers.google.com/youtube/v3/docs/videos/insert
 * @link https://developers.google.com/youtube/v3/guides/using_resumable_upload_protocol
 */

namespace DataMachineSocials\Abilities\YouTube;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachineSocials\Abilities\AbstractSocialAbility;

defined( 'ABSPATH' ) || exit;

/**
 * YouTube Upload Ability
 */
class YouTubeUploadAbility extends AbstractSocialAbility {

	/**
	 * Whether the ability has been registered.
	 *
	 * @var bool
	 */
	protected static bool $registered = false;

	const UPLOAD_BASE = 'https://www.googleapis.com/upload/youtube/v3/videos';

	const MAX_TITLE_LENGTH       = 100;
	const MAX_DESCRIPTION_LENGTH = 5000;
	const MAX_TAGS               = 500;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->registerAbility( $this->registerCallback() );
	}

	/**
	 * Build the YouTube upload ability registration callback.
	 *
	 * @return callable
	 */
	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/youtube-upload',
				array(
					'label'               => __( 'Upload Video to YouTube', 'data-machine-socials' ),
					'description'         => __( 'Upload a video to the authenticated channel via the YouTube Data API resumable upload protocol. Defaults to private until the API project passes YouTube compliance audit.', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'title' ),
						'properties' => array(
							'title'           => array(
								'type'        => 'string',
								'description' => 'Video title (max 100 characters)',
								'maxLength'   => self::MAX_TITLE_LENGTH,
							),
							'description'     => array(
								'type'        => 'string',
								'description' => 'Video description (max 5000 characters)',
								'maxLength'   => self::MAX_DESCRIPTION_LENGTH,
							),
							'tags'            => array(
								'type'        => 'array',
								'description' => 'Video tags (max 500 total)',
								'items'       => array( 'type' => 'string' ),
							),
							'category_id'     => array(
								'type'        => 'string',
								'description' => 'YouTube video category ID (e.g. 10 = Music, 22 = People & Blogs)',
							),
							'privacy_status'  => array(
								'type'        => 'string',
								'enum'        => array( 'private', 'unlisted', 'public' ),
								'default'     => 'private',
								'description' => 'Privacy status. Unverified API projects can only upload private videos.',
							),
							'video_file_path' => array(
								'type'        => 'string',
								'description' => 'Absolute local path to the video file to upload',
							),
							'video_url'       => array(
								'type'        => 'string',
								'description' => 'Public URL of a video to download and upload (used when no local path is available)',
								'format'      => 'uri',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'  => array( 'type' => 'boolean' ),
							'video_id' => array( 'type' => 'string' ),
							'url'      => array(
								'type'   => 'string',
								'format' => 'uri',
							),
							'privacy_status' => array( 'type' => 'string' ),
							'error'    => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'execute_upload' ),
					'permission_callback' => fn() => PermissionHelper::can( 'use_tools' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};
	}

	/**
	 * Execute a YouTube video upload via the resumable protocol.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error Upload result or error.
	 */
	public static function execute_upload( array $input ): array|\WP_Error {
		$title = trim( (string) ( $input['title'] ?? '' ) );
		if ( '' === $title ) {
			return new \WP_Error( 'missing_param', 'A video title is required', array( 'status' => 400 ) );
		}
		if ( mb_strlen( $title ) > self::MAX_TITLE_LENGTH ) {
			return new \WP_Error( 'missing_param', sprintf( 'Title exceeds %d characters', self::MAX_TITLE_LENGTH ), array( 'status' => 400 ) );
		}

		$provider = self::resolve_auth_provider();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$access_token = $provider->get_valid_access_token();
		if ( empty( $access_token ) ) {
			return new \WP_Error( 'missing_auth', 'YouTube access token unavailable (expired or refresh failed)', array( 'status' => 401 ) );
		}

		// Resolve a local video file. YouTube requires raw bytes (it does not
		// fetch from a URL like Instagram does), so a URL input is downloaded
		// to a temp file first.
		$temp_file = self::resolveLocalVideo( $input );
		if ( is_wp_error( $temp_file ) ) {
			return $temp_file;
		}

		$privacy_status = in_array( $input['privacy_status'] ?? '', array( 'private', 'unlisted', 'public' ), true )
			? $input['privacy_status']
			: 'private';

		$metadata = self::buildMetadata( $input, $privacy_status );

		// Step 1: initiate the resumable session.
		$size = filesize( $temp_file->path );
		if ( false === $size ) {
			( $temp_file->cleanup )();
			return new \WP_Error( 'api_error', 'Could not determine local video file size', array( 'status' => 500 ) );
		}

		$location = self::init_resumable_session( $access_token, $metadata, $temp_file->mime, $size );
		if ( is_wp_error( $location ) ) {
			( $temp_file->cleanup )();
			return $location;
		}

		// Step 2: upload the bytes.
		$result = self::upload_bytes( $location, $access_token, $temp_file->path, $temp_file->mime );
		( $temp_file->cleanup )();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$video_id = $result['id'] ?? '';
		if ( '' === $video_id ) {
			return new \WP_Error( 'api_error', 'YouTube did not return a video ID after upload', array( 'status' => 500 ) );
		}

		return array(
			'success'        => true,
			'video_id'       => $video_id,
			'url'            => 'https://www.youtube.com/watch?v=' . $video_id,
			'privacy_status' => $privacy_status,
		);
	}

	/**
	 * Build the video resource metadata for the resumable init request.
	 *
	 * @param array  $input         Ability input.
	 * @param string $privacy_status Resolved privacy status.
	 * @return array Video resource (snippet + status).
	 */
	private static function buildMetadata( array $input, string $privacy_status ): array {
		$snippet = array( 'title' => trim( (string) ( $input['title'] ?? '' ) ) );

		$description = trim( (string) ( $input['description'] ?? '' ) );
		if ( '' !== $description ) {
			$snippet['description'] = mb_substr( $description, 0, self::MAX_DESCRIPTION_LENGTH );
		}

		if ( ! empty( $input['tags'] ) && is_array( $input['tags'] ) ) {
			$tags = array_slice( array_filter( array_map( 'strval', $input['tags'] ) ), 0, self::MAX_TAGS );
			if ( ! empty( $tags ) ) {
				$snippet['tags'] = $tags;
			}
		}

		if ( ! empty( $input['category_id'] ) ) {
			$snippet['categoryId'] = (string) $input['category_id'];
		}

		return array(
			'snippet' => $snippet,
			'status'  => array(
				'privacyStatus'      => $privacy_status,
				'selfDeclaredMadeForKids' => false,
			),
		);
	}

	/**
	 * Initiate a resumable upload session and return the session URI.
	 *
	 * @param string $access_token Valid access token.
	 * @param array  $metadata     Video resource metadata.
	 * @param string $mime         Video MIME type.
	 * @param int    $size         File size in bytes.
	 * @return string|\WP_Error Resumable session URI (Location header) or error.
	 */
	private static function init_resumable_session( string $access_token, array $metadata, string $mime, int $size ) {
		$url = self::UPLOAD_BASE . '?uploadType=resumable&part=snippet,status';

		$result = HttpClient::request(
			'POST',
			$url,
			array(
				'headers' => array(
					'Authorization'           => 'Bearer ' . $access_token,
					'Content-Type'            => 'application/json; charset=UTF-8',
					'X-Upload-Content-Type'   => $mime,
					'X-Upload-Content-Length' => (string) $size,
				),
				'body'    => wp_json_encode( $metadata ),
				'timeout' => 30,
				'context' => 'YouTube Upload Init',
			)
		);

		if ( empty( $result['success'] ) ) {
			return new \WP_Error( 'api_error', 'YouTube resumable session init failed: ' . ( $result['error'] ?? 'unknown error' ), array( 'status' => 500 ) );
		}

		$headers  = $result['headers'];
		$location = null;
		if ( is_object( $headers ) ) {
			$location = $headers->get( 'Location' ) ?: $headers->get( 'location' );
		} elseif ( is_array( $headers ) ) {
			foreach ( array( 'Location', 'location' ) as $key ) {
				if ( ! empty( $headers[ $key ] ) ) {
					$location = $headers[ $key ];
					break;
				}
			}
		}

		if ( empty( $location ) ) {
			return new \WP_Error( 'api_error', 'YouTube did not return a resumable session Location header', array( 'status' => 500 ) );
		}

		return (string) $location;
	}

	/**
	 * Upload the video bytes to the resumable session URI.
	 *
	 * @param string $location     Resumable session URI.
	 * @param string $access_token Valid access token.
	 * @param string $file_path    Local video file path.
	 * @param string $mime         Video MIME type.
	 * @return array|\WP_Error Decoded video resource or error.
	 */
	private static function upload_bytes( string $location, string $access_token, string $file_path, string $mime ) {
		$bytes = @file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local video bytes for upload.
		if ( false === $bytes ) {
			return new \WP_Error( 'api_error', 'Could not read local video file for upload', array( 'status' => 500 ) );
		}

		$result = HttpClient::request(
			'PUT',
			$location,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => $mime,
				),
				'body'    => $bytes,
				'timeout' => 600,
				'context' => 'YouTube Upload Bytes',
			)
		);

		if ( empty( $result['success'] ) ) {
			return new \WP_Error( 'api_error', 'YouTube video upload failed: ' . ( $result['error'] ?? 'unknown error' ), array( 'status' => 500 ) );
		}

		$data = json_decode( $result['data'], true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'api_error', 'YouTube returned an unparseable upload response', array( 'status' => 500 ) );
		}

		if ( ! empty( $data['error']['message'] ) ) {
			return new \WP_Error( 'api_error', $data['error']['message'], array( 'status' => 500 ) );
		}

		return $data;
	}

	/**
	 * Resolve a local video file, downloading from a URL when no local path
	 * is provided.
	 *
	 * Returns a small value object with path, mime, and a cleanup() callback.
	 *
	 * @param array $input Ability input.
	 * @return object|\WP_Error
	 */
	private static function resolveLocalVideo( array $input ) {
		$file_path = (string) ( $input['video_file_path'] ?? '' );

		if ( '' !== $file_path && file_exists( $file_path ) ) {
			return (object) array(
				'path'    => $file_path,
				'mime'    => self::guessMime( $file_path ),
				'cleanup' => static function () {},
			);
		}

		// Fallback: download a public video URL to a temp file.
		$video_url = (string) ( $input['video_url'] ?? '' );
		if ( '' === $video_url || ! filter_var( $video_url, FILTER_VALIDATE_URL ) ) {
			return new \WP_Error( 'missing_param', 'Either video_file_path or a valid video_url is required', array( 'status' => 400 ) );
		}

		$download = HttpClient::get( $video_url, array( 'context' => 'YouTube Upload Download', 'timeout' => 300 ) );
		if ( empty( $download['success'] ) ) {
			return new \WP_Error( 'api_error', 'Could not download video for upload: ' . ( $download['error'] ?? 'unknown error' ), array( 'status' => 500 ) );
		}

		$tmp_path = wp_tempnam( 'yt-upload-' );
		if ( ! $tmp_path ) {
			return new \WP_Error( 'api_error', 'Could not create a temp file for the video download', array( 'status' => 500 ) );
		}
		$written = file_put_contents( $tmp_path, $download['data'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- Writing downloaded video bytes to a temp file.
		if ( false === $written ) {
			return new \WP_Error( 'api_error', 'Could not write the downloaded video to a temp file', array( 'status' => 500 ) );
		}

		return (object) array(
			'path'    => $tmp_path,
			'mime'    => 'video/mp4',
			'cleanup' => static function () use ( $tmp_path ) {
				if ( file_exists( $tmp_path ) ) {
					@unlink( $tmp_path );
				}
			},
		);
	}

	/**
	 * Guess a video MIME type from a file path.
	 *
	 * @param string $file_path Local file path.
	 * @return string MIME type.
	 */
	private static function guessMime( string $file_path ): string {
		if ( function_exists( 'mime_content_type' ) ) {
			$mime = mime_content_type( $file_path );
			if ( is_string( $mime ) && '' !== $mime && 'application/octet-stream' !== $mime ) {
				return $mime;
			}
		}
		return 'video/mp4';
	}

	/**
	 * Resolve and authenticate the YouTube provider.
	 *
	 * @return object|\WP_Error
	 */
	private static function resolve_auth_provider() {
		$auth     = new AuthAbilities();
		$provider = $auth->getProvider( 'youtube' );

		if ( ! $provider || ! $provider->is_authenticated() ) {
			return new \WP_Error( 'missing_auth', 'YouTube not authenticated', array( 'status' => 401 ) );
		}

		return $provider;
	}
}
