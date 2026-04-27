<?php
/**
 * SimilarityEngine Tests
 *
 * Tests for the unified similarity engine — title normalization,
 * fuzzy matching, Jaccard similarity, and tokenization.
 *
 * @package DataMachine\Tests\Unit\Core\Similarity
 */

namespace DataMachine\Tests\Unit\Core\Similarity;

use DataMachine\Core\Similarity\SimilarityEngine;
use DataMachine\Core\Similarity\SimilarityResult;
use WP_UnitTestCase;

class SimilarityEngineTest extends WP_UnitTestCase {

	// -----------------------------------------------------------------------
	// normalizeDashes
	// -----------------------------------------------------------------------

	public function test_normalize_dashes_converts_em_dash(): void {
		$this->assertSame( 'foo - bar', SimilarityEngine::normalizeDashes( "foo \u{2014} bar" ) );
	}

	public function test_normalize_dashes_converts_en_dash(): void {
		$this->assertSame( 'foo - bar', SimilarityEngine::normalizeDashes( "foo \u{2013} bar" ) );
	}

	public function test_normalize_dashes_converts_multiple_types(): void {
		$input = "a\u{2010}b\u{2013}c\u{2014}d";
		$this->assertSame( 'a-b-c-d', SimilarityEngine::normalizeDashes( $input ) );
	}

	public function test_normalize_dashes_preserves_ascii_hyphen(): void {
		$this->assertSame( 'foo-bar', SimilarityEngine::normalizeDashes( 'foo-bar' ) );
	}

	// -----------------------------------------------------------------------
	// normalizeTitle
	// -----------------------------------------------------------------------

	public function test_normalize_title_lowercases(): void {
		$this->assertSame( 'hello world', SimilarityEngine::normalizeTitle( 'Hello World' ) );
	}

	public function test_normalize_title_strips_articles(): void {
		$result = SimilarityEngine::normalizeTitle( 'The Quick Brown Fox' );
		$this->assertStringNotContainsString( 'the', $result );
		$this->assertStringContainsString( 'quick', $result );
	}

	public function test_normalize_title_strips_subreddit_references(): void {
		$result = SimilarityEngine::normalizeTitle( 'Great Show on r/bonnaroo and from r/coachella' );
		$this->assertStringNotContainsString( 'bonnaroo', $result );
		$this->assertStringNotContainsString( 'coachella', $result );
	}

	public function test_normalize_title_strips_reaction_suffixes(): void {
		$result = SimilarityEngine::normalizeTitle( 'Band Announces Tour — Reddit Reacts' );
		$this->assertStringNotContainsString( 'reddit', $result );
		$this->assertStringNotContainsString( 'reacts', $result );
	}

	public function test_normalize_title_splits_on_dash_delimiter(): void {
		$result = SimilarityEngine::normalizeTitle( 'Andy Frasco - Growing Pains Tour' );
		$this->assertSame( 'andy frasco', $result );
	}

	public function test_normalize_title_splits_on_featuring(): void {
		$result = SimilarityEngine::normalizeTitle( 'Andy Frasco featuring Candi Jenkins' );
		$this->assertSame( 'andy frasco', $result );
	}

	public function test_normalize_title_splits_on_with(): void {
		$result = SimilarityEngine::normalizeTitle( 'Andy Frasco with Candi Jenkins' );
		$this->assertSame( 'andy frasco', $result );
	}

	public function test_normalize_title_splits_on_plus(): void {
		$result = SimilarityEngine::normalizeTitle( 'Andy Frasco + Candi Jenkins' );
		$this->assertSame( 'andy frasco', $result );
	}

	public function test_normalize_title_handles_comma_separated_artists(): void {
		$result = SimilarityEngine::normalizeTitle( 'Comfort Club, Valories, Barb' );
		$this->assertSame( 'comfort club', $result );
	}

	public function test_normalize_title_decodes_html_entities(): void {
		$result = SimilarityEngine::normalizeTitle( "C-Boy&#039;s Heart &amp; Soul" );
		// After normalization, special chars stripped, just alphanumeric + spaces
		$this->assertStringContainsString( 'cboys', $result );
	}

	public function test_normalize_title_handles_unicode_dashes_in_delimiters(): void {
		// The em dash should be normalized to ASCII, then split happens.
		$result = SimilarityEngine::normalizeTitle( "Andy Frasco \u{2014} Growing Pains Tour" );
		$this->assertSame( 'andy frasco', $result );
	}

