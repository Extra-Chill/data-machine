<?php
/**
 * WP-CLI Twitter Command
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
 * Manage Twitter/X integration for Data Machine Socials.
 *
 * ## EXAMPLES
 *
 *     wp datamachine-socials twitter tweets
 *     wp datamachine-socials twitter tweet 1234567890
 *     wp datamachine-socials twitter mentions
 *     wp datamachine-socials twitter status
 */
class TwitterCommand {

	/**
	 * List recent tweets from your timeline.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Number of tweets to return (min 5, max 100).
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--pagination-token=<token>]
	 * : Pagination token for next page.
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
	public function tweets( $args, $assoc_args ) {
		$ability = $this->get_ability();

		$result = $ability->execute( array(
			'action'           => 'list',
			'limit'            => absint( $assoc_args['limit'] ?? 25 ),
			'pagination_token' => $assoc_args['pagination-token'] ?? '',
		) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
		}

		$data   = $result['data'];
		$tweets = $data['tweets'] ?? array();
		$format = $assoc_args['format'] ?? 'table';

		if ( empty( $tweets ) ) {
			WP_CLI::warning( 'No tweets found.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::success( "Found {$data['count']} tweets" );
		WP_CLI::log( '' );

		foreach ( $tweets as $tweet ) {
			$text = mb_substr( $tweet['text'] ?? '', 0, 60 );
			if ( mb_strlen( $tweet['text'] ?? '' ) > 60 ) {
				$text .= '...';
			}
			$metrics = $tweet['public_metrics'] ?? array();
			$likes   = $metrics['like_count'] ?? 0;
			$rts     = $metrics['retweet_count'] ?? 0;
			$date    = isset( $tweet['created_at'] ) ? wp_date( 'Y-m-d', strtotime( $tweet['created_at'] ) ) : '';

			WP_CLI::log( sprintf( '  %s  %s  %d likes  %d RTs  %s', $tweet['id'], $date, $likes, $rts, $text ) );
		}

		if ( $data['has_next'] && ! empty( $data['next_token'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( "Next page: --pagination-token={$data['next_token']}" );
		}
	}

	/**
	 * Get details for a specific tweet.
	 *
	 * ## OPTIONS
	 *
	 * <tweet_id>
	 * : The tweet ID.
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
	public function tweet( $args, $assoc_args ) {
		$tweet_id = $args[0];
		$ability  = $this->get_ability();

		$result = $ability->execute( array(
			'action'   => 'get',
			'tweet_id' => $tweet_id,
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

		$metrics = $data['public_metrics'] ?? array();

		WP_CLI::success( "Tweet {$tweet_id}" );
		WP_CLI::log( '' );
		WP_CLI::log( 'ID:         ' . ( $data['id'] ?? '' ) );
		WP_CLI::log( 'Date:       ' . ( $data['created_at'] ?? '' ) );
		WP_CLI::log( 'Likes:      ' . ( $metrics['like_count'] ?? 0 ) );
		WP_CLI::log( 'Retweets:   ' . ( $metrics['retweet_count'] ?? 0 ) );
		WP_CLI::log( 'Replies:    ' . ( $metrics['reply_count'] ?? 0 ) );
		WP_CLI::log( 'Impressions:' . ( $metrics['impression_count'] ?? 0 ) );
		WP_CLI::log( '' );
		WP_CLI::log( 'Text:' );
		WP_CLI::log( $data['text'] ?? '' );
	}

	/**
	 * List recent mentions.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Number of mentions to return (min 5, max 100).
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
	 */
	public function mentions( $args, $assoc_args ) {
		$ability = $this->get_ability();

		$result = $ability->execute( array(
			'action' => 'mentions',
			'limit'  => absint( $assoc_args['limit'] ?? 25 ),
		) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
		}

		$data     = $result['data'];
		$mentions = $data['mentions'] ?? array();
		$format   = $assoc_args['format'] ?? 'table';

		if ( empty( $mentions ) ) {
			WP_CLI::warning( 'No mentions found.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::success( "Found {$data['count']} mentions" );
		WP_CLI::log( '' );

		foreach ( $mentions as $tweet ) {
			$text = mb_substr( $tweet['text'] ?? '', 0, 60 );
			if ( mb_strlen( $tweet['text'] ?? '' ) > 60 ) {
				$text .= '...';
			}
			$date = isset( $tweet['created_at'] ) ? wp_date( 'Y-m-d', strtotime( $tweet['created_at'] ) ) : '';

			WP_CLI::log( sprintf( '  %s  %s  %s', $tweet['id'], $date, $text ) );
		}
	}

	/**
	 * Show Twitter authentication status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials twitter status
	 */
	public function status( $args, $assoc_args ) {
		$args;
		$assoc_args;
		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'twitter' );

		WP_CLI::log( 'Twitter / X Integration Status' );
		WP_CLI::log( '---' );

		if ( ! $provider ) {
			WP_CLI::log( 'Provider:      Not found' );
			return;
		}

		$authenticated = $provider->is_authenticated();
		WP_CLI::log( 'Authenticated: ' . ( $authenticated ? 'Yes' : 'No' ) );

		$details = $provider->get_account_details();
		if ( $details ) {
			if ( ! empty( $details['screen_name'] ) ) {
				WP_CLI::log( 'Handle:        @' . $details['screen_name'] );
			}
			if ( ! empty( $details['user_id'] ) ) {
				WP_CLI::log( 'User ID:       ' . $details['user_id'] );
			}
		}

		WP_CLI::log( 'Auth type:     OAuth 1.0a (tokens do not expire)' );
	}

	/**
	 * Delete a tweet.
	 *
	 * ## OPTIONS
	 *
	 * <tweet_id>
	 * : The tweet ID to delete.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials twitter delete 1234567890
	 */
	public function delete( $args, $assoc_args ) {
		$assoc_args;
		$tweet_id = $args[0];
		$ability  = $this->get_delete_ability();

		$result = $ability->execute( array(
			'tweet_id' => $tweet_id,
		) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
		}

		WP_CLI::success( 'Tweet deleted successfully!' );
		WP_CLI::log( 'Tweet ID: ' . $result['data']['tweet_id'] );
	}

	/**
	 * Retweet a tweet.
	 *
	 * ## OPTIONS
	 *
	 * <tweet_id>
	 * : The tweet ID to retweet.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials twitter retweet 1234567890
	 */
	public function retweet( $args, $assoc_args ) {
		$assoc_args;
		$tweet_id = $args[0];
		$ability  = $this->get_update_ability();

		$result = $ability->execute( array(
			'action'   => 'retweet',
			'tweet_id' => $tweet_id,
		) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
		}

		WP_CLI::success( 'Retweeted successfully!' );
	}

	/**
	 * Like a tweet.
	 *
	 * ## OPTIONS
	 *
	 * <tweet_id>
	 * : The tweet ID to like.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials twitter like 1234567890
	 */
	public function like( $args) {
		$tweet_id = $args[0];
		$ability  = $this->get_update_ability();

		$result = $ability->execute( array(
			'action'   => 'like',
			'tweet_id' => $tweet_id,
		) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
		}

		WP_CLI::success( 'Tweet liked successfully!' );
	}

	/**
	 * Publish a tweet.
	 *
	 * ## OPTIONS
	 *
	 * <content>
	 * : Tweet text (max 280 characters).
	 *
	 * [--image=<path>]
	 * : Path to a local image file to attach.
	 *
	 * [--source-url=<url>]
	 * : Source URL to include with the tweet.
	 *
	 * [--link-handling=<mode>]
	 * : How to handle the source URL.
	 * ---
	 * default: append
	 * options:
	 *   - none
	 *   - append
	 *   - reply
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Simple tweet
	 *     wp datamachine-socials twitter publish "Hello from the CLI!"
	 *
	 *     # Tweet with image
	 *     wp datamachine-socials twitter publish "Check this out" --image=/tmp/photo.jpg
	 *
	 *     # Tweet with source URL appended
	 *     wp datamachine-socials twitter publish "New article" --source-url=https://extrachill.com/article
	 *
	 *     # Tweet with source URL as reply
	 *     wp datamachine-socials twitter publish "Read more below" --source-url=https://extrachill.com/article --link-handling=reply
	 */
	public function publish( $args, $assoc_args ) {
		$content = $args[0] ?? '';

		if ( empty( $content ) ) {
			WP_CLI::error( 'Tweet content is required.' );
		}

		if ( mb_strlen( $content ) > 280 && empty( $assoc_args['source-url'] ) ) {
			WP_CLI::error( 'Tweet exceeds 280 characters (' . mb_strlen( $content ) . ' chars).' );
		}

		$this->get_publish_ability();

		$input = array( 'content' => $content );

		if ( ! empty( $assoc_args['image'] ) ) {
			$image_path = $assoc_args['image'];
			if ( ! file_exists( $image_path ) ) {
				WP_CLI::error( "Image file not found: {$image_path}" );
			}
			$input['image_path'] = $image_path;
			WP_CLI::log( 'Publishing tweet with image...' );
		} else {
			WP_CLI::log( 'Publishing tweet...' );
		}

		if ( ! empty( $assoc_args['source-url'] ) ) {
			$input['source_url'] = $assoc_args['source-url'];
		}

		if ( ! empty( $assoc_args['link-handling'] ) ) {
			$input['link_handling'] = $assoc_args['link-handling'];
		}

		$result = \DataMachineSocials\Abilities\Twitter\TwitterPublishAbility::execute_publish( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
		}

		WP_CLI::success( 'Published to Twitter!' );
		WP_CLI::log( 'Tweet ID:  ' . ( $result['tweet_id'] ?? '' ) );
		WP_CLI::log( 'URL:       ' . ( $result['tweet_url'] ?? '' ) );

		if ( ! empty( $result['reply_tweet_id'] ) ) {
			WP_CLI::log( 'Reply ID:  ' . $result['reply_tweet_id'] );
			WP_CLI::log( 'Reply URL: ' . $result['reply_tweet_url'] );
		}
	}

	private function get_ability() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			WP_CLI::error( 'WordPress Abilities API not available (requires WP 6.9+).' );
		}

		$ability = wp_get_ability( 'datamachine/twitter-read' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/twitter-read ability not registered.' );
		}

		return new \DataMachineSocials\Abilities\Twitter\TwitterReadAbility();
	}

	private function get_update_ability() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			WP_CLI::error( 'WordPress Abilities API not available (requires WP 6.9+).' );
		}

		$ability = wp_get_ability( 'datamachine/twitter-update' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/twitter-update ability not registered.' );
		}

		return new \DataMachineSocials\Abilities\Twitter\TwitterUpdateAbility();
	}

	private function get_delete_ability() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			WP_CLI::error( 'WordPress Abilities API not available (requires WP 6.9+).' );
		}

		$ability = wp_get_ability( 'datamachine/twitter-delete' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/twitter-delete ability not registered.' );
		}

		return new \DataMachineSocials\Abilities\Twitter\TwitterDeleteAbility();
	}

	private function get_publish_ability() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			WP_CLI::error( 'WordPress Abilities API not available (requires WP 6.9+).' );
		}

		$ability = wp_get_ability( 'datamachine/twitter-publish' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/twitter-publish ability not registered.' );
		}

		return new \DataMachineSocials\Abilities\Twitter\TwitterPublishAbility();
	}
}
