<?php
/**
 * WP-CLI Tumblr Command
 *
 * Provides CLI access to Tumblr publishing, reading, discovery, and status.
 *
 * @package DataMachineSocials\Cli\Commands
 * @since 0.17.0
 */

namespace DataMachineSocials\Cli\Commands;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Tumblr integration for Data Machine Socials.
 *
 * ## EXAMPLES
 *
 *     # Check Tumblr status
 *     wp datamachine-socials tumblr status
 *
 *     # Discover tagged posts
 *     wp datamachine-socials tumblr tagged "live music"
 *
 *     # Publish a post
 *     wp datamachine-socials tumblr publish "Show Recap" --body="Great night..." --blog=extrachill
 */
class TumblrCommand {

	/**
	 * Show Tumblr integration status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials tumblr status
	 */
	public function status( $args, $assoc_args ) {
		$args;
		$assoc_args;
		$provider = $this->get_provider();

		WP_CLI::log( 'Tumblr Integration Status' );
		WP_CLI::log( '---' );
		WP_CLI::log( 'Configured: ' . ( $provider && $provider->is_configured() ? 'Yes' : 'No' ) );
		WP_CLI::log( 'Authenticated: ' . ( $provider && $provider->is_authenticated() ? 'Yes' : 'No' ) );
	}

