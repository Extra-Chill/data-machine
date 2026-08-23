<?php
/**
 * TikTok Publish Ability
 *
 * Publishes video content to TikTok via the official Content Posting API using
 * the Direct Post flow with server-hosted video URLs (PULL_FROM_URL).
 *
 * Flow:
 *   1. Query Creator Info — get the creator's allowed privacy_level_options
 *      and max_video_post_duration_sec.
 *   2. Init Direct Post — POST /v2/post/publish/video/init/ with a JSON body
 *      containing post_info (title, privacy_level) + source_info (PULL_FROM_URL).
 *   3. Poll Post Status — POST /v2/post/publish/status/fetch/ until
 *      PUBLISH_COMPLETE or FAILED.
 *
 * Pre-audit limitation: unaudited clients can only post to private viewing
 * mode. The audit lifts the restriction to public visibility. This is a
 * documented TikTok gate, not a code issue.
 *
 * Official docs:
 *   Get Started            — https://developers.tiktok.com/doc/content-posting-api-get-started
 *   Direct Post Reference  — https://developers.tiktok.com/doc/content-posting-api-reference-direct-post
 *   Get Post Status        — https://developers.tiktok.com/doc/content-posting-api-reference-get-video-status
 *
 * @package DataMachineSocials
 * @subpackage Abilities\TikTok
 * @since 0.17.0
 */

namespace DataMachineSocials\Abilities\TikTok;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachineSocials\Abilities\AbstractSocialAbility;
use DataMachineSocials\Handlers\TikTok\TikTokAuth;
use DataMachineSocials\PublishAuthorization;

defined( 'ABSPATH' ) || exit;

/**
 * TikTok Publish Ability
 */
class TikTokPublishAbility extends AbstractSocialAbility {

	/**
	 * Whether the ability has been registered.
	 *
	 * @var bool
	 */
	protected static bool $registered = false;

	const API_BASE          = TikTokAuth::API_BASE;
	const INIT_ENDPOINT     = self::API_BASE . '/v2/post/publish/video/init/';
	const CREATOR_ENDPOINT  = self::API_BASE . '/v2/post/publish/creator_info/query/';
	const STATUS_ENDPOINT   = self::API_BASE . '/v2/post/publish/status/fetch/';

