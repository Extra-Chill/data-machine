<?php
/**
 * GSC Opportunity Ability
 *
 * Search-demand opportunity auditor. Pulls per-query and/or per-page Google
 * Search Console stats (clicks, impressions, ctr, position) over a window via
 * the existing datamachine/google-search-console ability, then flags two
 * opportunity classes that GSC hands over for free:
 *
 *   1. SNIPPET / CTR GAP — the page already ranks well (position <= a good-rank
 *      cutoff) but its CTR is far below the CTR a result in that position
 *      typically earns. That gap is a title / meta-description rewrite roadmap:
 *      the ranking is fine, the listing just isn't earning the click.
 *
 *   2. PAGE-2 DEMAND — high impressions sitting at position ~8-15 (page 2 / low
 *      page 1). There is proven latent demand one rank-push away; a content or
 *      internal-link nudge could capture a large block of impressions.
 *
 * Each opportunity is ranked by ESTIMATED RECOVERABLE CLICKS:
 *   - snippet gap: impressions * (expected_ctr - current_ctr) — clicks the
 *     listing should already be earning at its current rank but isn't.
 *   - page-2 demand: impressions * expected_ctr_at_target_position — clicks the
 *     page would earn if pushed onto page 1 (a forward-looking estimate, since
 *     the current page-2 CTR is near zero by definition).
 *
 * This is the search-demand sibling of ContentPerformanceAbility: same
 * consume-another-ability shape (wp_get_ability(...)->execute()), but the data
 * source is Google Search Console (search-demand / SERP-listing weakness)
 * rather than Google Analytics engagement (on-page content weakness). It owns
 * NO API auth — all GSC transport lives in the primitive ability.
 *
 * POSITION -> EXPECTED-CTR BASELINE: a hardcoded, documented curve (see
 * POSITION_CTR_BASELINE). Organic CTR-by-position is well-studied and varies by
 * vertical/SERP layout, so this is a deliberately rough industry-shaped curve
 * meant to surface OUTLIERS (a #1 result at 1.7% CTR is broken regardless of
 * the exact baseline), not to be a precise CTR model. It can be refined or made
 * filterable later without changing the ability's contract.
 *
 * @package DataMachineBusiness\Abilities\Analytics
 * @since 0.11.0
 */

namespace DataMachineBusiness\Abilities\Analytics;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class GscOpportunityAbility {

	/**
	 * Position -> expected organic CTR baseline.
	 *
	 * A documented, deliberately-rough industry-shaped curve mapping an integer
	 * SERP position to the CTR a result in that position typically earns. Used
	 * to (a) detect snippet/CTR gaps (current CTR far below the baseline for a
	 * good position) and (b) estimate the clicks a page-2 page would earn if
	 * pushed to a target page-1 position.
	 *
	 * Values are fractions (0.30 = 30%). Fractional GSC positions are floored to
	 * the nearest integer bucket; positions beyond the last key use the last
	 * key's value as a floor. Source shape: aggregate of public organic
	 * CTR-by-position studies — intended to surface OUTLIERS, not to be precise.
	 *
	 * @var array<int, float>
	 */
	const POSITION_CTR_BASELINE = array(
		1  => 0.30,
		2  => 0.15,
		3  => 0.10,
		4  => 0.07,
		5  => 0.05,
		6  => 0.04,
		7  => 0.03,
		8  => 0.025,
		9  => 0.02,
		10 => 0.018,
	);

	/**
	 * Default lookback window in days.
	 *
	 * @var int
	 */
	const DEFAULT_DAYS = 28;

	/**
	 * Highest (numerically smallest) position considered "good rank" — at or
	 * above this, a low CTR is a snippet/listing problem, not a ranking one.
	 *
	 * @var float
	 */
	const DEFAULT_GOOD_POSITION = 5.0;

	/**
	 * CTR-gap factor for the snippet class: flag a good-rank row only when its
	 * CTR is below this fraction of the position-expected CTR (0.5 = at most
	 * half the CTR it should be earning).
	 *
	 * @var float
	 */
	const DEFAULT_CTR_GAP_FACTOR = 0.5;

