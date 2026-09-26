<?php
/**
 * GD Renderer — shared utilities for template-based image generation.
 *
 * Handles canvas creation, font loading, text rendering, color management,
 * and file output. Templates use this renderer for all GD operations so
 * they can focus on layout logic.
 *
 * Extracted and generalized from extrachill-events SlideGenerator.
 *
 * @package DataMachine\Abilities\Media
 * @since 0.32.0
 */

namespace DataMachine\Abilities\Media;

use DataMachine\Core\FilesRepository\FileStorage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GDRenderer {

	/**
	 * Default line height multiplier for text rendering.
	 */
	private const DEFAULT_LINE_HEIGHT = 1.4;

	/**
	 * System-wide fallback font. Used both when a requested font file
	 * cannot be resolved at all (register_font()) and per-character when
	 * a resolved font's `cmap` table has no real glyph for a specific
	 * codepoint (draw_text()). DejaVu Sans has broad Unicode coverage and
	 * is the font already relied on elsewhere in this class, so reusing
	 * it here keeps the fallback story to a single font instead of
	 * introducing a second dependency.
	 */
	private const SYSTEM_FALLBACK_FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

	/**
	 * Current GD image resource.
	 *
	 * @var \GdImage|null
	 */
	private ?\GdImage $image = null;

	/**
	 * Canvas width.
	 *
	 * @var int
	 */
	private int $width = 0;

	/**
	 * Canvas height.
	 *
	 * @var int
	 */
	private int $height = 0;

	/**
	 * Resolved font paths cache.
	 *
	 * @var array<string, string>
	 */
	private array $fonts = array();

	/**
	 * Allocated color cache.
	 *
	 * @var array<string, int>
	 */
	private array $colors = array();

	/**
	 * Parsed `cmap` glyph-coverage cache, keyed by font path. Shared across
	 * instances (static) because font files are immutable for the life of
	 * the process and re-parsing per-instance buys nothing.
	 *
	 * @var array<string, array|null>
	 */
	private static array $glyph_coverage_cache = array();

	// -------------------------------------------------------------------------
	// Canvas
	// -------------------------------------------------------------------------

	/**
	 * Create a new canvas.
	 *
	 * Accepts explicit dimensions or a platform preset name.
	 *
	 * @param int|string $width_or_preset Width in pixels, or a PlatformPresets name.
	 * @param int        $height          Height in pixels (ignored if preset used).
	 * @return self
	 */
	public function create_canvas( int|string $width_or_preset, int $height = 0 ): self {
		if ( is_string( $width_or_preset ) ) {
			$dims = PlatformPresets::dimensions( $width_or_preset );
			if ( ! $dims ) {
				do_action(
					'datamachine_log',
					'error',
					sprintf( 'GDRenderer: Unknown platform preset "%s"', $width_or_preset )
				);
				return $this;
			}
			$this->width  = $dims['width'];
			$this->height = $dims['height'];
		} else {
			$this->width  = $width_or_preset;
			$this->height = $height;
		}

		$this->image  = imagecreatetruecolor( $this->width, $this->height );
		$this->colors = array();

		if ( ! $this->image ) {
			do_action( 'datamachine_log', 'error', 'GDRenderer: Failed to create canvas' );
		}

		return $this;
	}

	/**
	 * Get the current GD image resource.
	 *
	 * @return \GdImage|null
	 */
	public function get_image(): ?\GdImage {
		return $this->image;
	}

	/**
	 * Get canvas width.
	 *
	 * @return int
	 */
	public function get_width(): int {
		return $this->width;
	}

	/**
	 * Get canvas height.
	 *
	 * @return int
	 */
	public function get_height(): int {
		return $this->height;
	}

	// -------------------------------------------------------------------------
	// Colors
	// -------------------------------------------------------------------------

	/**
	 * Allocate a color on the current canvas.
	 *
	 * Caches by name so repeated calls return the same allocated color.
	 *
	 * @param string $name  Color name for caching.
	 * @param int    $red   Red (0-255).
	 * @param int    $green Green (0-255).
	 * @param int    $blue  Blue (0-255).
	 * @param int    $alpha Alpha (0 = opaque, 127 = fully transparent).
	 * @return int GD color identifier.
	 */
	public function color( string $name, int $red, int $green, int $blue, int $alpha = 0 ): int {
		if ( isset( $this->colors[ $name ] ) ) {
			return $this->colors[ $name ];
		}

		if ( ! $this->image ) {
			return 0;
		}

		if ( $alpha > 0 ) {
			$color = imagecolorallocatealpha( $this->image, $red, $green, $blue, $alpha );
		} else {
			$color = imagecolorallocate( $this->image, $red, $green, $blue );
		}

		$this->colors[ $name ] = $color;

		return $color;
	}

	/**
	 * Allocate a color from a hex string.
	 *
	 * @param string $name Color name for caching.
	 * @param string $hex  Hex color (e.g. '#ff6b6b' or 'ff6b6b').
	 * @return int GD color identifier.
	 */
	public function color_hex( string $name, string $hex ): int {
		$hex = ltrim( $hex, '#' );
		$r   = hexdec( substr( $hex, 0, 2 ) );
		$g   = hexdec( substr( $hex, 2, 2 ) );
		$b   = hexdec( substr( $hex, 4, 2 ) );

		return $this->color( $name, $r, $g, $b );
	}

	/**
	 * Allocate a color from an RGB array.
	 *
	 * @param string $name  Color name for caching.
	 * @param int[]  $rgb   Array of [red, green, blue].
	 * @return int GD color identifier.
	 */
	public function color_rgb( string $name, array $rgb ): int {
		return $this->color( $name, $rgb[0] ?? 0, $rgb[1] ?? 0, $rgb[2] ?? 0 );
	}

	// -------------------------------------------------------------------------
	// Fill & Shapes
	// -------------------------------------------------------------------------

	/**
	 * Fill the entire canvas with a color.
	 *
	 * @param int $color GD color identifier.
	 * @return self
	 */
	public function fill( int $color ): self {
		if ( $this->image ) {
			imagefill( $this->image, 0, 0, $color );
		}
		return $this;
	}

	/**
	 * Draw a filled rectangle.
	 *
	 * @param int $x1    Top-left X.
	 * @param int $y1    Top-left Y.
	 * @param int $x2    Bottom-right X.
	 * @param int $y2    Bottom-right Y.
	 * @param int $color GD color identifier.
	 * @return self
	 */
	public function filled_rect( int $x1, int $y1, int $x2, int $y2, int $color ): self {
		if ( $this->image ) {
			imagefilledrectangle( $this->image, $x1, $y1, $x2, $y2, $color );
		}
		return $this;
	}

	// -------------------------------------------------------------------------
	// Fonts
	// -------------------------------------------------------------------------

	/**
	 * Register a font by name.
	 *
	 * Resolves the font path from common locations:
	 * 1. Explicit absolute path (if file exists)
	 * 2. Active theme's assets/fonts/ directory
	 * 3. System fallback (DejaVu Sans)
	 *
	 * @param string $name     Font name for reference (e.g. 'header', 'body').
	 * @param string $path_or_filename Absolute path or filename to look for in theme fonts.
	 * @return string Resolved absolute font path.
	 */
	public function register_font( string $name, string $path_or_filename ): string {
		// Absolute path provided.
		if ( str_starts_with( $path_or_filename, '/' ) && file_exists( $path_or_filename ) ) {
			$this->fonts[ $name ] = $path_or_filename;
			return $path_or_filename;
		}

		// Look in active theme fonts directory.
		$theme_path = get_template_directory() . '/assets/fonts/' . $path_or_filename;
		if ( file_exists( $theme_path ) ) {
			$this->fonts[ $name ] = $theme_path;
			return $theme_path;
		}

		// System fallback.
		$fallback             = self::SYSTEM_FALLBACK_FONT;
		$this->fonts[ $name ] = $fallback;

		do_action(
			'datamachine_log',
			'warning',
			sprintf( 'GDRenderer: Font "%s" not found at "%s", using system fallback', $name, $path_or_filename ),
			array(
				'font_name' => $name,
				'requested' => $path_or_filename,
			)
		);

		return $fallback;
	}

	/**
	 * Get a registered font path.
	 *
	 * @param string $name Font name.
	 * @return string Font path (falls back to system font if not registered).
	 */
	public function get_font( string $name ): string {
		return $this->fonts[ $name ] ?? self::SYSTEM_FALLBACK_FONT;
	}

	// -------------------------------------------------------------------------
	// Text Rendering
	// -------------------------------------------------------------------------

	/**
	 * Draw text on the canvas.
	 *
	 * Splits the text into runs based on whether the requested font has a
	 * real glyph for each character (see has_glyph_coverage()). Characters
	 * the font can't render fall through to the system fallback font
	 * instead of drawing that font's `.notdef` placeholder glyph. No
	 * character list is involved — coverage is read directly from each
	 * font's `cmap` table, so this applies uniformly to accented Latin,
	 * Cyrillic, CJK, emoji, or anything else a caller passes in.
	 *
	 * @param string $text      Text to render.
	 * @param int    $font_size Font size in points.
	 * @param int    $x         X position.
	 * @param int    $y         Y position (baseline).
	 * @param int    $color     GD color identifier.
	 * @param string $font_name Registered font name.
	 * @param float  $angle     Text angle in degrees (default 0).
	 * @return self
	 */
	public function draw_text( string $text, int $font_size, int $x, int $y, int $color, string $font_name, float $angle = 0 ): self {
		if ( ! $this->image || '' === $text ) {
			return $this;
		}

		$font_path = $this->get_font( $font_name );

		// Angled text draws in a single imagettftext() call — run-splitting
		// assumes a horizontal baseline for advancing $x between runs, which
		// doesn't hold once the text is rotated. No caller currently passes
		// non-ASCII text through an angled draw, so skip fallback there
		// rather than getting rotated-run positioning wrong.
		if ( 0.0 !== $angle ) {
			imagettftext( $this->image, $font_size, $angle, $x, $y, $color, $font_path, $text );
			return $this;
		}

		foreach ( $this->split_by_glyph_coverage( $text, $font_path ) as $run ) {
			imagettftext( $this->image, $font_size, 0, $x, $y, $color, $run['font'], $run['text'] );
			$x += $this->bbox_width( imagettfbbox( $font_size, 0, $run['font'], $run['text'] ) );
		}

		return $this;
	}

	/**
	 * Width in pixels between an `imagettfbbox()` result's left and right
	 * extents. `imagettfbbox()` is typed to return `array|false` (it can
	 * fail to open the requested font file), so this centralizes the
	 * false-safe read instead of indexing the result directly at each
	 * call site.
	 *
	 * @param array|false $bbox Return value of imagettfbbox().
	 * @return int
	 */
	private function bbox_width( array|false $bbox ): int {
		if ( false === $bbox ) {
			return 0;
		}
		return (int) abs( $bbox[4] - $bbox[0] );
	}

	/**
	 * Split text into runs of consecutive characters grouped by whether
	 * `$font_path` has a real glyph for each one.
	 *
	 * @param string $text      Text to split.
	 * @param string $font_path Primary font path.
	 * @return array<int, array{text:string, font:string}> Ordered runs, each
	 *         tagged with the font path that should render it.
	 */
	private function split_by_glyph_coverage( string $text, string $font_path ): array {
		// The system fallback font has nothing further to fall back to.
		if ( self::SYSTEM_FALLBACK_FONT === $font_path ) {
			return array(
				array(
					'text' => $text,
					'font' => $font_path,
				),
			);
		}

		$chars                  = mb_str_split( $text );
		$runs                   = array();
		$current                = '';
		$current_needs_fallback = null;

		foreach ( $chars as $char ) {
			$codepoint      = mb_ord( $char );
			$needs_fallback = ! $this->has_glyph_coverage( $font_path, $codepoint );

			if ( null === $current_needs_fallback || $needs_fallback === $current_needs_fallback ) {
				$current                = $current . $char;
				$current_needs_fallback = $needs_fallback;
				continue;
			}

			$runs[]                 = array(
				'text' => $current,
				'font' => $current_needs_fallback ? self::SYSTEM_FALLBACK_FONT : $font_path,
			);
			$current                = $char;
			$current_needs_fallback = $needs_fallback;
		}

		if ( '' !== $current ) {
			$runs[] = array(
				'text' => $current,
				'font' => $current_needs_fallback ? self::SYSTEM_FALLBACK_FONT : $font_path,
			);
		}

		return $runs;
	}

	/**
	 * Read a big-endian unsigned 16-bit integer from a byte string.
	 *
	 * `unpack()` is typed `array|false` (it can fail on a malformed format
	 * string, never on the fixed literal formats used here, but PHPStan
	 * doesn't know that) — centralizing the read here means every cmap
	 * parsing call site gets a plain `int` back instead of repeating a
	 * false-check at each of the ~20 unpack() call sites this parser needs.
	 *
	 * @param string $data   Byte string to read from.
	 * @param int    $offset Byte offset to read at.
	 * @return int 0 when the offset is out of range or unpack() fails.
	 */
	private function read_uint16( string $data, int $offset ): int {
		if ( $offset < 0 || $offset + 2 > strlen( $data ) ) {
			return 0;
		}
		$unpacked = unpack( 'n', substr( $data, $offset, 2 ) );
		return false !== $unpacked ? (int) $unpacked[1] : 0;
	}

	/**
	 * Read a big-endian unsigned 32-bit integer from a byte string.
	 *
	 * @param string $data   Byte string to read from.
	 * @param int    $offset Byte offset to read at.
	 * @return int 0 when the offset is out of range or unpack() fails.
	 */
	private function read_uint32( string $data, int $offset ): int {
		if ( $offset < 0 || $offset + 4 > strlen( $data ) ) {
			return 0;
		}
		$unpacked = unpack( 'N', substr( $data, $offset, 4 ) );
		return false !== $unpacked ? (int) $unpacked[1] : 0;
	}

	/**
	 * Read a big-endian signed 16-bit integer from a byte string.
	 *
	 * Used for cmap format 4's idDelta field, which the TrueType spec
	 * defines as signed even though it's stored as a plain uint16 on
	 * disk — the two's-complement conversion happens here.
	 *
	 * @param string $data   Byte string to read from.
	 * @param int    $offset Byte offset to read at.
	 * @return int
	 */
	private function read_int16( string $data, int $offset ): int {
		$value = $this->read_uint16( $data, $offset );
		return $value > 0x7FFF ? $value - 0x10000 : $value;
	}

	/**
	 * Whether a font file has a real (non-`.notdef`) glyph for a codepoint.
	 *
	 * Reads the font's own `cmap` table rather than checking the codepoint
	 * against any hardcoded character list, so coverage is detected for
	 * whatever the font actually supports — which varies per display font
	 * and isn't reliably predictable from a Unicode range alone.
	 *
	 * @param string $font_path Absolute path to a TTF/OTF font file.
	 * @param int    $codepoint Unicode codepoint.
	 * @return bool
	 */
	private function has_glyph_coverage( string $font_path, int $codepoint ): bool {
		if ( ! array_key_exists( $font_path, self::$glyph_coverage_cache ) ) {
			self::$glyph_coverage_cache[ $font_path ] = $this->parse_font_cmap( $font_path );
		}

		$table = self::$glyph_coverage_cache[ $font_path ];

		// Unparseable font (unreadable, malformed, unsupported cmap
		// format) — assume coverage rather than forcing every character
		// in every string through fallback because introspection failed.
		if ( null === $table ) {
			return true;
		}

		if ( 12 === $table['format'] ) {
			foreach ( $table['groups'] as $group ) {
				if ( $codepoint >= $group[0] && $codepoint <= $group[1] ) {
					return true;
				}
			}
			return false;
		}

		foreach ( $table['segments'] as $segment ) {
			[ $start, $end, $delta, $range_offset, $range_offset_pos ] = $segment;

			if ( $codepoint < $start || $codepoint > $end ) {
				continue;
			}

			if ( 0 === $range_offset ) {
				return 0 !== ( ( $codepoint + $delta ) & 0xFFFF );
			}

			// Indirect lookup via glyphIdArray, per the TrueType format 4
			// cmap subtable spec: glyphIndexAddress = address-of-
			// idRangeOffset[i] + idRangeOffset[i] + 2 * (c - startCode[i]).
			$glyph_index_address = $range_offset_pos + $range_offset + ( ( $codepoint - $start ) * 2 );
			$raw                 = $table['raw'];

			if ( $glyph_index_address < 0 || $glyph_index_address + 1 >= strlen( $raw ) ) {
				return false;
			}

			$glyph_id = $this->read_uint16( $raw, $glyph_index_address );

			if ( 0 === $glyph_id ) {
				return false;
			}

			return 0 !== ( ( $glyph_id + $delta ) & 0xFFFF );
		}

		return false;
	}

	/**
	 * Locate and parse a font file's best available `cmap` subtable.
	 *
	 * Supports format 4 (BMP segments) and format 12 (full-Unicode
	 * groups) — between them, effectively every TrueType/OpenType font.
	 * Works for both TTF and OTF/CFF files since `cmap` lives in the same
	 * sfnt wrapper regardless of outline format.
	 *
	 * @param string $font_path Absolute path to a font file.
	 * @return array|null Parsed table, or null if unreadable/unsupported.
	 */
	private function parse_font_cmap( string $font_path ): ?array {
		if ( ! is_readable( $font_path ) ) {
			return null;
		}

		$data = file_get_contents( $font_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local font file already resolved by register_font(), not a remote request.
		if ( false === $data || strlen( $data ) < 12 ) {
			return null;
		}

		$num_tables  = $this->read_uint16( $data, 4 );
		$cmap_offset = null;

		for ( $i = 0; $i < $num_tables; $i++ ) {
			$record_offset = 12 + ( $i * 16 );
			if ( $record_offset + 16 > strlen( $data ) ) {
				break;
			}
			if ( 'cmap' === substr( $data, $record_offset, 4 ) ) {
				$cmap_offset = $this->read_uint32( $data, $record_offset + 8 );
				break;
			}
		}

		if ( null === $cmap_offset || $cmap_offset + 4 > strlen( $data ) ) {
			return null;
		}

		$sub_table_count = $this->read_uint16( $data, $cmap_offset + 2 );
		$best_offset     = null;
		$best_score      = -1;

		for ( $i = 0; $i < $sub_table_count; $i++ ) {
			$record_offset = $cmap_offset + 4 + ( $i * 8 );
			if ( $record_offset + 8 > strlen( $data ) ) {
				break;
			}

			$platform_id = $this->read_uint16( $data, $record_offset );
			$encoding_id = $this->read_uint16( $data, $record_offset + 2 );
			$sub_offset  = $this->read_uint32( $data, $record_offset + 4 );

			// Prefer full-Unicode subtables (format 12) over BMP-only
			// (format 4), and Windows/Unicode platforms over others.
			$score = match ( true ) {
				3 === $platform_id && 10 === $encoding_id => 4,
				0 === $platform_id && $encoding_id >= 4 => 3,
				3 === $platform_id && 1 === $encoding_id => 2,
				0 === $platform_id => 1,
				default => 0,
			};

			if ( $score > $best_score ) {
				$best_score  = $score;
				$best_offset = $cmap_offset + $sub_offset;
			}
		}

		if ( null === $best_offset || $best_offset + 2 > strlen( $data ) ) {
			return null;
		}

		$format = $this->read_uint16( $data, $best_offset );

		if ( 12 === $format ) {
			return $this->parse_cmap_format_12( $data, $best_offset );
		}

		if ( 4 === $format ) {
			return $this->parse_cmap_format_4( $data, $best_offset );
		}

		return null;
	}

	/**
	 * Parse a cmap format 4 (segment mapping to delta values) subtable.
	 *
	 * @param string $data   Full font file contents.
	 * @param int    $offset Byte offset of the subtable within $data.
	 * @return array|null
	 */
	private function parse_cmap_format_4( string $data, int $offset ): ?array {
		if ( $offset + 14 > strlen( $data ) ) {
			return null;
		}

		$seg_count_x2 = $this->read_uint16( $data, $offset + 6 );
		$seg_count    = intdiv( $seg_count_x2, 2 );

		$end_codes_offset       = $offset + 14;
		$start_codes_offset     = $end_codes_offset + $seg_count_x2 + 2; // +2 skips reservedPad.
		$id_delta_offset        = $start_codes_offset + $seg_count_x2;
		$id_range_offset_offset = $id_delta_offset + $seg_count_x2;

		if ( $id_range_offset_offset + $seg_count_x2 > strlen( $data ) ) {
			return null;
		}

		$segments = array();

		for ( $i = 0; $i < $seg_count; $i++ ) {
			$end   = $this->read_uint16( $data, $end_codes_offset + ( $i * 2 ) );
			$start = $this->read_uint16( $data, $start_codes_offset + ( $i * 2 ) );
			$delta = $this->read_int16( $data, $id_delta_offset + ( $i * 2 ) );

			$range_offset_pos = $id_range_offset_offset + ( $i * 2 );
			$range_offset     = $this->read_uint16( $data, $range_offset_pos );

			if ( 0xFFFF === $start && 0xFFFF === $end ) {
				continue; // Terminal sentinel segment carries no real mapping.
			}

			// Store the range-offset field's position relative to the
			// subtable start so has_glyph_coverage() can resolve indirect
			// glyphIdArray lookups against the cached 'raw' bytes.
			$segments[] = array( $start, $end, $delta, $range_offset, $range_offset_pos - $offset );
		}

		return array(
			'format'   => 4,
			'segments' => $segments,
			'raw'      => substr( $data, $offset ),
		);
	}

	/**
	 * Parse a cmap format 12 (segmented coverage) subtable.
	 *
	 * @param string $data   Full font file contents.
	 * @param int    $offset Byte offset of the subtable within $data.
	 * @return array|null
	 */
	private function parse_cmap_format_12( string $data, int $offset ): ?array {
		if ( $offset + 16 > strlen( $data ) ) {
			return null;
		}

		$num_groups = $this->read_uint32( $data, $offset + 12 );
		$groups     = array();

		for ( $i = 0; $i < $num_groups; $i++ ) {
			$group_offset = $offset + 16 + ( $i * 12 );
			if ( $group_offset + 8 > strlen( $data ) ) {
				break;
			}
			$groups[] = array(
				$this->read_uint32( $data, $group_offset ),
				$this->read_uint32( $data, $group_offset + 4 ),
			);
		}

		return array(
			'format' => 12,
			'groups' => $groups,
		);
	}

	/**
	 * Draw text centered horizontally on the canvas.
	 *
	 * @param string $text      Text to render.
	 * @param int    $font_size Font size in points.
	 * @param int    $y         Y position (baseline).
	 * @param int    $color     GD color identifier.
	 * @param string $font_name Registered font name.
	 * @return self
	 */
	public function draw_text_centered( string $text, int $font_size, int $y, int $color, string $font_name ): self {
		$bbox       = imagettfbbox( $font_size, 0, $this->get_font( $font_name ), $text );
		$text_width = abs( $bbox[4] - $bbox[0] );
		$x          = (int) ( ( $this->width - $text_width ) / 2 );

		return $this->draw_text( $text, $font_size, $x, $y, $color, $font_name );
	}

	/**
	 * Draw wrapped text within a max width.
	 *
	 * Returns the Y position after the last line for layout chaining.
	 *
	 * @param string $text        Text to render.
	 * @param int    $font_size   Font size in points.
	 * @param int    $x           X position.
	 * @param int    $y           Y position (top of first line, not baseline).
	 * @param int    $color       GD color identifier.
	 * @param string $font_name   Registered font name.
	 * @param int    $max_width   Maximum text width in pixels.
	 * @param float  $line_height Line height multiplier (default 1.4).
	 * @param string $align       Text alignment: 'left', 'center', or 'right'.
	 * @return int Y position after the last rendered line.
	 */
	public function draw_text_wrapped( string $text, int $font_size, int $x, int $y, int $color, string $font_name, int $max_width, float $line_height = self::DEFAULT_LINE_HEIGHT, string $align = 'left' ): int {
		$lines          = $this->wrap_text( $text, $font_size, $font_name, $max_width );
		$line_height_px = (int) ( $font_size * $line_height );

		foreach ( $lines as $line ) {
			$draw_x = $x;

			if ( 'center' === $align || 'right' === $align ) {
				$bbox       = imagettfbbox( $font_size, 0, $this->get_font( $font_name ), $line );
				$line_width = abs( $bbox[4] - $bbox[0] );

				if ( 'center' === $align ) {
					$draw_x = $x + (int) ( ( $max_width - $line_width ) / 2 );
				} else {
					$draw_x = $x + $max_width - $line_width;
				}
			}

			$this->draw_text( $line, $font_size, $draw_x, $y + $font_size, $color, $font_name );
			$y += $line_height_px;
		}

		return $y;
	}

	/**
	 * Wrap text to fit within a max width.
	 *
	 * @param string $text      Text to wrap.
	 * @param int    $font_size Font size in points.
	 * @param string $font_name Registered font name.
	 * @param int    $max_width Maximum width in pixels.
	 * @return string[] Array of text lines.
	 */
	public function wrap_text( string $text, int $font_size, string $font_name, int $max_width ): array {
		$font_path    = $this->get_font( $font_name );
		$words        = explode( ' ', $text );
		$lines        = array();
		$current_line = '';

		foreach ( $words as $word ) {
			$test_line  = '' === $current_line ? $word : $current_line . ' ' . $word;
			$bbox       = imagettfbbox( $font_size, 0, $font_path, $test_line );
			$line_width = abs( $bbox[4] - $bbox[0] );

			if ( $line_width <= $max_width ) {
				$current_line = $test_line;
			} else {
				if ( '' !== $current_line ) {
					$lines[] = $current_line;
				}
				$current_line = $word;
			}
		}

		if ( '' !== $current_line ) {
			$lines[] = $current_line;
		}

		return $lines;
	}

	/**
	 * Measure text width in pixels.
	 *
	 * @param string $text      Text to measure.
	 * @param int    $font_size Font size in points.
	 * @param string $font_name Registered font name.
	 * @return int Width in pixels.
	 */
	public function measure_text_width( string $text, int $font_size, string $font_name ): int {
		$bbox = imagettfbbox( $font_size, 0, $this->get_font( $font_name ), $text );
		return abs( $bbox[4] - $bbox[0] );
	}

	/**
	 * Calculate the pixel height of wrapped text.
	 *
	 * @param string $text        Text to measure.
	 * @param int    $font_size   Font size in points.
	 * @param string $font_name   Registered font name.
	 * @param int    $max_width   Maximum text width in pixels.
	 * @param float  $line_height Line height multiplier.
	 * @return int Total height in pixels.
	 */
	public function measure_text_height( string $text, int $font_size, string $font_name, int $max_width, float $line_height = self::DEFAULT_LINE_HEIGHT ): int {
		$lines          = $this->wrap_text( $text, $font_size, $font_name, $max_width );
		$line_height_px = (int) ( $font_size * $line_height );
		return count( $lines ) * $line_height_px;
	}

	// -------------------------------------------------------------------------
	// Image Operations
	// -------------------------------------------------------------------------

	/**
	 * Overlay an image from a file path onto the canvas.
	 *
	 * @param string $image_path Path to the source image.
	 * @param int    $x          Destination X.
	 * @param int    $y          Destination Y.
	 * @param int    $width      Destination width (0 = source width).
	 * @param int    $height     Destination height (0 = source height).
	 * @param int    $opacity    Opacity percentage (0-100, default 100).
	 * @return self
	 */
	public function overlay_image( string $image_path, int $x, int $y, int $width = 0, int $height = 0, int $opacity = 100 ): self {
		if ( ! $this->image || ! file_exists( $image_path ) ) {
			return $this;
		}

		$info = getimagesize( $image_path );
		if ( ! $info ) {
			return $this;
		}

		$source = match ( $info[2] ) {
			IMAGETYPE_PNG  => imagecreatefrompng( $image_path ),
			IMAGETYPE_JPEG => imagecreatefromjpeg( $image_path ),
			IMAGETYPE_WEBP => imagecreatefromwebp( $image_path ),
			IMAGETYPE_GIF  => imagecreatefromgif( $image_path ),
			default        => false,
		};

		if ( ! $source ) {
			return $this;
		}

		$src_width  = imagesx( $source );
		$src_height = imagesy( $source );
		$dst_width  = $width > 0 ? $width : $src_width;
		$dst_height = $height > 0 ? $height : $src_height;

		if ( $opacity < 100 ) {
			imagecopymerge( $this->image, $source, $x, $y, 0, 0, $dst_width, $dst_height, $opacity );
		} else {
			imagecopyresampled( $this->image, $source, $x, $y, 0, 0, $dst_width, $dst_height, $src_width, $src_height );
		}

		unset( $source );

		return $this;
	}

	// -------------------------------------------------------------------------
	// Chart Drawing
	// -------------------------------------------------------------------------

	/**
	 * Draw a bar in a bar chart.
	 *
	 * @param int $x         X position of bar's left edge.
	 * @param int $y         Y position of bar's top.
	 * @param int $width     Bar width.
	 * @param int $height    Bar height (positive = grows up from baseline).
	 * @param int $color     GD color identifier.
	 * @param int $baseline  Y position of the baseline (bars grow from here).
	 * @return self
	 */
	public function draw_bar( int $x, int $y, int $width, int $height, int $color, int $baseline ): self {
		if ( ! $this->image ) {
			return $this;
		}

		// Height positive = grows UP from baseline
		// Height negative = grows DOWN from baseline
		$top    = $height >= 0 ? $baseline - $height : $baseline;
		$bottom = $height >= 0 ? $baseline : $baseline - $height;

		imagefilledrectangle( $this->image, $x, $top, $x + $width, $bottom, $color );

		return $this;
	}

	/**
	 * Draw a line segment in a line chart.
	 *
	 * @param int $x1    Start X.
	 * @param int $y1    Start Y.
	 * @param int $x2    End X.
	 * @param int $y2    End Y.
	 * @param int $color GD color identifier.
	 * @param int $width Line thickness.
	 * @return self
	 */
	public function draw_line( int $x1, int $y1, int $x2, int $y2, int $color, int $width = 2 ): self {
		if ( ! $this->image ) {
			return $this;
		}

		imagesetthickness( $this->image, $width );
		imageline( $this->image, $x1, $y1, $x2, $y2, $color );

		return $this;
	}

	/**
	 * Draw a point/marker in a line chart.
	 *
	 * @param int $x      X position.
	 * @param int $y      Y position.
	 * @param int $color  GD color identifier.
	 * @param int $radius Point radius.
	 * @return self
	 */
	public function draw_point( int $x, int $y, int $color, int $radius = 4 ): self {
		if ( ! $this->image ) {
			return $this;
		}

		imagefilledellipse( $this->image, $x, $y, $radius * 2, $radius * 2, $color );

		return $this;
	}

	/**
	 * Draw an arc (for pie charts).
	 *
	 * @param int $center_x  Center X.
	 * @param int $center_y  Center Y.
	 * @param int $width     Arc width (diameter).
	 * @param int $height    Arc height.
	 * @param int $start_deg Start angle in degrees (0 = 3 o'clock, clockwise).
	 * @param int $end_deg   End angle in degrees.
	 * @param int $color     GD color identifier.
	 * @param int $style    ImageArc style (IMG_ARC_CHORD, IMG_ARC_PIE, IMG_ARC_NOFILL).
	 * @return self
	 */
	public function draw_arc( int $center_x, int $center_y, int $width, int $height, int $start_deg, int $end_deg, int $color, int $style = IMG_ARC_PIE ): self {
		if ( ! $this->image ) {
			return $this;
		}

		imagefilledarc(
			$this->image,
			$center_x,
			$center_y,
			$width,
			$height,
			$start_deg,
			$end_deg,
			$color,
			$style
		);

		return $this;
	}

	/**
	 * Draw a rectangle.
	 *
	 * @param int $x1    Top-left X.
	 * @param int $y1    Top-left Y.
	 * @param int $x2    Bottom-right X.
	 * @param int $y2    Bottom-right Y.
	 * @param int $color GD color identifier.
	 * @param bool $filled Whether to fill the rectangle.
	 * @return self
	 */
	public function draw_rect( int $x1, int $y1, int $x2, int $y2, int $color, bool $filled = true ): self {
		if ( ! $this->image ) {
			return $this;
		}

		if ( $filled ) {
			imagefilledrectangle( $this->image, $x1, $y1, $x2, $y2, $color );
		} else {
			imagerectangle( $this->image, $x1, $y1, $x2, $y2, $color );
		}

		return $this;
	}

	/**
	 * Draw an ellipse.
	 *
	 * @param int $center_x Center X.
	 * @param int $center_y Center Y.
	 * @param int $width    Ellipse width.
	 * @param int $height   Ellipse height.
	 * @param int $color    GD color identifier.
	 * @param bool $filled  Whether to fill the ellipse.
	 * @return self
	 */
	public function draw_ellipse( int $center_x, int $center_y, int $width, int $height, int $color, bool $filled = true ): self {
		if ( ! $this->image ) {
			return $this;
		}

		if ( $filled ) {
			imagefilledellipse( $this->image, $center_x, $center_y, $width, $height, $color );
		} else {
			imageellipse( $this->image, $center_x, $center_y, $width, $height, $color );
		}

		return $this;
	}

	/**
	 * Get an array of colors for a chart palette.
	 *
	 * @param int $count Number of colors needed.
	 * @return int[] Array of GD color identifiers.
	 */
	public function get_chart_palette( int $count ): array {
		// Professional chart palette (colorblind-friendly)
		$palette = array(
			array( 83, 148, 11 ),   // Green (EC brand)
			array( 0, 122, 255 ),   // Blue
			array( 255, 149, 0 ),   // Orange
			array( 255, 59, 48 ),   // Red
			array( 88, 86, 214 ),   // Purple
			array( 255, 204, 5 ),   // Yellow
			array( 0, 204, 198 ),   // Teal
			array( 175, 82, 222 ),  // Pink
		);

		$colors = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$rgb      = $palette[ $i % count( $palette ) ];
			$name     = "chart_{$i}";
			$colors[] = $this->color( $name, $rgb[0], $rgb[1], $rgb[2] );
		}

		return $colors;
	}

	// -------------------------------------------------------------------------
	// Diagram Drawing
	// -------------------------------------------------------------------------

	/**
	 * Draw a line with an arrowhead.
	 *
	 * @param int $x1      Start X.
	 * @param int $y1      Start Y.
	 * @param int $x2      End X.
	 * @param int $y2      End Y.
	 * @param int $color   GD color identifier.
	 * @param int $width   Line thickness.
	 * @return self
	 */
	public function draw_arrow( int $x1, int $y1, int $x2, int $y2, int $color, int $width = 2 ): self {
		$this->draw_line( $x1, $y1, $x2, $y2, $color, $width );

		// Calculate arrowhead.
		$angle     = atan2( $y2 - $y1, $x2 - $x1 );
		$arrow_len = 12;

		$arrow_x1 = $x2 - $arrow_len * cos( $angle - M_PI / 6 );
		$arrow_y1 = $y2 - $arrow_len * sin( $angle - M_PI / 6 );
		$arrow_x2 = $x2 - $arrow_len * cos( $angle + M_PI / 6 );
		$arrow_y2 = $y2 - $arrow_len * sin( $angle + M_PI / 6 );

		$this->draw_line( $x2, $y2, (int) $arrow_x1, (int) $arrow_y1, $color, $width );
		$this->draw_line( $x2, $y2, (int) $arrow_x2, (int) $arrow_y2, $color, $width );

		return $this;
	}

	/**
	 * Draw a rounded rectangle (for flowchart nodes).
	 *
	 * @param int $x      X position.
	 * @param int $y      Y position.
	 * @param int $width  Rectangle width.
	 * @param int $height Rectangle height.
	 * @param int $color  GD color identifier.
	 * @param int $radius Corner radius.
	 * @return self
	 */
	public function draw_rounded_rect( int $x, int $y, int $width, int $height, int $color, int $radius = 8 ): self {
		if ( ! $this->image ) {
			return $this;
		}

		$x2 = $x + $width;
		$y2 = $y + $height;

		// imagefilledroundedrectangle() is PHP 8.4+. Fall back to a manual
		// composition (rects + corner arcs) so rounded nodes render on 8.0–8.3.
		if ( function_exists( 'imagefilledroundedrectangle' ) ) {
			imagefilledroundedrectangle( $this->image, $x, $y, $x2, $y2, $radius, $color );
			return $this;
		}

		$radius = max( 0, min( $radius, intdiv( $width, 2 ), intdiv( $height, 2 ) ) );

		if ( 0 === $radius ) {
			imagefilledrectangle( $this->image, $x, $y, $x2, $y2, $color );
			return $this;
		}

		// Center cross of the rounded rect.
		imagefilledrectangle( $this->image, $x + $radius, $y, $x2 - $radius, $y2, $color );
		imagefilledrectangle( $this->image, $x, $y + $radius, $x2, $y2 - $radius, $color );

		$d = $radius * 2;
		// Four corner quarter-circles.
		imagefilledellipse( $this->image, $x + $radius, $y + $radius, $d, $d, $color );
		imagefilledellipse( $this->image, $x2 - $radius, $y + $radius, $d, $d, $color );
		imagefilledellipse( $this->image, $x + $radius, $y2 - $radius, $d, $d, $color );
		imagefilledellipse( $this->image, $x2 - $radius, $y2 - $radius, $d, $d, $color );

		return $this;
	}

	/**
	 * Draw a diamond (for decision nodes in flowcharts).
	 *
	 * @param int $center_x Center X.
	 * @param int $center_y Center Y.
	 * @param int $width    Diamond width.
	 * @param int $height   Diamond height.
	 * @param int $color    GD color identifier.
	 * @param bool $filled  Whether to fill.
	 * @return self
	 */
	public function draw_diamond( int $center_x, int $center_y, int $width, int $height, int $color, bool $filled = true ): self {
		if ( ! $this->image ) {
			return $this;
		}

		$half_w = (int) ( $width / 2 );
		$half_h = (int) ( $height / 2 );

		$points = array(
			$center_x,
			$center_y - $half_h,           // Top.
			$center_x + $half_w,
			$center_y,            // Right.
			$center_x,
			$center_y + $half_h,            // Bottom.
			$center_x - $half_w,
			$center_y,            // Left.
		);

		if ( $filled ) {
			imagefilledpolygon( $this->image, $points, 4, $color );
		} else {
			imagepolygon( $this->image, $points, 4, $color );
		}

		return $this;
	}

	/**
	 * Draw an oval/ellipse (for start/end nodes in flowcharts).
	 *
	 * Already implemented as draw_ellipse above, alias for clarity.
	 */
	public function draw_oval( int $center_x, int $center_y, int $width, int $height, int $color, bool $filled = true ): self {
		return $this->draw_ellipse( $center_x, $center_y, $width, $height, $color, $filled );
	}

	// -------------------------------------------------------------------------
	// Output
	// -------------------------------------------------------------------------

	/**
	 * Save the current canvas to a temporary file.
	 *
	 * @param string $format   Output format: 'png' or 'jpeg' (default 'png').
	 * @param int    $quality  JPEG quality (0-100) or PNG compression (0-9).
	 * @return string|null Temporary file path on success, null on failure.
	 */
	public function save_temp( string $format = 'png', int $quality = -1 ): ?string {
		if ( ! $this->image ) {
			return null;
		}

		$temp_file = tempnam( sys_get_temp_dir(), 'datamachine_img_' );
		$ext       = 'jpeg' === $format ? '.jpg' : '.png';
		$path      = $temp_file . $ext;

		$success = match ( $format ) {
			'jpeg'  => imagejpeg( $this->image, $path, $quality > 0 ? $quality : 90 ),
			default => imagepng( $this->image, $path, $quality >= 0 ? $quality : 9 ),
		};

		if ( file_exists( $temp_file ) ) {
			wp_delete_file( $temp_file );
		}

		if ( ! $success ) {
			wp_delete_file( $path );
			return null;
		}

		return $path;
	}

	/**
	 * Save the current canvas to the Data Machine files repository.
	 *
	 * @param string $filename Target filename (e.g. 'quote-card-1.png').
	 * @param array  $context  Storage context with pipeline_id and flow_id.
	 * @param string $format   Output format: 'png' or 'jpeg'.
	 * @param int    $quality  JPEG quality or PNG compression level.
	 * @return string|null Stored file path on success, null on failure.
	 */
	public function save_to_repository( string $filename, array $context, string $format = 'png', int $quality = -1 ): ?string {
		$temp_path = $this->save_temp( $format, $quality );
		if ( ! $temp_path ) {
			return null;
		}

		$storage     = new FileStorage();
		$stored_path = $storage->store_file( $temp_path, $filename, $context );

		if ( file_exists( $temp_path ) ) {
			wp_delete_file( $temp_path );
		}

		return $stored_path ? $stored_path : null;
	}

	/**
	 * Destroy the current canvas and free memory.
	 *
	 * @return void
	 */
	public function destroy(): void {
		if ( $this->image ) {
			$this->image = null;
		}
		$this->colors = array();
	}

	/**
	 * Destructor — clean up GD resources.
	 */
	public function __destruct() {
		$this->destroy();
	}
}
