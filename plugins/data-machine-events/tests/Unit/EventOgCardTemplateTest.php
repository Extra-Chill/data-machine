<?php
/**
 * EventOgCardTemplate geometry regression tests (issues #853, #855, #856).
 *
 * The original bug (#853): the venue/city footer block was positioned
 * with a fixed top-down offset from $footer_band_y, so the city line's
 * baseline sat below the top of the black brand strip on every card
 * that had both a venue and a city.
 *
 * #855 fixed it by anchoring bottom-up from the brand strip instead.
 * #856 removed the brand strip entirely (replaced by a logo/brand-mark
 * in the same corner) and re-pointed that same bottom-up anchor at the
 * canvas bottom edge ($height) — the fix logic itself is unchanged, only
 * what it anchors to. These tests cover both: the geometry is verified
 * as exact arithmetic (see the long-standing rationale below for why),
 * and the new brand-mark resolution chain is verified independently.
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
	 * Canvas height for the `open_graph` preset (1200x630). #856 removed
	 * the black brand strip that venue/city baselines used to anchor to
	 * ($height - 64 = 566); the anchor is now the canvas bottom edge
	 * itself.
	 */
	private const CANVAS_HEIGHT = 630;

	/**
	 * Minimum acceptable clearance (px) between a text baseline and the
	 * canvas bottom edge. Descenders extend below the baseline — this is
	 * not zero-tolerance, it's "comfortably clear of the edge". The venue
	 * line (34px font) has the largest descent at roughly 20% of font
	 * size (~7px); 8px of headroom past that is a meaningful regression
	 * guard without being so tight it flags harmless rounding.
	 */
	private const MIN_CLEARANCE = 8;

	/**
	 * Temp files written during a test via make_temp_png(), cleaned up
	 * in tearDown().
	 *
	 * @var string[]
	 */
	private array $temp_files = array();

	public function tearDown(): void {
		foreach ( $this->temp_files as $path ) {
			wp_delete_file( $path );
		}
		$this->temp_files = array();

		parent::tearDown();
	}

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

		return $method->invoke( $template, $has_venue, $has_city, self::CANVAS_HEIGHT );
	}

	/**
	 * Call a private method on a fresh EventOgCardTemplate instance.
	 *
	 * @param string $name Method name.
	 * @param mixed  ...$args Arguments to pass through.
	 * @return mixed
	 */
	private function invoke_private( string $name, ...$args ) {
		$template = new EventOgCardTemplate();
		$method   = new ReflectionMethod( $template, $name );
		$method->setAccessible( true );

		return $method->invokeArgs( $template, $args );
	}

	/**
	 * Write a tiny real PNG to a temp path inside the uploads directory,
	 * tracked for cleanup in tearDown().
	 *
	 * @param int $w Width in pixels.
	 * @param int $h Height in pixels.
	 * @return string Absolute path to the written file.
	 */
	private function make_temp_png( int $w = 64, int $h = 64 ): string {
		$upload_dir = wp_upload_dir();
		$path       = trailingslashit( $upload_dir['path'] ) . 'ecogcard-test-' . uniqid() . '.png';

		$image = imagecreatetruecolor( $w, $h );
		imagepng( $image, $path );
		imagedestroy( $image );

		$this->temp_files[] = $path;

		return $path;
	}

	public function test_venue_and_city_baselines_clear_the_canvas_bottom_edge(): void {
		[ $venue_baseline, $city_baseline ] = $this->compute_baselines( true, true );

		// City is the last line in this case — it must clear the edge.
		$this->assertLessThanOrEqual(
			self::CANVAS_HEIGHT - self::MIN_CLEARANCE,
			$city_baseline,
			'City baseline must clear the canvas bottom edge with margin — this is the exact defect from issue #853'
		);

		// Venue must sit strictly above the city line (no overlap), and
		// with a sane, non-negative gap between the two baselines.
		$this->assertLessThan( $city_baseline, $venue_baseline, 'Venue baseline must sit above the city baseline' );
		$this->assertGreaterThan( 0, $city_baseline - $venue_baseline, 'Venue and city baselines must not collapse onto each other' );
	}

	public function test_venue_only_baseline_clears_the_canvas_bottom_edge(): void {
		[ $venue_baseline, ] = $this->compute_baselines( true, false );

		$this->assertLessThanOrEqual( self::CANVAS_HEIGHT - self::MIN_CLEARANCE, $venue_baseline );
	}

	public function test_city_only_baseline_clears_the_canvas_bottom_edge(): void {
		[ , $city_baseline ] = $this->compute_baselines( false, true );

		$this->assertLessThanOrEqual( self::CANVAS_HEIGHT - self::MIN_CLEARANCE, $city_baseline );
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

	// -------------------------------------------------------------------
	// Brand mark resolution (#856): pure layout on top of BrandTokens.
	//
	// resolve_logo() here only picks a background-appropriate variant
	// from whatever BrandTokens::get() already resolved and validates
	// it's a readable image — it does NOT implement the explicit-token
	// vs. site-icon fallback chain itself. That resolution now lives in
	// BrandTokens::get() (data-machine), covered by
	// tests/Unit/Abilities/Media/BrandTokensLogoTest.php there, so every
	// GD-rendered template gets it, not just this one. See
	// Extra-Chill/data-machine#3538.
	// -------------------------------------------------------------------

	public function test_is_dark_hex_classifies_light_and_dark_backgrounds(): void {
		$this->assertFalse( $this->invoke_private( 'is_dark_hex', '#ffffff' ) );
		$this->assertFalse( $this->invoke_private( 'is_dark_hex', '#f1f5f9' ) );
		$this->assertTrue( $this->invoke_private( 'is_dark_hex', '#000000' ) );
		$this->assertTrue( $this->invoke_private( 'is_dark_hex', '#0f0f0f' ) );
		// Not a parseable hex color — must not throw, defaults to light.
		$this->assertFalse( $this->invoke_private( 'is_dark_hex', 'not-a-color' ) );
	}

	public function test_fit_within_preserves_aspect_ratio_without_stretching(): void {
		// Wordmark (~1.18:1), height-constrained by the box.
		[ $w, $h ] = $this->invoke_private( 'fit_within', 1088, 923, 220, 88 );
		$this->assertSame( 88, $h, 'Height should hit the box max' );
		$this->assertLessThanOrEqual( 220, $w );
		$this->assertEqualsWithDelta( 1088 / 923, $w / $h, 0.01, 'Aspect ratio must be preserved, not stretched' );

		// Square site icon (1:1) — also height-constrained here, and must
		// stay square (never distorted into a non-square box fit).
		[ $sw, $sh ] = $this->invoke_private( 'fit_within', 1080, 1080, 220, 88 );
		$this->assertSame( $sw, $sh, 'A square source must render as a square' );
		$this->assertSame( 88, $sh );
	}

	public function test_resolve_logo_reads_logo_path_on_a_light_background(): void {
		$token_path = $this->make_temp_png();
		$tokens     = array( 'logo_path' => $token_path );

		$logo = $this->invoke_private( 'resolve_logo', $tokens, false );

		$this->assertNotNull( $logo );
		$this->assertSame( $token_path, $logo['path'] );
		$this->assertSame( 64, $logo['width'] );
		$this->assertSame( 64, $logo['height'] );
	}

	public function test_resolve_logo_returns_null_when_no_token_is_resolved(): void {
		// BrandTokens::get() already tried explicit token + site icon and
		// came back empty — this template's only remaining move is to
		// fall back to text, not fatal.
		$logo = $this->invoke_private( 'resolve_logo', array(), false );

		$this->assertNull( $logo );
	}

	public function test_resolve_logo_ignores_a_token_for_the_wrong_background_variant(): void {
		// Only a light-background token is supplied, but the card
		// background is dark — using it anyway would render invisible
		// dark-on-dark ink. It must fall through, not render blindly.
		$token_path = $this->make_temp_png();
		$tokens     = array( 'logo_path' => $token_path );

		$logo = $this->invoke_private( 'resolve_logo', $tokens, true );

		$this->assertNull( $logo, 'A light-only token must not be used on a dark background' );
	}

	public function test_resolve_logo_uses_the_inverse_token_on_a_dark_background(): void {
		$inverse_path = $this->make_temp_png();
		$tokens       = array(
			'logo_path'         => $this->make_temp_png(),
			'logo_path_inverse' => $inverse_path,
		);

		$logo = $this->invoke_private( 'resolve_logo', $tokens, true );

		$this->assertNotNull( $logo );
		$this->assertSame( $inverse_path, $logo['path'] );
	}

	public function test_resolve_logo_ignores_a_token_path_that_does_not_exist_on_disk(): void {
		// DB/config drift: a token points at a file that is no longer
		// there. Must fall through to text, not fatal — this template has
		// no further fallback level of its own to try.
		$tokens = array( 'logo_path' => '/nonexistent/path/to/logo.png' );
		$logo   = $this->invoke_private( 'resolve_logo', $tokens, false );

		$this->assertNull( $logo );
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
