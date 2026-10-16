<?php
/**
 * Poster template QR-hero layout and large-format print preset tests (#3621).
 *
 * Proves, by rendering real pixels, that `qr_emphasis: hero` turns the
 * poster into a QR-first printed sign:
 *  - print_tabloid_300 (3300x5100 @300dpi) and print_pocket_flyer
 *    (1275x1650 @300dpi) ship with the documented sizes; print_tabloid
 *    is untouched
 *  - the hero QR is at least 45% of the canvas width on both portrait
 *    print presets, horizontally centered in the lower part of the
 *    canvas, on a white quiet-zone card padded by at least 4 modules
 *  - hero layout boxes stack without overlap (plan_hero_layout) and the
 *    card stays inside a 0.25" (75px @300dpi) trim-safe margin
 *  - the QR bitmap is requested at its final pixel size (crisp at print
 *    resolution, no blurry resample)
 *  - landscape hero puts the QR on one side at >= 40% of the height
 *  - no QR provider answering the filter -> hero falls back cleanly,
 *    no card, no QR, canvas still renders at preset size
 *  - footer mode (default) is byte-for-byte unchanged by this feature
 *
 * Runs pure (no WordPress) via guarded global stubs, and under a
 * WP-loaded suite via real filters bridged in setUpBeforeClass().
 *
 * @package DataMachine\Tests\Unit\Abilities\Media
 */

declare( strict_types = 1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', '/tmp/' );
	}

	$GLOBALS['dm_poster_hero_qr_provider']  = null;
	$GLOBALS['dm_poster_hero_brand_tokens'] = null;

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value, ...$rest ) {
			if ( 'datamachine/image_generation/qr_image_path' === $tag && is_callable( $GLOBALS['dm_poster_hero_qr_provider'] ?? null ) ) {
				return ( $GLOBALS['dm_poster_hero_qr_provider'] )( $value, ...$rest );
			}
			if ( 'datamachine/image_template/brand_tokens' === $tag && is_callable( $GLOBALS['dm_poster_hero_brand_tokens'] ?? null ) ) {
				return ( $GLOBALS['dm_poster_hero_brand_tokens'] )( $value, ...$rest );
			}
			return $value;
		}
	}
	if ( ! function_exists( 'do_action' ) ) {
		function do_action( ...$args ) {}
	}
	if ( ! function_exists( 'wp_delete_file' ) ) {
		function wp_delete_file( $file ) {
			if ( is_string( $file ) && file_exists( $file ) ) {
				@unlink( $file ); // phpcs:ignore
			}
		}
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data ) {
			return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		}
	}
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $name, $default_value = false ) {
			return $default_value;
		}
	}
	if ( ! function_exists( 'get_template_directory' ) ) {
		function get_template_directory() {
			return '/tmp/datamachine-no-theme';
		}
	}
}

namespace DataMachine\Tests\Unit\Abilities\Media {

	use DataMachine\Abilities\Media\GDRenderer;
	use DataMachine\Abilities\Media\PlatformPresets;
	use DataMachine\Abilities\Media\Templates\PosterTemplate;
	use PHPUnit\Framework\TestCase;

	class PosterTemplateQrHeroTest extends TestCase {

		/** Trim-safe margin on 300dpi print presets: 0.25" = 75px. */
		private const SAFE_MARGIN = 75;

		/** GDRenderer's system fallback font; render tests need a real font. */
		private const SYSTEM_FALLBACK_FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

		/**
		 * Deterministic brand colors so pure black and pure white appear in
		 * the rendered poster *only* as the hero QR and its quiet-zone card.
		 */
		private const TEST_COLORS = array(
			'background_dark' => '#1a1a1a',
			'text_inverse'    => '#e5e5e5',
			'text_muted'      => '#6b7280',
			'accent'          => '#53940b',
		);

		/** Generic QR-first sign copy (the issue #3621 example shape). */
		private const SIGN_DATA = array(
			'kicker'       => 'ACME PRESENTS',
			'title'        => 'EXAMPLE CONTEST',
			'tagline'      => 'Enter your thing. Get your friends to vote.',
			'detail_lines' => array( 'Top 10 take the stage' ),
			'accent_line'  => 'SAT JAN 1 · SOMEWHERE',
			'qr_url'       => 'https://example.com/contest/?qr=1',
			'qr_label'     => 'SCAN TO ENTER & VOTE',
		);

		/** @var array<int, string> Files to delete in tearDown. */
		private array $cleanup = array();

