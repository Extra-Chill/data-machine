<?php
/**
 * WP-CLI Shares Command
 *
 * Query and manage social share tracking data.
 *
 * @package    DataMachineSocials
 * @subpackage Cli\Commands
 * @since      0.4.0
 */

namespace DataMachineSocials\Cli\Commands;

use WP_CLI;
use DataMachineSocials\Tracking\SocialShareTracker;

defined( 'ABSPATH' ) || exit;

/**
 * Manage social share tracking for Data Machine Socials.
 *
 * ## EXAMPLES
 *
 *     # List shares for a post
 *     wp datamachine-socials shares list 42
 *
 *     # Check if a post was shared to Instagram
 *     wp datamachine-socials shares check 42 instagram
 *
 *     # Clear all tracking data for a post
 *     wp datamachine-socials shares clear 42
 */
class SharesCommand {

	/**
	 * List share history for a WordPress post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The WordPress post ID.
	 *
	 * [--platform=<platform>]
	 * : Filter by platform slug (instagram, twitter, etc.).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials shares list 42
	 *     wp datamachine-socials shares list 42 --platform=instagram
	 *     wp datamachine-socials shares list 42 --format=json
	 */
	public function list_( $args, $assoc_args ) {
		$post_id  = intval( $args[0] );
		$platform = $assoc_args['platform'] ?? null;
		$format   = $assoc_args['format'] ?? 'table';

		$post = get_post( $post_id );
		if ( ! $post ) {
			WP_CLI::error( "Post {$post_id} not found." );
		}

		$shares = SocialShareTracker::get_shares( $post_id, $platform );

		if ( empty( $shares ) ) {
			$msg = $platform
				? "Post {$post_id} has not been shared to {$platform}."
				: "Post {$post_id} has no share history.";
			WP_CLI::warning( $msg );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $shares, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::success( sprintf(
			'Found %d share(s) for post %d (%s)',
			count( $shares ),
			$post_id,
			$post->post_title ?: '(no title)'
		) );
		WP_CLI::log( '' );

		foreach ( $shares as $share ) {
			$status    = $share['status'] ?? 'published';
			$date      = isset( $share['shared_at'] ) ? wp_date( 'Y-m-d H:i', $share['shared_at'] ) : '';
			$kind      = $share['media_kind'] ?? '';
			$kind_str  = $kind ? " ({$kind})" : '';
			$status_str = 'deleted' === $status ? ' [DELETED]' : '';

			WP_CLI::log( sprintf(
				'  %-12s %s  %s%s%s',
				$share['platform'] ?? '',
				$date,
				$share['platform_url'] ?? $share['platform_post_id'] ?? '',
				$kind_str,
				$status_str
			) );
		}
	}

	/**
	 * Check if a post has been shared to a platform.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The WordPress post ID.
	 *
	 * <platform>
	 * : Platform slug to check (instagram, twitter, etc.).
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials shares check 42 instagram
	 */
	public function check( $args ) {
		$post_id  = intval( $args[0] );
		$platform = $args[1] ?? '';

		if ( empty( $platform ) ) {
			WP_CLI::error( 'Platform slug is required.' );
		}

		if ( SocialShareTracker::has_been_shared( $post_id, $platform ) ) {
			$latest = SocialShareTracker::get_latest_share( $post_id, $platform );
			$date   = isset( $latest['shared_at'] ) ? wp_date( 'Y-m-d H:i', $latest['shared_at'] ) : 'unknown';
			$url    = $latest['platform_url'] ?? '';

			WP_CLI::success( "Post {$post_id} was shared to {$platform} (last: {$date})" );
			if ( $url ) {
				WP_CLI::log( "  URL: {$url}" );
			}
		} else {
			WP_CLI::warning( "Post {$post_id} has not been shared to {$platform}." );
		}
	}

	/**
	 * Show which platforms a post has been shared to.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The WordPress post ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials shares platforms 42
	 */
	public function platforms( $args ) {
		$post_id   = intval( $args[0] );
		$platforms = SocialShareTracker::get_shared_platforms( $post_id );

		if ( empty( $platforms ) ) {
			WP_CLI::warning( "Post {$post_id} has not been shared to any platform." );
			return;
		}

		WP_CLI::success( sprintf(
			'Post %d shared to: %s',
			$post_id,
			implode( ', ', $platforms )
		) );
	}

	/**
	 * Clear all share tracking data for a post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The WordPress post ID.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials shares clear 42 --yes
	 */
	public function clear( $args, $assoc_args ) {
		$post_id = intval( $args[0] );

		$count = SocialShareTracker::count_shares( $post_id, null, true );

		if ( 0 === $count ) {
			WP_CLI::warning( "Post {$post_id} has no share data to clear." );
			return;
		}

		WP_CLI::confirm(
			"Clear {$count} share record(s) for post {$post_id}?",
			$assoc_args
		);

		SocialShareTracker::clear( $post_id );

		// Also clean up legacy meta if present.
		delete_post_meta( $post_id, '_dms_shared_posts' );

		WP_CLI::success( "Share data cleared for post {$post_id}." );
	}
}
