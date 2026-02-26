<?php
/**
 * WP-CLI Reddit Command
 *
 * Provides CLI access to Reddit fetch operations and account management.
 * Wraps the FetchRedditAbility and RedditAuth providers.
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
 * Manage Reddit integration for Data Machine Socials.
 *
 * ## EXAMPLES
 *
 *     # Fetch a post from a subreddit
 *     wp datamachine-socials reddit fetch jambands
 *
 *     # Fetch with filters
 *     wp datamachine-socials reddit fetch jambands --sort=top --min-upvotes=100
 *
 *     # Check Reddit auth status
 *     wp datamachine-socials reddit status
 */
class RedditCommand {

	/**
	 * Fetch a post from a Reddit subreddit.
	 *
	 * Uses the datamachine/fetch-reddit ability to fetch the first eligible
	 * post from the given subreddit, applying all configured filters.
	 *
	 * ## OPTIONS
	 *
	 * <subreddit>
	 * : The subreddit name (without "r/").
	 *
	 * [--sort=<sort>]
	 * : Sort order for posts.
	 * ---
	 * default: hot
	 * options:
	 *   - hot
	 *   - new
	 *   - top
	 *   - rising
	 *   - controversial
	 * ---
	 *
	 * [--timeframe=<timeframe>]
	 * : Timeframe filter.
	 * ---
	 * default: all_time
	 * options:
	 *   - all_time
	 *   - 24_hours
	 *   - 72_hours
	 *   - 7_days
	 *   - 30_days
	 *   - 90_days
	 *   - 6_months
	 *   - 1_year
	 * ---
	 *
	 * [--min-upvotes=<min_upvotes>]
	 * : Minimum upvotes required.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--min-comments=<min_comments>]
	 * : Minimum comment count required.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--comments=<comments>]
	 * : Number of top comments to fetch per post.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--search=<search>]
	 * : Comma-separated search terms to filter posts.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials reddit fetch jambands
	 *     wp datamachine-socials reddit fetch festivals --sort=top --min-upvotes=50
	 *     wp datamachine-socials reddit fetch bonnaroo --timeframe=7_days --comments=5
	 *     wp datamachine-socials reddit fetch sxsw --format=json
	 */
	public function fetch( $args, $assoc_args ) {
		$subreddit = $args[0];

		// Get auth provider.
		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'reddit' );

		if ( ! $provider ) {
			WP_CLI::error( 'Reddit auth provider not found. Is data-machine-socials active?' );
		}

		if ( ! $provider->is_authenticated() ) {
			WP_CLI::error( 'Reddit is not authenticated. Connect via Settings > Data Machine > Auth.' );
		}

		$access_token = $provider->get_valid_access_token();
		if ( empty( $access_token ) ) {
			WP_CLI::error( 'Failed to obtain a valid Reddit access token (expired and refresh failed).' );
		}

		// Build ability input.
		$input = array(
			'subreddit'         => $subreddit,
			'access_token'      => $access_token,
			'sort_by'           => $assoc_args['sort'] ?? 'hot',
			'timeframe_limit'   => $assoc_args['timeframe'] ?? 'all_time',
			'min_upvotes'       => absint( $assoc_args['min-upvotes'] ?? 0 ),
			'min_comment_count' => absint( $assoc_args['min-comments'] ?? 0 ),
			'comment_count'     => absint( $assoc_args['comments'] ?? 0 ),
			'search'            => $assoc_args['search'] ?? '',
			'processed_items'   => array(),
			'fetch_batch_size'  => 100,
			'max_pages'         => 5,
			'download_images'   => false, // CLI doesn't download images by default.
		);

		WP_CLI::log( "Fetching from r/{$subreddit} (sort: {$input['sort_by']})..." );

		if ( ! function_exists( 'wp_get_ability' ) ) {
			WP_CLI::error( 'WordPress Abilities API not available (requires WP 6.9+).' );
		}

