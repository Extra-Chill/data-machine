<?php
/**
 * Content Performance Ability
 *
 * Within-category content-performance audit. Joins a category's published posts
 * to GA4 per-page engagement (via the datamachine/google-analytics `engagement`
 * action) and ranks them so editorial can find UNDERPERFORMERS — posts that
 * draw real demand but hold readers poorly (low dwell / low engagement rate),
 * versus structurally-similar siblings that perform well.
 *
 * This is the engagement-axis sibling of data-machine's
 * InternalLinkingAbilities::getLinkOpportunities(): it reuses the same proven
 * category -> post-IDs -> url_to_postid() join shape, but swaps the data source
 * from Google Search Console clicks (link-equity weakness) to Google Analytics
 * engagement (content-quality weakness), and enriches each row with post age +
 * word count so "similar on the surface, divergent outcome" is visible.
 *
 * Honesty note: per-page dwell is GA4-only. The first-party analytics events
 * table records a load-time pageview with no exit/duration event, so it cannot
 * produce time-on-page. GA engagement carries GA's sampling/threshold caveats
 * and is not bot-filtered the way the first-party reads are.
 *
 * @package DataMachineBusiness\Abilities\Analytics
 * @since 0.40.0
 */

namespace DataMachineBusiness\Abilities\Analytics;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class ContentPerformanceAbility {

	/**
	 * Default lookback window in days.
	 *
	 * @var int
	 */
	const DEFAULT_DAYS = 28;

	/**
	 * Default minimum engaged sessions for a post to be "comparable".
	 *
	 * Below this, a single anomalous session can dominate the dwell average, so
	 * such posts are reported separately (insufficient_sample) rather than ranked.
	 *
	 * @var int
	 */
	const DEFAULT_MIN_SESSIONS = 5;

