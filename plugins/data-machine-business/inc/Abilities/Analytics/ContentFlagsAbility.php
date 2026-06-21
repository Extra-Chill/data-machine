<?php
/**
 * Content Flags Ability
 *
 * Deterministic TRIAGE SCREEN — not a quality score. Sits on top of the
 * datamachine/content-performance join (category -> post-IDs -> url_to_postid()
 * -> GA4 engagement) and flags posts by OUTCOME, then annotates each flagged
 * post with structural notes as a POSSIBLE explanation. It tells a human WHICH
 * posts to look at; the human judges. The screen never claims a post is bad
 * because of its structure.
 *
 * Why outcome-only, with structure demoted to advisory notes: a 277-post
 * empirical validation (music-history + song-meanings, "bad" = bottom-third
 * dwell within category, base rate 34%) found the structural rules predict
 * quality at barely-above-chance precision and do NOT generalize:
 *   - word_count < 500          -> 59% precision (1.77x lift)
 *   - word_count < 600          -> 48% precision
 *   - padded (h/1k>15 & wc<1000) -> 47% precision
 *   - heading density h/1k > 12  -> 38% precision
 * Correlations are ~zero (avg_para vs dwell r=0.005, h_per_1k vs dwell
 * r=-0.011). The memorable "padded stub" (jerry-garcias-run-for-the-roses) is
 * real for that ONE post but is memorable, not predictive. Flagging a post on
 * structure alone is wrong ~half the time — it would call good posts bad. So
 * structure is EXPLANATION, not PREDICTION: a structural note may only ride
 * along on a post already flagged by the outcome rule.
 *
 * The ONE confident flag:
 *   demand_failing_content — engaged_sessions >= 10 AND
 *                            avg_duration < 0.4 * category_median_duration.
 *                            Real search demand landing on a page that can't
 *                            hold it. This is the OUTCOME (high sessions + dwell
 *                            far below the category median), not a structural
 *                            proxy for it — the only confident, load-bearing
 *                            signal. Sorted first (it is the only flag).
 *
 * Advisory structural notes (only attached to an already-flagged post, never a
 * standalone verdict):
 *   - thin         — word_count < 500 (the 59%-precision threshold). A note
 *                    that the flagged post is also short.
 *   - padded_stub  — headings_per_1k_words > 15 AND word_count < 1000. A note
 *                    that the flagged post is also chopped up to look fuller.
 *
 * Category context (advisory, NOT a per-post flag):
 *   - coverage     — with_traffic / published_total. A low ratio is a
 *                    deterministic DISCOVERY-gap signal (the category's problem
 *                    is distribution, fixable by crosslinking traffic IN), as
 *                    distinct from a content-quality gap. e.g. interviews 36%
 *                    vs song-meanings 99%.
 *
 * Cross-category caveat: dwell is comparable ONLY WITHIN a category. It is
 * contaminated by traffic-source and demand differences between categories, so
 * a post's dwell must never be compared against a post in another category. All
 * flags here are category-relative (demand_failing_content uses the category
 * median); there is no global threshold.
 *
 * Honesty note (mirrors content-performance): per-page dwell is GA4-only. The
 * first-party analytics events table records a load-time pageview with no
 * exit/duration event, so it cannot produce time-on-page. GA engagement carries
 * GA's sampling/threshold caveats and is not bot-filtered the way the
 * first-party reads are.
 *
 * Two confidence guards (added after dogfooding the live screen):
 *   1. Low-sample confidence — avg_duration is noisy at the ~10-16 engaged
 *      sessions where flagged posts cluster, so the FACT of being flagged is
 *      reliable but the worst-holding-first ORDERING among flagged posts is not.
 *      Each flagged post carries a `confidence` field derived from
 *      engaged_sessions (low < 15, moderate 15-40, good > 40); low-confidence
 *      rows must not be finely ranked against each other.
 *   2. Query-intent caveat — the screen measures dwell-vs-median and CANNOT see
 *      query intent or the cultural/topical weight of a post's subject. A low
 *      dwell can mean a fully-satisfying answer to a shallow quick-answer query
 *      (healthy: the reader got the gist and left) just as easily as weak
 *      content. The tool ADMITS this limit rather than trying to measure culture
 *      from data — confirm weakness with human judgment before treating a flag
 *      as a fixable defect.
 *
 * @package DataMachineBusiness\Abilities\Analytics
 * @since 0.41.0
 */

namespace DataMachineBusiness\Abilities\Analytics;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class ContentFlagsAbility {

	/**
	 * Word-count floor below which a flagged post also gets the `thin` note.
	 *
	 * Tightened to the empirically-validated 59%-precision threshold (< 500),
	 * not < 600 — and only ever as an advisory note on an already-flagged post.
	 *
	 * @var int
	 */
	const THIN_WORDS = 500;

	/**
	 * `padded_stub` note threshold: too many headings per 1k words.
	 *
	 * @var float
	 */
	const PADDED_HEADINGS_PER_1K = 15.0;

