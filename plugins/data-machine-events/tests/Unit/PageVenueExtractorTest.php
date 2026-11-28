<?php
/**
 * PageVenueExtractor Tests
 *
 * Regression coverage for the Squarespace timezone regex fix (#254).
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\PageVenueExtractor;

class PageVenueExtractorTest extends WP_UnitTestCase {

	/**
	 * Minimal HTML mimicking Squarespace's nested SQUARESPACE_CONTEXT JSON.
	 *
	 * The pre-#254 regex used `[^}]*` which cannot cross any `}` boundary, so
	 * it could never match a real Squarespace page (whose context JSON always
	 * has multiple nested objects before `website.timeZone`). This fixture
	 * reproduces that nested shape with the absolute minimum nesting required
	 * to trigger the bug.
	 */
	public function test_extractTimezone_matches_nested_squarespace_context() {
		$html = '<html><head><script>'
			. 'Static.SQUARESPACE_CONTEXT = {'
			. '"betaFeatureFlags":{"foo":true,"bar":1},'
			. '"rollups":{"x":{"y":1}},'
			. '"website":{"timeZone":"America/New_York","other":"x"}'
			. '};'
			. '</script></head><body></body></html>';

		$this->assertEquals(
			'America/New_York',
			PageVenueExtractor::extractTimezone( $html ),
			'Squarespace context with nested objects before website.timeZone must still match.'
		);
	}

	public function test_extractTimezone_matches_when_timezone_is_first_key() {
		// Simple shape (no nesting before timeZone) — should still match.
		$html = '<script>Static.SQUARESPACE_CONTEXT = {"website":{"timeZone":"America/Chicago"}};</script>';

		$this->assertEquals( 'America/Chicago', PageVenueExtractor::extractTimezone( $html ) );
	}

	public function test_extractTimezone_falls_back_to_generic_timezone_property() {
		// Non-Squarespace platforms expose a generic "timezone" JSON property —
		// the second regex branch must still hit when there's no SQUARESPACE_CONTEXT.
		$html = '<script>var config = {"timezone":"America/Denver"};</script>';

		$this->assertEquals( 'America/Denver', PageVenueExtractor::extractTimezone( $html ) );
	}

	public function test_extractTimezone_falls_back_to_meta_tag() {
		$html = '<html><head><meta name="timezone" content="Europe/London"></head></html>';

		$this->assertEquals( 'Europe/London', PageVenueExtractor::extractTimezone( $html ) );
	}

	public function test_extractTimezone_returns_empty_when_nothing_found() {
		$html = '<html><body>no timezone here</body></html>';

		$this->assertEquals( '', PageVenueExtractor::extractTimezone( $html ) );
	}

	public function test_extractTimezone_matches_royal_american_fixture() {
		// Snapshot of https://www.theroyalamerican.com/schedule (Squarespace 7.x)
		// captured during the #254 investigation. The full live HTML reproduces
		// the exact failure mode of the pre-fix regex — if this stops matching,
		// the regex has regressed.
		$fixture = __DIR__ . '/../Fixtures/squarespace-royal-american.html';

		if ( ! file_exists( $fixture ) ) {
			$this->markTestSkipped( 'Royal American fixture not present (optional snapshot).' );
		}

		$html = file_get_contents( $fixture );
		$this->assertEquals( 'America/New_York', PageVenueExtractor::extractTimezone( $html ) );
	}
}
