<?php
/**
 * MergedBillDetectAbilities Scoring Tests
 *
 * Covers the deterministic scoring layer (issue #256). The lineup-mention
 * heuristic is the strongest signal and the only one capable of catching
 * the Maraluso/Emma Grace pattern, so it gets the most coverage.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.34.0
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Abilities\MergedBillDetectAbilities;

class MergedBillDetectScoringTest extends WP_UnitTestCase {

	private MergedBillDetectAbilities $detector;

	public function setUp(): void {
		parent::setUp();
		$this->detector = new MergedBillDetectAbilities();
	}

	private function bodyFromFixture( string $name ): string {
		$path = __DIR__ . '/../Fixtures/merged-bills/' . $name;
		$this->assertFileExists( $path, 'Fixture missing: ' . $name );

		$raw    = (string) file_get_contents( $path );
		$blocks = parse_blocks( $raw );

		foreach ( $blocks as $block ) {
			if ( 'data-machine-events/event-details' !== $block['blockName'] ) {
				continue;
			}
			$parts = array();
			foreach ( $block['innerBlocks'] ?? array() as $inner ) {
				if ( ! empty( $inner['innerHTML'] ) ) {
					$parts[] = wp_strip_all_tags( $inner['innerHTML'] );
				}
			}
			return trim( implode( ' ', $parts ) );
		}

		return wp_strip_all_tags( $raw );
	}

	// ------------------------------------------------------------------
	// hasMutualLineupMention
	// ------------------------------------------------------------------

	public function test_maraluso_emma_grace_fixture_is_mutual_mention(): void {
		$body_a = $this->bodyFromFixture( 'pair-maraluso-emma-grace-a.txt' );
		$body_b = $this->bodyFromFixture( 'pair-maraluso-emma-grace-b.txt' );

		$mutual = $this->detector->hasMutualLineupMention(
			'Maraluso',
			'Maraluso',
			$body_a,
			'Emma Grace Burton',
			'Emma Grace Burton',
			$body_b
		);

		$this->assertTrue( $mutual, 'Maraluso/Emma Grace bodies should mutually mention each other.' );
	}

	public function test_local_nomad_babe_club_fixture_is_mutual_mention(): void {
		$body_a = $this->bodyFromFixture( 'pair-local-nomad-babe-club-a.txt' );
		$body_b = $this->bodyFromFixture( 'pair-local-nomad-babe-club-b.txt' );

		$mutual = $this->detector->hasMutualLineupMention(
			'Local Nomad (Record Release)',
			'Local Nomad',
			$body_a,
			'Babe Club + Local Nomad (Record Release)',
			'Babe Club',
			$body_b
		);

		$this->assertTrue( $mutual, 'Local Nomad/Babe Club bodies should mutually mention each other.' );
	}

	public function test_one_sided_mention_is_not_mutual(): void {
		// Body A mentions B's artist, but body B does not mention A's.
		$mutual = $this->detector->hasMutualLineupMention(
			'Headliner A',
			'Headliner A',
			'Tonight we welcome Headliner A with special guests Headliner B and friends.',
			'Headliner B',
			'Headliner B',
			'Standalone show. Doors at 9pm.'
		);

		$this->assertFalse( $mutual, 'One-sided mentions must not count as mutual.' );
	}

	public function test_distinct_shows_at_same_time_are_not_mutual(): void {
		// Comedy club two-room scenario: same venue, same start time, totally
		// different lineups, no mention of each other.
		$body_a = 'Open Mic Night. Sign up at the bar. Hosted by Jane Doe.';
		$body_b = 'Stand-up showcase featuring John Roe, Sam Smith, and Alex Lee.';

		$mutual = $this->detector->hasMutualLineupMention(
			'Open Mic Night',
			'Open Mic Night',
			$body_a,
			'Stand-up Showcase',
			'John Roe',
			$body_b
		);

		$this->assertFalse( $mutual, 'Different lineups must not score as a mutual mention.' );
	}

	public function test_short_artist_names_are_not_used_for_matching(): void {
		// "Ad" is too short to be discriminative — the algorithm must skip it
		// to avoid false-positive matches against words like "address" or "and".
		$body_a = 'A great show with Ad and the Animals.';
		$body_b = 'Featuring Ad headlining.';

		$mutual = $this->detector->hasMutualLineupMention(
			'Ad',
			'Ad',
			$body_a,
			'Ad',
			'Ad',
			$body_b
		);

		// Both 'Ad' strings are below the 3-character threshold → no match.
		$this->assertFalse( $mutual, 'Sub-3-char artist names must be rejected.' );
	}

	// ------------------------------------------------------------------
	// scorePair via execute() — integration over fixtures requires the
	// post records to exist. Skip; covered by direct lineup tests above.
	// ------------------------------------------------------------------

	public function test_buildPairKey_is_order_independent(): void {
		$k1 = $this->detector->buildPairKey( 5366, 6504 );
		$k2 = $this->detector->buildPairKey( 6504, 5366 );

		$this->assertSame( $k1, $k2, 'Pair key must be order-independent.' );
		$this->assertStringContainsString( '5366', $k1 );
		$this->assertStringContainsString( '6504', $k1 );
	}
}