	/**
	 * Default hostname for resolving page paths to posts.
	 *
	 * The GA `engagement` action groups by pagePath only (host-independent), so
	 * on a multisite GA4 property we constrain to one host to avoid cross-site
	 * "/" collisions. The category posts are resolved on the current blog via
	 * url_to_postid(), so this should match the blog the command runs on.
	 *
	 * @var string
	 */
	const DEFAULT_HOSTNAME = 'extrachill.com';

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/content-performance',
				array(
					'label'               => 'Content Performance Audit',
					'description'         => 'Rank a category\'s published posts by GA4 engagement (avg session duration, engagement rate) to surface content-level underperformers — posts that draw real demand but hold readers poorly. Joins the category post set to per-page GA engagement (datamachine/google-analytics engagement action) and enriches each row with post age + word count so structurally-similar posts with divergent outcomes are comparable. Per-page dwell is GA4-only (the first-party pageview table has no duration event), so results carry GA sampling caveats and are not bot-filtered.',
					'category'            => 'datamachine-analytics',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'category' ),
						'properties' => array(
							'category'     => array(
								'type'        => 'string',
								'description' => 'Category slug to audit (e.g. "music-history", "song-meanings").',
							),
							'days'         => array(
								'type'        => 'integer',
								'description' => 'Lookback window in days (default: 28).',
							),
							'min_sessions' => array(
								'type'        => 'integer',
								'description' => 'Minimum engaged sessions for a post to be ranked (default: 5). Posts below this are returned in insufficient_sample, never ranked, so a single anomalous session cannot dominate the dwell average.',
							),
							'hostname'     => array(
								'type'        => 'string',
								'description' => 'Hostname whose pages map to this blog\'s posts (default: extrachill.com). The GA engagement report is filtered to this host.',
							),
							'sort_by'      => array(
								'type'        => 'string',
								'description' => 'Rank metric: "duration" (avg session duration, default) or "rate" (engagement rate). Underperformers sort ascending on this.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'             => array( 'type' => 'boolean' ),
							'category'            => array( 'type' => 'string' ),
							'window'              => array( 'type' => 'object' ),
							'published_total'     => array( 'type' => 'integer' ),
							'with_traffic'        => array( 'type' => 'integer' ),
							'comparable'          => array( 'type' => 'integer' ),
							'median_duration'     => array( 'type' => 'number' ),
							'posts'               => array( 'type' => 'array' ),
							'insufficient_sample' => array( 'type' => 'array' ),
							'error'               => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'audit' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Run the within-category content-performance audit.
	 *
	 * @param array $input Ability input.
	 * @return array Ability response.
	 */
	public static function audit( array $input ): array {
		$category     = sanitize_title( $input['category'] ?? '' );
		$days         = ! empty( $input['days'] ) ? max( 1, (int) $input['days'] ) : self::DEFAULT_DAYS;
		$min_sessions = isset( $input['min_sessions'] ) ? max( 1, (int) $input['min_sessions'] ) : self::DEFAULT_MIN_SESSIONS;
		$hostname     = ! empty( $input['hostname'] ) ? sanitize_text_field( $input['hostname'] ) : self::DEFAULT_HOSTNAME;
		$sort_by      = ( 'rate' === ( $input['sort_by'] ?? '' ) ) ? 'rate' : 'duration';

		if ( '' === $category ) {
			return array(
				'success' => false,
				'error'   => 'A category slug is required.',
			);
		}

		$term = get_term_by( 'slug', $category, 'category' );
		if ( ! $term ) {
			return array(
				'success' => false,
				'error'   => "Category '{$category}' not found.",
			);
		}

		// 1. The category's published posts, keyed by ID for an O(1) join filter.
		$post_ids = get_posts(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'category'    => $term->term_id,
				'fields'      => 'ids',
				'numberposts' => -1,
			)
		);

		if ( empty( $post_ids ) ) {
			return array(
				'success'         => true,
				'category'        => $category,
				'published_total' => 0,
				'with_traffic'    => 0,
				'comparable'      => 0,
				'posts'           => array(),
			);
		}

		$in_category = array_flip( $post_ids );

		// 2. Per-page GA4 engagement, scoped to the host that maps to this blog.
		$ga = wp_get_ability( 'datamachine/google-analytics' );
		if ( ! $ga ) {
			return array(
				'success' => false,
				'error'   => 'Google Analytics ability not available. Ensure Data Machine Business is active and GA is configured.',
			);
		}

		$start_date = gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );
		$end_date   = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );

		$ga_result = $ga->execute(
			array(
				'action'     => 'engagement',
				'start_date' => $start_date,
				'end_date'   => $end_date,
				'hostname'   => $hostname,
				'limit'      => 10000,
			)
		);