	/**
	 * Page-2 demand band: inclusive position range that counts as "one push from
	 * page 1" — high impressions here are ranking-push candidates.
	 *
	 * @var float
	 */
	const DEFAULT_PAGE2_MIN_POSITION = 8.0;
	const DEFAULT_PAGE2_MAX_POSITION = 15.0;

	/**
	 * Minimum impressions for a row to qualify as an opportunity. Below this the
	 * estimated recoverable clicks are noise and a single fluctuation dominates.
	 *
	 * @var int
	 */
	const DEFAULT_MIN_IMPRESSIONS = 100;

	/**
	 * Target page-1 position used to estimate recoverable clicks for the page-2
	 * demand class (what CTR the row would earn if pushed onto page 1).
	 *
	 * @var int
	 */
	const PAGE2_TARGET_POSITION = 5;

	/**
	 * Max GSC rows to request from the primitive ability per dimension.
	 *
	 * @var int
	 */
	const FETCH_LIMIT = 25000;

	/**
	 * Definition-box (zero-click) query detection — bare-definitional patterns.
	 *
	 * Some queries are bare-word definitional searches — "<term> meaning",
	 * "define <term>" — where Google answers inline via a definition panel, an
	 * AI Overview, or a featured snippet, and the organic result is structurally
	 * never seen. For those, a near-0% CTR at a strong rank is STRUCTURAL
	 * (SERP-captured), not a fixable title/meta problem. Scoring them as
	 * snippet-gap "recoverable clicks" produces phantom top-of-list
	 * opportunities that dominate the ranking and bury genuinely recoverable
	 * song/context-intent queries underneath them on the same page.
	 *
	 * Each entry is a fully-delimited PCRE pattern. A match means the query has
	 * a DEFINITIONAL shape. Match ALONE is never sufficient to flag a row — it
	 * is only leg (a) of a three-leg signature; see is_definition_box() and
	 * DEFINITION_BOX_CTR_FRACTION. The patterns are deliberately conservative
	 * on term length so song-intent queries ("no diggity lyrics meaning") and
	 * natural-language questions ("what does X mean in <song>") do not match;
	 * even if one slipped through, the CTR leg (c) would still protect it.
	 *
	 * @var string[]
	 */
	const DEFINITION_BOX_PATTERNS = array(
		// "<term> meaning" / "<term> definition(s)" — term is 1-2 words.
		'/^(?:[\w\'-]+(?:\s+[\w\'-]+)?)\s+(?:meaning|definitions?)$/i',
		// "define <term>" / "definition(s) of <term>" / "meaning of <term>".
		'/^(?:define|definitions?\s+of|meaning\s+of)\s+\S+/i',
	);