	public function test_normalize_title_short_fallback(): void {
		// Very short title falls back to normalizeBasic.
		$result = SimilarityEngine::normalizeTitle( 'Hi' );
		$this->assertSame( 'hi', $result );
	}

	// -----------------------------------------------------------------------
	// titlesMatch
	// -----------------------------------------------------------------------

	public function test_titles_match_exact(): void {
		$result = SimilarityEngine::titlesMatch( 'Andy Frasco', 'Andy Frasco' );
		$this->assertTrue( $result->match );
		$this->assertSame( SimilarityResult::STRATEGY_EXACT, $result->strategy );
		$this->assertSame( 1.0, $result->score );
	}

	public function test_titles_match_case_insensitive(): void {
		$result = SimilarityEngine::titlesMatch( 'Andy Frasco', 'ANDY FRASCO' );
		$this->assertTrue( $result->match );
		$this->assertSame( SimilarityResult::STRATEGY_EXACT, $result->strategy );
	}

	public function test_titles_match_with_article_differences(): void {
		$result = SimilarityEngine::titlesMatch( 'The Blue Note', 'Blue Note' );
		$this->assertTrue( $result->match );
	}

	public function test_titles_match_prefix(): void {
		// "colombian jazz experience" matches "colombian jazz experience sahara"
		$result = SimilarityEngine::titlesMatch(
			'Colombian Jazz Experience',
			'Colombian Jazz Experience at Sahara'
		);
		$this->assertTrue( $result->match );
		$this->assertSame( SimilarityResult::STRATEGY_PREFIX, $result->strategy );
	}

	public function test_titles_match_prefix_requires_minimum_length(): void {
		// Too short to trigger prefix match.
		$result = SimilarityEngine::titlesMatch( 'Hi', 'Hiking Trip' );
		$this->assertFalse( $result->match );
	}

	public function test_titles_match_levenshtein(): void {
		// Long titles with minor word differences that survive normalization.
		// "programming" vs "programing" (typo) — different after normalization.
		$result = SimilarityEngine::titlesMatch(
			'advanced programming techniques explained',
			'advanced programing techniques explained'
		);
		$this->assertTrue( $result->match );
		$this->assertSame( SimilarityResult::STRATEGY_EDIT, $result->strategy );
	}

	public function test_titles_match_levenshtein_requires_minimum_length(): void {
		// Short titles don't get Levenshtein.
		$result = SimilarityEngine::titlesMatch( 'Cat', 'Car' );
		$this->assertFalse( $result->match );
	}

	public function test_titles_no_match_different_content(): void {
		$result = SimilarityEngine::titlesMatch(
			'Best Restaurants in Austin',
			'Live Music Venues in Nashville'
		);
		$this->assertFalse( $result->match );
		$this->assertSame( SimilarityResult::STRATEGY_NONE, $result->strategy );
	}

	public function test_titles_match_with_supporting_acts_stripped(): void {
		$result = SimilarityEngine::titlesMatch(
			'Andy Frasco & the U.N. — Growing Pains Tour with Candi Jenkins',
			'Andy Frasco & The U.N.'
		);
		$this->assertTrue( $result->match );
	}

	public function test_titles_match_returns_similarity_result(): void {
		$result = SimilarityEngine::titlesMatch( 'Foo', 'Bar' );
		$this->assertInstanceOf( SimilarityResult::class, $result );
		$this->assertIsBool( $result->match );
		$this->assertIsFloat( $result->score );
		$this->assertIsString( $result->strategy );
	}

	// -----------------------------------------------------------------------
	// tokenize
	// -----------------------------------------------------------------------

	public function test_tokenize_removes_stop_words(): void {
		$tokens = SimilarityEngine::tokenize( 'The cat is on the mat' );
		$this->assertContains( 'cat', $tokens );
		$this->assertContains( 'mat', $tokens );
		$this->assertNotContains( 'the', $tokens );
		$this->assertNotContains( 'is', $tokens );
		$this->assertNotContains( 'on', $tokens );
	}

	public function test_tokenize_removes_short_words(): void {
		$tokens = SimilarityEngine::tokenize( 'I go to a bar' );
		$this->assertNotContains( 'i', $tokens );
		$this->assertContains( 'go', $tokens );
		$this->assertContains( 'bar', $tokens );
	}

	public function test_tokenize_returns_unique_words(): void {
		$tokens = SimilarityEngine::tokenize( 'cat cat cat dog dog' );
		$this->assertCount( 2, $tokens );
	}