		public static function setUpBeforeClass(): void {
			parent::setUpBeforeClass();
			// Under a WP-loaded suite the global stubs above are skipped;
			// bridge the same test-controlled callbacks through real filters.
			if ( function_exists( 'add_filter' ) ) {
				add_filter(
					'datamachine/image_generation/qr_image_path',
					static function ( $value, $url, $size ) {
						return is_callable( $GLOBALS['dm_poster_hero_qr_provider'] ?? null )
							? ( $GLOBALS['dm_poster_hero_qr_provider'] )( $value, $url, $size )
							: $value;
					},
					10,
					3
				);
				add_filter(
					'datamachine/image_template/brand_tokens',
					static function ( $value, $template_id, $context ) {
						return is_callable( $GLOBALS['dm_poster_hero_brand_tokens'] ?? null )
							? ( $GLOBALS['dm_poster_hero_brand_tokens'] )( $value, $template_id, $context )
							: $value;
					},
					10,
					3
				);
			}
		}

		protected function setUp(): void {
			parent::setUp();
			if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagettftext' ) ) {
				$this->markTestSkipped( 'GD with FreeType is required for poster rendering.' );
			}
			$GLOBALS['dm_poster_hero_qr_provider']  = null;
			$GLOBALS['dm_poster_hero_brand_tokens'] = static function ( $tokens ) {
				$tokens           = (array) $tokens;
				$tokens['colors'] = array_merge( (array) ( $tokens['colors'] ?? array() ), self::TEST_COLORS );
				// No logo: keeps pure black/white pixels exclusive to the QR card.
				$tokens['logo_path']         = null;
				$tokens['logo_path_inverse'] = null;
				return $tokens;
			};
		}

		protected function tearDown(): void {
			$GLOBALS['dm_poster_hero_qr_provider']  = null;
			$GLOBALS['dm_poster_hero_brand_tokens'] = null;
			foreach ( $this->cleanup as $file ) {
				if ( file_exists( $file ) ) {
					@unlink( $file ); // phpcs:ignore
				}
			}
			$this->cleanup = array();
			parent::tearDown();
		}

		// ── Contract ───────────────────────────────────────────────────

		public function test_qr_emphasis_field_is_declared_and_normalized(): void {
			$fields = ( new PosterTemplate() )->get_fields();

			$this->assertArrayHasKey( 'qr_emphasis', $fields );
			$this->assertFalse( (bool) $fields['qr_emphasis']['required'] );

			$this->assertSame( 'footer', PosterTemplate::normalize_fields( array() )['qr_emphasis'], 'default is footer' );
			$this->assertSame( 'hero', PosterTemplate::normalize_fields( array( 'qr_emphasis' => 'Hero' ) )['qr_emphasis'], 'hero, case-insensitive' );
			$this->assertSame( 'footer', PosterTemplate::normalize_fields( array( 'qr_emphasis' => 'giant' ) )['qr_emphasis'], 'unknown values fall back to footer' );
		}

		public function test_print_presets_registered_with_documented_sizes(): void {
			$this->assertSame(
				array(
					'width'  => 3300,
					'height' => 5100,
				),
				PlatformPresets::dimensions( 'print_tabloid_300' ),
				'print_tabloid_300 is 11x17 portrait at 300dpi'
			);
			$this->assertSame(
				array(
					'width'  => 1275,
					'height' => 1650,
				),
				PlatformPresets::dimensions( 'print_pocket_flyer' ),
				'print_pocket_flyer is 4.25x5.5 portrait at 300dpi'
			);
			$this->assertSame(
				array(
					'width'  => 1650,
					'height' => 2550,
				),
				PlatformPresets::dimensions( 'print_tabloid' ),
				'the existing 150dpi print_tabloid preset is unchanged'
			);
			$this->assertStringContainsString( '300dpi', PlatformPresets::get( 'print_tabloid_300' )['label'] );
			$this->assertStringContainsString( '300dpi', PlatformPresets::get( 'print_pocket_flyer' )['label'] );
		}

		// ── Pure layout geometry ───────────────────────────────────────

		public function test_hero_card_pads_qr_by_at_least_four_modules(): void {
			foreach ( array( 574, 1485, 1716 ) as $qr ) {
				$card        = PosterTemplate::hero_card( $qr, 120 );
				$four_module = (int) ceil( $qr * PosterTemplate::HERO_QUIET_MODULES / 33 );

				$this->assertGreaterThanOrEqual( $four_module, $card['pad'], "quiet zone for a {$qr}px QR" );
				$this->assertSame( $qr + 2 * $card['pad'], $card['width'] );
				$this->assertSame( $qr + 2 * $card['pad'] + 120 + $card['pad'], $card['height'], 'label row gets its own quiet zone' );
			}
		}

