<?php
/**
 * Social Publisher
 *
 * Core publishing logic extracted from RestApi for reuse by both
 * the REST endpoint and the DM Task System.
 *
 * @package DataMachineSocials
 * @since   0.12.0
 */

namespace DataMachineSocials;

use DataMachineSocials\Tracking\SocialShareTracker;

defined( 'ABSPATH' ) || exit;

class Publisher {

	/**
	 * Cross-post content to multiple social platforms.
	 *
	 * Takes the same params shape as the REST request and returns
	 * per-platform results. Does NOT schedule jobs — that is the
	 * caller's responsibility (RestApi or SocialCrossPostTask).
	 *
	 * @param array $params {
	 *     @type array  $platforms    Target platforms.
	 *     @type string $caption      Post caption.
	 *     @type array  $images       Image objects with 'url' key.
	 *     @type int    $post_id      Optional WP post ID.
	 *     @type int    $post_site_id Optional canonical WP site ID.
	 *     @type array  $attribution_post Optional site_id/post_id tracking owner.
	 *     @type string $aspect_ratio Image aspect ratio.
	 *     @type string $media_kind   image | carousel | reel | story.
	 *     @type string $video_url    Video URL for reels/stories.
	 *     @type string $cover_url    Cover image URL.
	 *     @type bool   $share_to_feed Share reel to feed.
	 * }
	 * @return array{
	 *     success: bool,
	 *     results: array,
	 *     errors?: array,
	 * }
	 */
	public static function cross_post( array $params ): array {
		$post_site_id = absint( $params['post_site_id'] ?? get_current_blog_id() );
		if ( $post_site_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'Invalid canonical post site',
				'results' => array(),
			);
		}