	public function test_tokenize_lowercases(): void {
		$tokens = SimilarityEngine::tokenize( 'HELLO World' );
		$this->assertContains( 'hello', $tokens );
		$this->assertContains( 'world', $tokens );
	}

	// -----------------------------------------------------------------------
	// jaccard
	// -----------------------------------------------------------------------

	public function test_jaccard_identical_sets(): void {
		$this->assertSame( 1.0, SimilarityEngine::jaccard( array( 'cat', 'dog' ), array( 'cat', 'dog' ) ) );
	}

	public function test_jaccard_disjoint_sets(): void {
		$this->assertSame( 0.0, SimilarityEngine::jaccard( array( 'cat' ), array( 'dog' ) ) );
	}

	public function test_jaccard_partial_overlap(): void {
		// { cat, dog } ∩ { cat, fish } = { cat }
		// { cat, dog } ∪ { cat, fish } = { cat, dog, fish }
		// Jaccard = 1/3 ≈ 0.333
		$score = SimilarityEngine::jaccard( array( 'cat', 'dog' ), array( 'cat', 'fish' ) );
		$this->assertEqualsWithDelta( 1 / 3, $score, 0.001 );
	}

	public function test_jaccard_empty_sets(): void {
		$this->assertSame( 0.0, SimilarityEngine::jaccard( array(), array( 'cat' ) ) );
		$this->assertSame( 0.0, SimilarityEngine::jaccard( array( 'cat' ), array() ) );
		$this->assertSame( 0.0, SimilarityEngine::jaccard( array(), array() ) );
	}

	// -----------------------------------------------------------------------
	// jaccardMatch
	// -----------------------------------------------------------------------

	public function test_jaccard_match_above_threshold(): void {
		$result = SimilarityEngine::jaccardMatch(
			'Best Phish Shows of 2025',
			'Top Phish Concerts from 2025',
			0.3
		);
		$this->assertTrue( $result->match );
		$this->assertSame( SimilarityResult::STRATEGY_JACCARD, $result->strategy );
	}

	public function test_jaccard_match_below_threshold(): void {
		$result = SimilarityEngine::jaccardMatch(
			'Best Restaurants in Austin',
			'Live Music Venues in Nashville',
			0.65
		);
		$this->assertFalse( $result->match );
	}

	// -----------------------------------------------------------------------
	// getBestSearchWord
	// -----------------------------------------------------------------------

	public function test_get_best_search_word_returns_longest(): void {
		$word = SimilarityEngine::getBestSearchWord( 'The cat sat on the comfortable chair' );
		$this->assertSame( 'comfortable', $word );
	}

	public function test_get_best_search_word_skips_stop_words(): void {
		$word = SimilarityEngine::getBestSearchWord( 'what is the meaning' );
		$this->assertSame( 'meaning', $word );
	}

	public function test_get_best_search_word_returns_null_for_only_stop_words(): void {
		$word = SimilarityEngine::getBestSearchWord( 'it is the' );
		$this->assertNull( $word );
	}

	// -----------------------------------------------------------------------
	// SimilarityResult
	// -----------------------------------------------------------------------

	public function test_similarity_result_to_array(): void {
		$result = new SimilarityResult( true, 0.95, SimilarityResult::STRATEGY_EXACT, 'foo', 'foo' );
		$array  = $result->toArray();

		$this->assertTrue( $array['match'] );
		$this->assertSame( 0.95, $array['score'] );
		$this->assertSame( 'exact', $array['strategy'] );
		$this->assertSame( 'foo', $array['normalized_a'] );
		$this->assertSame( 'foo', $array['normalized_b'] );
	}

	public function test_similarity_result_no_match_factory(): void {
		$result = SimilarityResult::noMatch( 'foo', 'bar' );

		$this->assertFalse( $result->match );
		$this->assertSame( 0.0, $result->score );
		$this->assertSame( SimilarityResult::STRATEGY_NONE, $result->strategy );
	}

	// -----------------------------------------------------------------------
	// normalizeBasic
	// -----------------------------------------------------------------------

	public function test_normalize_basic_strips_leading_article(): void {
		$this->assertSame( 'foo bar', SimilarityEngine::normalizeBasic( 'The Foo Bar' ) );
	}

	public function test_normalize_basic_lowercases_and_trims(): void {
		$this->assertSame( 'hello world', SimilarityEngine::normalizeBasic( '  Hello   World  ' ) );
	}
}
