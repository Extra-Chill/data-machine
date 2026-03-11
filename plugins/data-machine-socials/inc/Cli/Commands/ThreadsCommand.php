<?php
/**
 * WP-CLI Threads Command
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
 * Manage Threads integration for Data Machine Socials.
 *
 * ## EXAMPLES
 *
 *     wp datamachine-socials threads posts
 *     wp datamachine-socials threads post 17891234567890
 *     wp datamachine-socials threads replies 17891234567890
 *     wp datamachine-socials threads status
 */
class ThreadsCommand {

	/**
	 * List recent Threads posts.
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
	 *     wp datamachine-socials threads posts
	 *     wp datamachine-socials threads posts --limit=10 --format=json
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

		$data    = $result['data'];
		$threads = $data['threads'] ?? array();
		$format  = $assoc_args['format'] ?? 'table';

		if ( empty( $threads ) ) {
			WP_CLI::warning( 'No threads found.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::success( "Found {$data['count']} threads" );
		WP_CLI::log( '' );

		foreach ( $threads as $item ) {
			$text = mb_substr( $item['text'] ?? '(no text)', 0, 60 );
			if ( mb_strlen( $item['text'] ?? '' ) > 60 ) {
				$text .= '...';
			}
			$likes = $item['like_count'] ?? 0;
			$type  = $item['media_type'] ?? 'TEXT';
			$date  = isset( $item['timestamp'] ) ? wp_date( 'Y-m-d', strtotime( $item['timestamp'] ) ) : '';

			WP_CLI::log( sprintf( '  %s  %-12s  %s  %d likes  %s', $item['id'], $type, $date, $likes, $text ) );
		}

		if ( $data['has_next'] && ! empty( $data['cursors']['after'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( "Next page: --after={$data['cursors']['after']}" );
		}
	}

	/**
	 * Get details for a specific thread.
	 *
	 * ## OPTIONS
	 *
	 * <thread_id>
	 * : The Threads media ID.
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
		$thread_id = $args[0];
		$ability   = $this->get_ability();

		$result = $ability->execute( array(
			'action'    => 'get',
			'thread_id' => $thread_id,
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

		WP_CLI::success( "Thread {$thread_id}" );
		WP_CLI::log( '' );
		WP_CLI::log( 'ID:        ' . ( $data['id'] ?? '' ) );
		WP_CLI::log( 'Type:      ' . ( $data['media_type'] ?? '' ) );
		WP_CLI::log( 'Date:      ' . ( $data['timestamp'] ?? '' ) );
		WP_CLI::log( 'Likes:     ' . ( $data['like_count'] ?? 0 ) );
		WP_CLI::log( 'Permalink: ' . ( $data['permalink'] ?? '' ) );
		WP_CLI::log( '' );
		WP_CLI::log( 'Text:' );
		WP_CLI::log( $data['text'] ?? '(no text)' );
	}

	/**
	 * Get replies on a thread.
	 *
	 * ## OPTIONS
	 *
	 * <thread_id>
	 * : The Threads media ID.
	 *
	 * [--limit=<limit>]
	 * : Number of replies to return.
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
	public function replies( $args, $assoc_args ) {
		$thread_id = $args[0];
		$ability   = $this->get_ability();

		$result = $ability->execute( array(
			'action'    => 'replies',
			'thread_id' => $thread_id,
			'limit'     => absint( $assoc_args['limit'] ?? 25 ),
		) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
		}

		$data    = $result['data'];
		$replies = $data['replies'] ?? array();
		$format  = $assoc_args['format'] ?? 'table';

		if ( empty( $replies ) ) {
			WP_CLI::warning( 'No replies found.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::success( "Found {$data['count']} replies" );
		WP_CLI::log( '' );

		foreach ( $replies as $reply ) {
			$username = $reply['username'] ?? 'unknown';
			$text     = $reply['text'] ?? '';
			$likes    = $reply['like_count'] ?? 0;
			$date     = isset( $reply['timestamp'] ) ? wp_date( 'Y-m-d H:i', strtotime( $reply['timestamp'] ) ) : '';

			WP_CLI::log( sprintf( '  @%-20s %s  (%d likes)  %s', $username, $date, $likes, $text ) );
		}
	}

	/**
	 * Show Threads authentication status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials threads status
	 */
	public function status( $args, $assoc_args ) {
		$args;
		$assoc_args;
		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'threads' );

		WP_CLI::log( 'Threads Integration Status' );
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
				WP_CLI::log( 'User ID:       ' . $page_id );
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

		$next_cron = wp_next_scheduled( 'datamachine_refresh_token_threads' );
		if ( $next_cron ) {
			WP_CLI::log( 'Next cron:     ' . wp_date( 'Y-m-d H:i:s', $next_cron ) );
		}
	}

	private function get_ability() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			WP_CLI::error( 'WordPress Abilities API not available (requires WP 6.9+).' );
		}

		$ability = wp_get_ability( 'datamachine/threads-read' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/threads-read ability not registered.' );
		}

		return new \DataMachineSocials\Abilities\Threads\ThreadsReadAbility();
	}

	private function get_update_ability() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			WP_CLI::error( 'WordPress Abilities API not available (requires WP 6.9+).' );
		}

		$ability = wp_get_ability( 'datamachine/threads-update' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/threads-update ability not registered.' );
		}

		return new \DataMachineSocials\Abilities\Threads\ThreadsUpdateAbility();
	}

	private function get_delete_ability() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			WP_CLI::error( 'WordPress Abilities API not available (requires WP 6.9+).' );
		}

		$ability = wp_get_ability( 'datamachine/threads-delete' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/threads-delete ability not registered.' );
		}

		return new \DataMachineSocials\Abilities\Threads\ThreadsDeleteAbility();
	}

	/**
	 * Delete a Threads post.
	 *
	 * ## OPTIONS
	 *
	 * <thread_id>
	 * : The Threads thread ID to delete.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials threads delete 1234567890
	 */
	public function delete( $args, $assoc_args ) {
		$assoc_args;
		$thread_id = $args[0];
		$ability   = $this->get_delete_ability();

		$result = $ability->execute( array(
			'thread_id' => $thread_id,
		) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
		}

		WP_CLI::success( 'Thread deleted successfully!' );
		WP_CLI::log( 'Thread ID: ' . $result['data']['thread_id'] );
	}
}
