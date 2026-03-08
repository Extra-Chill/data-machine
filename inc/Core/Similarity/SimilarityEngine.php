<?php
/**
 * Unified similarity engine for duplicate detection.
 *
 * Consolidates title normalization, fuzzy matching, Jaccard similarity,
 * and tokenization from three prior implementations:
 *
 * - DuplicateDetection (Core/WordPress) — publish-level fuzzy title match
 * - QueueValidator (Engine/AI/Tools) — Jaccard word-set similarity
 * - EventIdentifierGenerator (data-machine-events) — event title/venue matching
 *
 * All similarity math lives here. Consumers (QueueValidator, publish handlers,
 * DuplicateCheckAbility, extension strategies) call these pure functions.
 *
 * @package DataMachine\Core\Similarity
 * @since   0.39.0
 * @see     https://github.com/Extra-Chill/data-machine/issues/731
 */

namespace DataMachine\Core\Similarity;

defined( 'ABSPATH' ) || exit;

class SimilarityEngine {

	/**
	 * Default Jaccard similarity threshold.
	 *
	 * @var float
	 */
	const DEFAULT_JACCARD_THRESHOLD = 0.65;

	/**
	 * Default Levenshtein tolerance (fraction of max length).
	 *
	 * @var float
	 */
	const DEFAULT_LEVENSHTEIN_TOLERANCE = 0.15;

	/**
	 * Minimum title length for Levenshtein comparison.
	 *
	 * @var int
	 */
	const MIN_LEVENSHTEIN_LENGTH = 15;

	/**
	 * Minimum prefix length for prefix matching.
	 *
	 * @var int
	 */
	const MIN_PREFIX_LENGTH = 5;

	/**
	 * Stop words excluded from Jaccard tokenization.
	 *
	 * @var array
	 */
	const STOP_WORDS = array(
		'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to',
		'for', 'of', 'with', 'by', 'from', 'is', 'it', 'are', 'was',
		'were', 'be', 'been', 'being', 'have', 'has', 'had', 'do', 'does',
		'did', 'will', 'would', 'could', 'should', 'may', 'might', 'shall',
		'can', 'not', 'no', 'if', 'when', 'what', 'why', 'how', 'who',
		'where', 'which', 'that', 'this', 'you', 'your', 'my', 'am',
		'me', 'we', 'they', 'them', 'its',
	);

	// -----------------------------------------------------------------------
	// Title comparison
	// -----------------------------------------------------------------------

	/**
	 * Compare two titles for semantic match.
	 *
	 * Runs a cascade of strategies in order of strictness:
	 * 1. Exact match after normalization
	 * 2. Prefix match (one core title starts with the other, min 5 chars)
	 * 3. Levenshtein distance (≤15% diff for titles ≥15 chars)
	 *
	 * Returns a SimilarityResult with match, score, and strategy.
	 *
	 * @param string $title1 First title.
	 * @param string $title2 Second title.
	 * @return SimilarityResult
	 */
	public static function titlesMatch( string $title1, string $title2 ): SimilarityResult {
		$core1 = self::normalizeTitle( $title1 );
		$core2 = self::normalizeTitle( $title2 );

		// Strategy 1: Exact match after normalization.
		if ( $core1 === $core2 ) {
			return new SimilarityResult( true, 1.0, SimilarityResult::STRATEGY_EXACT, $core1, $core2 );
		}

		// Strategy 2: Prefix match.
		$shorter = strlen( $core1 ) <= strlen( $core2 ) ? $core1 : $core2;
		$longer  = strlen( $core1 ) <= strlen( $core2 ) ? $core2 : $core1;

		if ( strlen( $shorter ) >= self::MIN_PREFIX_LENGTH && str_starts_with( $longer, $shorter ) ) {
			// Score proportional to how much of the longer title the prefix covers.
			$score = strlen( $shorter ) / strlen( $longer );
			return new SimilarityResult( true, $score, SimilarityResult::STRATEGY_PREFIX, $core1, $core2 );
		}

		// Strategy 3: Levenshtein distance for very similar titles.
		$len1 = strlen( $core1 );
		$len2 = strlen( $core2 );

		if ( $len1 >= self::MIN_LEVENSHTEIN_LENGTH && $len2 >= self::MIN_LEVENSHTEIN_LENGTH ) {
			$max_len  = max( $len1, $len2 );
			$distance = levenshtein( $core1, $core2 );

			if ( $distance <= (int) ( $max_len * self::DEFAULT_LEVENSHTEIN_TOLERANCE ) ) {
				$score = 1.0 - ( $distance / $max_len );
				return new SimilarityResult( true, $score, SimilarityResult::STRATEGY_EDIT, $core1, $core2 );
			}
		}

		return SimilarityResult::noMatch( $core1, $core2 );
	}

