<?php
/**
 * WP-CLI Instagram Command
 *
 * Provides CLI access to Instagram read operations and account management.
 * Wraps the InstagramReadAbility and InstagramAuth providers.
 *
 * @package    DataMachineSocials
 * @subpackage Cli\Commands
 * @since      0.3.0
 */

namespace DataMachineSocials\Cli\Commands;

use WP_CLI;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Instagram integration for Data Machine Socials.
 *
 * ## EXAMPLES
 *
 *     # List recent posts
 *     wp datamachine-socials instagram posts
 *
 *     # Get details for a specific post
 *     wp datamachine-socials instagram post 17891234567890
 *
 *     # Get comments on a post
 *     wp datamachine-socials instagram comments 17891234567890
 *
 *     # Check auth status
 *     wp datamachine-socials instagram status
 */
class InstagramCommand {

	/**
	 * List recent Instagram posts.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Number of posts to return.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--after=<cursor>]
	 * : Pagination cursor for next page.
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
	 *     wp datamachine-socials instagram posts
	 *     wp datamachine-socials instagram posts --limit=10
	 *     wp datamachine-socials instagram posts --format=json
	 */
	public function posts( $args, $assoc_args ) {
		$ability = $this->get_ability();

		$result = $ability->execute( array(
			'action' => 'list',
			'limit'  => absint( $assoc_args['limit'] ?? 25 ),
			'after'  => $assoc_args['after'] ?? '',
		) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
		}

		$data   = $result['data'];
		$media  = $data['media'] ?? array();
		$format = $assoc_args['format'] ?? 'table';

		if ( empty( $media ) ) {
			WP_CLI::warning( 'No posts found.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		// Table format.
		WP_CLI::success( "Found {$data['count']} posts" );
		WP_CLI::log( '' );

		foreach ( $media as $item ) {
			$caption = $item['caption'] ?? '(no caption)';
			$caption = mb_substr( $caption, 0, 60 );
			if ( mb_strlen( $item['caption'] ?? '' ) > 60 ) {
				$caption .= '...';
			}

			$likes    = $item['like_count'] ?? 0;
			$comments = $item['comments_count'] ?? 0;
			$type     = $item['media_type'] ?? 'UNKNOWN';
			$date     = isset( $item['timestamp'] ) ? wp_date( 'Y-m-d', strtotime( $item['timestamp'] ) ) : '';

			WP_CLI::log( sprintf(
				'  %s  %-12s  %s  %d likes  %d comments  %s',
				$item['id'],
				$type,
				$date,
				$likes,
				$comments,
				$caption
			) );
		}

		if ( $data['has_next'] && ! empty( $data['cursors']['after'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( "Next page: --after={$data['cursors']['after']}" );
		}
	}

	/**
	 * Get details for a specific Instagram post.
	 *
	 * ## OPTIONS
	 *
	 * <media_id>
	 * : The Instagram media ID.
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
	 *     wp datamachine-socials instagram post 17891234567890
	 *     wp datamachine-socials instagram post 17891234567890 --format=json
	 */
	public function post( $args, $assoc_args ) {
		$media_id = $args[0];
		$ability  = $this->get_ability();

		$result = $ability->execute( array(
			'action'   => 'get',
			'media_id' => $media_id,
		) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
		}

		$data   = $result['data'];
		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::success( "Post {$media_id}" );
		WP_CLI::log( '' );
		WP_CLI::log( 'ID:        ' . ( $data['id'] ?? '' ) );
		WP_CLI::log( 'Type:      ' . ( $data['media_type'] ?? '' ) );
		WP_CLI::log( 'Date:      ' . ( $data['timestamp'] ?? '' ) );
		WP_CLI::log( 'Likes:     ' . ( $data['like_count'] ?? 0 ) );
		WP_CLI::log( 'Comments:  ' . ( $data['comments_count'] ?? 0 ) );
		WP_CLI::log( 'Permalink: ' . ( $data['permalink'] ?? '' ) );

		$caption = $data['caption'] ?? '(no caption)';
		WP_CLI::log( '' );
		WP_CLI::log( 'Caption:' );
		WP_CLI::log( $caption );
	}

	/**
	 * Get comments on an Instagram post.
	 *
	 * ## OPTIONS
	 *
	 * <media_id>
	 * : The Instagram media ID.
	 *
	 * [--limit=<limit>]
	 * : Number of comments to return.
	 * ---
	 * default: 25
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
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials instagram comments 17891234567890
	 *     wp datamachine-socials instagram comments 17891234567890 --limit=10
	 */
	public function comments( $args, $assoc_args ) {
		$media_id = $args[0];
		$ability  = $this->get_ability();

		$result = $ability->execute( array(
			'action'   => 'comments',
			'media_id' => $media_id,
			'limit'    => absint( $assoc_args['limit'] ?? 25 ),
		) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
		}

		$data     = $result['data'];
		$comments = $data['comments'] ?? array();
		$format   = $assoc_args['format'] ?? 'table';

		if ( empty( $comments ) ) {
			WP_CLI::warning( 'No comments found.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::success( "Found {$data['count']} comments" );
		WP_CLI::log( '' );

		foreach ( $comments as $comment ) {
			$username = $comment['username'] ?? 'unknown';
			$text     = $comment['text'] ?? '';
			$likes    = $comment['like_count'] ?? 0;
			$date     = isset( $comment['timestamp'] ) ? wp_date( 'Y-m-d H:i', strtotime( $comment['timestamp'] ) ) : '';

			WP_CLI::log( sprintf( '  @%-20s %s  (%d likes)  %s', $username, $date, $likes, $text ) );
		}
	}

	/**
	 * Reply to an Instagram comment.
	 *
	 * ## OPTIONS
	 *
	 * <comment_id>
	 * : The Instagram comment ID to reply to.
	 *
	 * <message>
	 * : The reply text.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials instagram reply-comment 1789000000000 "Thanks for listening!"
	 */
	public function reply_comment( $args) {
		$comment_id = $args[0] ?? '';
		$message    = $args[1] ?? '';
		$ability    = $this->get_comment_reply_ability();

		if ( empty( $comment_id ) ) {
			WP_CLI::error( 'Comment ID is required.' );
		}

		if ( empty( $message ) ) {
			WP_CLI::error( 'Reply message is required.' );
		}

		$result = $ability->execute(
			array(
				'comment_id' => $comment_id,
				'message'    => $message,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
		}

		WP_CLI::success( 'Instagram comment reply posted successfully!' );
		WP_CLI::log( 'Comment ID: ' . ( $result['data']['comment_id'] ?? $comment_id ) );
		WP_CLI::log( 'Reply ID:   ' . ( $result['data']['reply_id'] ?? '' ) );
	}

	/**
	 * Show Instagram authentication status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials instagram status
	 */
	public function status( $args, $assoc_args ) {
		$args;
		$assoc_args;
		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'instagram' );

		WP_CLI::log( 'Instagram Integration Status' );
		WP_CLI::log( '---' );

		if ( ! $provider ) {
			WP_CLI::log( 'Provider:      Not found' );
			return;
		}

		$authenticated = $provider->is_authenticated();
		WP_CLI::log( 'Authenticated: ' . ( $authenticated ? 'Yes' : 'No' ) );

		if ( method_exists( $provider, 'is_configured' ) ) {
			WP_CLI::log( 'Configured:    ' . ( $provider->is_configured() ? 'Yes' : 'No' ) );
		}

		if ( method_exists( $provider, 'get_username' ) ) {
			$username = $provider->get_username();
			if ( $username ) {
				WP_CLI::log( 'Username:      @' . $username );
			}
		}

		if ( method_exists( $provider, 'get_user_id' ) ) {
			$user_id = $provider->get_user_id();
			if ( $user_id ) {
				WP_CLI::log( 'User ID:       ' . $user_id );
			}
		}

		$details = $provider->get_account_details();
		if ( $details && ! empty( $details['token_expires_at'] ) ) {
			$expires_at = intval( $details['token_expires_at'] );
			$remaining  = $expires_at - time();

			if ( $remaining > 0 ) {
				$days = round( $remaining / DAY_IN_SECONDS );
				WP_CLI::log( "Token expires: in {$days} days" );
			} else {
				WP_CLI::log( 'Token expires: EXPIRED (will auto-refresh)' );
			}
		}

		$next_cron = wp_next_scheduled( 'datamachine_refresh_token_instagram' );
		if ( $next_cron ) {
			WP_CLI::log( 'Next cron:     ' . wp_date( 'Y-m-d H:i:s', $next_cron ) );
		}
	}

	/**
	 * Edit caption of an Instagram post.
	 *
	 * ## OPTIONS
	 *
	 * <media_id>
	 * : The Instagram media ID.
	 *
	 * <caption>
	 * : New caption text.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials instagram edit-caption 17891234567890 "New caption here"
	 */
	public function edit_caption( $args) {
		$media_id = $args[0];
		$caption  = $args[1] ?? '';
		$ability  = $this->get_update_ability();

		if ( empty( $caption ) ) {
			WP_CLI::error( 'Caption is required.' );
		}

		$result = $ability->execute( array(
			'action'   => 'edit',
			'media_id' => $media_id,
			'caption'  => $caption,
		) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
		}

		WP_CLI::success( 'Caption updated successfully!' );
		WP_CLI::log( 'Media ID: ' . $result['data']['media_id'] );
	}

	/**
	 * Delete an Instagram post.
	 *
	 * ## OPTIONS
	 *
	 * <media_id>
	 * : The Instagram media ID to delete.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials instagram delete 17891234567890
	 */
	public function delete( $args, $assoc_args ) {
		$assoc_args;
		$media_id = $args[0];
		$ability  = $this->get_delete_ability();

		$result = $ability->execute( array(
			'media_id' => $media_id,
		) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
		}

		WP_CLI::success( 'Post deleted successfully!' );
		WP_CLI::log( 'Media ID: ' . $result['data']['media_id'] );
	}

	/**
	 * Archive an Instagram post (hide from profile).
	 *
	 * ## OPTIONS
	 *
	 * <media_id>
	 * : The Instagram media ID to archive.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials instagram archive 17891234567890
	 */
	public function archive( $args, $assoc_args ) {
		$assoc_args;
		$media_id = $args[0];
		$ability  = $this->get_update_ability();

		$result = $ability->execute( array(
			'action'   => 'archive',
			'media_id' => $media_id,
		) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
		}

		WP_CLI::success( 'Post archived successfully!' );
		WP_CLI::log( 'Media ID: ' . $result['data']['media_id'] );
	}

	/**
	 * Publish to Instagram.
	 *
	 * Posts content to Instagram with optional images. Wraps the
	 * datamachine/instagram-publish ability.
	 *
	 * ## OPTIONS
	 *
	 * <caption>
	 * : The caption text for the post.
	 *
	 * [--image=<url>]
	 * : Image URL to include. Can be specified multiple times for carousel (up to 10).
	 *
	 * [--aspect-ratio=<ratio>]
	 * : Aspect ratio for images.
	 * ---
	 * default: 4:5
	 * options:
	 *   - 1:1
	 *   - 4:5
	 *   - 3:4
	 *   - 1.91:1
	 * ---
	 *
	 * [--source-url=<url>]
	 * : Source URL to attribute.
	 *
	 * ## EXAMPLES
	 *
	 *     # Post with caption only
	 *     wp datamachine-socials instagram publish "Check out our new show!"
	 *
	 *     # Post with image
	 *     wp datamachine-socials instagram publish "Tonight's lineup 🎶" --image=https://example.com/flyer.jpg
	 *
	 *     # Carousel post with multiple images
	 *     wp datamachine-socials instagram publish "Best moments" --image=https://example.com/1.jpg --image=https://example.com/2.jpg
	 *
	 *     # Post with custom aspect ratio
	 *     wp datamachine-socials instagram publish "Wide shot" --image=https://example.com/wide.jpg --aspect-ratio=1.91:1
	 */
	public function publish( $args, $assoc_args ) {
		$caption = $args[0] ?? '';

		if ( empty( $caption ) ) {
			WP_CLI::error( 'Caption is required.' );
		}

		$publish_ability = $this->get_publish_ability();

		$input = array(
			'content' => $caption,
		);

		// Collect image URLs (--image can be repeated)
		if ( ! empty( $assoc_args['image'] ) ) {
			$images              = is_array( $assoc_args['image'] ) ? $assoc_args['image'] : array( $assoc_args['image'] );
			$input['image_urls'] = $images;

			if ( count( $images ) > 10 ) {
				WP_CLI::error( 'Instagram supports a maximum of 10 images per carousel.' );
			}

			$count_label = count( $images ) > 1 ? count( $images ) . ' images (carousel)' : '1 image';
			WP_CLI::log( "Publishing to Instagram with {$count_label}..." );
		} else {
			WP_CLI::log( 'Publishing to Instagram (text only)...' );
		}

		if ( ! empty( $assoc_args['aspect-ratio'] ) ) {
			$input['aspect_ratio'] = $assoc_args['aspect-ratio'];
		}

		if ( ! empty( $assoc_args['source-url'] ) ) {
			$input['source_url'] = $assoc_args['source-url'];
		}

		$result = $publish_ability->execute( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
		}

		WP_CLI::success( 'Published to Instagram!' );
		WP_CLI::log( 'Media ID:  ' . ( $result['media_id'] ?? '' ) );
		WP_CLI::log( 'Permalink: ' . ( $result['permalink'] ?? '' ) );
	}

	/**
	 * Publish a Reel (video) to Instagram.
	 *
	 * Posts a video as an Instagram Reel. The video must be hosted at
	 * a publicly accessible URL.
	 *
	 * ## OPTIONS
	 *
	 * <caption>
	 * : The caption text for the Reel.
	 *
	 * --video=<url>
	 * : Public URL of the video file (required).
	 *
	 * [--cover=<url>]
	 * : Optional cover image URL for the Reel.
	 *
	 * [--no-feed]
	 * : Don't share the Reel to the main profile feed.
	 *
	 * [--source-url=<url>]
	 * : Source URL to attribute.
	 *
	 * ## EXAMPLES
	 *
	 *     # Publish a Reel
	 *     wp datamachine-socials instagram publish-reel "Check this out!" --video=https://example.com/clip.mp4
	 *
	 *     # Reel with cover image
	 *     wp datamachine-socials instagram publish-reel "New track" --video=https://example.com/clip.mp4 --cover=https://example.com/thumb.jpg
	 *
	 *     # Reel without sharing to feed
	 *     wp datamachine-socials instagram publish-reel "Behind the scenes" --video=https://example.com/bts.mp4 --no-feed
	 */
	public function publish_reel( $args, $assoc_args ) {
		$caption = $args[0] ?? '';

		if ( empty( $caption ) ) {
			WP_CLI::error( 'Caption is required.' );
		}

		if ( empty( $assoc_args['video'] ) ) {
			WP_CLI::error( 'Video URL is required. Use --video=<url>.' );
		}

		$publish_ability = $this->get_publish_ability();

		$input = array(
			'content'    => $caption,
			'media_kind' => 'reel',
			'video_url'  => $assoc_args['video'],
		);

		if ( ! empty( $assoc_args['cover'] ) ) {
			$input['cover_url'] = $assoc_args['cover'];
		}

		if ( isset( $assoc_args['no-feed'] ) ) {
			$input['share_to_feed'] = false;
		}

		if ( ! empty( $assoc_args['source-url'] ) ) {
			$input['source_url'] = $assoc_args['source-url'];
		}

		WP_CLI::log( 'Publishing Reel to Instagram...' );
		WP_CLI::log( 'Video processing may take up to 60 seconds.' );

		$result = $publish_ability->execute( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
		}

		WP_CLI::success( 'Reel published to Instagram!' );
		WP_CLI::log( 'Media ID:  ' . ( $result['media_id'] ?? '' ) );
		WP_CLI::log( 'Permalink: ' . ( $result['permalink'] ?? '' ) );
	}

	/**
	 * Publish a Story to Instagram.
	 *
	 * Publishes an ephemeral Instagram Story from an image or video URL.
	 * Stories are visible for 24 hours and do not support captions via the API.
	 *
	 * ## OPTIONS
	 *
	 * [--image=<url>]
	 * : Public URL of an image for the Story. Use either --image or --video.
	 *
	 * [--video=<url>]
	 * : Public URL of a video for the Story. Use either --image or --video.
	 *
	 * ## EXAMPLES
	 *
	 *     # Publish an image Story
	 *     wp datamachine-socials instagram publish-story --image=https://example.com/photo.jpg
	 *
	 *     # Publish a video Story
	 *     wp datamachine-socials instagram publish-story --video=https://example.com/clip.mp4
	 */
	public function publish_story( $args, $assoc_args ) {
		$args; // unused positional args.
		$image_url = $assoc_args['image'] ?? '';
		$video_url = $assoc_args['video'] ?? '';

		if ( empty( $image_url ) && empty( $video_url ) ) {
			WP_CLI::error( 'An image or video URL is required. Use --image=<url> or --video=<url>.' );
		}

		if ( ! empty( $image_url ) && ! empty( $video_url ) ) {
			WP_CLI::error( 'Provide either --image or --video, not both.' );
		}

		$publish_ability = $this->get_publish_ability();

		$input = array(
			'content'    => 'Story', // Content is required by the ability but not used for Stories.
			'media_kind' => 'story',
		);

		if ( ! empty( $video_url ) ) {
			$input['video_url'] = $video_url;
			WP_CLI::log( 'Publishing video Story to Instagram...' );
			WP_CLI::log( 'Video processing may take up to 60 seconds.' );
		} else {
			$input['story_image_url'] = $image_url;
			WP_CLI::log( 'Publishing image Story to Instagram...' );
		}

		$result = $publish_ability->execute( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
		}

		WP_CLI::success( 'Story published to Instagram!' );
		WP_CLI::log( 'Media ID:  ' . ( $result['media_id'] ?? '' ) );
		WP_CLI::log( 'Permalink: ' . ( $result['permalink'] ?? '(Stories may not have permalinks)' ) );
	}

	/**
	 * Get the Instagram publish ability.
	 *
	 * @return \DataMachineSocials\Abilities\Instagram\InstagramPublishAbility
	 */
	private function get_publish_ability() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			WP_CLI::error( 'WordPress Abilities API not available (requires WP 6.9+).' );
		}

		$ability = wp_get_ability( 'datamachine/instagram-publish' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/instagram-publish ability not registered.' );
		}

		return new \DataMachineSocials\Abilities\Instagram\InstagramPublishAbility();
	}

	/**
	 * Get the Instagram read ability.
	 *
	 * @return \DataMachineSocials\Abilities\Instagram\InstagramReadAbility
	 */
	private function get_ability() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			WP_CLI::error( 'WordPress Abilities API not available (requires WP 6.9+).' );
		}

		$ability = wp_get_ability( 'datamachine/instagram-read' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/instagram-read ability not registered.' );
		}

		return new \DataMachineSocials\Abilities\Instagram\InstagramReadAbility();
	}

	/**
	 * Get the Instagram update ability.
	 *
	 * @return \DataMachineSocials\Abilities\Instagram\InstagramUpdateAbility
	 */
	private function get_update_ability() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			WP_CLI::error( 'WordPress Abilities API not available (requires WP 6.9+).' );
		}

		$ability = wp_get_ability( 'datamachine/instagram-update' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/instagram-update ability not registered.' );
		}

		return new \DataMachineSocials\Abilities\Instagram\InstagramUpdateAbility();
	}

	/**
	 * Get the Instagram delete ability.
	 *
	 * @return \DataMachineSocials\Abilities\Instagram\InstagramDeleteAbility
	 */
	private function get_delete_ability() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			WP_CLI::error( 'WordPress Abilities API not available (requires WP 6.9+).' );
		}

		$ability = wp_get_ability( 'datamachine/instagram-delete' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/instagram-delete ability not registered.' );
		}

		return new \DataMachineSocials\Abilities\Instagram\InstagramDeleteAbility();
	}

	/**
	 * Get the Instagram comment reply ability.
	 *
	 * @return \DataMachineSocials\Abilities\Instagram\InstagramCommentReplyAbility
	 */
	private function get_comment_reply_ability() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			WP_CLI::error( 'WordPress Abilities API not available (requires WP 6.9+).' );
		}

		$ability = wp_get_ability( 'datamachine/instagram-comment-reply' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/instagram-comment-reply ability not registered.' );
		}

		return new \DataMachineSocials\Abilities\Instagram\InstagramCommentReplyAbility();
	}
}