		if ( empty( $ga_result['success'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Failed to fetch GA engagement data: ' . ( $ga_result['error'] ?? 'Unknown error' ),
			);
		}

		// 3. Join GA rows (keyed by pagePath) to category posts via url_to_postid().
		$by_post = array();
		foreach ( (array) ( $ga_result['results'] ?? array() ) as $row ) {
			$path = (string) ( $row['pagePath'] ?? '' );
			if ( '' === $path ) {
				continue;
			}

			$post_id = self::resolve_post_id( $path, $hostname );
			if ( 0 === $post_id || ! isset( $in_category[ $post_id ] ) ) {
				continue;
			}

			if ( ! isset( $by_post[ $post_id ] ) ) {
				$by_post[ $post_id ] = array(
					'engaged_sessions' => 0.0,
					'dur_weighted'     => 0.0,
					'rate_weighted'    => 0.0,
				);
			}

			$engaged = (float) ( $row['engagedSessions'] ?? 0 );
			$dur     = (float) ( $row['averageSessionDuration'] ?? 0 );
			$rate    = (float) ( $row['engagementRate'] ?? 0 );

			// Weight per-path averages by that path's engaged sessions so multiple
			// URL variants of one post aggregate into a session-weighted mean
			// rather than an unweighted average of averages.
			$weight                                = max( $engaged, 1.0 );
			$by_post[ $post_id ]['engaged_sessions'] += $engaged;
			$by_post[ $post_id ]['dur_weighted']     += $dur * $weight;
			$by_post[ $post_id ]['rate_weighted']    += $rate * $weight;
		}

		// 4. Build enriched rows; split comparable vs insufficient-sample.
		$comparable   = array();
		$insufficient = array();

		foreach ( $by_post as $post_id => $agg ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$sessions     = (int) round( $agg['engaged_sessions'] );
			$weight_total = max( $agg['engaged_sessions'], 1.0 );
			$avg_dur      = round( $agg['dur_weighted'] / $weight_total, 1 );
			$avg_rate     = round( $agg['rate_weighted'] / $weight_total, 3 );

			$entry = array(
				'post_id'          => $post_id,
				'slug'             => $post->post_name,
				'title'            => $post->post_title,
				'engaged_sessions' => $sessions,
				'avg_duration'     => $avg_dur,
				'engagement_rate'  => $avg_rate,
				'word_count'       => self::word_count( $post ),
				'age_days'         => self::age_days( $post ),
			);

			if ( $sessions >= $min_sessions ) {
				$comparable[] = $entry;
			} else {
				$insufficient[] = $entry;
			}
		}

		// 5. Rank comparable posts: underperformers first (ascending on the metric).
		$metric_key = ( 'rate' === $sort_by ) ? 'engagement_rate' : 'avg_duration';
		usort(
			$comparable,
			static function ( $a, $b ) use ( $metric_key ) {
				return $a[ $metric_key ] <=> $b[ $metric_key ];
			}
		);

		// Category benchmark: the median duration across comparable posts. Posts
		// far below it carry the strongest content-weakness signal.
		$durations       = wp_list_pluck( $comparable, 'avg_duration' );
		sort( $durations );
		$count           = count( $durations );
		$median_duration = 0.0;
		if ( $count > 0 ) {
			$mid             = (int) floor( ( $count - 1 ) / 2 );
			$median_duration = ( 0 === $count % 2 )
				? round( ( $durations[ $mid ] + $durations[ $mid + 1 ] ) / 2, 1 )
				: $durations[ $mid ];
		}

		return array(
			'success'             => true,
			'category'            => $category,
			'window'              => array(
				'start' => $start_date,
				'end'   => $end_date,
				'days'  => $days,
			),
			'hostname'            => $hostname,
			'sort_by'             => $sort_by,
			'min_sessions'        => $min_sessions,
			'published_total'     => count( $post_ids ),
			'with_traffic'        => count( $by_post ),
			'comparable'          => count( $comparable ),
			'median_duration'     => $median_duration,
			'posts'               => $comparable,
			'insufficient_sample' => $insufficient,
		);
	}

	/**
	 * Resolve a GA pagePath to a post ID on the current blog.
	 *
	 * GA pagePath is host-relative (e.g. "/some-slug/"); url_to_postid() wants a
	 * full URL, so prepend the scheme+host before resolving.
	 *
	 * @param string $path     GA pagePath (host-relative).
	 * @param string $hostname Hostname the path belongs to.
	 * @return int Post ID, or 0 if unresolved.
	 */
	private static function resolve_post_id( string $path, string $hostname ): int {
		$path = strtok( $path, '?' );
		$path = '/' . ltrim( (string) $path, '/' );

		return (int) url_to_postid( 'https://' . $hostname . $path );
	}

	/**
	 * Word count of a post's rendered text content.
	 *
	 * @param \WP_Post $post Post object.
	 * @return int
	 */
	private static function word_count( \WP_Post $post ): int {
		$text = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );

		return str_word_count( $text );
	}

	/**
	 * Age of a post in days since publish.
	 *
	 * @param \WP_Post $post Post object.
	 * @return int
	 */
	private static function age_days( \WP_Post $post ): int {
		$published = strtotime( $post->post_date_gmt . ' UTC' );
		if ( ! $published ) {
			return 0;
		}

		return (int) max( 0, floor( ( time() - $published ) / DAY_IN_SECONDS ) );
	}
}