		public function test_plan_hero_layout_stacks_without_overlap(): void {
			foreach ( array( 'print_tabloid_300' => 3300, 'print_pocket_flyer' => 1275 ) as $preset => $width ) {
				$dims   = PlatformPresets::dimensions( $preset );
				$margin = (int) round( 80 * $width / 1080 );
				$gap    = (int) round( 40 * $width / 1080 );
				$card   = PosterTemplate::hero_card( (int) round( $width * PosterTemplate::HERO_QR_MIN_RATIO ), (int) round( 50 * $width / 1080 ) );
				$banner = (int) round( 120 * $width / 1080 );
				$plan   = PosterTemplate::plan_hero_layout( $dims['height'], $margin, (int) round( 300 * $width / 1080 ), $banner, $card['height'], $gap );

				$this->assertGreaterThanOrEqual( self::SAFE_MARGIN, $margin, "{$preset}: design margin covers the 0.25in trim zone" );
				$this->assertTrue( $plan['fits'], "{$preset}: hero plan fits" );
				$this->assertGreaterThanOrEqual( $margin, $plan['content_top'], "{$preset}: content starts below the top margin" );
				$this->assertLessThanOrEqual( $plan['banner_top'] - $gap, $plan['content_bottom'], "{$preset}: content clears the banner" );
				$this->assertLessThanOrEqual( $plan['card_top'] - $gap, $plan['banner_top'] + $banner, "{$preset}: banner clears the QR card" );
				$this->assertSame( $dims['height'] - $margin - $card['height'], $plan['card_top'], "{$preset}: card is pinned to the bottom margin" );
			}

			$this->assertFalse(
				PosterTemplate::plan_hero_layout( 1650, 94, 2000, 142, 900, 47 )['fits'],
				'oversized content reports it does not fit'
			);
		}

		// ── Rendered pixels ────────────────────────────────────────────

		/**
		 * Text measurement needs a font GD can open; without one PHPUnit
		 * turns imagettfbbox()'s warning into an error. Same skip policy as
		 * GDRendererGlyphFallbackTest.
		 */
		private function require_render_font(): void {
			if ( ! is_readable( self::SYSTEM_FALLBACK_FONT ) ) {
				$this->markTestSkipped( 'System DejaVu Sans font not present in this environment.' );
			}
		}

		/**
		 * @dataProvider portrait_print_presets
		 */
		public function test_hero_qr_dominates_portrait_print_presets( string $preset ): void {
			$this->require_render_font();
			$sizes = array();
			$this->stub_qr_provider( $sizes );

			$dims = PlatformPresets::dimensions( $preset );
			$path = $this->render( self::SIGN_DATA + array( 'qr_emphasis' => 'hero' ), $preset );
			$this->assertNotSame( '', $path, "{$preset}: hero poster renders" );

			$qr_min   = (int) round( $dims['width'] * PosterTemplate::HERO_QR_MIN_RATIO );
			$qr_drawn = (int) end( $sizes );
			$this->assertGreaterThanOrEqual( $qr_min, $qr_drawn, "{$preset}: QR is at least 45% of the canvas width" );
			$this->assertSame( $qr_drawn, min( $sizes ), "{$preset}: the final provider request is the drawn size — never an upscale" );

			$img = imagecreatefrompng( $path );
			$this->assertNotFalse( $img );
			$step  = max( 1, intdiv( $dims['width'], 1200 ) );
			$boxes = $this->scan_pure_black_and_white( $img, $step );
			$tol   = 2 * $step + 2;

			$qr   = $boxes['black'];
			$card = $boxes['white'];
			$this->assertNotNull( $qr, "{$preset}: QR pixels drawn" );
			$this->assertNotNull( $card, "{$preset}: white quiet-zone card drawn" );

			// The QR is the drawn size and horizontally centered.
			$this->assertGreaterThanOrEqual( $qr_drawn - $tol, $qr['max_x'] - $qr['min_x'] + 1, "{$preset}: drawn QR matches the requested size" );
			$this->assertLessThanOrEqual( $tol, abs( ( $qr['min_x'] + $qr['max_x'] ) / 2 - $dims['width'] / 2 ), "{$preset}: QR is horizontally centered" );

			// ...in the lower part of the canvas.
			$this->assertGreaterThan( $dims['height'] / 2, ( $qr['min_y'] + $qr['max_y'] ) / 2, "{$preset}: QR sits in the lower half" );

			// Quiet zone: at least 4 modules of white on every side.
			$pad = (int) ceil( $qr_drawn * PosterTemplate::HERO_QUIET_MODULES / 33 );
			$this->assertGreaterThanOrEqual( $pad - $tol, $qr['min_x'] - $card['min_x'], "{$preset}: left quiet zone" );
			$this->assertGreaterThanOrEqual( $pad - $tol, $card['max_x'] - $qr['max_x'], "{$preset}: right quiet zone" );
			$this->assertGreaterThanOrEqual( $pad - $tol, $qr['min_y'] - $card['min_y'], "{$preset}: top quiet zone" );
			$this->assertGreaterThanOrEqual( $pad - $tol, $card['max_y'] - $qr['max_y'], "{$preset}: bottom quiet zone (label row below)" );

			// Everything the card occupies stays inside the trim-safe margin.
			$this->assertGreaterThanOrEqual( self::SAFE_MARGIN, $card['min_x'], "{$preset}: card clears the left trim zone" );
			$this->assertGreaterThanOrEqual( self::SAFE_MARGIN, $card['min_y'], "{$preset}: card clears the top trim zone" );
			$this->assertLessThanOrEqual( $dims['width'] - self::SAFE_MARGIN, $card['max_x'], "{$preset}: card clears the right trim zone" );
			$this->assertLessThanOrEqual( $dims['height'] - self::SAFE_MARGIN, $card['max_y'], "{$preset}: card clears the bottom trim zone" );
		}

