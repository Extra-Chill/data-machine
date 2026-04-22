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
		$platforms     = $params['platforms'] ?? array();
		$images        = $params['images'] ?? array();
		$caption       = sanitize_textarea_field( $params['caption'] ?? '' );
		$post_id       = intval( $params['post_id'] ?? 0 );
		$aspect_ratio  = sanitize_text_field( $params['aspect_ratio'] ?? '4:5' );
		$media_kind    = sanitize_text_field( $params['media_kind'] ?? 'image' );
		$video_url     = sanitize_url( $params['video_url'] ?? '' );
		$cover_url     = sanitize_url( $params['cover_url'] ?? '' );
		$share_to_feed = $params['share_to_feed'] ?? true;

		if ( empty( $platforms ) || ! is_array( $platforms ) ) {
			return array(
				'success' => false,
				'error'   => 'No platforms selected',
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

		$source_url = $post_id ? get_permalink( $post_id ) : '';

		$extra = array(
			'media_kind'    => $media_kind,
			'video_url'     => $video_url,
			'cover_url'     => $cover_url,
			'share_to_feed' => $share_to_feed,
		);

		$results = array();
		$errors  = array();

		foreach ( $platforms as $platform ) {
			$result    = self::post_to_platform( $platform, $images, $caption, $source_url, $extra );
			$results[] = $result;

			if ( ! $result['success'] ) {
				$errors[] = $platform . ': ' . $result['error'];
			}

			// Track successful shares via SocialShareTracker when post_id is available.
			if ( $post_id && ! empty( $result['success'] ) ) {
				SocialShareTracker::record_from_result(
					$post_id,
					$platform,
					$result,
					array( 'media_kind' => $media_kind )
				);
			}
		}

		return array(
			'success' => empty( $errors ),
			'results' => $results,
			'errors'  => $errors ?: null,
		);
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
		$ability_slug = "datamachine/{$platform}-publish";

		$ability = wp_get_ability( $ability_slug );

		if ( ! $ability ) {
			return array(
				'platform' => $platform,
				'success'  => false,
				'error'    => "Ability {$ability_slug} not registered",
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
			'image_urls' => $image_urls,
			'source_url' => $source_url,
		);

		$media_kind = $extra['media_kind'] ?? 'image';
		if ( 'reel' === $media_kind ) {
			$input['media_kind']    = 'reel';
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
				'platform' => $platform,
				'success'  => false,
				'error'    => $result->get_error_message(),
			);
		}

		if ( ! empty( $result['success'] ) ) {
			return array(
				'platform'         => $platform,
				'success'          => true,
				'platform_post_id' => SocialShareTracker::extract_platform_post_id( $platform, $result ),
				'platform_url'     => SocialShareTracker::extract_platform_url( $platform, $result ),
				'media_kind'       => $result['media_kind'] ?? null,
			);
		}

		return array(
			'platform' => $platform,
			'success'  => false,
			'error'    => $result['error'] ?? 'Unknown error',
		);
	}
}