	// -----------------------------------------------------------------------
	// Jaccard similarity (word-set)
	// -----------------------------------------------------------------------

	/**
	 * Compare two texts using Jaccard similarity on tokenized word sets.
	 *
	 * Useful for topically-similar content where wording differs significantly
	 * (e.g., "Best Phish Shows of 2025" vs "Top Phish Concerts from 2025").
	 *
	 * @param string $text1     First text.
	 * @param string $text2     Second text.
	 * @param float  $threshold Minimum Jaccard coefficient to count as a match.
	 * @return SimilarityResult
	 */
	public static function jaccardMatch( string $text1, string $text2, float $threshold = self::DEFAULT_JACCARD_THRESHOLD ): SimilarityResult {
		$set_a = self::tokenize( $text1 );
		$set_b = self::tokenize( $text2 );
		$score = self::jaccard( $set_a, $set_b );

		if ( $score >= $threshold ) {
			return new SimilarityResult( true, $score, SimilarityResult::STRATEGY_JACCARD, implode( ' ', $set_a ), implode( ' ', $set_b ) );
		}

		return SimilarityResult::noMatch( implode( ' ', $set_a ), implode( ' ', $set_b ) );
	}

	/**
	 * Compute Jaccard coefficient between two word sets.
	 *
	 * @param array $set_a First word set.
	 * @param array $set_b Second word set.
	 * @return float Similarity coefficient between 0.0 and 1.0.
	 */
	public static function jaccard( array $set_a, array $set_b ): float {
		if ( empty( $set_a ) || empty( $set_b ) ) {
			return 0.0;
		}

		$intersection = array_intersect( $set_a, $set_b );
		$union        = array_unique( array_merge( $set_a, $set_b ) );

		return count( $intersection ) / count( $union );
	}

	// -----------------------------------------------------------------------
	// Title normalization
	// -----------------------------------------------------------------------

	/**
	 * Extract the core identifying portion of a title.
	 *
	 * Consolidated from DuplicateDetection::extractCoreTitle() and
	 * EventIdentifierGenerator::extractCoreTitle(). Handles:
	 *
	 * - HTML entity decoding
	 * - Unicode dash normalization
	 * - Subreddit reference removal (core DuplicateDetection)
	 * - Reaction/attribution suffix removal (core DuplicateDetection)
	 * - Delimiter splitting: dashes, colons, pipes, featuring/with/+
	 *   (events EventIdentifierGenerator)
	 * - Comma-separated artist list handling (events EventIdentifierGenerator)
	 * - Article removal, non-alnum stripping, whitespace collapse
	 *
	 * @param string $title Title to normalize.
	 * @return string Normalized core title for comparison.
	 */
	public static function normalizeTitle( string $title ): string {
		// Decode HTML entities.
		$text = html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Normalize unicode dashes to ASCII hyphen.
		$text = self::normalizeDashes( $text );

		// Lowercase.
		$text = strtolower( $text );

		// Remove subreddit references: "on r/bonnaroo", "from r/coachella", etc.
		$text = preg_replace( '/\s+(on|from|via|at)\s+r\/\w+/i', '', $text );
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
			$escaped = preg_quote( $suffix, '/' );
			$text    = preg_replace( '/\s*[-—–]\s*' . $escaped . '\s*$/i', '', $text );
			$text    = preg_replace( '/\s*[-—–:]\s*' . $escaped . '/i', '', $text );
		}

