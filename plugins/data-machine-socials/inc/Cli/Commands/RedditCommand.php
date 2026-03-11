<?php
/**
 * WP-CLI Reddit Command
 *
 * Provides CLI access to Reddit fetch operations, subreddit discovery,
 * and account management. Wraps the FetchRedditAbility and RedditAuth
 * providers, plus direct Reddit API calls for discovery commands.
 *
 * @package    DataMachineSocials
 * @subpackage Cli\Commands
 * @since      0.3.0
 */

namespace DataMachineSocials\Cli\Commands;

use WP_CLI;
use DataMachine\Abilities\AuthAbilities;
use DataMachine\Core\HttpClient;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Reddit integration for Data Machine Socials.
 *
 * ## EXAMPLES
 *
 *     # Fetch a post from a subreddit
 *     wp datamachine-socials reddit fetch jambands
 *
 *     # Get subreddit info
 *     wp datamachine-socials reddit info bonnaroo
 *
 *     # Search for subreddits
 *     wp datamachine-socials reddit search "music festivals"
 *
 *     # List posts with scores
 *     wp datamachine-socials reddit posts sxsw --sort=top --limit=20
 *
 *     # Browse trending subreddits
 *     wp datamachine-socials reddit trending --limit=10
 *
 *     # Check Reddit auth status
 *     wp datamachine-socials reddit status
 */
class RedditCommand {

	/**
	 * Reddit OAuth API base URL.
	 */
	private const API_BASE = 'https://oauth.reddit.com';

