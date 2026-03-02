<?php
/**
 * WP-CLI Bluesky Command
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
 * Manage Bluesky integration for Data Machine Socials.
 *
 * ## EXAMPLES
 *
 *     wp datamachine-socials bluesky posts
 *     wp datamachine-socials bluesky thread at://did:plc:abc/app.bsky.feed.post/xyz
 *     wp datamachine-socials bluesky profile
 *     wp datamachine-socials bluesky status
 */
class BlueskyCommand {

	/**
	 * List recent Bluesky posts from your feed.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Number of posts to return.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--cursor=<cursor>]
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
	 */
	public function posts( $args, $assoc_args ) {
		$ability = $this->get_ability();

		$result = $ability->execute( array(
			'action' => 'list',
			'limit'  => absint( $assoc_args['limit'] ?? 25 ),
			'cursor' => $assoc_args['cursor'] ?? '',
		) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
		}

		$data   = $result['data'];
		$posts  = $data['posts'] ?? array();
		$format = $assoc_args['format'] ?? 'table';

		if ( empty( $posts ) ) {
			WP_CLI::warning( 'No posts found.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::success( "Found {$data['count']} posts" );
		WP_CLI::log( '' );

		foreach ( $posts as $feed_item ) {
			$post   = $feed_item['post'] ?? $feed_item;
			$record = $post['record'] ?? array();
			$text   = mb_substr( $record['text'] ?? '(no text)', 0, 60 );
			if ( mb_strlen( $record['text'] ?? '' ) > 60 ) {
				$text .= '...';
			}
			$likes   = $post['likeCount'] ?? 0;
			$reposts = $post['repostCount'] ?? 0;
			$date    = isset( $record['createdAt'] ) ? wp_date( 'Y-m-d', strtotime( $record['createdAt'] ) ) : '';
			$uri     = $post['uri'] ?? '';

			WP_CLI::log( sprintf( '  %s  %d likes  %d reposts  %s', $date, $likes, $reposts, $text ) );
		}

		if ( $data['has_next'] && ! empty( $data['cursor'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( "Next page: --cursor={$data['cursor']}" );
		}
	}

	/**
	 * Get a post thread.
	 *
	 * ## OPTIONS
	 *
	 * <post_uri>
	 * : The AT Protocol post URI (at://did:plc:.../app.bsky.feed.post/...).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - table
	 *   - json
	 * ---
	 */
	public function thread( $args, $assoc_args ) {
		$post_uri = $args[0];
		$ability  = $this->get_ability();

		$result = $ability->execute( array(
			'action'   => 'get',
			'post_uri' => $post_uri,
		) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
		}

		$data   = $result['data'];
		$format = $assoc_args['format'] ?? 'json';

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		$post   = $data['post'] ?? $data;
		$record = $post['record'] ?? array();

		WP_CLI::success( 'Post thread' );
		WP_CLI::log( '' );
		WP_CLI::log( 'URI:       ' . ( $post['uri'] ?? $post_uri ) );
		WP_CLI::log( 'Author:    ' . ( $post['author']['handle'] ?? 'unknown' ) );
		WP_CLI::log( 'Date:      ' . ( $record['createdAt'] ?? '' ) );
		WP_CLI::log( 'Likes:     ' . ( $post['likeCount'] ?? 0 ) );
		WP_CLI::log( 'Reposts:   ' . ( $post['repostCount'] ?? 0 ) );
		WP_CLI::log( 'Replies:   ' . ( $post['replyCount'] ?? 0 ) );
		WP_CLI::log( '' );
		WP_CLI::log( 'Text:' );
		WP_CLI::log( $record['text'] ?? '(no text)' );
	}

	/**
	 * Show your Bluesky profile.
	 *
	 * ## OPTIONS
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
	public function profile( $args, $assoc_args ) {
		$ability = $this->get_ability();

		$result = $ability->execute( array( 'action' => 'profile' ) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
		}

		$data   = $result['data'];
		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::success( 'Bluesky Profile' );
		WP_CLI::log( '' );
		WP_CLI::log( 'Handle:      ' . ( $data['handle'] ?? '' ) );
		WP_CLI::log( 'Display:     ' . ( $data['displayName'] ?? '' ) );
		WP_CLI::log( 'DID:         ' . ( $data['did'] ?? '' ) );
		WP_CLI::log( 'Followers:   ' . ( $data['followersCount'] ?? 0 ) );
		WP_CLI::log( 'Following:   ' . ( $data['followsCount'] ?? 0 ) );
		WP_CLI::log( 'Posts:       ' . ( $data['postsCount'] ?? 0 ) );

		if ( ! empty( $data['description'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Bio:' );
			WP_CLI::log( $data['description'] );
		}
	}

	/**
	 * Show Bluesky authentication status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials bluesky status
	 */
	public function status( $args, $assoc_args ) {
		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'bluesky' );

		WP_CLI::log( 'Bluesky Integration Status' );
		WP_CLI::log( '---' );

		if ( ! $provider ) {
			WP_CLI::log( 'Provider:      Not found' );
			return;
		}

		$authenticated = $provider->is_authenticated();
		WP_CLI::log( 'Authenticated: ' . ( $authenticated ? 'Yes' : 'No' ) );

		$details = $provider->get_account_details();
		if ( $details ) {
			if ( ! empty( $details['username'] ) ) {
				WP_CLI::log( 'Handle:        ' . $details['username'] );
			}
		}

		WP_CLI::log( 'Auth type:     App Password (session-based, no stored tokens)' );
	}

	/**
	 * Publish a post to Bluesky.
	 *
	 * ## OPTIONS
	 *
	 * <content>
	 * : Post text content.
	 *
	 * [--title=<title>]
	 * : Optional title (prepended to content).
	 *
	 * [--image=<url>]
	 * : Image URL to attach to the post.
	 *
	 * [--source-url=<url>]
	 * : Source URL to append to the post.
	 *
	 * ## EXAMPLES
	 *
	 *     # Simple text post
	 *     wp datamachine-socials bluesky publish "Hello from the CLI!"
	 *
	 *     # Post with title
	 *     wp datamachine-socials bluesky publish "Great show last night" --title="Live Review"
	 *
	 *     # Post with image
	 *     wp datamachine-socials bluesky publish "Check this out" --image=https://extrachill.com/photo.jpg
	 *
	 *     # Post with source URL
	 *     wp datamachine-socials bluesky publish "New article on the site" --source-url=https://extrachill.com/article
	 */
	public function publish( $args, $assoc_args ) {
		$content = $args[0] ?? '';

		if ( empty( $content ) ) {
			WP_CLI::error( 'Post content is required.' );
		}

		$this->get_publish_ability();

		$input = array( 'content' => $content );

		if ( ! empty( $assoc_args['title'] ) ) {
			$input['title'] = $assoc_args['title'];
		}

		if ( ! empty( $assoc_args['image'] ) ) {
			$input['image_url'] = $assoc_args['image'];
			WP_CLI::log( 'Publishing to Bluesky with image...' );
		} else {
			WP_CLI::log( 'Publishing to Bluesky...' );
		}

		if ( ! empty( $assoc_args['source-url'] ) ) {
			$input['source_url'] = $assoc_args['source-url'];
		}

		$result = \DataMachineSocials\Abilities\Bluesky\BlueskyPublishAbility::execute_publish( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
		}

		WP_CLI::success( 'Published to Bluesky!' );
		WP_CLI::log( 'Post ID: ' . ( $result['post_id'] ?? '' ) );
		WP_CLI::log( 'URL:     ' . ( $result['post_url'] ?? '' ) );
	}

	private function get_ability() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			WP_CLI::error( 'WordPress Abilities API not available (requires WP 6.9+).' );
		}

		$ability = wp_get_ability( 'datamachine/bluesky-read' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/bluesky-read ability not registered.' );
		}

		return new \DataMachineSocials\Abilities\Bluesky\BlueskyReadAbility();
	}

	private function get_update_ability() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			WP_CLI::error( 'WordPress Abilities API not available (requires WP 6.9+).' );
		}