	const MAX_TITLE_LENGTH  = 2200;
	const STATUS_POLL_MAX   = 40;
	const STATUS_POLL_SLEEP = 3;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->registerAbility( $this->registerCallback() );
	}

	/**
	 * Build the TikTok publish ability registration callback.
	 *
	 * @return callable
	 */
	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/tiktok-publish',
				array(
					'label'               => __( 'Publish to TikTok', 'data-machine-socials' ),
					'description'         => __( 'Post a video to TikTok from a public video URL via the Content Posting API (Direct Post, PULL_FROM_URL). Requires video.publish scope and Content Posting Audit for public visibility.', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'content', 'video_url' ),
						'properties' => array(
							'content'        => array(
								'type'        => 'string',
								'description' => 'Video caption/title text (max 2200 characters)',
								'maxLength'   => 2200,
							),
							'video_url'      => array(
								'type'        => 'string',
								'description' => 'Public HTTPS video URL for TikTok to pull (must be on a verified domain). MP4/WebM/MOV, max 4 GB.',
								'format'      => 'uri',
							),
							'privacy_level'  => array(
								'type'        => 'string',
								'description' => 'Post visibility. PUBLIC_TO_EVERYONE requires Content Posting Audit; pre-audit posts are forced to SELF_ONLY.',
								'enum'        => array( 'PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY' ),
								'default'     => 'PUBLIC_TO_EVERYONE',
							),
							'disable_duet'   => array(
								'type'        => 'boolean',
								'description' => 'Disable duets on this video.',
								'default'     => false,
							),
							'disable_stitch' => array(
								'type'        => 'boolean',
								'description' => 'Disable stitches on this video.',
								'default'     => false,
							),
							'disable_comment' => array(
								'type'        => 'boolean',
								'description' => 'Disable comments on this video.',
								'default'     => false,
							),
							'cover_timestamp_ms' => array(
								'type'        => 'integer',
								'description' => 'Cover frame timestamp in milliseconds.',
							),
							'source_url'     => array(
								'type'        => 'string',
								'description' => 'Optional source URL appended to the caption.',
								'format'      => 'uri',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'        => array( 'type' => 'boolean' ),
							'publish_id'     => array( 'type' => 'string' ),
							'status'         => array( 'type' => 'string' ),
							'public_post_id' => array( 'type' => 'string' ),
							'post_url'       => array( 'type' => 'string', 'format' => 'uri' ),
							'error'          => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'execute_publish' ),
					'permission_callback' => array( PublishAuthorization::class, 'can_publish' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/tiktok-account',
				array(
					'label'               => __( 'TikTok Account Info', 'data-machine-socials' ),
					'description'         => __( 'Get authenticated TikTok account details and creator info', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'open_id'       => array( 'type' => 'string' ),
							'display_name'  => array( 'type' => 'string' ),
							'creator_info'  => array( 'type' => 'object' ),
							'authenticated' => array( 'type' => 'boolean' ),
							'error'         => array( 'type' => 'string' ),
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
	 * Execute TikTok publish via the Direct Post flow.
	 *
	 * @param array $input Ability input with publish parameters.
	 * @return array|\WP_Error Response with publish_id and status, or error.
	 */
	public static function execute_publish( array $input ): array|\WP_Error {
		$content  = $input['content'] ?? '';
		$video_url = $input['video_url'] ?? '';
		$source_url = $input['source_url'] ?? '';

		if ( empty( $content ) ) {
			return new \WP_Error( 'missing_param', 'Caption (content) is required', array( 'status' => 400 ) );
		}

		if ( empty( $video_url ) ) {
			return new \WP_Error( 'missing_param', 'video_url is required for TikTok publish', array( 'status' => 400 ) );
		}

		if ( ! filter_var( $video_url, FILTER_VALIDATE_URL ) ) {
			return new \WP_Error( 'missing_param', 'Invalid video URL: ' . $video_url, array( 'status' => 400 ) );
		}

		$provider = self::resolve_provider();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$access_token = $provider->get_valid_access_token();
		if ( empty( $access_token ) ) {
			return new \WP_Error( 'missing_auth', 'TikTok access token unavailable', array( 'status' => 401 ) );
		}

		$privacy_level = $input['privacy_level'] ?? 'PUBLIC_TO_EVERYONE';

		// Build the caption, appending source URL if provided.
		$title = $content;
		if ( ! empty( $source_url ) ) {
			$title .= "\n\n" . $source_url;
		}
		if ( mb_strlen( $title ) > self::MAX_TITLE_LENGTH ) {
			$title = mb_substr( $title, 0, self::MAX_TITLE_LENGTH - 3 ) . '...';
		}

		// Validate privacy_level against the creator's allowed options.
		$creator_info = self::query_creator_info( $access_token );
		if ( is_wp_error( $creator_info ) ) {
			return $creator_info;
		}

		$allowed_privacy = $creator_info['privacy_level_options'] ?? array( 'SELF_ONLY' );
		if ( ! in_array( $privacy_level, $allowed_privacy, true ) ) {
			// Fall back to the first allowed option rather than failing hard.
			$privacy_level = $allowed_privacy[0];
		}

		// Init the direct post.
		$publish_id = self::init_direct_post( $access_token, $title, $video_url, $privacy_level, $input );
		if ( is_wp_error( $publish_id ) ) {
			return $publish_id;
		}

		// Poll for completion.
		$status_result = self::poll_post_status( $access_token, $publish_id );
		if ( is_wp_error( $status_result ) ) {
			// Return the publish_id even on status failure so the caller can retry.
			return array(
				'success'    => true,
				'publish_id' => $publish_id,
				'status'     => 'PROCESSING',
				'error'      => $status_result->get_error_message(),
			);
		}

		return $status_result;
	}

	/**
	 * Query creator info to determine allowed privacy levels and max duration.
	 *
	 * Required before init — the privacy_level passed to init must match one
	 * of the options returned here.
	 *
	 * @param string $access_token Valid TikTok user access token.
	 * @return array|\WP_Error Creator info data or error.
	 */
	public static function query_creator_info( string $access_token ): array|\WP_Error {
		$result = HttpClient::post(
			self::CREATOR_ENDPOINT,
			array(
				'context' => 'TikTok Creator Info',
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json; charset=UTF-8',
				),
				'body'    => wp_json_encode( array() ),
				'timeout' => 30,
			)
		);

		return self::parse_json_response( $result, 'TikTok creator info query' );
	}

	/**
	 * Init a direct post with PULL_FROM_URL.
	 *
	 * @param string $access_token  Valid access token.
	 * @param string $title         Caption text.
	 * @param string $video_url     Public HTTPS video URL.
	 * @param string $privacy_level One of the creator's allowed privacy levels.
	 * @param array  $input         Full input for optional fields.
	 * @return string|\WP_Error publish_id or error.
	 */
	private static function init_direct_post( string $access_token, string $title, string $video_url, string $privacy_level, array $input ): string|\WP_Error {
		$post_info = array(
			'title'         => $title,
			'privacy_level' => $privacy_level,
			'disable_duet'  => ! empty( $input['disable_duet'] ),
			'disable_stitch' => ! empty( $input['disable_stitch'] ),
			'disable_comment' => ! empty( $input['disable_comment'] ),
		);

		if ( isset( $input['cover_timestamp_ms'] ) ) {
			$post_info['video_cover_timestamp_ms'] = (int) $input['cover_timestamp_ms'];
		}

		$body = array(
			'post_info'   => $post_info,
			'source_info' => array(
				'source'    => 'PULL_FROM_URL',
				'video_url' => $video_url,
			),
		);

		$result = HttpClient::post(
			self::INIT_ENDPOINT,
			array(
				'context' => 'TikTok Direct Post Init',
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json; charset=UTF-8',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 60,
			)
		);

		$data = self::parse_json_response( $result, 'TikTok direct post init' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( empty( $data['publish_id'] ) ) {
			return new \WP_Error( 'api_error', 'TikTok init returned no publish_id', array( 'status' => 500 ) );
		}

		return $data['publish_id'];
	}

	/**
	 * Poll the post status endpoint until completion or timeout.
	 *
	 * @param string $access_token Valid access token.
	 * @param string $publish_id   Publish ID from init.
	 * @param int    $max_retries  Max poll attempts.
	 * @return array|\WP_Error Result array or error.
	 */
	public static function poll_post_status( string $access_token, string $publish_id, int $max_retries = self::STATUS_POLL_MAX ): array|\WP_Error {
		for ( $i = 0; $i < $max_retries; $i++ ) {
			$status_data = self::fetch_post_status( $access_token, $publish_id );
			if ( is_wp_error( $status_data ) ) {
				return $status_data;
			}

			$status = $status_data['status'] ?? '';

			if ( 'PUBLISH_COMPLETE' === $status ) {
				$public_post_id = '';
				if ( ! empty( $status_data['publicaly_available_post_id'] ) && is_array( $status_data['publicaly_available_post_id'] ) ) {
					$public_post_id = (string) reset( $status_data['publicaly_available_post_id'] );
				}

				return array(
					'success'        => true,
					'publish_id'     => $publish_id,
					'status'         => 'PUBLISH_COMPLETE',
					'public_post_id' => $public_post_id,
					// TikTok status provides an ID, not a canonical public URL or
					// username. Do not synthesize a misleading permalink.
					'post_url'       => '',
				);
			}

			if ( 'FAILED' === $status ) {
				$fail_reason = $status_data['fail_reason'] ?? 'unknown';
				return new \WP_Error( 'api_error', 'TikTok publish failed: ' . $fail_reason, array( 'status' => 500 ) );
			}

			// Still processing (PROCESSING_DOWNLOAD, PROCESSING_UPLOAD, SEND_TO_USER_INBOX).
			sleep( self::STATUS_POLL_SLEEP );
		}

		// Timed out waiting — not a failure, just async.
		return array(
			'success'    => true,
			'publish_id' => $publish_id,
			'status'     => 'PROCESSING',
			'error'      => 'TikTok post is still processing. Check status later with the publish_id.',
		);
	}

	/**
	 * Fetch the status of a single publish attempt.
	 *
	 * @param string $access_token Valid access token.
	 * @param string $publish_id   Publish ID.
	 * @return array|\WP_Error Status data or error.
	 */
	public static function fetch_post_status( string $access_token, string $publish_id ): array|\WP_Error {
		$result = HttpClient::post(
			self::STATUS_ENDPOINT,
			array(
				'context' => 'TikTok Post Status',
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json; charset=UTF-8',
				),
				'body'    => wp_json_encode( array( 'publish_id' => $publish_id ) ),
				'timeout' => 30,
			)
		);

		return self::parse_json_response( $result, 'TikTok post status' );
	}

	/**
	 * Parse a TikTok JSON API response into data or WP_Error.
	 *
	 * TikTok wraps responses in { data: {...}, error: { code, message, log_id } }.
	 * On error code != "ok", we surface the message.
	 *
	 * @param array  $result HttpClient result.
	 * @param string $context Context label for errors.
	 * @return array|\WP_Error Parsed data array or error.
	 */
	private static function parse_json_response( array $result, string $context ): array|\WP_Error {
		if ( empty( $result['success'] ) ) {
			return new \WP_Error( 'api_error', $context . ': ' . ( $result['error'] ?? 'HTTP request failed' ), array( 'status' => 500 ) );
		}

		$decoded = json_decode( $result['data'], true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'api_error', $context . ': invalid JSON response', array( 'status' => 500 ) );
		}

		$error = $decoded['error'] ?? array();
		if ( isset( $error['code'] ) && 'ok' !== $error['code'] ) {
			$message = $error['message'] ?? $error['code'];
			$code    = $error['code'];
			return new \WP_Error( 'api_error', $context . ': ' . $message . ' (' . $code . ')', array( 'status' => 500, 'tiktok_error' => $error ) );
		}

		return $decoded['data'] ?? $decoded;
	}

	/**
	 * Get TikTok account and creator details.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error Account details or error.
	 */
	public static function get_account( array $input ): array|\WP_Error {
		$input;
		$provider = self::resolve_provider();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$access_token = $provider->get_valid_access_token();
		if ( empty( $access_token ) ) {
			return new \WP_Error( 'missing_auth', 'TikTok access token unavailable', array( 'status' => 401 ) );
		}

		// Fetch display name via the Display API user info endpoint.
		$display_name = '';
		$user_result  = HttpClient::get(
			self::API_BASE . '/v2/user/info/?fields=open_id,display_name',
			array(
				'context' => 'TikTok User Info',
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
				'timeout' => 30,
			)
		);

		if ( ! empty( $user_result['success'] ) ) {
			$user_data = json_decode( $user_result['data'], true );
			if ( ! empty( $user_data['data']['user']['display_name'] ) ) {
				$display_name = $user_data['data']['user']['display_name'];
			}
		}

		// Fetch creator info (privacy options + max duration).
		$creator_info = self::query_creator_info( $access_token );
		if ( is_wp_error( $creator_info ) ) {
			$creator_info = array();
		}

		return array(
			'success'       => true,
			'authenticated' => true,
			'open_id'       => $provider->get_user_id() ?? '',
			'display_name'  => $display_name,
			'creator_info'  => $creator_info,
		);
	}

	/**
	 * Resolve and authenticate the TikTok auth provider.
	 *
	 * @return object|\WP_Error Provider or error.
	 */
	private static function resolve_provider() {
		$auth     = new AuthAbilities();
		$provider = $auth->getProvider( 'tiktok' );

		if ( ! $provider || ! $provider->is_authenticated() ) {
			return new \WP_Error( 'missing_auth', 'TikTok not authenticated', array( 'status' => 401 ) );
		}

		return $provider;
	}
}