	/**
	 * Get subreddit information and statistics.
	 *
	 * Returns subscriber count, description, creation date, and activity
	 * metrics for a subreddit. Use this to understand a subreddit's size
	 * and set appropriate thresholds for flow configuration.
	 *
	 * ## OPTIONS
	 *
	 * <subreddit>
	 * : The subreddit name (without "r/").
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
	 *     wp datamachine-socials reddit info bonnaroo
	 *     wp datamachine-socials reddit info festivals --format=json
	 *     wp datamachine-socials reddit info jambands --format=yaml
	 *
	 * @subcommand info
	 */
	public function info( $args, $assoc_args ) {
		$subreddit = $args[0];
		$format    = $assoc_args['format'] ?? 'table';

		WP_CLI::log( "Fetching info for r/{$subreddit}..." );

		$result = $this->reddit_api_get( "/r/{$subreddit}/about" );
		$data   = $result['data'] ?? array();

		$info = array(
			'name'           => $data['display_name'] ?? $subreddit,
			'title'          => $data['title'] ?? '',
			'description'    => $data['public_description'] ?? '',
			'subscribers'    => $data['subscribers'] ?? 0,
			'active_users'   => $data['accounts_active'] ?? 0,
			'created'        => ! empty( $data['created_utc'] ) ? wp_date( 'Y-m-d', intval( $data['created_utc'] ) ) : 'unknown',
			'over_18'        => ! empty( $data['over18'] ) ? 'yes' : 'no',
			'subreddit_type' => $data['subreddit_type'] ?? 'unknown',
			'url'            => 'https://www.reddit.com/r/' . ( $data['display_name'] ?? $subreddit ),
		);

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( 'yaml' === $format ) {
			$this->output_yaml( $info );
			return;
		}

		// Table format.
		WP_CLI::success( "r/{$subreddit}" );
		WP_CLI::log( '' );
		WP_CLI::log( 'Name:         ' . $info['name'] );
		WP_CLI::log( 'Title:        ' . $info['title'] );
		WP_CLI::log( 'Subscribers:  ' . number_format( $info['subscribers'] ) );
		WP_CLI::log( 'Active now:   ' . number_format( $info['active_users'] ) );
		WP_CLI::log( 'Created:      ' . $info['created'] );
		WP_CLI::log( 'Type:         ' . $info['subreddit_type'] );
		WP_CLI::log( 'NSFW:         ' . $info['over_18'] );
		WP_CLI::log( 'URL:          ' . $info['url'] );

		if ( ! empty( $info['description'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Description:' );
			WP_CLI::log( $info['description'] );
		}
	}

	/**
	 * Search for subreddits by keyword.
	 *
	 * Find subreddits matching a search query. Returns name, subscriber
	 * count, and description for each match. Useful for discovering new
	 * data sources for flows.
	 *
	 * ## OPTIONS
	 *
	 * <query>
	 * : Search query to find subreddits.
	 *
	 * [--limit=<limit>]
	 * : Maximum number of results.
	 * ---
	 * default: 10
	 * ---
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
	 *     wp datamachine-socials reddit search "music festivals"
	 *     wp datamachine-socials reddit search "jam bands" --limit=20
	 *     wp datamachine-socials reddit search "electronic music" --format=json
	 */
	public function search( $args, $assoc_args ) {
		$query  = $args[0];
		$limit  = absint( $assoc_args['limit'] ?? 10 );
		$format = $assoc_args['format'] ?? 'table';

		WP_CLI::log( "Searching subreddits for \"{$query}\"..." );

		$result   = $this->reddit_api_get( '/subreddits/search', array(
			'q'     => $query,
			'limit' => min( $limit, 100 ),
			'sort'  => 'relevance',
			'type'  => 'sr',
		) );
		$children = $result['data']['children'] ?? array();

		if ( empty( $children ) ) {
			WP_CLI::warning( 'No subreddits found for "' . $query . '".' );
			return;
		}

		$rows = array();
		foreach ( $children as $child ) {
			$sub    = $child['data'] ?? array();
			$rows[] = array(
				'subreddit'   => 'r/' . ( $sub['display_name'] ?? '' ),
				'subscribers' => $sub['subscribers'] ?? 0,
				'active'      => $sub['accounts_active'] ?? 0,
				'nsfw'        => ! empty( $sub['over18'] ) ? 'yes' : 'no',
				'description' => mb_substr( $sub['public_description'] ?? '', 0, 80 ),
			);
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( 'yaml' === $format ) {
			$this->output_yaml( $rows );
			return;
		}

		WP_CLI::success( count( $rows ) . ' subreddits found for "' . $query . '"' );
		WP_CLI\Utils\format_items( 'table', $rows, array( 'subreddit', 'subscribers', 'active', 'nsfw', 'description' ) );
	}

	/**
	 * List posts from a subreddit with scores.
	 *
	 * Unlike `fetch` which returns a single processed post, `posts` returns
	 * a table of multiple posts with their scores, comment counts, and ages.
	 * Use this to understand a subreddit's score distribution and set
	 * appropriate min_upvotes thresholds.
	 *
	 * ## OPTIONS
	 *
	 * <subreddit>
	 * : The subreddit name (without "r/").
	 *
	 * [--sort=<sort>]
	 * : Sort order for posts.
	 * ---
	 * default: top
	 * options:
	 *   - hot
	 *   - new
	 *   - top
	 *   - rising
	 *   - controversial
	 * ---
	 *
	 * [--timeframe=<timeframe>]
	 * : Time period for top/controversial sort.
	 * ---
	 * default: week
	 * options:
	 *   - hour
	 *   - day
	 *   - week
	 *   - month
	 *   - year
	 *   - all
	 * ---
	 *
	 * [--limit=<limit>]
	 * : Number of posts to return.
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
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials reddit posts bonnaroo
	 *     wp datamachine-socials reddit posts sxsw --sort=top --timeframe=month
	 *     wp datamachine-socials reddit posts festivals --limit=50 --format=json
	 *     wp datamachine-socials reddit posts jambands --sort=hot
	 */
	public function posts( $args, $assoc_args ) {
		$subreddit = $args[0];
		$sort      = $assoc_args['sort'] ?? 'top';
		$timeframe = $assoc_args['timeframe'] ?? 'week';
		$limit     = absint( $assoc_args['limit'] ?? 25 );
		$format    = $assoc_args['format'] ?? 'table';

		WP_CLI::log( "Listing posts from r/{$subreddit} (sort: {$sort}, timeframe: {$timeframe})..." );

		$params = array(
			'limit' => min( $limit, 100 ),
		);

		// Reddit only uses the 't' parameter for top and controversial sorts.
		if ( in_array( $sort, array( 'top', 'controversial' ), true ) ) {
			$params['t'] = $timeframe;
		}

		$result   = $this->reddit_api_get( "/r/{$subreddit}/{$sort}", $params );
		$children = $result['data']['children'] ?? array();

		if ( empty( $children ) ) {
			WP_CLI::warning( "No posts found in r/{$subreddit}." );
			return;
		}

		$rows = array();
		foreach ( $children as $child ) {
			$post = $child['data'] ?? array();

			// Skip stickied/pinned mod posts.
			if ( ! empty( $post['stickied'] ) || ! empty( $post['pinned'] ) ) {
				continue;
			}

			$age_hours = ! empty( $post['created_utc'] ) ? round( ( time() - $post['created_utc'] ) / 3600 ) : 0;
			if ( $age_hours >= 24 ) {
				$age = round( $age_hours / 24 ) . 'd';
			} else {
				$age = $age_hours . 'h';
			}

			$rows[] = array(
				'score'    => $post['score'] ?? 0,
				'comments' => $post['num_comments'] ?? 0,
				'age'      => $age,
				'author'   => $post['author'] ?? '[deleted]',
				'title'    => mb_substr( $post['title'] ?? '', 0, 70 ),
			);
		}

		if ( empty( $rows ) ) {
			WP_CLI::warning( "No non-stickied posts found in r/{$subreddit}." );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( 'yaml' === $format ) {
			$this->output_yaml( $rows );
			return;
		}

		// Show score distribution summary before table.
		$scores = array_column( $rows, 'score' );
		sort( $scores );
		$count  = count( $scores );
		$median = 0 === $count % 2
			? ( $scores[ $count / 2 - 1 ] + $scores[ $count / 2 ] ) / 2
			: $scores[ intval( $count / 2 ) ];

		WP_CLI::success( count( $rows ) . " posts from r/{$subreddit}" );
		WP_CLI::log( sprintf(
			'Score distribution: min=%d, median=%s, max=%d, avg=%d',
			min( $scores ),
			$median,
			max( $scores ),
			round( array_sum( $scores ) / $count )
		) );
		WP_CLI::log( '' );
		WP_CLI\Utils\format_items( $format, $rows, array( 'score', 'comments', 'age', 'author', 'title' ) );
	}

	/**
	 * Browse trending and popular subreddits.
	 *
	 * Lists popular subreddits sorted by subscriber count. Use --search
	 * to filter by category or topic. Useful for discovering new sources.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Number of subreddits to show.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--new]
	 * : Show new subreddits instead of popular ones.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials reddit trending
	 *     wp datamachine-socials reddit trending --limit=50
	 *     wp datamachine-socials reddit trending --new
	 *     wp datamachine-socials reddit trending --format=json
	 */
	public function trending( $args, $assoc_args ) {
		$limit    = absint( $assoc_args['limit'] ?? 25 );
		$format   = $assoc_args['format'] ?? 'table';
		$endpoint = isset( $assoc_args['new'] ) ? '/subreddits/new' : '/subreddits/popular';
		$label    = isset( $assoc_args['new'] ) ? 'new' : 'popular';

		WP_CLI::log( "Fetching {$label} subreddits..." );

		$result   = $this->reddit_api_get( $endpoint, array(
			'limit' => min( $limit, 100 ),
		) );
		$children = $result['data']['children'] ?? array();

		if ( empty( $children ) ) {
			WP_CLI::warning( "No {$label} subreddits returned." );
			return;
		}

		$rows = array();
		foreach ( $children as $child ) {
			$sub    = $child['data'] ?? array();
			$rows[] = array(
				'subreddit'   => 'r/' . ( $sub['display_name'] ?? '' ),
				'subscribers' => $sub['subscribers'] ?? 0,
				'active'      => $sub['accounts_active'] ?? 0,
				'nsfw'        => ! empty( $sub['over18'] ) ? 'yes' : 'no',
				'description' => mb_substr( $sub['public_description'] ?? '', 0, 60 ),
			);
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( 'yaml' === $format ) {
			$this->output_yaml( $rows );
			return;
		}

		WP_CLI::success( count( $rows ) . " {$label} subreddits" );
		WP_CLI\Utils\format_items( $format, $rows, array( 'subreddit', 'subscribers', 'active', 'nsfw', 'description' ) );
	}

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
		$subreddit    = $args[0];
		$access_token = $this->get_access_token();

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
		$args;
		$assoc_args;
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
	 * Get a valid Reddit access token or exit with error.
	 *
	 * @return string Access token.
	 */
	private function get_access_token(): string {
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

		return $access_token;
	}

	/**
	 * Make an authenticated GET request to the Reddit OAuth API.
	 *
	 * @param string $endpoint API endpoint path (e.g. "/r/bonnaroo/about").
	 * @param array  $params   Optional query parameters.
	 * @return array Decoded JSON response data.
	 */
	private function reddit_api_get( string $endpoint, array $params = array() ): array {
		$access_token = $this->get_access_token();

		$url = self::API_BASE . $endpoint . '.json';
		if ( ! empty( $params ) ) {
			$url .= '?' . http_build_query( $params );
		}

		$result = HttpClient::get( $url, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'User-Agent'    => 'php:DataMachineWPPlugin:v' . DATAMACHINE_VERSION . ' (by Data Machine)',
			),
			'context' => 'Reddit CLI',
		) );

		if ( ! $result['success'] || 200 !== ( $result['status_code'] ?? 0 ) ) {
			$status = $result['status_code'] ?? 'unknown';
			WP_CLI::error( "Reddit API request failed (HTTP {$status}): {$endpoint}" );
		}

		$decoded = json_decode( $result['data'] ?? '', true );
		if ( ! is_array( $decoded ) ) {
			WP_CLI::error( "Reddit API returned invalid JSON for {$endpoint}" );
		}

		return $decoded;
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