	/**
	 * Word ceiling for the `padded_stub` note.
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

	/**
	 * Engaged-sessions ceiling (exclusive) for `low` confidence.
	 *
	 * Below this sample size, avg_duration is noisy enough that the
	 * worst-holding-first ordering among flagged posts is not reliable. The
	 * flag itself still holds (the >=10 gate is unchanged) — only the ranking
	 * confidence is `low`.
	 *
	 * @var int
	 */
	const CONFIDENCE_LOW_MAX = 15;

	/**
	 * Engaged-sessions ceiling (inclusive) for `moderate` confidence. Above it,
	 * confidence is `good`.
	 *
	 * @var int
	 */
	const CONFIDENCE_MODERATE_MAX = 40;

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
					'description'         => 'Deterministic TRIAGE SCREEN (not a quality score) over a category\'s published posts. Reuses the datamachine/content-performance category->GA4-engagement join, then flags posts by OUTCOME and annotates them with structure as a POSSIBLE explanation. The ONE confident flag is demand_failing_content (engaged_sessions >= 10 AND avg dwell < 0.4x the category median) — it measures the outcome, not a structural proxy. Structural signals (thin: word_count < 500; padded_stub: >15 headings/1k words AND <1000 words) are ADVISORY NOTES attached only to an already-flagged post — never standalone verdicts — because a 277-post validation showed structural rules predict quality at 40-59% precision (barely above the 34% base rate) and do not generalize. Also reports a category-level coverage ratio (with_traffic/published): a low ratio signals a DISCOVERY gap, not a content gap. Each flagged post carries a `confidence` field (low/moderate/good) derived from engaged_sessions — at low sample size avg dwell is noisy, so the FACT of a flag is reliable but the worst-holding-first ORDERING among low-confidence posts is not; do not finely rank low-confidence rows against each other. QUERY-INTENT CAVEAT (the most important limit): a flagged post may be a quick-answer query where low dwell is APPROPRIATE — the reader got the gist and left satisfied. Dwell vs. category median CANNOT distinguish weak content from a shallow-but-satisfied query; the cultural/topical weight of the underlying topic is invisible to this tool. Confirm content weakness with human judgment before treating a flag as a fixable defect — some flagged posts are healthy quick-answers, not defects. CAVEAT: flags and dwell are valid only WITHIN this category — dwell is contaminated by traffic-source and demand differences between categories, so never compare a post against a post in another category. Per-page dwell is GA4-only (the first-party pageview table has no duration event), so results carry GA sampling caveats and are NOT bot-filtered.',
					'category'            => 'datamachine-analytics',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'category' ),
						'properties' => array(
							'category' => array(
								'type'        => 'string',
								'description' => 'Category slug to screen (e.g. "music-history", "song-meanings").',
							),
							'days'     => array(
								'type'        => 'integer',
								'description' => 'Lookback window in days (default: 28). Passed straight through to content-performance.',
							),
							'hostname' => array(
								'type'        => 'string',
								'description' => 'Hostname whose pages map to this blog\'s posts (default: extrachill.com).',
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
							'coverage'            => array( 'type' => 'number' ),
							'comparable'          => array( 'type' => 'integer' ),
							'flagged'             => array( 'type' => 'integer' ),
							'median_duration'     => array( 'type' => 'number' ),
							'posts'               => array( 'type' => 'array' ),
							'caveat'              => array( 'type' => 'string' ),
							'query_intent_caveat' => array( 'type' => 'string' ),
							'error'               => array( 'type' => 'string' ),
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
	 * Run the deterministic, outcome-first red-flag screen over a category.
	 *
	 * Reuses ContentPerformanceAbility::audit() for the category -> post-IDs ->
	 * url_to_postid() -> GA4-engagement join, the comparable-post set
	 * (engaged_sessions, avg_duration, word_count), the category median
	 * duration, and the published/with-traffic totals. We do NOT rebuild that
	 * join.
	 *
	 * A post is FLAGGED only when it trips the single confident, outcome-based
	 * rule (demand_failing_content). On each flagged post we then attach
	 * advisory structural NOTES (thin, padded_stub) as possible explanations.
	 * Structure never flags a post on its own.
	 *
	 * @param array $input Ability input.
	 * @return array Ability response.
	 */
	public static function screen( array $input ): array {
		$category = sanitize_title( $input['category'] ?? '' );

		if ( '' === $category ) {
			return array(
				'success' => false,
				'error'   => 'A category slug is required.',
			);
		}

		// Reuse the content-performance join + GA engagement + median + totals,
		// rather than rebuilding it. Force min_sessions of 1 so we screen every
		// post that had ANY traffic — the demand flag carries its own session
		// gate (>=10), so the ranking-honesty gate content-performance uses (>=5)
		// is not what we want here.
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
		$published_total = (int) ( $performance['published_total'] ?? 0 );
		$with_traffic    = (int) ( $performance['with_traffic'] ?? 0 );
		$coverage        = $published_total > 0 ? round( $with_traffic / $published_total, 3 ) : 0.0;

		$flagged = array();

		foreach ( $comparable as $row ) {
			$post = get_post( (int) ( $row['post_id'] ?? 0 ) );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$word_count       = (int) ( $row['word_count'] ?? 0 );
			$engaged_sessions = (int) ( $row['engaged_sessions'] ?? 0 );
			$avg_duration     = (float) ( $row['avg_duration'] ?? 0.0 );

			// The ONLY flag: outcome-based demand_failing_content. Guarded by a
			// positive category median (category-relative, never global).
			$demand_threshold = $median_duration > 0 ? self::DEMAND_DURATION_FACTOR * $median_duration : 0.0;
			$is_demand_fail   = ( $engaged_sessions >= self::DEMAND_MIN_SESSIONS && $demand_threshold > 0 && $avg_duration < $demand_threshold );

			if ( ! $is_demand_fail ) {
				continue;
			}

			// Advisory structural notes — POSSIBLE explanations only, attached to
			// this already-flagged post. They never flag a post on their own.
			$headings        = self::count_headings( $post );
			$headings_per_1k = $word_count > 0 ? round( $headings / ( $word_count / 1000 ), 1 ) : 0.0;

			$structural_notes = array();
			if ( $word_count < self::THIN_WORDS ) {
				$structural_notes[] = 'thin';
			}
			if ( $headings_per_1k > self::PADDED_HEADINGS_PER_1K && $word_count < self::PADDED_MAX_WORDS ) {
				$structural_notes[] = 'padded_stub';
			}

			$flagged[] = array(
				'post_id'          => (int) $post->ID,
				'slug'             => $post->post_name,
				'title'            => $post->post_title,
				'flag'             => 'demand_failing_content',
				'engaged_sessions' => $engaged_sessions,
				'confidence'       => self::confidence_for_sessions( $engaged_sessions ),
				'avg_duration'     => $avg_duration,
				'category_median'  => $median_duration,
				'word_count'       => $word_count,
				'headings'         => $headings,
				'headings_per_1k'  => $headings_per_1k,
				'structural_notes' => $structural_notes,
			);
		}

		// Worst-holding pages first (lowest dwell relative to the same category
		// median). Single flag, so severity is just ascending dwell.
		usort(
			$flagged,
			static function ( $a, $b ) {
				return $a['avg_duration'] <=> $b['avg_duration'];
			}
		);

		return array(
			'success'             => true,
			'category'            => $category,
			'window'              => $performance['window'] ?? array(),
			'published_total'     => $published_total,
			'with_traffic'        => $with_traffic,
			'coverage'            => $coverage,
			'comparable'          => count( $comparable ),
			'flagged'             => count( $flagged ),
			'median_duration'     => $median_duration,
			'posts'               => $flagged,
			'caveat'              => 'Triage screen, not a quality score. The ONLY flag is demand_failing_content (an OUTCOME measure); structural notes are possible explanations, never verdicts — structure predicts quality at barely-above-chance precision. Flags/dwell are valid only WITHIN this category; never compare across categories. Per-page dwell is GA4-only and not bot-filtered. Each post carries a confidence (low/moderate/good) from its engaged_sessions — low-sample dwell is noisy, so the worst-holding-first ORDERING among low-confidence posts is soft; do not finely rank them against each other.',
			'query_intent_caveat' => 'A flagged post may be a quick-answer query where low dwell is APPROPRIATE — the reader got what they came for and left satisfied. Dwell vs. category median cannot distinguish weak content from a shallow-but-satisfied query (the cultural/topical weight of the underlying topic is invisible to this tool). Confirm content weakness with human judgment before treating a flag as a fix; some flagged posts are healthy quick-answers, not defects.',
		);
	}

	/**
	 * Map engaged sessions to a ranking-confidence label.
	 *
	 * Dwell (avg_duration) is averaged over engaged_sessions, so at low N a
	 * couple of quick bounces swing it hard and the worst-holding-first ordering
	 * among flagged posts becomes unreliable. This label tells the reader how
	 * much to
	 * trust the per-post dwell value (and therefore the ranking) — it does NOT
	 * affect whether a post is flagged (the >=10 gate is unchanged).
	 *
	 * Thresholds: low (< 15), moderate (15-40), good (> 40).
	 *
	 * @param int $engaged_sessions Engaged sessions for the post.
	 * @return string One of `low`, `moderate`, `good`.
	 */
	private static function confidence_for_sessions( int $engaged_sessions ): string {
		if ( $engaged_sessions < self::CONFIDENCE_LOW_MAX ) {
			return 'low';
		}

		if ( $engaged_sessions <= self::CONFIDENCE_MODERATE_MAX ) {
			return 'moderate';
		}

		return 'good';
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
}
