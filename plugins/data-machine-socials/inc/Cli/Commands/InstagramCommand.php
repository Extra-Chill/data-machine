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
	 * Show Instagram authentication status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials instagram status
	 */
	public function status( $args, $assoc_args ) {
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
}
