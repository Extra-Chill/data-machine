<?php
/**
 * Social Share Tracker
 *
 * Tracks which WordPress posts/attachments have been shared to which
 * social platforms. Uses WordPress post meta for storage — no custom tables.
 *
 * Meta keys:
 * - _datamachine_social_shares: Full share history (array of share records)
 * - _datamachine_shared_platforms: Flat array of platform slugs for fast lookups
 *
 * @package    DataMachineSocials
 * @subpackage Tracking
 * @since      0.4.0
 */

namespace DataMachineSocials\Tracking;

defined( 'ABSPATH' ) || exit;

class SocialShareTracker {

	/**
	 * Post meta key for full share history.
	 */
	const SHARES_META_KEY = '_datamachine_social_shares';

	/**
	 * Post meta key for quick platform lookup.
	 */
	const PLATFORMS_META_KEY = '_datamachine_shared_platforms';

	/**
	 * Record a successful share to a social platform.
	 *
	 * @param int    $post_id          WordPress post or attachment ID.
	 * @param string $platform         Platform slug (instagram, twitter, etc.).
	 * @param string $platform_post_id Platform-specific post ID (media_id, tweet_id, etc.).
	 * @param string $platform_url     Permalink on the platform.
	 * @param array  $extra            Optional extra data (media_kind, shared_by, job_id, etc.).
	 * @return bool True on success.
	 */
	public static function record( int $post_id, string $platform, string $platform_post_id = '', string $platform_url = '', array $extra = array() ): bool {
		if ( ! $post_id || empty( $platform ) ) {
			return false;
		}

		$shares = self::get_shares( $post_id );

		$record = array(
			'platform'         => sanitize_key( $platform ),
			'platform_post_id' => sanitize_text_field( $platform_post_id ),
			'platform_url'     => esc_url_raw( $platform_url ),
			'shared_at'        => time(),
			'shared_by'        => $extra['shared_by'] ?? get_current_user_id(),
			'media_kind'       => sanitize_key( $extra['media_kind'] ?? '' ),
			'job_id'           => intval( $extra['job_id'] ?? 0 ) ?: null,
		);

		$shares[] = $record;

		update_post_meta( $post_id, self::SHARES_META_KEY, $shares );
		self::update_platforms_index( $post_id, $shares );

		return true;
	}

	/**
	 * Check if a post has been shared to a specific platform.
	 *
	 * Uses the flat platforms index for fast lookups.
	 *
	 * @param int    $post_id  WordPress post ID.
	 * @param string $platform Platform slug.
	 * @return bool
	 */
	public static function has_been_shared( int $post_id, string $platform ): bool {
		$platforms = get_post_meta( $post_id, self::PLATFORMS_META_KEY, true );

		if ( ! is_array( $platforms ) ) {
			return false;
		}

		return in_array( $platform, $platforms, true );
	}

	/**
	 * Check if a post has been shared to any platform.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return bool
	 */
	public static function has_any_shares( int $post_id ): bool {
		$platforms = get_post_meta( $post_id, self::PLATFORMS_META_KEY, true );

		return is_array( $platforms ) && ! empty( $platforms );
	}

	/**
	 * Get all share records for a post, optionally filtered by platform.
	 *
	 * @param int         $post_id  WordPress post ID.
	 * @param string|null $platform Optional platform slug to filter by.
	 * @return array Array of share records.
	 */
	public static function get_shares( int $post_id, ?string $platform = null ): array {
		$shares = get_post_meta( $post_id, self::SHARES_META_KEY, true );

		if ( ! is_array( $shares ) ) {
			return array();
		}

		if ( null === $platform ) {
			return $shares;
		}

		return array_values(
			array_filter(
				$shares,
				fn( $share ) => ( $share['platform'] ?? '' ) === $platform
			)
		);
	}

	/**
	 * Get the list of platforms a post has been shared to.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return string[] Array of platform slugs.
	 */
	public static function get_shared_platforms( int $post_id ): array {
		$platforms = get_post_meta( $post_id, self::PLATFORMS_META_KEY, true );

		if ( ! is_array( $platforms ) ) {
			return array();
		}

		return $platforms;
	}

	/**
	 * Get the most recent share for a post on a given platform.
	 *
	 * @param int    $post_id  WordPress post ID.
	 * @param string $platform Platform slug.
	 * @return array|null Share record or null.
	 */
	public static function get_latest_share( int $post_id, string $platform ): ?array {
		$shares = self::get_shares( $post_id, $platform );

		if ( empty( $shares ) ) {
			return null;
		}

		// Sort by shared_at descending.
		usort( $shares, fn( $a, $b ) => ( $b['shared_at'] ?? 0 ) <=> ( $a['shared_at'] ?? 0 ) );

		return $shares[0];
	}

