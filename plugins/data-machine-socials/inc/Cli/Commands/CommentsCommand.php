<?php
/**
 * WP-CLI Comments Command
 *
 * Cross-platform comment access via the generic comments API.
 * Returns normalized SocialComment shape regardless of platform.
 *
 * @package    DataMachineSocials
 * @subpackage Cli\Commands
 * @since      0.9.0
 */

namespace DataMachineSocials\Cli\Commands;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Read and reply to comments across social platforms.
 *
 * Provides a unified interface for comment operations across Instagram,
 * Facebook, and other supported platforms. Returns comments in a normalized
 * shape with parsed @mentions for downstream processing.
 *
 * ## EXAMPLES
 *
 *     # List all comments on an Instagram post
 *     wp datamachine-socials comments list instagram 17891234567890
 *
 *     # List comments in JSON (pipe-friendly)
 *     wp datamachine-socials comments list instagram 17891234567890 --format=json
 *
 *     # Reply to a comment
 *     wp datamachine-socials comments reply instagram 17891234567890 "Thanks!"
 *
 *     # Show only comments that contain @mentions
 *     wp datamachine-socials comments list instagram 17891234567890 --has-mentions
 */
class CommentsCommand {

	/**
	 * Platform-to-read-ability slug mapping.
	 */
	private const READ_SLUG_MAP = array(
		'instagram' => 'datamachine/instagram-read',
		'facebook'  => 'datamachine/facebook-read',
	);

	/**
	 * Platform-to-reply-ability slug mapping.
	 */
	private const REPLY_SLUG_MAP = array(
		'instagram' => 'datamachine/instagram-comment-reply',
	);

