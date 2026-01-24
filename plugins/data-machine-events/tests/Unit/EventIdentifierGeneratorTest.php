<?php
/**
 * EventIdentifierGenerator Tests
 *
 * Tests for duplicate event detection via title normalization.
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
			// The bug that triggered this test file
			'burgundy_soul_nite_em_dash_vs_no_dash' => array(
				'Burgundy: Soul Nite — Bill Wilson & The Ingredients',
				'Burgundy: Soul Nite Bill Wilson & The Ingredients',
			),

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
	 * Test rightmost delimiter extraction (the core fix)
	 */
	public function test_rightmost_delimiter_used(): void {
		// "Burgundy: Soul Nite — Bill Wilson" has colon at 8 and em dash at 19
		// Should split at em dash (position 19), keeping "Burgundy: Soul Nite"
		$core = EventIdentifierGenerator::extractCoreTitle( 'Burgundy: Soul Nite — Bill Wilson' );

		$this->assertStringContainsString( 'burgundy', $core );
		$this->assertStringContainsString( 'soul', $core );
		$this->assertStringContainsString( 'nite', $core );
		$this->assertStringNotContainsString( 'bill', $core );
		$this->assertStringNotContainsString( 'wilson', $core );
	}
}