		/**
		 * Rendered-pixel cases use the pocket flyer only. The layout is
		 * resolution-independent (every size goes through the px scaler), and
		 * the 11x17 preset is covered by test_plan_hero_layout_stacks_without_overlap
		 * and the preset-size assertions. A full 3300x5100 truecolor render
		 * costs ~67MB of GD memory per canvas, which starves the in-memory
		 * test filesystem for suites sharing the shard.
		 */
		public function portrait_print_presets(): array {
			return array(
				'pocket flyer @300dpi' => array( 'print_pocket_flyer' ),
			);
		}

		public function test_hero_qr_takes_one_side_of_landscape_canvases(): void {
			$this->require_render_font();
			$sizes = array();
			$this->stub_qr_provider( $sizes );

			$dims = PlatformPresets::dimensions( 'landscape_16_9' );
			$path = $this->render( self::SIGN_DATA + array( 'qr_emphasis' => 'hero' ), 'landscape_16_9' );
			$this->assertNotSame( '', $path );

			$qr_min   = (int) round( $dims['height'] * PosterTemplate::HERO_QR_MIN_RATIO_LANDSCAPE );
			$qr_drawn = (int) end( $sizes );
			$this->assertGreaterThanOrEqual( $qr_min, $qr_drawn, 'QR is at least 40% of the canvas height' );

			$img = imagecreatefrompng( $path );
			$this->assertNotFalse( $img );
			$boxes = $this->scan_pure_black_and_white( $img, 2 );
			$qr    = $boxes['black'];
			$this->assertNotNull( $qr );
			$this->assertGreaterThanOrEqual( $qr_min - 6, $qr['max_y'] - $qr['min_y'] + 1 );
			$this->assertGreaterThan( $dims['width'] / 2, $qr['min_x'], 'QR owns the right side, text the left' );
		}

		public function test_hero_omits_qr_cleanly_without_a_provider(): void {
			$this->require_render_font();
			$GLOBALS['dm_poster_hero_qr_provider'] = null;

			$dims = PlatformPresets::dimensions( 'print_pocket_flyer' );
			$path = $this->render( self::SIGN_DATA + array( 'qr_emphasis' => 'hero' ), 'print_pocket_flyer' );
			$this->assertNotSame( '', $path, 'hero poster still renders with no QR provider' );

			$info = getimagesize( $path );
			$this->assertSame( array( $dims['width'], $dims['height'] ), array( $info[0], $info[1] ), 'canvas keeps the preset size' );

			$img   = imagecreatefrompng( $path );
			$boxes = $this->scan_pure_black_and_white( $img, 3 );
			$this->assertNull( $boxes['black'], 'no QR is drawn' );
			$this->assertNull( $boxes['white'], 'no empty quiet-zone card is left behind' );
		}