		$ability = wp_get_ability( 'datamachine/fetch-reddit' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/fetch-reddit ability not registered.' );
		}

		$result = $ability->execute( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Reddit fetch failed.' );
		}

		if ( empty( $result['data'] ) ) {
			WP_CLI::warning( 'No eligible posts found with the given filters.' );
			return;
		}

		$data   = $result['data'];
		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( 'yaml' === $format ) {
			$this->output_yaml( $data );
			return;
		}

		// Table format: show key fields.
		$metadata = $data['metadata'] ?? array();
		WP_CLI::success( 'Fetched post from r/' . $subreddit );
		WP_CLI::log( '' );
		WP_CLI::log( 'Title:    ' . ( $data['title'] ?? '(none)' ) );
		WP_CLI::log( 'Author:   ' . ( $metadata['author'] ?? 'unknown' ) );
		WP_CLI::log( 'Upvotes:  ' . ( $metadata['upvotes'] ?? 0 ) );
		WP_CLI::log( 'Comments: ' . ( $metadata['comment_count'] ?? 0 ) );
		WP_CLI::log( 'Date:     ' . ( $metadata['original_date_gmt'] ?? 'unknown' ) );
		WP_CLI::log( 'URL:      ' . ( $result['source_url'] ?? '' ) );

		$content = $data['content'] ?? '';
		if ( ! empty( $content ) ) {
			WP_CLI::log( '' );
			$preview = mb_substr( $content, 0, 500 );
			if ( mb_strlen( $content ) > 500 ) {
				$preview .= '...';
			}
			WP_CLI::log( 'Content:' );
			WP_CLI::log( $preview );
		}
	}

	/**
	 * Show Reddit authentication status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials reddit status
	 */
	public function status( $args, $assoc_args ) {
		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'reddit' );

		WP_CLI::log( 'Reddit Integration Status' );
		WP_CLI::log( '---' );

		if ( ! $provider ) {
			WP_CLI::log( 'Provider:      Not found' );
			WP_CLI::log( 'Authenticated: No' );
			return;
		}

		$authenticated = $provider->is_authenticated();
		$details       = $provider->get_account_details();

		WP_CLI::log( 'Authenticated: ' . ( $authenticated ? 'Yes' : 'No' ) );

		if ( $details ) {
			WP_CLI::log( 'Username:      ' . ( $details['username'] ?? 'unknown' ) );
			WP_CLI::log( 'Scope:         ' . ( $details['scope'] ?? 'unknown' ) );

			if ( ! empty( $details['token_expires_at'] ) ) {
				$expires_at = intval( $details['token_expires_at'] );
				$remaining  = $expires_at - time();

				if ( $remaining > 0 ) {
					$minutes = round( $remaining / 60 );
					WP_CLI::log( "Token expires: in {$minutes} minutes" );
				} else {
					WP_CLI::log( 'Token expires: EXPIRED (will auto-refresh)' );
				}
			}

			if ( ! empty( $details['last_refreshed_at'] ) ) {
				WP_CLI::log( 'Last refresh:  ' . wp_date( 'Y-m-d H:i:s', intval( $details['last_refreshed_at'] ) ) );
			}

			$next_cron = wp_next_scheduled( 'datamachine_refresh_token_reddit' );
			if ( $next_cron ) {
				WP_CLI::log( 'Next cron:     ' . wp_date( 'Y-m-d H:i:s', $next_cron ) );
			}
		}
	}

	/**
	 * Output data as YAML-like format.
	 *
	 * @param array $data Data to output.
	 */
	private function output_yaml( array $data, int $indent = 0 ): void {
		$prefix = str_repeat( '  ', $indent );
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				WP_CLI::log( "{$prefix}{$key}:" );
				$this->output_yaml( $value, $indent + 1 );
			} else {
				$display = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
				WP_CLI::log( "{$prefix}{$key}: {$display}" );
			}
		}
	}
}