	/**
	 * CTR-ratio threshold for the definition-box signature (leg c).
	 *
	 * Flag a definitional-pattern query only when its current CTR is below this
	 * fraction of the position-expected CTR. 0.1 means "an order of magnitude
	 * below baseline" — the leg that separates a genuinely SERP-captured query
	 * ("diggity meaning", CTR 0.005% at a rank expecting 10% → ratio 0.0005,
	 * far below 0.1) from a modestly-recoverable song-intent query on the SAME
	 * page at nearly the SAME rank ("no diggity meaning", CTR 1.0% expecting 7%
	 * → ratio 0.14, above 0.1). The 200x CTR gap between the two IS the
	 * definition-box signature; this threshold encodes it.
	 *
	 * @var float
	 */
	const DEFINITION_BOX_CTR_FRACTION = 0.1;

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
				'datamachine/gsc-opportunity',
				array(
					'label'               => 'GSC Opportunity Auditor',
					'description'         => 'Find search-demand opportunities GSC hands over for free: (1) SNIPPET/CTR GAP — pages ranking well (good position) but with CTR far below the position-expected baseline, i.e. title/meta-description rewrite candidates; and (2) PAGE-2 DEMAND — high-impression queries/pages stuck at position ~8-15, i.e. ranking-push candidates with proven latent demand. Pulls per-query and/or per-page stats from datamachine/google-search-console and ranks each opportunity by estimated recoverable clicks (impressions x ctr-gap). Uses a documented, deliberately-rough position->expected-CTR baseline to surface outliers, not to be a precise CTR model. Owns no API auth — all GSC transport lives in the primitive ability.',
					'category'            => 'datamachine-analytics',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'dimension'       => array(
								'type'        => 'string',
								'description' => 'Which GSC dimension(s) to audit: "query" (per-query stats), "page" (per-page stats), or "both" (default). Maps to the google-search-console query_stats / page_stats actions.',
							),
							'days'            => array(
								'type'        => 'integer',
								'description' => 'Lookback window in days (default: 28). The window ends 3 days ago for finalized GSC data.',
							),
							'start_date'      => array(
								'type'        => 'string',
								'description' => 'Explicit start date (YYYY-MM-DD). Overrides days when both are given.',
							),
							'end_date'        => array(
								'type'        => 'string',
								'description' => 'Explicit end date (YYYY-MM-DD). Defaults to 3 days ago for finalized data.',
							),
							'site_url'        => array(
								'type'        => 'string',
								'description' => 'GSC property URL (sc-domain: or https://). Defaults to the configured site URL.',
							),
							'url_filter'      => array(
								'type'        => 'string',
								'description' => 'Restrict the audit to URLs containing this string (passed through to GSC).',
							),
							'query_filter'    => array(
								'type'        => 'string',
								'description' => 'Restrict the audit to queries containing this string (passed through to GSC).',
							),
							'min_impressions' => array(
								'type'        => 'integer',
								'description' => 'Minimum impressions for a row to qualify as an opportunity (default: 100). Below this, estimated recoverable clicks are noise.',
							),
							'good_position'   => array(
								'type'        => 'number',
								'description' => 'Snippet-gap rank cutoff: rows at or above this position (default: 5) with low CTR are snippet/listing problems, not ranking ones.',
							),
							'ctr_gap_factor'  => array(
								'type'        => 'number',
								'description' => 'Snippet-gap sensitivity: flag a good-rank row only when its CTR is below this fraction of the position-expected CTR (default: 0.5 = at most half the expected CTR).',
							),
							'limit'           => array(
								'type'        => 'integer',
								'description' => 'Max opportunities to return per class (snippet_gap, page2_demand). Default: all, ranked by estimated recoverable clicks.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'              => array( 'type' => 'boolean' ),
							'window'               => array( 'type' => 'object' ),
							'dimension'            => array( 'type' => 'string' ),
							'thresholds'           => array( 'type' => 'object' ),
							'snippet_gap'          => array( 'type' => 'array' ),
							'page2_demand'         => array( 'type' => 'array' ),
							'serp_captured'        => array( 'type' => 'array' ),
							'snippet_gap_count'    => array( 'type' => 'integer' ),
							'page2_demand_count'   => array( 'type' => 'integer' ),
							'serp_captured_count'  => array( 'type' => 'integer' ),
							'definition_box_count' => array( 'type' => 'integer' ),
							'error'                => array( 'type' => 'string' ),
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
	 * Run the GSC opportunity audit.
	 *
	 * @param array $input Ability input.
	 * @return array Ability response.
	 */
	public static function audit( array $input ): array {
		$dimension = strtolower( (string) ( $input['dimension'] ?? 'both' ) );
		if ( ! in_array( $dimension, array( 'query', 'page', 'both' ), true ) ) {
			$dimension = 'both';
		}

		$days            = ! empty( $input['days'] ) ? max( 1, (int) $input['days'] ) : self::DEFAULT_DAYS;
		$end_date        = ! empty( $input['end_date'] ) ? sanitize_text_field( $input['end_date'] ) : gmdate( 'Y-m-d', strtotime( '-3 days' ) );
		$start_date      = ! empty( $input['start_date'] )
			? sanitize_text_field( $input['start_date'] )
			: gmdate( 'Y-m-d', strtotime( $end_date . ' -' . ( $days - 1 ) . ' days' ) );
		$min_impressions = isset( $input['min_impressions'] ) ? max( 1, (int) $input['min_impressions'] ) : self::DEFAULT_MIN_IMPRESSIONS;
		$good_position   = isset( $input['good_position'] ) ? (float) $input['good_position'] : self::DEFAULT_GOOD_POSITION;
		$ctr_gap_factor  = isset( $input['ctr_gap_factor'] ) ? (float) $input['ctr_gap_factor'] : self::DEFAULT_CTR_GAP_FACTOR;
		$limit           = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 0;

		$gsc = wp_get_ability( 'datamachine/google-search-console' );
		if ( ! $gsc ) {
			return array(
				'success' => false,
				'error'   => 'Google Search Console ability not available. Ensure Data Machine Business is active and GSC is configured.',
			);
		}

		$actions = array();
		if ( 'query' === $dimension || 'both' === $dimension ) {
			$actions['query'] = 'query_stats';
		}
		if ( 'page' === $dimension || 'both' === $dimension ) {
			$actions['page'] = 'page_stats';
		}

		$snippet_gap      = array();
		$page2_demand     = array();
		$serp_captured    = array();
		$captured_by_page = array();
		if ( 'page' === $dimension || 'both' === $dimension ) {
			$support_input    = self::gsc_input( 'query_page_stats', $start_date, $end_date, $input );
			$support_result   = $gsc->execute( $support_input );
			$captured_by_page = ! is_wp_error( $support_result ) && ! empty( $support_result['success'] )
				? self::captured_impressions_by_page( (array) ( $support_result['results'] ?? array() ), $good_position )
				: array();
		}

		foreach ( $actions as $kind => $action ) {
			$gsc_input = self::gsc_input( $action, $start_date, $end_date, $input );

			$result = $gsc->execute( $gsc_input );

			if ( is_wp_error( $result ) ) {
				return array(
					'success' => false,
					'error'   => 'GSC fetch failed (' . $action . '): ' . $result->get_error_message(),
				);
			}

			if ( empty( $result['success'] ) ) {
				return array(
					'success' => false,
					'error'   => 'GSC fetch failed (' . $action . '): ' . ( $result['error'] ?? 'Unknown error' ),
				);
			}

			foreach ( (array) ( $result['results'] ?? array() ) as $row ) {
				$label = self::row_label( $row );
				if ( '' === $label ) {
					continue;
				}

				$impressions = (int) ( $row['impressions'] ?? 0 );
				$ctr         = (float) ( $row['ctr'] ?? 0.0 );
				$position    = (float) ( $row['position'] ?? 0.0 );

				if ( $impressions < $min_impressions || $position <= 0.0 ) {
					continue;
				}

				$expected_ctr = self::expected_ctr( $position );

				// CLASS 1 — snippet/CTR gap: good rank, CTR far below baseline.
				if ( $position <= $good_position
					&& $expected_ctr > 0.0
					&& $ctr < ( $expected_ctr * $ctr_gap_factor )
				) {
					$definition_box = self::is_definition_box( $label, $position, $ctr, $expected_ctr, $good_position );

					if ( $definition_box && 'query' === $kind ) {
						$serp_captured[] = self::opportunity_row( $kind, $label, $row, $expected_ctr, 0 ) + array(
							'definition_box' => true,
							'serp_captured'  => true,
						);
						continue;
					}

					$captured    = 'page' === $kind ? (int) ( $captured_by_page[ $label ] ?? 0 ) : 0;
					$actionable  = max( 0, $impressions - $captured );
					$recoverable = (int) round( $actionable * max( 0.0, $expected_ctr - $ctr ) );
					if ( $recoverable > 0 ) {
						$row_out = self::opportunity_row( $kind, $label, $row, $expected_ctr, $recoverable );
						if ( $captured > 0 ) {
							$row_out += array(
								'serp_captured_impressions' => $captured,
								'actionable_impressions' => $actionable,
								'serp_captured_share'    => round( $captured / $impressions, 4 ),
								'serp_captured_observed_only' => true,
							);
						}
						$snippet_gap[] = $row_out;
					}
					continue;
				}

				// CLASS 2 — page-2 demand: high impressions stuck at position 8-15.
				if ( $position >= self::DEFAULT_PAGE2_MIN_POSITION
					&& $position <= self::DEFAULT_PAGE2_MAX_POSITION
				) {
					$target_ctr  = self::expected_ctr( (float) self::PAGE2_TARGET_POSITION );
					$recoverable = (int) round( $impressions * max( 0.0, $target_ctr - $ctr ) );
					if ( $recoverable > 0 ) {
						$page2_demand[] = array(
							'type'               => $kind,
							'target'             => $label,
							'position'           => round( $position, 1 ),
							'impressions'        => $impressions,
							'clicks'             => $clicks,
							'current_ctr'        => round( $ctr, 4 ),
							'target_position'    => self::PAGE2_TARGET_POSITION,
							'target_ctr'         => round( $target_ctr, 4 ),
							'recoverable_clicks' => $recoverable,
						);
					}
				}
			}
		}

		$snippet_gap   = self::sort_and_limit( $snippet_gap, 'recoverable_clicks', $limit );
		$page2_demand  = self::sort_and_limit( $page2_demand, 'recoverable_clicks', $limit );
		$serp_captured = self::sort_and_limit( $serp_captured, 'impressions', $limit );

		return array(
			'success'              => true,
			'dimension'            => $dimension,
			'window'               => array(
				'start_date' => $start_date,
				'end_date'   => $end_date,
				'days'       => $days,
			),
			'thresholds'           => array(
				'min_impressions'       => $min_impressions,
				'good_position'         => $good_position,
				'ctr_gap_factor'        => $ctr_gap_factor,
				'page2_min_position'    => self::DEFAULT_PAGE2_MIN_POSITION,
				'page2_max_position'    => self::DEFAULT_PAGE2_MAX_POSITION,
				'page2_target_position' => self::PAGE2_TARGET_POSITION,
			),
			'snippet_gap'          => $snippet_gap,
			'page2_demand'         => $page2_demand,
			'serp_captured'        => $serp_captured,
			'snippet_gap_count'    => count( $snippet_gap ),
			'page2_demand_count'   => count( $page2_demand ),
			'serp_captured_count'  => count( $serp_captured ),
			'definition_box_count' => count( $serp_captured ),
		);
	}

	public static function captured_impressions_by_page( array $rows, float $good_position = self::DEFAULT_GOOD_POSITION ): array {
		$captured = array();
		foreach ( $rows as $row ) {
			$keys = (array) ( $row['keys'] ?? array() );
			if ( count( $keys ) < 2 ) {
				continue;
			}
			$position = (float) ( $row['position'] ?? 0 );
			if ( self::is_definition_box( (string) $keys[0], $position, (float) ( $row['ctr'] ?? 0 ), self::expected_ctr( $position ), $good_position ) ) {
				$captured[ (string) $keys[1] ] = ( $captured[ (string) $keys[1] ] ?? 0 ) + (int) ( $row['impressions'] ?? 0 );
			}
		}
		return $captured;
	}

	public static function sort_and_limit( array $rows, string $field, int $limit = 0 ): array {
		usort( $rows, static fn( array $a, array $b ): int => ( $b[ $field ] ?? 0 ) <=> ( $a[ $field ] ?? 0 ) );
		return $limit > 0 ? array_slice( $rows, 0, $limit ) : $rows;
	}

	private static function gsc_input( string $action, string $start_date, string $end_date, array $input ): array {
		$output = compact( 'action', 'start_date', 'end_date' ) + array( 'limit' => self::FETCH_LIMIT );
		foreach ( array( 'site_url', 'url_filter', 'query_filter' ) as $key ) {
			if ( ! empty( $input[ $key ] ) ) {
				$output[ $key ] = sanitize_text_field( $input[ $key ] );
			}
		}
		return $output;
	}

	private static function opportunity_row( string $kind, string $label, array $row, float $expected_ctr, int $recoverable ): array {
		return array(
			'type'               => $kind,
			'target'             => $label,
			'position'           => round( (float) $row['position'], 1 ),
			'impressions'        => (int) $row['impressions'],
			'clicks'             => (int) ( $row['clicks'] ?? 0 ),
			'current_ctr'        => round( (float) ( $row['ctr'] ?? 0 ), 4 ),
			'expected_ctr'       => round( $expected_ctr, 4 ),
			'recoverable_clicks' => $recoverable,
		);
	}

	/**
	 * Expected organic CTR for a (possibly fractional) SERP position.
	 *
	 * Floors the position to its integer bucket and reads the baseline curve.
	 * Positions beyond the last baseline key clamp to the last key's value, so a
	 * deep position never reports a higher expected CTR than a shallower one.
	 *
	 * @param float $position GSC average position (>= 1).
	 * @return float Expected CTR as a fraction (0.30 = 30%).
	 */
	public static function expected_ctr( float $position ): float {
		$bucket = (int) floor( $position );
		if ( $bucket < 1 ) {
			$bucket = 1;
		}

		$baseline = self::POSITION_CTR_BASELINE;
		if ( isset( $baseline[ $bucket ] ) ) {
			return $baseline[ $bucket ];
		}

		// Beyond the curve: clamp to the deepest defined position's CTR.
		$last_key = array_key_last( $baseline );

		return $baseline[ $last_key ];
	}

	/**
	 * Detect whether a query row is a SERP-captured definition-box query.
	 *
	 * A definition-box query is a bare-word definitional search ("<term>
	 * meaning") where Google answers inline and the organic result is never
	 * seen. Returns true ONLY when ALL THREE legs of the signature hold:
	 *
	 *   (a) PATTERN — the query matches a bare-definitional shape (see
	 *       DEFINITION_BOX_PATTERNS). "Contains the word meaning" alone is NOT
	 *       enough: legit song-intent queries like "no diggity lyrics meaning"
	 *       or "what does X mean in <song>" must stay fully scored.
	 *   (b) RANK — position <= good_position. A definition box only suppresses
	 *       the organic result when the page is actually ranking well; a page-2
	 *       row is not SERP-captured, it is just not ranking (and cannot reach
	 *       this method anyway, since page-2 rows fall into CLASS 2).
	 *   (c) CTR — current CTR is an order of magnitude below the position
	 *       baseline (ctr < expected_ctr * DEFINITION_BOX_CTR_FRACTION). This
	 *       is the leg that separates "diggity meaning" (0.005% CTR) from "no
	 *       diggity meaning" (1.0% CTR) at the same rank on the same page.
	 *
	 * Any single leg is noise; all three together are the signature. The checks
	 * are ordered cheapest-first (rank, then CTR, then the regex) so the common
	 * non-matching rows short-circuit before pattern matching.
	 *
	 * @param string $query         Query string (row label).
	 * @param float  $position      SERP position.
	 * @param float  $ctr           Current CTR (fraction).
	 * @param float  $expected_ctr  Position-expected CTR (fraction).
	 * @param float  $good_position Good-rank cutoff (position <= this is strong).
	 * @return bool True when the row is a SERP-captured definition-box query.
	 */
	public static function is_definition_box( string $query, float $position, float $ctr, float $expected_ctr, float $good_position ): bool {

		// Leg (b) — strong rank.
		if ( $position > $good_position || $expected_ctr <= 0.0 ) {
			return false;
		}

		// Leg (c) — catastrophic CTR: an order of magnitude below baseline.
		if ( $ctr >= ( $expected_ctr * self::DEFINITION_BOX_CTR_FRACTION ) ) {
			return false;
		}

		// Leg (a) — definitional pattern.
		foreach ( self::DEFINITION_BOX_PATTERNS as $pattern ) {
			if ( preg_match( $pattern, $query ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve a GSC result row's human label from its dimension keys.
	 *
	 * The primitive ability returns raw GSC rows whose `keys` array holds the
	 * dimension value(s) in dimension order. query_stats / page_stats are
	 * single-dimension, so the first key is the query or page; query_page_stats
	 * would join the keys with a separator.
	 *
	 * @param array $row Raw GSC result row.
	 * @return string Row label, or '' if absent.
	 */
	private static function row_label( array $row ): string {
		$keys = (array) ( $row['keys'] ?? array() );
		if ( empty( $keys ) ) {
			return '';
		}

		return implode( ' | ', array_map( 'strval', $keys ) );
	}
}
