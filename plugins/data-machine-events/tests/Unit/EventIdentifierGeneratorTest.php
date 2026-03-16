<?php
/**
 * EventIdentifierGenerator Tests
 *
 * Tests for duplicate event detection via title normalization.
 * EventIdentifierGenerator now delegates to the core SimilarityEngine
 * for title normalization and matching. These tests verify the
 * delegation works correctly.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since 0.10.2
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Utilities\EventIdentifierGenerator;

class EventIdentifierGeneratorTest extends WP_UnitTestCase {

	/**
	 * Test cases: pairs of titles that SHOULD match
	 */
	public function get_matching_title_pairs(): array {
		return array(
			// Article variations
			'the_blue_note_vs_blue_note' => array(
				'The Blue Note Jazz Night',
				'Blue Note Jazz Night',
			),

			// Case variations
			'case_insensitive' => array(
				'JAZZ NIGHT SPECIAL',
				'jazz night special',
			),

			// Whitespace variations
			'extra_whitespace' => array(
				'Jazz  Night   Special',
				'Jazz Night Special',
			),

			// Tour name stripped (em dash)
			'tour_name_em_dash' => array(
				'Andy Frasco & the U.N. — Growing Pains Tour',
				'Andy Frasco & the U.N.',
			),

			// Supporting act stripped (with)
			'supporting_act_with' => array(
				'Headliner Band with Opening Act',
				'Headliner Band',
			),

			// Supporting act stripped (feat)
			'supporting_act_feat' => array(
				'Main Artist feat. Guest Artist',
				'Main Artist',
			),

			// Colon delimiter
			'colon_series_name' => array(
				'Jazz Night: Holiday Special',
				'Jazz Night',
			),
		);
	}

	/**
	 * Test cases: pairs of titles that should NOT match
	 */
	public function get_non_matching_title_pairs(): array {
		return array(
			'completely_different' => array(
				'Jazz Night at the Blue Note',
				'Rock Concert at Red Rocks',
			),

			'similar_but_different_event' => array(
				'Burgundy: Soul Nite',
				'Burgundy: Funk Nite',
			),

			'same_venue_different_event' => array(
				'Blue Note: Jazz Series',
				'Blue Note: Blues Series',
			),
		);
	}

	/**
	 * @dataProvider get_matching_title_pairs
	 */
	public function test_titles_should_match( string $title1, string $title2 ): void {
		$this->assertTrue(
			EventIdentifierGenerator::titlesMatch( $title1, $title2 ),
			sprintf(
				"Expected titles to match:\n  Title 1: %s\n  Title 2: %s\n  Core 1: %s\n  Core 2: %s",
				$title1,
				$title2,
				EventIdentifierGenerator::extractCoreTitle( $title1 ),
				EventIdentifierGenerator::extractCoreTitle( $title2 )
			)
		);
	}

	/**
	 * @dataProvider get_non_matching_title_pairs
	 */
	public function test_titles_should_not_match( string $title1, string $title2 ): void {
		$this->assertFalse(
			EventIdentifierGenerator::titlesMatch( $title1, $title2 ),
			sprintf(
				"Expected titles NOT to match:\n  Title 1: %s\n  Title 2: %s",
				$title1,
				$title2
			)
		);
	}

	/**
	 * Test that band names with hyphens are preserved
	 */
	public function test_hyphenated_band_names_preserved(): void {
		$core = EventIdentifierGenerator::extractCoreTitle( 'Run-DMC Live in Concert' );

		// Hyphen removed but name should still be recognizable
		$this->assertStringContainsString( 'run', $core );
		$this->assertStringContainsString( 'dmc', $core );
	}

	/**
	 * Test identifier generation consistency
	 */
	public function test_generate_produces_consistent_hash(): void {
		$hash1 = EventIdentifierGenerator::generate( 'Test Event', '2026-01-28', 'Test Venue' );
		$hash2 = EventIdentifierGenerator::generate( 'Test Event', '2026-01-28', 'Test Venue' );

		$this->assertEquals( $hash1, $hash2, 'Same input should produce same hash' );
	}

	/**
	 * Test that article variations produce same identifier
	 */
	public function test_generate_normalizes_articles(): void {
		$hash1 = EventIdentifierGenerator::generate( 'The Blue Note', '2026-01-28', 'The Venue' );
		$hash2 = EventIdentifierGenerator::generate( 'Blue Note', '2026-01-28', 'Venue' );

		$this->assertEquals( $hash1, $hash2, 'Article variations should produce same hash' );
	}

	/**
	 * Test earliest delimiter extraction
	 *
	 * SimilarityEngine uses leftmost-wins: the earliest delimiter in the
	 * text is used to split. For "Burgundy: Soul Nite — Bill Wilson":
	 * - ": " at pos 8 wins over " - " at pos 20
	 * - Core title is "burgundy"
	 */
	public function test_earliest_delimiter_used(): void {
		$core = EventIdentifierGenerator::extractCoreTitle( 'Burgundy: Soul Nite — Bill Wilson' );

		// ": " is the earliest delimiter (pos 8), so we get "burgundy"
		$this->assertStringContainsString( 'burgundy', $core );
		$this->assertStringNotContainsString( 'bill', $core );
		$this->assertStringNotContainsString( 'wilson', $core );
	}

	/**
	 * Test that em dash delimiter properly splits titles
	 */
	public function test_em_dash_delimiter_splits(): void {
		// When em dash is the only/earliest delimiter
		$core = EventIdentifierGenerator::extractCoreTitle( 'Soul Nite — Bill Wilson & The Ingredients' );

		$this->assertStringContainsString( 'soul', $core );
		$this->assertStringContainsString( 'nite', $core );
		$this->assertStringNotContainsString( 'bill', $core );
		$this->assertStringNotContainsString( 'wilson', $core );
	}

	/**
	 * Test that em dash with/without surrounding content produces matching titles.
	 *
	 * The original bug: "Burgundy: Soul Nite — Bill Wilson & The Ingredients"
	 * vs "Burgundy: Soul Nite Bill Wilson & The Ingredients" should match
	 * because both normalize to the same core ("burgundy").
	 */
	public function test_burgundy_soul_nite_em_dash_variant_match(): void {
		$this->assertTrue(
			EventIdentifierGenerator::titlesMatch(
				'Burgundy: Soul Nite — Bill Wilson & The Ingredients',
				'Burgundy: Soul Nite Bill Wilson & The Ingredients'
			),
			'Em dash variant should match (both normalize to same core via colon split)'
		);
	}

	/**
	 * Test venue matching basics
	 */
	public function test_venues_match_exact(): void {
		$this->assertTrue(
			EventIdentifierGenerator::venuesMatch( 'The Parish', 'The Parish' )
		);
	}

	public function test_venues_match_with_qualifier(): void {
		$this->assertTrue(
			EventIdentifierGenerator::venuesMatch( "Buck's Backyard", "Buck's Backyard (Indoor)" )
		);
	}

	public function test_venues_match_with_dash_suffix(): void {
		$this->assertTrue(
			EventIdentifierGenerator::venuesMatch( 'Brooklyn Bowl', 'Brooklyn Bowl - Nashville' )
		);
	}

	public function test_venues_do_not_match_different(): void {
		$this->assertFalse(
			EventIdentifierGenerator::venuesMatch( 'The Basement', 'The Basement East' )
		);
	}

	public function test_venues_empty_does_not_match(): void {
		$this->assertFalse(
			EventIdentifierGenerator::venuesMatch( '', 'Some Venue' )
		);
	}

	/**
	 * Test that extractCoreTitle delegates to SimilarityEngine::normalizeTitle
	 */
	public function test_extract_core_title_delegates_to_similarity_engine(): void {
		if ( ! class_exists( 'DataMachine\Core\Similarity\SimilarityEngine' ) ) {
			$this->markTestSkipped( 'SimilarityEngine not available (data-machine core not loaded).' );
		}

		$title  = 'Andy Frasco & the U.N. — Growing Pains Tour';
		$core   = EventIdentifierGenerator::extractCoreTitle( $title );
		$engine = \DataMachine\Core\Similarity\SimilarityEngine::normalizeTitle( $title );

		$this->assertEquals( $engine, $core, 'extractCoreTitle should delegate to SimilarityEngine::normalizeTitle' );
	}

	public function test_low_confidence_short_non_specific_title(): void {
		$this->assertTrue( EventIdentifierGenerator::isLowConfidenceTitle( 'Showcase' ) );
		$this->assertSame( 'low', EventIdentifierGenerator::getIdentityConfidence( 'Showcase', '2026-03-10', '' ) );
	}

	public function test_specific_title_without_venue_is_medium_confidence(): void {
		$this->assertFalse( EventIdentifierGenerator::isLowConfidenceTitle( 'The California Honeydrops - Shine Delight Tour 2026' ) );
		$this->assertSame( 'medium', EventIdentifierGenerator::getIdentityConfidence( 'The California Honeydrops - Shine Delight Tour 2026', '2026-03-10', '' ) );
	}

	public function test_specific_title_with_venue_is_high_confidence(): void {
		$this->assertSame( 'high', EventIdentifierGenerator::getIdentityConfidence( 'The California Honeydrops - Shine Delight Tour 2026', '2026-03-10', 'ACL Live' ) );
	}

	public function test_schedule_blob_title_is_low_confidence(): void {
		$title = 'Artist A 7pm, Artist B 8:15pm, Artist C 9:30pm';
		$this->assertTrue( EventIdentifierGenerator::isLowConfidenceTitle( $title ) );
	}
}