		// Split on common delimiters that separate headline from supporting info.
		// Superset of delimiters from both DuplicateDetection and EventIdentifierGenerator.
		$delimiters = array(
			' - ',           // ASCII hyphen with spaces (catches normalized em/en dash)
			' — ',           // em dash with spaces (pre-normalization)
			' – ',           // en dash with spaces (pre-normalization)
			' : ',           // colon with spaces
			': ',            // colon
			' | ',           // pipe with spaces
			'|',             // pipe
			' featuring ',
			' feat. ',
			' feat ',
			' ft. ',
			' ft ',
			' with ',
			' w/ ',
			' + ',
		);

		$best_pos   = PHP_INT_MAX;
		$best_delim = null;

		foreach ( $delimiters as $delimiter ) {
			$pos = strpos( $text, $delimiter );
			if ( false !== $pos && $pos > 0 && $pos < $best_pos ) {
				$best_pos   = $pos;
				$best_delim = $delimiter;
			}
		}

		if ( null !== $best_delim ) {
			$text = substr( $text, 0, $best_pos );
		}

		// Comma-separated artist lists: treat first segment as the headliner.
		// "Comfort Club, Valories, Barb" → "Comfort Club"
		if ( strpos( $text, ',' ) !== false ) {
			$comma_parts = explode( ',', $text, 2 );
			$first       = trim( $comma_parts[0] );
			if ( strlen( $first ) > 2 ) {
				$text = $first;
			}
		}

		// Remove articles at word boundaries.
		$text = preg_replace( '/\b(the|a|an)\b/i', '', $text );

		// Remove non-alphanumeric characters (keep spaces and digits).
		$text = preg_replace( '/[^a-z0-9\s]/i', '', $text );

		// Collapse whitespace and trim.
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );

		// If result is too short, return a basic normalization instead.
		if ( strlen( $text ) < 3 ) {
			return self::normalizeBasic( $title );
		}

		return $text;
	}

	// -----------------------------------------------------------------------
	// Tokenization
	// -----------------------------------------------------------------------

	/**
	 * Tokenize text into a set of significant lowercase words.
	 *
	 * Strips stop words and short words (< 2 chars) to focus on
	 * content-bearing terms for Jaccard comparison.
	 *
	 * @param string $text Input text.
	 * @return array Set of significant words (unique).
	 */
	public static function tokenize( string $text ): array {
		preg_match_all( '/[a-z0-9]+/', strtolower( $text ), $matches );

		$words = array();
		foreach ( $matches[0] as $word ) {
			if ( strlen( $word ) >= 2 && ! in_array( $word, self::STOP_WORDS, true ) ) {
				$words[ $word ] = true;
			}
		}

		return array_keys( $words );
	}

	/**
	 * Get the most distinctive search word from a text.
	 *
	 * Returns the longest significant word (3+ chars, not a stop word)
	 * for use as a WP_Query keyword search to fetch candidates.
	 *
	 * @param string $text Input text.
	 * @return string|null Best search word, or null if none found.
	 */
	public static function getBestSearchWord( string $text ): ?string {
		preg_match_all( '/[a-z0-9]+/', strtolower( $text ), $matches );

		$candidates = array();
		foreach ( $matches[0] as $word ) {
			if ( strlen( $word ) >= 3 && ! in_array( $word, self::STOP_WORDS, true ) ) {
				$candidates[] = $word;
			}
		}

		if ( empty( $candidates ) ) {
			return null;
		}

		usort( $candidates, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );

		return $candidates[0];
	}

	// -----------------------------------------------------------------------
	// Text normalization utilities
	// -----------------------------------------------------------------------

	/**
	 * Normalize unicode dash characters to ASCII hyphen.
	 *
	 * Consolidated from DuplicateDetection::normalizeDashes() and
	 * EventIdentifierGenerator::normalize_dashes() — identical implementations.
	 *
	 * @param string $text Input text.
	 * @return string Text with all unicode dashes replaced by ASCII hyphen.
	 */
	public static function normalizeDashes( string $text ): string {
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
	 * Basic text normalization (fallback for very short titles).
	 *
	 * @param string $text Input text.
	 * @return string Normalized text.
	 */
	public static function normalizeBasic( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = self::normalizeDashes( $text );
		$text = strtolower( $text );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		$text = preg_replace( '/^(the|a|an)\s+/i', '', $text );
		return $text;
	}
}
