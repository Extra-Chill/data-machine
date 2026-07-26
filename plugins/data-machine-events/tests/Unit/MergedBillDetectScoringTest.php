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
	// Canonical ticket identity
	// ------------------------------------------------------------------

	public function test_ticket_identity_matches_across_source_suffix_and_lineup_drift(): void {
		$this->assertTrue(
			$this->detector->ticketIdentitiesMatch(
				'https://www.etix.com/ticket/p/80665941/campground-underground-music-showcase-wsinistarrj-bolivarmrfrickganocerval-denver-campground?partner_id=100',
				'https://www.etix.com/ticket/p/80665941/campground-underground-music-showcase-wsinistarrj-bolivarmrfrickganolevi-double-u-denver-campground?partner_id=100'
			),
			'Source-generated lineup suffixes must not hide a shared stable provider event ID.'
		);
	}

	public function test_ticket_identity_matches_across_affiliate_wrapped_title_variants(): void {
		$this->assertTrue(
			$this->detector->ticketIdentitiesMatch(
				'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=https%3A%2F%2Fwww.ticketmaster.com%2Freprise-recreating-10291994-at-spartanburg-memorial-charleston-south-carolina-07-30-2026%2Fevent%2F2D00649E9A9BF91F&utm_medium=affiliate',
				'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=https%3A%2F%2Fwww.ticketmaster.com%2Freprise-charleston-south-carolina-07-30-2026%2Fevent%2F2D00649E9A9BF91F&utm_medium=affiliate'
			),
			'Affiliate wrappers and source title variants must resolve to the shared Ticketmaster event ID.'
		);
	}

	public function test_distinct_ticket_identities_do_not_match_at_same_venue_and_time(): void {
		$this->assertFalse(
			$this->detector->ticketIdentitiesMatch(
				'https://www.etix.com/ticket/p/80665941/first-show',
				'https://www.etix.com/ticket/p/80665942/second-show'
			),
			'Different provider event IDs must remain distinct even when venue and time collide.'
		);
	}

	public function test_missing_ticket_identity_never_matches(): void {
		$this->assertFalse( $this->detector->ticketIdentitiesMatch( '', '' ) );
		$this->assertFalse( $this->detector->ticketIdentitiesMatch( 'https://tickets.example.com/show', '' ) );
	}

	public function test_matching_ticket_identity_reaches_review_threshold_without_lineup_overlap(): void {
		$post_a = self::factory()->post->create( array( 'post_content' => 'Source A description.' ) );
		$post_b = self::factory()->post->create( array( 'post_content' => 'Source B description.' ) );
		update_post_meta( $post_a, \DataMachineEvents\Core\EVENT_TICKET_URL_META_KEY, 'https://www.etix.com/ticket/p/80665941/cerval' );
		update_post_meta( $post_b, \DataMachineEvents\Core\EVENT_TICKET_URL_META_KEY, 'https://www.etix.com/ticket/p/80665941/levi-double-u' );

		$score_pair = new \ReflectionMethod( MergedBillDetectAbilities::class, 'scorePair' );
		$score_pair->setAccessible( true );
		$result = $score_pair->invoke(
			$this->detector,
			array( 'post_id' => $post_a, 'title' => 'Campground with Cerval' ),
			array( 'post_id' => $post_b, 'title' => 'Campground with Levi Double U' )
		);

		$this->assertSame( MergedBillDetectAbilities::DEFAULT_THRESHOLD, $result['score'] );
		$this->assertTrue( $result['signals']['matching_ticket_identity'] );
		$this->assertFalse( $result['signals']['mutual_lineup_mention'] );
	}

	// ------------------------------------------------------------------
	// execute() candidate discovery remains covered by the managed database
	// suite; scorePair() is exercised directly above with real post records.
	// ------------------------------------------------------------------

	public function test_buildPairKey_is_order_independent(): void {
		$k1 = $this->detector->buildPairKey( 5366, 6504 );
		$k2 = $this->detector->buildPairKey( 6504, 5366 );

		$this->assertSame( $k1, $k2, 'Pair key must be order-independent.' );
		$this->assertStringContainsString( '5366', $k1 );
		$this->assertStringContainsString( '6504', $k1 );
	}
}
