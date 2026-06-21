<?php
/**
 * Content Flags Ability
 *
 * Deterministic TRIAGE SCREEN — not a quality score. Sits on top of the
 * datamachine/content-performance join (category -> post-IDs -> url_to_postid()
 * -> GA4 engagement) and runs a small set of deterministic structural red-flag
 * signatures over each comparable post, telling a human WHICH posts to look at.
 * The human judges; the screen does not.
 *
 * Why a screen and not a model: validation over 277 comparable posts
 * (music-history + song-meanings) found NO strong single structural predictor
 * of GOOD performance — every structural feature (heading count, image count,
 * lists, internal links, word count beyond a floor) correlates near-zero
 * (|Pearson r| < 0.15) with dwell/engagement. Content is the quality lever, not
 * structure. But the BAD signatures ARE clean and deterministic, so we encode
 * only those. Per-page dwell is noisy and unbounded (one validation outlier
 * showed 3,642s — almost certainly a tab left open, not 60 minutes of reading),
 * so a regression/score on these rows would be self-deception. We deliberately
 * avoid one.
 *
 * Flag rules (all deterministic, validated on 277 posts):
 *   1. thin                   — word_count < 600. Word count is the ONE clean
 *                               structural signal: median dwell rises
 *                               monotonically 79s (0-500w) -> 98s (500-800w) ->
 *                               123s (800-1200w) then plateaus.
 *   2. padded_stub            — headings_per_1k_words > 15 AND word_count < 1000.
 *                               A thin post chopped up with sub-headings/embeds
 *                               to look substantial. Bottom-quartile posts carry
 *                               MORE headings/1k (10.9 vs 5.7) and embeds (14 vs
 *                               8) than top-quartile.
 *   3. demand_failing_content — engaged_sessions >= 10 AND
 *                               avg_duration < 0.4 * category_median_duration.
 *                               Real search demand landing on a page that can't
 *                               hold it — the highest-priority editorial fix and
 *                               the load-bearing flag. Sorted first.
 *   4. format_mismatch        — listicle-titled (^(\d+|top|best)) post in an
 *                               explainer category. Advisory only / opt-in,
 *                               because it is category-relative.
 *
 * Honesty note (mirrors content-performance): per-page dwell is GA4-only. The
 * first-party analytics events table records a load-time pageview with no
 * exit/duration event, so it cannot produce time-on-page. GA engagement carries
 * GA's sampling/threshold caveats and is not bot-filtered the way the
 * first-party reads are.
 *
 * @package DataMachineBusiness\Abilities\Analytics
 * @since 0.41.0
 */

namespace DataMachineBusiness\Abilities\Analytics;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class ContentFlagsAbility {

	/**
	 * Word-count floor below which a post is flagged `thin`.
	 *
	 * @var int
	 */
	const THIN_WORDS = 600;

	/**
	 * `padded_stub` thresholds: too many headings per 1k words AND short.
	 *
	 * @var float
	 */
	const PADDED_HEADINGS_PER_1K = 15.0;

	/**
	 * Word ceiling for the `padded_stub` flag.
	 *
	 * @var int
	 */
	const PADDED_MAX_WORDS = 1000;

	/**
	 * `demand_failing_content` minimum engaged sessions (real demand floor).
	 *
	 * @var int
	 */
	const DEMAND_MIN_SESSIONS = 10;

