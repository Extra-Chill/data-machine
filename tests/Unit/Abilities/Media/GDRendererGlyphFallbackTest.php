<?php
/**
 * GDRenderer glyph-coverage fallback tests (issue Extra-Chill/data-machine-events#853).
 *
 * Pure unit tests — no WordPress bootstrap required. GDRenderer's glyph
 * detection reads a font file's `cmap` table directly, so these tests run
 * it against real system/theme font files rather than fixtures, proving
 * the detection matches reality: DejaVu Sans (the system fallback) covers
 * accented Latin but not CJK; the Extra Chill display font (Wilco Loft
 * Sans) covers neither.
 *
 * @package DataMachine\Tests\Unit\Abilities\Media
 */

namespace DataMachine\Tests\Unit\Abilities\Media;

use DataMachine\Abilities\Media\GDRenderer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class GDRendererGlyphFallbackTest extends TestCase {

	private const SYSTEM_FALLBACK_FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

	/**
	 * Extra Chill's display font, used for OG card titles/venue names.
	 * Real production font known (per issue #853) to lack accented Latin
	 * glyphs. Tests skip gracefully if the theme isn't checked out
	 * alongside this repo (e.g. a bare CI checkout of data-machine only).
	 */
	private const DISPLAY_FONT = '/var/www/extrachill.com/wp-content/themes/extrachill/assets/fonts/WilcoLoftSans-Treble.ttf';

	private function has_glyph_coverage( GDRenderer $renderer, string $font_path, int $codepoint ): bool {
		$method = ( new ReflectionClass( $renderer ) )->getMethod( 'has_glyph_coverage' );
		$method->setAccessible( true );
		return $method->invoke( $renderer, $font_path, $codepoint );
	}

	private function split_by_glyph_coverage( GDRenderer $renderer, string $text, string $font_path ): array {
		$method = ( new ReflectionClass( $renderer ) )->getMethod( 'split_by_glyph_coverage' );
		$method->setAccessible( true );
		return $method->invoke( $renderer, $text, $font_path );
	}

	public function test_system_fallback_covers_basic_latin(): void {
		if ( ! is_readable( self::SYSTEM_FALLBACK_FONT ) ) {
			$this->markTestSkipped( 'System DejaVu Sans font not present in this environment.' );
		}

		$renderer = new GDRenderer();

		$this->assertTrue( $this->has_glyph_coverage( $renderer, self::SYSTEM_FALLBACK_FONT, mb_ord( 'A' ) ) );
	}

	public function test_system_fallback_covers_accented_latin(): void {
		if ( ! is_readable( self::SYSTEM_FALLBACK_FONT ) ) {
			$this->markTestSkipped( 'System DejaVu Sans font not present in this environment.' );
		}

		$renderer = new GDRenderer();

		foreach ( array( 'å', 'ä', 'ö', 'é', 'ñ', 'ü', 'ç', 'ø' ) as $char ) {
			$this->assertTrue(
				$this->has_glyph_coverage( $renderer, self::SYSTEM_FALLBACK_FONT, mb_ord( $char ) ),
				"DejaVu Sans should have a glyph for '$char'"
			);
		}
	}

	public function test_system_fallback_lacks_cjk(): void {
		if ( ! is_readable( self::SYSTEM_FALLBACK_FONT ) ) {
			$this->markTestSkipped( 'System DejaVu Sans font not present in this environment.' );
		}

		$renderer = new GDRenderer();

		// DejaVu Sans deliberately excludes CJK — proves detection is
		// reading real coverage, not just "is it ASCII".
		$this->assertFalse( $this->has_glyph_coverage( $renderer, self::SYSTEM_FALLBACK_FONT, mb_ord( '中' ) ) );
	}

	public function test_display_font_lacks_accented_latin_but_not_ascii(): void {
		if ( ! is_readable( self::DISPLAY_FONT ) ) {
			$this->markTestSkipped( 'Extra Chill display font not present in this environment.' );
		}

		$renderer = new GDRenderer();

		$this->assertTrue(
			$this->has_glyph_coverage( $renderer, self::DISPLAY_FONT, mb_ord( 'A' ) ),
			'Display font should cover plain ASCII'
		);
		$this->assertFalse(
			$this->has_glyph_coverage( $renderer, self::DISPLAY_FONT, mb_ord( 'å' ) ),
			'This is the exact defect from issue #853 — the display font renders NO GLYPH for å'
		);
	}

	public function test_unreadable_font_assumes_coverage(): void {
		$renderer = new GDRenderer();

		// A font that can't be introspected should not force every
		// character in every string through fallback.
		$this->assertTrue( $this->has_glyph_coverage( $renderer, '/nonexistent/font.ttf', mb_ord( 'A' ) ) );
	}

	public function test_run_splitting_isolates_uncovered_characters(): void {
		if ( ! is_readable( self::DISPLAY_FONT ) ) {
			$this->markTestSkipped( 'Extra Chill display font not present in this environment.' );
		}

		$renderer = new GDRenderer();

		// Lowercase 'å' — the exact character from issue #853's repro
		// ("Baggelycke Gård"). Notably the display font covers uppercase
		// 'Å' but not lowercase 'å', which is exactly why per-character
		// cmap detection is the correct fix instead of any case-based or
		// character-list heuristic.
		$runs = $this->split_by_glyph_coverage( $renderer, 'Baggelycke Gård', self::DISPLAY_FONT );

		// Reassembling the runs must reproduce the original text exactly.
		$this->assertSame( 'Baggelycke Gård', implode( '', array_column( $runs, 'text' ) ) );

		// At least one run must be routed to the system fallback font —
		// that's what replaces the "NO GLYPH" placeholder with a real å.
		$fallback_runs = array_filter( $runs, static fn( $run ) => self::SYSTEM_FALLBACK_FONT === $run['font'] );
		$this->assertNotEmpty( $fallback_runs, 'Expected the å character to route to the system fallback font' );

		// And no run should be tagged with the display font while
		// containing the uncovered character.
		foreach ( $runs as $run ) {
			if ( self::DISPLAY_FONT === $run['font'] ) {
				$this->assertStringNotContainsString( 'å', $run['text'] );
			}
		}
	}

	public function test_run_splitting_returns_single_run_for_fully_covered_text(): void {
		if ( ! is_readable( self::SYSTEM_FALLBACK_FONT ) ) {
			$this->markTestSkipped( 'System DejaVu Sans font not present in this environment.' );
		}

		$renderer = new GDRenderer();

		$runs = $this->split_by_glyph_coverage( $renderer, 'Lo-Fi Brewing', self::SYSTEM_FALLBACK_FONT );

		$this->assertCount( 1, $runs, 'Fully-covered ASCII text should not be split into runs' );
		$this->assertSame( 'Lo-Fi Brewing', $runs[0]['text'] );
	}
}
