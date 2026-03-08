<?php
/**
 * Publish-level duplicate detection for WordPress posts.
 *
 * Provides fuzzy title matching to prevent cross-flow duplicate publishing.
 * Ported and simplified from data-machine-events EventIdentifierGenerator —
 * events use venue + date + ticket URL for richer matching, but the core
 * title normalization and fuzzy comparison logic is shared here.
 *
 * @package DataMachine\Core\WordPress
 * @since   0.38.0
 */

namespace DataMachine\Core\WordPress;

defined( 'ABSPATH' ) || exit;

class DuplicateDetection {

	/**
	 * Default number of days to look back for duplicate candidates.
	 */
	const DEFAULT_LOOKBACK_DAYS = 14;

	/**
	 * Find an existing published post with a matching title.
	 *
	 * Queries recent posts of the same post type and runs fuzzy title
	 * comparison to catch near-duplicates (same story from different sources).
	 *
	 * @param string $title         Title of the post being published.
	 * @param string $post_type     WordPress post type to search.
	 * @param int    $lookback_days How many days back to search (default 14).
	 * @return int|null Post ID of the duplicate, or null if none found.
	 */
	public static function findExistingPostByTitle( string $title, string $post_type, int $lookback_days = 0 ): ?int {
		if ( empty( $title ) || empty( $post_type ) ) {
			return null;
		}

		if ( $lookback_days <= 0 ) {
			$lookback_days = self::DEFAULT_LOOKBACK_DAYS;
		}

		$cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$lookback_days} days" ) );

		// Query recent posts of the same type.
		$candidates = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- intentional batch query
				'posts_per_page' => 200,
				'date_query'     => array(
					array(
						'after'     => $cutoff_date,
						'inclusive' => true,
					),
				),
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( empty( $candidates ) ) {
			return null;
		}

		$incoming_core = self::extractCoreTitle( $title );

		foreach ( $candidates as $candidate_id ) {
			$candidate_title = get_the_title( $candidate_id );
			if ( self::titlesMatch( $title, $candidate_title ) ) {
				do_action(
					'datamachine_log',
					'info',
					'DuplicateDetection: Found existing post with matching title',
					array(
						'incoming_title' => $title,
						'incoming_core'  => $incoming_core,
						'existing_id'    => $candidate_id,
						'existing_title' => $candidate_title,
						'existing_core'  => self::extractCoreTitle( $candidate_title ),
					)
				);
				return $candidate_id;
			}
		}

		return null;
	}

	/**
	 * Compare two titles for semantic match.
	 *
	 * Returns true if core titles match after extraction and normalization.
	 * Handles variations like different suffixes, subreddit attributions,
	 * "Reddit Reacts" / "Fans React" additions, etc.
	 *
	 * @param string $title1 First title.
	 * @param string $title2 Second title.
	 * @return bool True if titles represent the same story.
	 */
	public static function titlesMatch( string $title1, string $title2 ): bool {
		$core1 = self::extractCoreTitle( $title1 );
		$core2 = self::extractCoreTitle( $title2 );

		// Exact match after normalization.
		if ( $core1 === $core2 ) {
			return true;
		}

		// One core is a prefix of the other (catches cases where one version
		// has extra context appended, e.g. "— fans react on r/WhenWeWereYoung").
		$shorter = strlen( $core1 ) <= strlen( $core2 ) ? $core1 : $core2;
		$longer  = strlen( $core1 ) <= strlen( $core2 ) ? $core2 : $core1;

		if ( strlen( $shorter ) >= 8 && str_starts_with( $longer, $shorter ) ) {
			return true;
		}

		// Levenshtein distance for very similar titles (typos, minor wording).
		// Only apply to titles of similar length to avoid false positives.
		$len1 = strlen( $core1 );
		$len2 = strlen( $core2 );

		if ( $len1 >= 15 && $len2 >= 15 ) {
			$max_len  = max( $len1, $len2 );
			$distance = levenshtein( $core1, $core2 );

			// Allow up to 15% character difference.
			if ( $distance <= (int) ( $max_len * 0.15 ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract the core identifying portion of a title.
	 *
	 * Strips reaction suffixes, subreddit attributions, common filler phrases,
	 * and normalizes for comparison.
	 *
	 * @param string $title Post title.
	 * @return string Normalized core title for comparison.
	 */
	public static function extractCoreTitle( string $title ): string {
		// Decode HTML entities.
		$text = html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Normalize unicode dashes to ASCII hyphen.
		$text = self::normalizeDashes( $text );

		// Lowercase.
		$text = strtolower( $text );

		// Remove subreddit references: "on r/bonnaroo", "from r/coachella", etc.
		$text = preg_replace( '/\s+(on|from|via|at)\s+r\/\w+/i', '', $text );
		// Remove standalone "r/subreddit" references.
		$text = preg_replace( '/\s*r\/\w+/i', '', $text );

		// Remove reaction/attribution suffixes.
		$suffixes = array(
			'reddit reacts',
			'fans react on reddit',
			'fans react',
			'redditors react',
			'reddit reactions',
			'reddit buzz',
			'reddit thread',
			'reddit weighs in',
			'redditors say',
			'redditors share',
		);
		foreach ( $suffixes as $suffix ) {
			$text = preg_replace( '/\s*[-—–]\s*' . preg_quote( $suffix, '/' ) . '\s*$/i', '', $text );
			$text = preg_replace( '/\s*[-—–:]\s*' . preg_quote( $suffix, '/' ) . '/i', '', $text );
		}

		// Split on common delimiters that separate headline from attribution.
		$delimiters = array(
			' - ',
			' — ',
			' – ',
		);
		$best_pos   = PHP_INT_MAX;
		$best_delim = null;

		foreach ( $delimiters as $delimiter ) {
			$pos = strpos( $text, $delimiter );
			// Only split if the part before the delimiter is substantial.
			if ( false !== $pos && $pos >= 15 && $pos < $best_pos ) {
				$best_pos   = $pos;
				$best_delim = $delimiter;
			}
		}

		if ( null !== $best_delim ) {
			$text = substr( $text, 0, $best_pos );
		}

		// Remove articles at word boundaries.
		$text = preg_replace( '/\b(the|a|an)\b/i', '', $text );

		// Remove non-alphanumeric characters (keep spaces and digits).
		$text = preg_replace( '/[^a-z0-9\s]/i', '', $text );

		// Collapse whitespace and trim.
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );

		// If result is too short, return a basic normalization instead.
		if ( strlen( $text ) < 5 ) {
			return self::normalizeBasic( $title );
		}

		return $text;
	}

	/**
	 * Normalize unicode dash characters to ASCII hyphen.
	 *
	 * @param string $text Input text.
	 * @return string Text with all dashes normalized.
	 */
	private static function normalizeDashes( string $text ): string {
		$unicode_dashes = array(
			"\u{2010}", // hyphen
			"\u{2011}", // non-breaking hyphen
			"\u{2012}", // figure dash
			"\u{2013}", // en dash
			"\u{2014}", // em dash
			"\u{2015}", // horizontal bar
			"\u{FE58}", // small em dash
			"\u{FE63}", // small hyphen-minus
			"\u{FF0D}", // fullwidth hyphen-minus
		);

		return str_replace( $unicode_dashes, '-', $text );
	}

	/**
	 * Basic normalization fallback.
	 *
	 * @param string $text Input text.
	 * @return string Normalized text.
	 */
	private static function normalizeBasic( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = self::normalizeDashes( $text );
		$text = strtolower( $text );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		$text = preg_replace( '/^(the|a|an)\s+/i', '', $text );
		return $text;
	}
}