	/**
	 * `demand_failing_content` dwell threshold as a fraction of the category
	 * median duration. Below this multiple of the median, real demand is
	 * landing on a page that can't hold it.
	 *
	 * @var float
	 */
	const DEMAND_DURATION_FACTOR = 0.4;

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
				'datamachine/content-flags',
				array(
					'label'               => 'Content Red-Flag Detector',
					'description'         => 'Deterministic TRIAGE SCREEN (not a quality score) over a category\'s published posts. Reuses the datamachine/content-performance category->GA4-engagement join, then runs structural red-flag signatures over each comparable post: thin (word_count < 600), padded_stub (>15 headings/1k words AND <1000 words), demand_failing_content (>=10 engaged sessions AND avg dwell < 0.4x category median — the load-bearing flag, sorted first), and an advisory format_mismatch (listicle title in an explainer category). Validation on 277 posts found NO clean predictor of GOOD performance, so this screens for the clean BAD signatures only and tells a human which posts to look at; it does not score quality. Per-page dwell is GA4-only (the first-party pageview table has no duration event), so results carry GA sampling caveats and are NOT bot-filtered.',
					'category'            => 'datamachine-analytics',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'category' ),
						'properties' => array(
							'category'         => array(
								'type'        => 'string',
								'description' => 'Category slug to screen (e.g. "music-history", "song-meanings").',
							),
							'days'             => array(
								'type'        => 'integer',
								'description' => 'Lookback window in days (default: 28). Passed straight through to content-performance.',
							),
							'hostname'         => array(
								'type'        => 'string',
								'description' => 'Hostname whose pages map to this blog\'s posts (default: extrachill.com).',
							),
							'include_advisory' => array(
								'type'        => 'boolean',
								'description' => 'Include the category-relative advisory format_mismatch flag (default: false). It is opt-in because it is only meaningful in explainer-style categories.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'         => array( 'type' => 'boolean' ),
							'category'        => array( 'type' => 'string' ),
							'window'          => array( 'type' => 'object' ),
							'comparable'      => array( 'type' => 'integer' ),
							'flagged'         => array( 'type' => 'integer' ),
							'median_duration' => array( 'type' => 'number' ),
							'flag_counts'     => array( 'type' => 'object' ),
							'posts'           => array( 'type' => 'array' ),
							'caveat'          => array( 'type' => 'string' ),
							'error'           => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'screen' ),
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
	 * Run the deterministic red-flag screen over a category.
	 *
	 * Reuses ContentPerformanceAbility::audit() for the category -> post-IDs ->
	 * url_to_postid() -> GA4-engagement join and the comparable-post set
	 * (engaged_sessions, avg_duration, word_count) plus the category median
	 * duration. We do NOT rebuild that join. On top of the joined rows we
	 * extract the few extra structural features the flag rules need (headings)
	 * and apply the deterministic signatures.
	 *
	 * @param array $input Ability input.
	 * @return array Ability response.
	 */
	public static function screen( array $input ): array {
		$category         = sanitize_title( $input['category'] ?? '' );
		$include_advisory = ! empty( $input['include_advisory'] );

		if ( '' === $category ) {
			return array(
				'success' => false,
				'error'   => 'A category slug is required.',
			);
		}

		// Reuse the content-performance join + GA engagement + median, rather
		// than rebuilding it. Force a min_sessions of 1 so we screen every post
		// that had ANY traffic — the flag rules carry their own session gates
		// (demand_failing_content requires >=10), so the ranking-honesty gate
		// that content-performance uses (>=5) is not what we want here.
		$performance = ContentPerformanceAbility::audit(
			array(
				'category'     => $category,
				'days'         => isset( $input['days'] ) ? max( 1, (int) $input['days'] ) : ContentPerformanceAbility::DEFAULT_DAYS,
				'min_sessions' => 1,
				'hostname'     => ! empty( $input['hostname'] ) ? sanitize_text_field( $input['hostname'] ) : ContentPerformanceAbility::DEFAULT_HOSTNAME,
				'sort_by'      => 'duration',
			)
		);

		if ( empty( $performance['success'] ) ) {
			return array(
				'success' => false,
				'error'   => $performance['error'] ?? 'Content-performance join failed.',
			);
		}

		$comparable      = (array) ( $performance['posts'] ?? array() );
		$median_duration = (float) ( $performance['median_duration'] ?? 0.0 );

		$flagged     = array();
		$flag_counts = array(
			'thin'                   => 0,
			'padded_stub'            => 0,
			'demand_failing_content' => 0,
		);
		if ( $include_advisory ) {
			$flag_counts['format_mismatch'] = 0;
		}

		foreach ( $comparable as $row ) {
			$post = get_post( (int) ( $row['post_id'] ?? 0 ) );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$word_count       = (int) ( $row['word_count'] ?? 0 );
			$engaged_sessions = (int) ( $row['engaged_sessions'] ?? 0 );
			$avg_duration     = (float) ( $row['avg_duration'] ?? 0.0 );
			$headings         = self::count_headings( $post );
			$headings_per_1k  = $word_count > 0 ? round( $headings / ( $word_count / 1000 ), 1 ) : 0.0;

			$flags = array();

			// 1. thin — the one clean structural floor.
			if ( $word_count < self::THIN_WORDS ) {
				$flags[] = 'thin';
			}

			// 2. padded_stub — chopped up to look substantial while staying thin.
			if ( $headings_per_1k > self::PADDED_HEADINGS_PER_1K && $word_count < self::PADDED_MAX_WORDS ) {
				$flags[] = 'padded_stub';
			}

			// 3. demand_failing_content — the load-bearing flag. Real demand on a
			// page that can't hold it. Guarded by a positive category median.
			$demand_threshold = $median_duration > 0 ? self::DEMAND_DURATION_FACTOR * $median_duration : 0.0;
			if ( $engaged_sessions >= self::DEMAND_MIN_SESSIONS && $demand_threshold > 0 && $avg_duration < $demand_threshold ) {
				$flags[] = 'demand_failing_content';
			}

			// 4. format_mismatch — advisory / opt-in, category-relative.
			if ( $include_advisory && self::is_listicle_title( $post->post_title ) ) {
				$flags[] = 'format_mismatch';
			}

			if ( empty( $flags ) ) {
				continue;
			}

			foreach ( $flags as $flag ) {
				if ( isset( $flag_counts[ $flag ] ) ) {
					++$flag_counts[ $flag ];
				}
			}

			$flagged[] = array(
				'post_id'          => (int) $post->ID,
				'slug'             => $post->post_name,
				'title'            => $post->post_title,
				'flags'            => $flags,
				'severity'         => self::severity( $flags ),
				'word_count'       => $word_count,
				'headings'         => $headings,
				'headings_per_1k'  => $headings_per_1k,
				'engaged_sessions' => $engaged_sessions,
				'avg_duration'     => $avg_duration,
				'category_median'  => $median_duration,
			);
		}

		// Sort by severity descending — demand_failing_content posts first, then
		// stable on lowest dwell so the worst-holding pages bubble up.
		usort(
			$flagged,
			static function ( $a, $b ) {
				if ( $a['severity'] !== $b['severity'] ) {
					return $b['severity'] <=> $a['severity'];
				}
				return $a['avg_duration'] <=> $b['avg_duration'];
			}
		);

		return array(
			'success'         => true,
			'category'        => $category,
			'window'          => $performance['window'] ?? array(),
			'comparable'      => count( $comparable ),
			'flagged'         => count( $flagged ),
			'median_duration' => $median_duration,
			'flag_counts'     => $flag_counts,
			'posts'           => $flagged,
			'caveat'          => 'Triage screen, not a quality score. Per-page dwell is GA4-only and not bot-filtered; the flags tell a human which posts to inspect — the human judges.',
		);
	}

	/**
	 * Severity rank for sorting. demand_failing_content is the load-bearing
	 * flag and outranks everything; padded_stub outranks plain thin.
	 *
	 * @param array $flags Flags tripped on a post.
	 * @return int Higher = sort first.
	 */
	private static function severity( array $flags ): int {
		if ( in_array( 'demand_failing_content', $flags, true ) ) {
			return 3;
		}
		if ( in_array( 'padded_stub', $flags, true ) ) {
			return 2;
		}
		if ( in_array( 'thin', $flags, true ) ) {
			return 1;
		}
		return 0;
	}

	/**
	 * Count structural headings in a post's content.
	 *
	 * Counts both block-editor heading blocks (`wp:heading`) and raw
	 * `<h2>`/`<h3>` tags so classic and block content are both covered.
	 *
	 * @param \WP_Post $post Post object.
	 * @return int
	 */
	private static function count_headings( \WP_Post $post ): int {
		$content = (string) $post->post_content;

		$block_headings = substr_count( $content, '<!-- wp:heading' );
		$tag_headings   = preg_match_all( '/<h[23][\s>]/i', $content );

		// Block headings render to <h2>/<h3>, so a wp:heading comment and its
		// rendered tag would double-count. Prefer the block count when present
		// (block editor), else fall back to raw tag count (classic content).
		return $block_headings > 0 ? $block_headings : (int) $tag_headings;
	}

	/**
	 * Whether a title reads as a listicle (leading number, "top", or "best").
	 *
	 * @param string $title Post title.
	 * @return bool
	 */
	private static function is_listicle_title( string $title ): bool {
		return 1 === preg_match( '/^\s*(\d+|top|best)\b/i', $title );
	}
}