	/**
	 * Retrieve Tumblr blog info.
	 *
	 * ## OPTIONS
	 *
	 * <blog_identifier>
	 * : The Tumblr blog name or hostname.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials tumblr info extrachill
	 */
	public function info( $args, $assoc_args ) {
		$assoc_args;
		$blog   = $args[0];
		$result = $this->read( array(
			'action'          => 'info',
			'blog_identifier' => $blog,
		) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::log( wp_json_encode( $result['data'] ?? array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * List a Tumblr blog's posts.
	 *
	 * ## OPTIONS
	 *
	 * <blog_identifier>
	 * : The Tumblr blog name or hostname.
	 *
	 * [--limit=<limit>]
	 * : Number of posts (max 100).
	 * ---
	 * default: 20
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials tumblr posts extrachill --limit=10
	 */
	public function posts( $args, $assoc_args ) {
		$blog   = $args[0];
		$result = $this->read( array(
			'action'          => 'posts',
			'blog_identifier' => $blog,
			'limit'           => absint( $assoc_args['limit'] ?? 20 ),
		) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$posts = $result['data']['posts'] ?? array();
		if ( empty( $posts ) ) {
			WP_CLI::warning( 'No posts found.' );
			return;
		}

		WP_CLI::success( "Found {$result['data']['count']} posts" );
		foreach ( $posts as $post ) {
			$summary = $this->post_summary( $post );
			WP_CLI::log( sprintf( '  %s  %s', $post['id_string'] ?? ( $post['id'] ?? '' ), $summary ) );
		}
	}

	/**
	 * Get a single Tumblr post.
	 *
	 * ## OPTIONS
	 *
	 * <blog_identifier>
	 * : The Tumblr blog name or hostname.
	 *
	 * <post_id>
	 * : The Tumblr post ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials tumblr post extrachill 1234567890
	 */
	public function post( $args, $assoc_args ) {
		$assoc_args;
		$result = $this->read( array(
			'action'          => 'post',
			'blog_identifier' => $args[0],
			'post_id'         => $args[1],
		) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::log( wp_json_encode( $result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Discover Tumblr posts by tag (tagged search).
	 *
	 * ## OPTIONS
	 *
	 * <tag>
	 * : The tag to search for.
	 *
	 * [--limit=<limit>]
	 * : Number of posts (max 20).
	 * ---
	 * default: 20
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials tumblr tagged "live music" --limit=10
	 */
	public function tagged( $args, $assoc_args ) {
		$result = $this->read( array(
			'action' => 'tagged',
			'tag'    => $args[0],
			'limit'  => absint( $assoc_args['limit'] ?? 20 ),
		) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$posts = $result['data']['posts'] ?? array();
		if ( empty( $posts ) ) {
			WP_CLI::warning( 'No tagged posts found.' );
			return;
		}

		WP_CLI::success( "Found {$result['data']['count']} tagged posts" );
		foreach ( $posts as $post ) {
			$blog = $post['blog_name'] ?? ( $post['blog']['name'] ?? '' );
			$summary = $this->post_summary( $post );
			WP_CLI::log( sprintf( '  [%s] %s  %s', $blog, $post['id_string'] ?? ( $post['id'] ?? '' ), $summary ) );
		}
	}

	/**
	 * Publish a Tumblr post.
	 *
	 * ## OPTIONS
	 *
	 * <blog_identifier>
	 * : The Tumblr blog to post to.
	 *
	 * --body=<text>
	 * : The post body text (required).
	 *
	 * [--title=<text>]
	 * : Optional post title.
	 *
	 * [--tags=<tags>]
	 * : Comma-separated tags.
	 *
	 * [--state=<state>]
	 * : Post state: published, queue, or draft.
	 * ---
	 * default: published
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials tumblr publish extrachill --body="Show recap..." --tags="live music, charleston"
	 */
	public function publish( $args, $assoc_args ) {
		$blog = $args[0] ?? '';

		if ( empty( $assoc_args['body'] ) ) {
			WP_CLI::error( 'Post body is required. Use --body=<text>.' );
		}

		$input = array(
			'blog_identifier' => $blog,
			'body'            => $assoc_args['body'],
		);
		if ( ! empty( $assoc_args['title'] ) ) {
			$input['title'] = $assoc_args['title'];
		}
		if ( ! empty( $assoc_args['tags'] ) ) {
			$input['tags'] = $assoc_args['tags'];
		}
		if ( ! empty( $assoc_args['state'] ) ) {
			$input['state'] = $assoc_args['state'];
		}

		WP_CLI::log( 'Publishing to Tumblr...' );

		$result = \DataMachineSocials\Abilities\Tumblr\TumblrPublishAbility::execute_publish( $input );

		if ( is_wp_error( $result ) || ! $result['success'] ) {
			WP_CLI::error( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Tumblr publish failed' ) );
		}

		WP_CLI::success( 'Post published to Tumblr!' );
		WP_CLI::log( 'Post ID:  ' . ( $result['post_id'] ?? '' ) );
		WP_CLI::log( 'Post URL: ' . ( $result['post_url'] ?? '' ) );
	}

	/**
	 * Delete a Tumblr post.
	 *
	 * ## OPTIONS
	 *
	 * <blog_identifier>
	 * : The Tumblr blog name or hostname.
	 *
	 * <post_id>
	 * : The Tumblr post ID to delete.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials tumblr delete extrachill 1234567890
	 */
	public function delete( $args, $assoc_args ) {
		$assoc_args;
		$ability = $this->get_ability( 'datamachine/tumblr-delete' );

		$result = $ability->execute( array(
			'blog_identifier' => $args[0],
			'post_id'         => $args[1],
		) );

		if ( is_wp_error( $result ) || ! $result['success'] ) {
			WP_CLI::error( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Delete failed' ) );
		}

		WP_CLI::success( 'Post deleted successfully!' );
		WP_CLI::log( 'Post ID: ' . $result['data']['post_id'] );
	}

	private function get_provider() {
		if ( ! class_exists( '\DataMachine\Abilities\AuthAbilities' ) ) {
			return null;
		}
		$auth = new \DataMachine\Abilities\AuthAbilities();
		return $auth->getProvider( 'tumblr' );
	}

	private function get_ability( $slug ) {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $slug ) : null;
		if ( ! $ability ) {
			WP_CLI::error( "{$slug} ability not registered." );
		}
		return $ability;
	}

	private function read( array $input ) {
		$ability = $this->get_ability( 'datamachine/tumblr-read' );
		return $ability->execute( $input );
	}

	private function post_summary( array $post ): string {
		$summary = $post['summary'] ?? '';
		if ( '' === $summary && ! empty( $post['content'] ) && is_array( $post['content'] ) ) {
			foreach ( $post['content'] as $block ) {
				if ( 'text' === ( $block['type'] ?? '' ) && ! empty( $block['text'] ) ) {
					$summary = $block['text'];
					break;
				}
			}
		}
		return mb_substr( (string) $summary, 0, 60 );
	}
}