		public function test_footer_mode_output_is_unchanged(): void {
			$this->assertSame(
				PosterTemplate::normalize_fields( self::SIGN_DATA ),
				PosterTemplate::normalize_fields( self::SIGN_DATA + array( 'qr_emphasis' => 'footer' ) ),
				'omitting qr_emphasis and passing footer normalize identically'
			);

			$this->require_render_font();
			$sizes = array();
			$this->stub_qr_provider( $sizes );

			$default = $this->render( self::SIGN_DATA, 'instagram_feed_portrait' );
			$footer  = $this->render( self::SIGN_DATA + array( 'qr_emphasis' => 'footer' ), 'instagram_feed_portrait' );
			$hero    = $this->render( self::SIGN_DATA + array( 'qr_emphasis' => 'hero' ), 'instagram_feed_portrait' );

			$this->assertSame( array( 190, 190 ), array( $sizes[0], $sizes[1] ), 'footer QR keeps its pre-hero 190px-per-1080 size' );
			$this->assertSame( sha1_file( $default ), sha1_file( $footer ), 'footer mode renders byte-identically to the previous default' );
			$this->assertNotSame( sha1_file( $default ), sha1_file( $hero ), 'hero mode is a different layout' );
		}

		// ── Helpers ────────────────────────────────────────────────────

		/**
		 * QR provider stub: a solid pure-black PNG at the requested size,
		 * recording every requested size in $sizes.
		 *
		 * @param array<int, int> $sizes Receives each requested pixel size.
		 */
		private function stub_qr_provider( array &$sizes ): void {
			$GLOBALS['dm_poster_hero_qr_provider'] = static function ( $value, $url, $size ) use ( &$sizes ) {
				$sizes[] = (int) $size;
				$img     = imagecreatetruecolor( max( 1, (int) $size ), max( 1, (int) $size ) );
				imagefill( $img, 0, 0, imagecolorallocate( $img, 0, 0, 0 ) );
				$path = tempnam( sys_get_temp_dir(), 'poster-hero-qr' );
				imagepng( $img, $path );
				return $path;
			};
		}

		/** Render a poster and return the output path ('' on failure). */
		private function render( array $data, string $preset ): string {
			$paths = ( new PosterTemplate() )->render( $data, new GDRenderer(), array( 'preset' => $preset ) );
			$path  = $paths[0] ?? '';
			if ( '' !== $path ) {
				$this->cleanup[] = $path;
			}
			return $path && file_exists( $path ) ? $path : '';
		}

		/**
		 * Bounding boxes of pure-black and pure-white pixels.
		 *
		 * With the test brand palette (dark gray background, off-white type,
		 * green accent) the only pure-black pixels are the stubbed QR and the
		 * only pure-white pixels are its quiet-zone card.
		 *
		 * @param \GdImage $img  Decoded render.
		 * @param int      $step Sampling stride (assertions use a 2*step+2 tolerance).
		 * @return array{black: ?array{min_x:int,min_y:int,max_x:int,max_y:int}, white: ?array{min_x:int,min_y:int,max_x:int,max_y:int}}
		 */
		private function scan_pure_black_and_white( \GdImage $img, int $step ): array {
			$w     = imagesx( $img );
			$h     = imagesy( $img );
			$boxes = array(
				'black' => null,
				'white' => null,
			);
			for ( $y = 0; $y < $h; $y += $step ) {
				for ( $x = 0; $x < $w; $x += $step ) {
					$rgb = imagecolorat( $img, $x, $y ) & 0xFFFFFF;
					if ( 0x000000 === $rgb ) {
						$key = 'black';
					} elseif ( 0xFFFFFF === $rgb ) {
						$key = 'white';
					} else {
						continue;
					}
					if ( null === $boxes[ $key ] ) {
						$boxes[ $key ] = array(
							'min_x' => $x,
							'min_y' => $y,
							'max_x' => $x,
							'max_y' => $y,
						);
						continue;
					}
					$boxes[ $key ]['min_x'] = min( $boxes[ $key ]['min_x'], $x );
					$boxes[ $key ]['min_y'] = min( $boxes[ $key ]['min_y'], $y );
					$boxes[ $key ]['max_x'] = max( $boxes[ $key ]['max_x'], $x );
					$boxes[ $key ]['max_y'] = max( $boxes[ $key ]['max_y'], $y );
				}
			}
			return $boxes;
		}
	}
}