	/**
	 * List comments on a social media post.
	 *
	 * Fetches all comments (auto-paginates) and returns them in the
	 * normalized SocialComment shape with parsed @mentions.
	 *
	 * ## OPTIONS
	 *
	 * <platform>
	 * : Platform slug (instagram, facebook).
	 *
	 * <media_id>
	 * : Platform-specific post/media ID.
	 *
	 * [--has-mentions]
	 * : Only show comments containing @mentions.
	 *
	 * [--min-mentions=<count>]
	 * : Only show comments with at least this many @mentions.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--exclude=<usernames>]
	 * : Comma-separated list of usernames to exclude from results.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials comments list instagram 17891234567890
	 *     wp datamachine-socials comments list instagram 17891234567890 --has-mentions --format=json
	 *     wp datamachine-socials comments list instagram 17891234567890 --exclude=extrachill,botaccount
	 *     wp datamachine-socials comments list instagram 17891234567890 --format=count
	 */
	public function list_( $args, $assoc_args ) {
		$platform = $args[0] ?? '';
		$media_id = $args[1] ?? '';

		if ( ! isset( self::READ_SLUG_MAP[ $platform ] ) ) {
			WP_CLI::error( "Unsupported platform: {$platform}. Supported: " . implode( ', ', array_keys( self::READ_SLUG_MAP ) ) );
		}

		$ability = $this->get_ability( self::READ_SLUG_MAP[ $platform ] );

		WP_CLI::log( "Fetching all comments from {$platform}..." );

		$result = $ability->execute( array(
			'action'   => 'comments_all',
			'media_id' => $media_id,
		) );

		if ( is_wp_error( $result ) || ! $result['success'] ) {
			WP_CLI::error( is_wp_error( $result ) ? $result->get_error_message() : $result['error'] );
		}

		$comments = $result['data']['comments'] ?? array();
		$total    = count( $comments );

		if ( ! empty( $result['data']['partial'] ) ) {
			WP_CLI::warning( 'Partial results: ' . ( $result['data']['error'] ?? 'pagination interrupted' ) );
		}

		// Apply filters.
		$has_mentions = isset( $assoc_args['has-mentions'] );
		$min_mentions = absint( $assoc_args['min-mentions'] ?? 0 );
		$exclude      = array();

		if ( ! empty( $assoc_args['exclude'] ) ) {
			$exclude = array_map( 'trim', explode( ',', $assoc_args['exclude'] ) );
			$exclude = array_map( 'strtolower', $exclude );
		}

		if ( $has_mentions ) {
			$min_mentions = max( $min_mentions, 1 );
		}

		if ( $min_mentions > 0 || ! empty( $exclude ) ) {
			$comments = array_filter( $comments, function ( $c ) use ( $min_mentions, $exclude ) {
				if ( $min_mentions > 0 && count( $c['mentions'] ?? array() ) < $min_mentions ) {
					return false;
				}

				if ( ! empty( $exclude ) && in_array( strtolower( $c['author_username'] ?? '' ), $exclude, true ) ) {
					return false;
				}

				return true;
			} );
			$comments = array_values( $comments );
		}

		$format   = $assoc_args['format'] ?? 'table';
		$filtered = count( $comments );

		if ( 'count' === $format ) {
			WP_CLI::log( (string) $filtered );
			return;
		}

		if ( empty( $comments ) ) {
			WP_CLI::warning( "No comments found ({$total} total, {$filtered} after filtering)." );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( array(
				'platform'  => $platform,
				'media_id'  => $media_id,
				'total'     => $total,
				'filtered'  => $filtered,
				'comments'  => $comments,
			), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( 'csv' === $format ) {
			WP_CLI::log( 'id,author_username,text,timestamp,like_count,mentions' );
			foreach ( $comments as $c ) {
				$mentions_str = implode( ' ', array_map( function ( $m ) { return '@' . $m; }, $c['mentions'] ?? array() ) );
				$text         = str_replace( '"', '""', $c['text'] ?? '' );
				WP_CLI::log( sprintf(
					'%s,%s,"%s",%s,%d,"%s"',
					$c['id'],
					$c['author_username'],
					$text,
					$c['timestamp'],
					$c['like_count'],
					$mentions_str
				) );
			}
			return;
		}

		// Table format.
		$filter_note = $filtered < $total ? " ({$filtered} after filters)" : '';
		WP_CLI::success( "Found {$total} comments{$filter_note}" );
		WP_CLI::log( '' );

		foreach ( $comments as $c ) {
			$mentions = '';
			if ( ! empty( $c['mentions'] ) ) {
				$mentions = ' → @' . implode( ', @', $c['mentions'] );
			}

			$date = ! empty( $c['timestamp'] ) ? wp_date( 'Y-m-d H:i', strtotime( $c['timestamp'] ) ) : '';

			WP_CLI::log( sprintf(
				'  @%-20s %s  (%d likes)  %s%s',
				$c['author_username'],
				$date,
				$c['like_count'],
				mb_substr( $c['text'] ?? '', 0, 80 ),
				$mentions
			) );
		}
	}

	/**
	 * Reply to a comment on a social media post.
	 *
	 * ## OPTIONS
	 *
	 * <platform>
	 * : Platform slug (instagram).
	 *
	 * <comment_id>
	 * : The comment ID to reply to.
	 *
	 * <message>
	 * : The reply text.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials comments reply instagram 1789000000000 "Thanks for entering!"
	 */
	public function reply( $args ) {
		$platform   = $args[0] ?? '';
		$comment_id = $args[1] ?? '';
		$message    = $args[2] ?? '';

		if ( ! isset( self::REPLY_SLUG_MAP[ $platform ] ) ) {
			WP_CLI::error( "Comment reply not supported for platform: {$platform}. Supported: " . implode( ', ', array_keys( self::REPLY_SLUG_MAP ) ) );
		}

		if ( empty( $comment_id ) || empty( $message ) ) {
			WP_CLI::error( 'comment_id and message are required.' );
		}

		$ability = $this->get_ability( self::REPLY_SLUG_MAP[ $platform ] );

		$result = $ability->execute( array(
			'comment_id' => $comment_id,
			'message'    => $message,
		) );

		if ( is_wp_error( $result ) || ! $result['success'] ) {
			WP_CLI::error( is_wp_error( $result ) ? $result->get_error_message() : $result['error'] );
		}

		WP_CLI::success( "Reply posted on {$platform}!" );
		WP_CLI::log( 'Comment ID: ' . ( $result['data']['comment_id'] ?? $comment_id ) );
		WP_CLI::log( 'Reply ID:   ' . ( $result['data']['reply_id'] ?? '' ) );
	}

	/**
	 * Get an ability by slug, or exit with error.
	 *
	 * @param string $slug Ability slug.
	 * @return object Ability instance.
	 */
	private function get_ability( string $slug ) {
		$ability = wp_get_ability( $slug );
		if ( ! $ability ) {
			WP_CLI::error( "{$slug} ability not registered." );
		}

		return $ability;
	}
}