		return self::with_site( $post_site_id, static fn(): array => self::cross_post_in_current_site( $params ) );
	}

	private static function cross_post_in_current_site( array $params ): array {
		$platforms     = $params['platforms'] ?? array();
		$images        = $params['images'] ?? array();
		$caption       = sanitize_textarea_field( $params['caption'] ?? '' );
		$post_id       = intval( $params['post_id'] ?? 0 );
		$aspect_ratio  = sanitize_text_field( $params['aspect_ratio'] ?? '4:5' );
		$media_kind    = sanitize_text_field( $params['media_kind'] ?? 'image' );
		$video_url     = sanitize_url( $params['video_url'] ?? '' );
		$cover_url     = sanitize_url( $params['cover_url'] ?? '' );
		$share_to_feed = $params['share_to_feed'] ?? true;
		$operation_ref = sanitize_text_field( $params['delegated_operation_ref'] ?? '' );
		$tracking_post = self::tracking_post( $params, $post_id );

		if ( empty( $platforms ) || ! is_array( $platforms ) ) {
			return array(
				'success' => false,
				'error'   => 'No platforms selected',
				'results' => array(),
			);
		}

		$contract_validation = PublishComposerContract::validate_cross_post( $platforms, $media_kind );
		if ( is_wp_error( $contract_validation ) ) {
			return array(
				'success' => false,
				'error'   => $contract_validation->get_error_message(),
				'results' => array(),
			);
		}

		if ( 'reel' === $media_kind ) {
			if ( empty( $video_url ) ) {
				return array(
					'success' => false,
					'error'   => 'video_url is required for Reel publishing',
					'results' => array(),
				);
			}
		} elseif ( 'story' === $media_kind ) {
			if ( empty( $video_url ) && empty( $images ) ) {
				return array(
					'success' => false,
					'error'   => 'image or video_url is required for Story publishing',
					'results' => array(),
				);
			}
		} elseif ( empty( $images ) ) {
			return array(
				'success' => false,
				'error'   => 'No images provided',
				'results' => array(),
			);
		}

		$source_url = sanitize_url( $params['source_url'] ?? '' );
		if ( '' === $source_url && $post_id ) {
			$source_url = get_permalink( $post_id );
		}

		$extra = array(
			'media_kind'    => $media_kind,
			'video_url'     => $video_url,
			'cover_url'     => $cover_url,
			'share_to_feed' => $share_to_feed,
		);

		$results = array();
		$errors  = array();

		foreach ( $platforms as $platform ) {
			$existing_share = $tracking_post['post_id'] && '' !== $operation_ref
				? self::with_site( $tracking_post['site_id'], static fn() => SocialShareTracker::get_operation_share( $tracking_post['post_id'], $platform, $operation_ref ) )
				: null;
			$result         = $existing_share
				? array(
					'platform'         => $platform,
					'success'          => true,
					'platform_post_id' => (string) ( $existing_share['platform_post_id'] ?? '' ),
					'platform_url'     => (string) ( $existing_share['platform_url'] ?? '' ),
					'media_kind'       => (string) ( $existing_share['media_kind'] ?? '' ),
					'replayed'         => true,
				)
				: self::post_to_platform( $platform, $images, $caption, $source_url, $extra );
			// Track successful shares via SocialShareTracker when post_id is available.
			if ( $tracking_post['post_id'] && ! $existing_share && ! empty( $result['success'] ) ) {
				$recorded = self::with_site(
					$tracking_post['site_id'],
					static fn(): bool => SocialShareTracker::record_from_result(
						$tracking_post['post_id'],
						$platform,
						$result,
						array(
							'media_kind'    => $media_kind,
							'operation_ref' => $operation_ref,
						)
					)
				);
				if ( '' !== $operation_ref && ! $recorded ) {
					$result = array(
						'platform'       => $platform,
						'success'        => false,
						'error'          => 'delivery_receipt_failed',
						'error_code'     => 'delivery_receipt_failed',
						'delivery_state' => 'unknown',
					);
				}
			}

			$results[] = $result;
			if ( ! $result['success'] ) {
				$errors[] = $platform . ': ' . $result['error'];
			}
		}

		return array(
			'success' => empty( $errors ),
			'results' => $results,
			'errors'  => $errors ? $errors : null,
		);
	}

	/** @return array{site_id:int,post_id:int} */
	private static function tracking_post( array $params, int $post_id ): array {
		$reference = is_array( $params['attribution_post'] ?? null ) ? $params['attribution_post'] : array();
		$site_id   = absint( $reference['site_id'] ?? 0 );
		$tracking_post_id = absint( $reference['post_id'] ?? 0 );

		return $site_id && $tracking_post_id
			? array( 'site_id' => $site_id, 'post_id' => $tracking_post_id )
			: array( 'site_id' => get_current_blog_id(), 'post_id' => $post_id );
	}

	/** @return mixed */
	private static function with_site( int $site_id, callable $callback ) {
		$switched = get_current_blog_id() !== $site_id;
		if ( $switched ) {
			switch_to_blog( $site_id );
		}

		try {
			return $callback();
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Post to an individual platform via its publish ability.
	 *
	 * @param string $platform   Platform slug.
	 * @param array  $images     Array of image objects with 'url' key.
	 * @param string $caption    Post caption.
	 * @param string $source_url Source URL to attribute.
	 * @param array  $extra      Extra params (media_kind, video_url, cover_url, share_to_feed).
	 * @return array Result.
	 */
	public static function post_to_platform( string $platform, array $images, string $caption, string $source_url, array $extra = array() ): array {
		$media_kind          = $extra['media_kind'] ?? 'image';
		$contract_validation = PublishComposerContract::validate_cross_post( array( $platform ), $media_kind );
		if ( is_wp_error( $contract_validation ) ) {
			return array(
				'platform'       => $platform,
				'success'        => false,
				'error'          => $contract_validation->get_error_message(),
				'error_code'     => $contract_validation->get_error_code(),
				'delivery_state' => 'undelivered',
			);
		}

		$ability_slug = "datamachine/{$platform}-publish";

		$ability = wp_get_ability( $ability_slug );

		if ( ! $ability ) {
			return array(
				'platform'       => $platform,
				'success'        => false,
				'error'          => "Ability {$ability_slug} not registered",
				'error_code'     => 'channel_unavailable',
				'delivery_state' => 'undelivered',
			);
		}

		$image_urls = array_map(
			function ( $img ) {
				return $img['url'] ?? '';
			},
			$images
		);

		$input = array(
			'content'    => $caption,
			'source_url' => $source_url,
		);
		if ( in_array( $platform, array( 'instagram', 'twitter' ), true ) ) {
			$input['image_urls'] = $image_urls;
		} elseif ( in_array( $platform, array( 'bluesky', 'facebook', 'threads' ), true ) ) {
			$input['image_url'] = $image_urls[0] ?? '';
		} elseif ( 'pinterest' === $platform ) {
			$input = array(
				'title'       => mb_substr( wp_strip_all_tags( $caption ), 0, 100 ),
				'description' => $caption,
				'image_url'   => $image_urls[0] ?? '',
				'link'        => $source_url,
			);
		}

		$input['media_kind'] = $media_kind;
		if ( 'reel' === $media_kind ) {
			$input['video_url']     = $extra['video_url'] ?? '';
			$input['cover_url']     = $extra['cover_url'] ?? '';
			$input['share_to_feed'] = $extra['share_to_feed'] ?? true;
		} elseif ( 'story' === $media_kind ) {
			$input['media_kind'] = 'story';
			$input['video_url']  = $extra['video_url'] ?? '';
			if ( ! empty( $image_urls[0] ) && empty( $extra['video_url'] ) ) {
				$input['story_image_url'] = $image_urls[0];
			}
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return array(
				'platform'       => $platform,
				'success'        => false,
				'error'          => $result->get_error_message(),
				'error_code'     => $result->get_error_code(),
				'delivery_state' => self::is_explicitly_undelivered_error( $result->get_error_code() ) ? 'undelivered' : 'unknown',
			);
		}

		if ( ! empty( $result['success'] ) ) {
			$platform_post_id = SocialShareTracker::extract_platform_post_id( $platform, $result );
			$platform_url     = SocialShareTracker::extract_platform_url( $platform, $result );
			if ( ! SocialShareTracker::is_safe_platform_reference( $platform, $platform_post_id, $platform_url ) ) {
				return array(
					'platform'       => $platform,
					'success'        => false,
					'error'          => 'delivery_receipt_failed',
					'error_code'     => 'delivery_receipt_failed',
					'delivery_state' => 'unknown',
				);
			}
			return array(
				'platform'         => $platform,
				'success'          => true,
				'platform_post_id' => $platform_post_id,
				'platform_url'     => $platform_url,
				'media_kind'       => $result['media_kind'] ?? null,
			);
		}

		return array(
			'platform'       => $platform,
			'success'        => false,
			'error'          => $result['error'] ?? 'Unknown error',
			'error_code'     => $result['error_code'] ?? 'publish_failed',
			'delivery_state' => ! empty( $result['undelivered'] ) ? 'undelivered' : 'unknown',
		);
	}

	private static function is_explicitly_undelivered_error( string $error_code ): bool {
		return in_array( $error_code, array( 'invalid_media_url', 'media_download_failed', 'media_upload_failed', 'missing_auth', 'missing_param', 'not_found' ), true );
	}
}