		$ability = wp_get_ability( 'datamachine/bluesky-update' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/bluesky-update ability not registered.' );
		}

		return new \DataMachineSocials\Abilities\Bluesky\BlueskyUpdateAbility();
	}

	private function get_delete_ability() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			WP_CLI::error( 'WordPress Abilities API not available (requires WP 6.9+).' );
		}

		$ability = wp_get_ability( 'datamachine/bluesky-delete' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/bluesky-delete ability not registered.' );
		}

		return new \DataMachineSocials\Abilities\Bluesky\BlueskyDeleteAbility();
	}

	/**
	 * Delete a Bluesky post.
	 *
	 * ## OPTIONS
	 *
	 * <post_uri>
	 * : The Bluesky post URI (at://did/rkey).
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials bluesky delete "at://did:plc:xxx/app.bsky.feed.post/abc123"
	 */
	public function delete( $args, $assoc_args ) {
		$post_uri = $args[0];
		$ability  = $this->get_delete_ability();

		$result = $ability->execute( array(
			'post_uri' => $post_uri,
		) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
		}

		WP_CLI::success( 'Post deleted successfully!' );
		WP_CLI::log( 'Post URI: ' . $result['data']['post_uri'] );
	}

	/**
	 * Like a Bluesky post.
	 *
	 * ## OPTIONS
	 *
	 * <post_uri>
	 * : The Bluesky post URI to like.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials bluesky like "at://did:plc:xxx/app.bsky.feed.post/abc123"
	 */
	public function like( $args, $assoc_args ) {
		$post_uri = $args[0];
		$ability  = $this->get_update_ability();

		$result = $ability->execute( array(
			'action'   => 'like',
			'post_uri' => $post_uri,
		) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
		}

		WP_CLI::success( 'Post liked successfully!' );
	}

	private function get_publish_ability() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			WP_CLI::error( 'WordPress Abilities API not available (requires WP 6.9+).' );
		}

		$ability = wp_get_ability( 'datamachine/bluesky-publish' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/bluesky-publish ability not registered.' );
		}

		return new \DataMachineSocials\Abilities\Bluesky\BlueskyPublishAbility();
	}
}
