<?php
/**
 * WP-CLI Facebook Command
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
 * Manage Facebook integration for Data Machine Socials.
 *
 * ## EXAMPLES
 *
 *     wp datamachine-socials facebook posts
 *     wp datamachine-socials facebook post 123456789
 *     wp datamachine-socials facebook comments 123456789
 *     wp datamachine-socials facebook status
 */
class FacebookCommand {

	/**
	 * List recent Facebook Page posts.
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

		foreach ( $posts as $item ) {
			$message = mb_substr( $item['message'] ?? '(no message)', 0, 60 );
			if ( mb_strlen( $item['message'] ?? '' ) > 60 ) {
				$message .= '...';
			}
			$likes    = $item['likes']['summary']['total_count'] ?? 0;
			$comments = $item['comments']['summary']['total_count'] ?? 0;
			$shares   = $item['shares']['count'] ?? 0;
			$date     = isset( $item['created_time'] ) ? wp_date( 'Y-m-d', strtotime( $item['created_time'] ) ) : '';

			WP_CLI::log( sprintf(
				'  %s  %s  %d likes  %d comments  %d shares  %s',
				$item['id'],
				$date,
				$likes,
				$comments,
				$shares,
				$message
			) );
		}

		if ( $data['has_next'] && ! empty( $data['cursors']['after'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( "Next page: --after={$data['cursors']['after']}" );
		}
	}

	/**
	 * Get details for a specific Facebook post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The Facebook post ID.
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
	public function post( $args, $assoc_args ) {
		$post_id = $args[0];
		$ability = $this->get_ability();

		$result = $ability->execute( array(
			'action'  => 'get',
			'post_id' => $post_id,
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

		WP_CLI::success( "Post {$post_id}" );
		WP_CLI::log( '' );
		WP_CLI::log( 'ID:        ' . ( $data['id'] ?? '' ) );
		WP_CLI::log( 'Date:      ' . ( $data['created_time'] ?? '' ) );
		WP_CLI::log( 'Likes:     ' . ( $data['likes']['summary']['total_count'] ?? 0 ) );
		WP_CLI::log( 'Comments:  ' . ( $data['comments']['summary']['total_count'] ?? 0 ) );
		WP_CLI::log( 'Shares:    ' . ( $data['shares']['count'] ?? 0 ) );
		WP_CLI::log( 'Permalink: ' . ( $data['permalink_url'] ?? '' ) );
		WP_CLI::log( '' );
		WP_CLI::log( 'Message:' );
		WP_CLI::log( $data['message'] ?? '(no message)' );
	}

	/**
	 * Get comments on a Facebook post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The Facebook post ID.
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
	 */
	public function comments( $args, $assoc_args ) {
		$post_id = $args[0];
		$ability = $this->get_ability();

		$result = $ability->execute( array(
			'action'  => 'comments',
			'post_id' => $post_id,
			'limit'   => absint( $assoc_args['limit'] ?? 25 ),
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
			$from = $comment['from']['name'] ?? 'unknown';
			$text = $comment['message'] ?? '';
			$date = isset( $comment['created_time'] ) ? wp_date( 'Y-m-d H:i', strtotime( $comment['created_time'] ) ) : '';

			WP_CLI::log( sprintf( '  %-20s %s  %s', $from, $date, $text ) );
		}
	}

	/**
	 * Show Facebook authentication status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials facebook status
	 */
	public function status( $args, $assoc_args ) {
		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'facebook' );

		WP_CLI::log( 'Facebook Integration Status' );
		WP_CLI::log( '---' );

		if ( ! $provider ) {
			WP_CLI::log( 'Provider:      Not found' );
			return;
		}

		$authenticated = $provider->is_authenticated();
		WP_CLI::log( 'Authenticated: ' . ( $authenticated ? 'Yes' : 'No' ) );

		if ( method_exists( $provider, 'get_page_id' ) ) {
			$page_id = $provider->get_page_id();
			if ( $page_id ) {
				WP_CLI::log( 'Page ID:       ' . $page_id );
			}
		}

		$details = $provider->get_account_details();
		if ( $details ) {
			if ( ! empty( $details['page_name'] ) ) {
				WP_CLI::log( 'Page:          ' . $details['page_name'] );
			}
			if ( ! empty( $details['user_name'] ) ) {
				WP_CLI::log( 'User:          ' . $details['user_name'] );
			}
		}

		$next_cron = wp_next_scheduled( 'datamachine_refresh_token_facebook' );
		if ( $next_cron ) {
			WP_CLI::log( 'Next cron:     ' . wp_date( 'Y-m-d H:i:s', $next_cron ) );
		}
	}

	private function get_ability() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			WP_CLI::error( 'WordPress Abilities API not available (requires WP 6.9+).' );
		}

		$ability = wp_get_ability( 'datamachine/facebook-read' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/facebook-read ability not registered.' );
		}

		return new \DataMachineSocials\Abilities\Facebook\FacebookReadAbility();
	}
}