	/**
	 * Mark a specific share as deleted on the platform.
	 *
	 * Does not remove the record — sets status to 'deleted' for audit trail.
	 *
	 * @param int    $post_id          WordPress post ID.
	 * @param string $platform         Platform slug.
	 * @param string $platform_post_id Platform-specific post ID.
	 * @return bool True if found and updated.
	 */
	public static function mark_deleted( int $post_id, string $platform, string $platform_post_id ): bool {
		$shares  = self::get_shares( $post_id );
		$updated = false;

		foreach ( $shares as &$share ) {
			if (
				( $share['platform'] ?? '' ) === $platform &&
				( $share['platform_post_id'] ?? '' ) === $platform_post_id
			) {
				$share['status']     = 'deleted';
				$share['deleted_at'] = time();
				$updated             = true;
				break;
			}
		}
		unset( $share );

		if ( $updated ) {
			update_post_meta( $post_id, self::SHARES_META_KEY, $shares );
			self::update_platforms_index( $post_id, $shares );
		}

		return $updated;
	}

	/**
	 * Get share count for a post, optionally filtered by platform.
	 *
	 * Excludes deleted shares by default.
	 *
	 * @param int         $post_id        WordPress post ID.
	 * @param string|null $platform       Optional platform filter.
	 * @param bool        $include_deleted Whether to include deleted shares.
	 * @return int
	 */
	public static function count_shares( int $post_id, ?string $platform = null, bool $include_deleted = false ): int {
		$shares = self::get_shares( $post_id, $platform );

		if ( $include_deleted ) {
			return count( $shares );
		}

		return count(
			array_filter(
				$shares,
				fn( $share ) => ( $share['status'] ?? 'published' ) !== 'deleted'
			)
		);
	}

	/**
	 * Extract the platform post ID from a publish result.
	 *
	 * Different platforms use different keys (media_id, tweet_id, post_id, pin_id).
	 * This normalizes them.
	 *
	 * @param string $platform Platform slug.
	 * @param array  $result   Publish result array.
	 * @return string Platform post ID or empty string.
	 */
	public static function extract_platform_post_id( string $platform, array $result ): string {
		$id_keys = array(
			'instagram' => 'media_id',
			'twitter'   => 'tweet_id',
			'facebook'  => 'post_id',
			'bluesky'   => 'post_id',
			'threads'   => 'post_id',
			'pinterest' => 'pin_id',
		);

		$key = $id_keys[ $platform ] ?? 'post_id';

		return (string) ( $result[ $key ] ?? '' );
	}

	/**
	 * Extract the platform URL from a publish result.
	 *
	 * Different platforms use different keys (permalink, tweet_url, post_url, pin_url).
	 * This normalizes them.
	 *
	 * @param string $platform Platform slug.
	 * @param array  $result   Publish result array.
	 * @return string Platform URL or empty string.
	 */
	public static function extract_platform_url( string $platform, array $result ): string {
		$url_keys = array(
			'instagram' => 'permalink',
			'twitter'   => 'tweet_url',
			'facebook'  => 'post_url',
			'bluesky'   => 'post_url',
			'threads'   => 'post_url',
			'pinterest' => 'pin_url',
		);

		$key = $url_keys[ $platform ] ?? 'permalink';

		return (string) ( $result[ $key ] ?? '' );
	}

	/**
	 * Record a share from a publish result using platform-aware key extraction.
	 *
	 * Convenience method that combines extract_* and record().
	 *
	 * @param int    $post_id  WordPress post ID.
	 * @param string $platform Platform slug.
	 * @param array  $result   Publish result from an ability.
	 * @param array  $extra    Optional extra data.
	 * @return bool True on success.
	 */
	public static function record_from_result( int $post_id, string $platform, array $result, array $extra = array() ): bool {
		if ( empty( $result['success'] ) ) {
			return false;
		}

		$platform_post_id = self::extract_platform_post_id( $platform, $result );
		$platform_url     = self::extract_platform_url( $platform, $result );

		// Merge media_kind from result if present.
		if ( ! empty( $result['media_kind'] ) && empty( $extra['media_kind'] ) ) {
			$extra['media_kind'] = $result['media_kind'];
		}

		return self::record( $post_id, $platform, $platform_post_id, $platform_url, $extra );
	}

	/**
	 * Update the flat platforms index from the full shares array.
	 *
	 * Only includes platforms with at least one non-deleted share.
	 *
	 * @param int   $post_id WordPress post ID.
	 * @param array $shares  Full shares array.
	 */
	private static function update_platforms_index( int $post_id, array $shares ): void {
		$active_platforms = array();

		foreach ( $shares as $share ) {
			$status = $share['status'] ?? 'published';
			if ( 'deleted' !== $status && ! empty( $share['platform'] ) ) {
				$active_platforms[] = $share['platform'];
			}
		}

		$active_platforms = array_values( array_unique( $active_platforms ) );

		update_post_meta( $post_id, self::PLATFORMS_META_KEY, $active_platforms );
	}

	/**
	 * Remove all share tracking data for a post.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return bool
	 */
	public static function clear( int $post_id ): bool {
		delete_post_meta( $post_id, self::SHARES_META_KEY );
		delete_post_meta( $post_id, self::PLATFORMS_META_KEY );
		return true;
	}
}
