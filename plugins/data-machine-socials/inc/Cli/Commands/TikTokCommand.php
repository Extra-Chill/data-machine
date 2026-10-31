<?php
/**
 * WP-CLI TikTok Command.
 *
 * @package DataMachineSocials
 * @subpackage Cli\Commands
 * @since 0.17.0
 */

namespace DataMachineSocials\Cli\Commands;

use DataMachine\Abilities\AuthAbilities;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

class TikTokCommand {

	/**
	 * Publish a video to TikTok from a public HTTPS URL.
	 *
	 * Public visibility requires TikTok's Content Posting Audit. Before that
	 * audit, TikTok permits private-only posts from the integration.
	 *
	 * ## OPTIONS
	 *
	 * <caption>
	 * : Video caption (max 2200 characters).
	 *
	 * --video=<url>
	 * : Public HTTPS video URL on a verified domain.
	 *
	 * [--privacy=<privacy>]
	 * : Requested post visibility.
	 * ---
	 * default: PUBLIC_TO_EVERYONE
	 * options:
	 *   - PUBLIC_TO_EVERYONE
	 *   - SELF_ONLY
	 *   - MUTUAL_FOLLOW_FRIENDS
	 *   - FOLLOWER_OF_CREATOR
	 * ---
	 *
	 * [--disable-duet]
	 * : Disable duets.
	 *
	 * [--disable-stitch]
	 * : Disable stitches.
	 *
	 * [--disable-comment]
	 * : Disable comments.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials tiktok publish "Charleston shows this week" --video=https://example.com/shows.mp4
	 *     wp datamachine-socials tiktok publish "Pilot clip" --video=https://example.com/clip.mp4 --privacy=SELF_ONLY
	 */
	public function publish( $args, $assoc_args ) {
		$caption = $args[0] ?? '';
		$video   = $assoc_args['video'] ?? '';

		if ( '' === $caption ) {
			WP_CLI::error( 'Caption is required.' );
		}

		if ( '' === $video ) {
			WP_CLI::error( 'Video URL is required. Use --video=<url>.' );
		}

		$ability = $this->get_ability( 'datamachine/tiktok-publish' );
		$result  = $ability->execute(
			array(
				'content'         => $caption,
				'video_url'       => $video,
				'privacy_level'   => $assoc_args['privacy'] ?? 'PUBLIC_TO_EVERYONE',
				'disable_duet'    => isset( $assoc_args['disable-duet'] ),
				'disable_stitch'  => isset( $assoc_args['disable-stitch'] ),
				'disable_comment' => isset( $assoc_args['disable-comment'] ),
			)
		);

		if ( is_wp_error( $result ) || empty( $result['success'] ) ) {
			WP_CLI::error( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'TikTok publish failed.' ) );
		}

		WP_CLI::success( 'TikTok post submitted.' );
		WP_CLI::log( 'Publish ID: ' . ( $result['publish_id'] ?? '' ) );
		WP_CLI::log( 'Status:     ' . ( $result['status'] ?? '' ) );
		if ( ! empty( $result['public_post_id'] ) ) {
			WP_CLI::log( 'Post ID:    ' . $result['public_post_id'] );
		}
	}

	/**
	 * Check the status of a submitted TikTok post.
	 *
	 * ## OPTIONS
	 *
	 * <publish_id>
	 * : Publish ID returned by the publish command.
	 */
	public function status( $args, $assoc_args ) {
		$assoc_args;
		$publish_id = $args[0] ?? '';
		if ( '' === $publish_id ) {
			WP_CLI::error( 'Publish ID is required.' );
		}

		$auth     = new AuthAbilities();
		$provider = $auth->getProvider( 'tiktok' );
		if ( ! $provider || ! $provider->is_authenticated() ) {
			WP_CLI::error( 'TikTok not authenticated.' );
		}

		$result = \DataMachineSocials\Abilities\TikTok\TikTokPublishAbility::fetch_post_status(
			$provider->get_valid_access_token(),
			$publish_id
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * List the authenticated creator's public TikTok videos.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Videos to return (1-20).
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--cursor=<cursor>]
	 * : Pagination cursor.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 */
	public function videos( $args, $assoc_args ) {
		$args;
		$ability = $this->get_ability( 'datamachine/tiktok-read' );
		$result  = $ability->execute(
			array(
				'action' => 'list',
				'limit'  => absint( $assoc_args['limit'] ?? 10 ),
				'cursor' => absint( $assoc_args['cursor'] ?? 0 ),
			)
		);

		if ( is_wp_error( $result ) || empty( $result['success'] ) ) {
			WP_CLI::error( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'TikTok video list failed.' ) );
		}

		$data = $result['data'];
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		$videos = $data['videos'] ?? array();
		if ( empty( $videos ) ) {
			WP_CLI::warning( 'No public videos found.' );
			return;
		}

		WP_CLI\Utils\format_items( 'table', $videos, array( 'id', 'create_time', 'view_count', 'like_count', 'comment_count', 'share_url', 'title' ) );
	}

	/**
	 * Show TikTok integration status.
	 */
	public function auth_status( $args, $assoc_args ) {
		$args;
		$assoc_args;
		$auth     = new AuthAbilities();
		$provider = $auth->getProvider( 'tiktok' );

		WP_CLI::log( 'TikTok Integration Status' );
		WP_CLI::log( '---' );
		if ( ! $provider ) {
			WP_CLI::log( 'Provider:      Not found' );
			return;
		}

		WP_CLI::log( 'Configured:    ' . ( $provider->is_configured() ? 'Yes' : 'No' ) );
		WP_CLI::log( 'Authenticated: ' . ( $provider->is_authenticated() ? 'Yes' : 'No' ) );
		if ( $provider->get_user_id() ) {
			WP_CLI::log( 'Open ID:       ' . $provider->get_user_id() );
		}
	}

	private function get_ability( string $slug ) {
		$ability = wp_get_ability( $slug );
		if ( ! $ability ) {
			WP_CLI::error( $slug . ' ability not registered.' );
		}
		return $ability;
	}
}
