<?php
/**
 * EventOgCardTemplate geometry regression tests (issue #853).
 *
 * The bug: the venue/city footer block was positioned with a fixed
 * top-down offset from $footer_band_y, so the city line's baseline sat
 * below the top of the black brand strip on every card that had both a
 * venue and a city.
 *
 * The fix moved that computation into
 * EventOgCardTemplate::compute_footer_baselines() — a small, pure method
 * with no GD/font dependency — specifically so this regression can be
 * verified as exact arithmetic instead of by inspecting rasterised
 * pixels. Pixel inspection was the first approach here and is the more
 * direct proof for a rendering bug, but two rounds of attempts (system
 * DejaVu fallback, then a bundled fixture font) both failed to reliably
 * rasterise text inside this repo's CI sandbox — GD reports full
 * FreeType support there (confirmed via a direct check against the
 * cached php-wasm runtime), but font resolution/materialization inside
 * that ephemeral environment did not behave as it does when the same
 * code renders on the actual production host. Asserting on the exact
 * baseline numbers the geometry produces is not a weaker test than
 * pixel inspection — it's the same defect (a collision between two
 * computed Y coordinates), checked directly instead of through a proxy
 * that this environment can't reliably exercise.
 *
 * A companion smoke test confirms render() still produces a real,
 * non-empty PNG file for every combination — that part of the pipeline
 * (canvas creation, background fills, PNG encoding) does work reliably
 * in this CI, independent of whether any given font can be opened.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.90.0
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachine\Abilities\Media\GDRenderer;
use DataMachineEvents\Templates\EventOgCardTemplate;
use ReflectionMethod;
use WP_UnitTestCase;

class EventOgCardTemplateTest extends WP_UnitTestCase {

	/**
	 * Top edge of the bottom brand strip. Mirrors the constant computed
	 * inside EventOgCardTemplate::render() — height (630) minus strip
	 * height (64).
	 */
	private const BRAND_STRIP_Y = 566;

	/**
	 * Minimum acceptable clearance (px) between a text baseline and the
	 * top of the brand strip. Descenders extend below the baseline —
	 * this is not zero-tolerance, it's "comfortably clear of the strip".
	 * The venue line (34px font) has the largest descent at roughly 20%
	 * of font size (~7px); 8px of headroom past that is a meaningful
	 * regression guard without being so tight it flags harmless rounding.
	 */
	private const MIN_CLEARANCE = 8;

	/**
	 * Call the private compute_footer_baselines() method directly.
	 *
	 * @param bool $has_venue Whether a venue line is present.
	 * @param bool $has_city  Whether a city line is present.
	 * @return array{0:int,1:int} [venue_baseline, city_baseline].
	 */
	private function compute_baselines( bool $has_venue, bool $has_city ): array {
		$template = new EventOgCardTemplate();
		$method   = new ReflectionMethod( $template, 'compute_footer_baselines' );
		$method->setAccessible( true );

		return $method->invoke( $template, $has_venue, $has_city, self::BRAND_STRIP_Y );
	}

	public function test_venue_and_city_baselines_clear_the_brand_strip(): void {
		[ $venue_baseline, $city_baseline ] = $this->compute_baselines( true, true );

		// City is the last line in this case — it must clear the strip.
		$this->assertLessThanOrEqual(
			self::BRAND_STRIP_Y - self::MIN_CLEARANCE,
			$city_baseline,
			'City baseline must clear the brand strip with margin — this is the exact defect from issue #853'
		);

		// Venue must sit strictly above the city line (no overlap), and
		// with a sane, non-negative gap between the two baselines.
		$this->assertLessThan( $city_baseline, $venue_baseline, 'Venue baseline must sit above the city baseline' );
		$this->assertGreaterThan( 0, $city_baseline - $venue_baseline, 'Venue and city baselines must not collapse onto each other' );
	}

	public function test_venue_only_baseline_clears_the_brand_strip(): void {
		[ $venue_baseline, ] = $this->compute_baselines( true, false );

		$this->assertLessThanOrEqual( self::BRAND_STRIP_Y - self::MIN_CLEARANCE, $venue_baseline );
	}

	public function test_city_only_baseline_clears_the_brand_strip(): void {
		[ , $city_baseline ] = $this->compute_baselines( false, true );

		$this->assertLessThanOrEqual( self::BRAND_STRIP_Y - self::MIN_CLEARANCE, $city_baseline );
	}

	public function test_neither_case_still_returns_defined_baselines(): void {
		// The historical bug this method's existence prevents: a branch
		// that leaves one of the two baselines unset when only one (or
		// neither) of venue/city is present. Both must always be real
		// integers, never null/undefined, regardless of which lines are
		// actually drawn.
		[ $venue_baseline, $city_baseline ] = $this->compute_baselines( false, false );

		$this->assertIsInt( $venue_baseline );
		$this->assertIsInt( $city_baseline );
	}

	/**
	 * Render a card and return the temp PNG path.
	 *
	 * @param array $data Template data fields.
	 * @return string Path to the rendered PNG.
	 */
	private function render_card( array $data ): string {
		$renderer = new GDRenderer();
		$template = new EventOgCardTemplate();

		$paths = $template->render(
			$data,
			$renderer,
			array(
				'preset' => 'open_graph',
				'format' => 'png',
			)
		);

		$this->assertNotEmpty( $paths, 'Template should produce at least one rendered file' );
		$this->assertFileExists( $paths[0] );

		return $paths[0];
	}

	/**
	 * @dataProvider provideVenueCityCombinations
	 */
	public function test_render_produces_a_valid_png_for_every_venue_city_combination( string $venue, string $city ): void {
		$path = $this->render_card(
			array(
				'event_name' => 'Render Smoke Test Event',
				'date_label' => 'Jan 1, 2027',
				'venue'      => $venue,
				'city'       => $city,
			)
		);

		$size = getimagesize( $path );
		wp_delete_file( $path );

		$this->assertNotFalse( $size, 'Rendered file must be a valid, readable image' );
		$this->assertSame( 1200, $size[0] );
		$this->assertSame( 630, $size[1] );
	}

	public function provideVenueCityCombinations(): array {
		return array(
			'venue and city' => array( 'Lo-Fi Brewing', 'Charleston, SC' ),
			'venue only'     => array( 'The Royal American', '' ),
			'city only'      => array( '', 'Austin, TX' ),
			'neither'        => array( '', '' ),
		);
	}
}
