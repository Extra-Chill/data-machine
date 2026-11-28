<?php
/**
 * Event OG Card Template
 *
 * Renders a 1200x630 Open Graph card for event posts via the Data Machine
 * GDRenderer + TemplateInterface contract. Brand identity (colors, fonts,
 * logo) comes from BrandTokens which themes/plugins populate via filter —
 * the template is intentionally brand-agnostic.
 *
 * Layout:
 *   - Date pill (top-left), location pill (top-right, optional)
 *   - Event title (left-aligned, wrapped)
 *   - Venue + city, bottom-left, anchored to the canvas bottom
 *   - Brand mark, bottom-right — logo when resolvable, otherwise text
 *
 * Brand mark resolution is a three-level fallback chain, split across two
 * layers: BrandTokens::get() (data-machine) resolves an explicit
 * `logo_path`/`logo_path_inverse` brand token if one is set, else falls
 * back to the site's core WordPress site icon — that part is generic to
 * every GD-rendered template, not just this one (see
 * Extra-Chill/data-machine#3538). This template only does the layout-level
 * third of the chain: resolve_logo() picks whichever variant matches this
 * card's background and guards against an unreadable file, falling back to
 * the legacy "<brand_text> · <site_label>" text pairing when nothing
 * usable comes back. Every level degrades to the next rather than fatally
 * erroring or silently rendering nothing.
 *
 * Required data fields:
 *   - event_name (string)
 *   - date_label (string)  e.g. "May 16, 2026"
 *
 * Optional data fields:
 *   - venue (string)       e.g. "Charleston Pour House"
 *   - city (string)        e.g. "Charleston, SC"
 *   - _brand_override.colors (array) — per-render color overrides
 *   - _brand_override.location_label (string) — top-right pill text
 *
 * @package DataMachineEvents\Templates
 * @since 0.30.0
 */

namespace DataMachineEvents\Templates;

use DataMachine\Abilities\Media\TemplateInterface;
use DataMachine\Abilities\Media\GDRenderer;
use DataMachine\Abilities\Media\BrandTokens;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventOgCardTemplate implements TemplateInterface {

	/**
	 * Bounding box height (px) for the brand mark — a logo or the text
	 * fallback — in the bottom-right corner. Kept modest so a corner mark
	 * reads as a signature, not a second competing headline.
	 */
	private const LOGO_MAX_H = 88;

	/**
	 * Bounding box width (px) for the brand mark. Generous enough for the
	 * ~1.18:1 Extra Chill wordmark at LOGO_MAX_H without crowding the
	 * title's content width; a square site-icon fallback never approaches
	 * this width since it is height-constrained to LOGO_MAX_H first.
	 */
	private const LOGO_MAX_W = 220;

	/**
	 * Clearance (px) between the canvas bottom edge and the bottom of the
	 * brand mark's bounding box.
	 */
	private const LOGO_BOTTOM_MARGIN = 32;

	public function get_id(): string {
		return 'event_og_card';
	}

	public function get_name(): string {
		return 'Event OG Card';
	}

	public function get_description(): string {
		return 'Open Graph (1200x630) card for event posts. Renders title, date, venue, and city on a branded background.';
	}

	public function get_fields(): array {
		return array(
			'event_name' => array(
				'label'    => 'Event Name',
				'type'     => 'string',
				'required' => true,
			),
			'date_label' => array(
				'label'    => 'Date Label',
				'type'     => 'string',
				'required' => true,
			),
			'venue'      => array(
				'label'    => 'Venue',
				'type'     => 'string',
				'required' => false,
			),
			'city'       => array(
				'label'    => 'City',
				'type'     => 'string',
				'required' => false,
			),
		);
	}

	public function get_default_preset(): string {
		return 'open_graph';
	}

	public function render( array $data, GDRenderer $renderer, array $options = array() ): array {
		$preset  = $options['preset'] ?? $this->get_default_preset();
		$format  = $options['format'] ?? 'png';
		$context = $options['context'] ?? array();

		$renderer->create_canvas( $preset );

		$width  = $renderer->get_width();
		$height = $renderer->get_height();

		// Resolve brand tokens (colors, fonts, labels). Themes hook
		// `datamachine/image_template/brand_tokens` to supply these. When
		// nothing is registered, the defaults from BrandTokens::DEFAULTS apply.
		$tokens     = BrandTokens::get( $this->get_id(), $data );
		$colors     = $tokens['colors'];
		$fonts      = $tokens['fonts'];
		$site_label = (string) ( $tokens['site_label'] ?? '' );
		$brand_text = (string) ( $tokens['brand_text'] ?? '' );

		// Per-render overrides (passed through the data payload, typically
		// injected by a plugin that knows context-specific branding — e.g.
		// the theme hooks datamachine_events_og_card_data to supply
		// per-location colors from the badge token system).
		$override = (array) ( $data['_brand_override'] ?? array() );
		if ( ! empty( $override['colors'] ) && is_array( $override['colors'] ) ) {
			$colors = array_merge( $colors, $override['colors'] );
		}
		$location_label = isset( $override['location_label'] ) ? $this->normalize_text( (string) $override['location_label'] ) : '';
		$accent_text    = (string) ( $colors['accent_text'] ?? $colors['text_inverse'] );

		// Surface colors for the card.
		$bg             = $renderer->color_hex( 'bg', $colors['background'] );
		$accent         = $renderer->color_hex( 'accent', $colors['accent'] );
		$text_pri       = $renderer->color_hex( 'text_pri', $colors['text_primary'] );
		$text_mute      = $renderer->color_hex( 'text_mute', $colors['text_muted'] );
		$text_inv       = $renderer->color_hex( 'text_inv', $colors['text_inverse'] );
		$text_on_accent = $renderer->color_hex( 'text_on_accent', $accent_text );

		// Layout: single flat background. The accent stripe, surface footer
		// band, and black brand strip that used to divide this canvas into
		// three horizontal bands are gone — date/location pills already
		// carry the accent color (see #856), and the brand mark (logo or
		// fallback text) sits directly on this background rather than a
		// dedicated strip.
		$renderer->fill( $bg );

		// Register fonts from brand tokens. GDRenderer handles fallback
		// to system DejaVu when a path is missing or null.
		$heading_path = $fonts['heading'] ?? '';
		$body_path    = $fonts['body'] ?? '';
		$brand_path   = $fonts['brand'] ?? '';

		$renderer->register_font( 'header', is_string( $heading_path ) && $heading_path ? $heading_path : 'Heading.ttf' );
		$renderer->register_font( 'body', is_string( $body_path ) && $body_path ? $body_path : 'Body.ttf' );
		$renderer->register_font( 'brand', is_string( $brand_path ) && $brand_path ? $brand_path : ( is_string( $heading_path ) && $heading_path ? $heading_path : 'Brand.ttf' ) );

		$padding     = 64;
		$content_max = $width - ( $padding * 2 );

		// Date pill (top-left).
		$date_label = $this->normalize_text( $data['date_label'] ?? '' );
		if ( '' !== $date_label ) {
			$pill_padding_x = 24;
			$pill_padding_y = 14;
			$pill_font_size = 28;
			$pill_label     = strtoupper( $date_label );
			$pill_text_w    = $renderer->measure_text_width( $pill_label, $pill_font_size, 'header' );
			$pill_w         = $pill_text_w + ( $pill_padding_x * 2 );
			$pill_h         = $pill_font_size + ( $pill_padding_y * 2 );
			$pill_x         = $padding;
			$pill_y         = $padding;

			$renderer->filled_rect( $pill_x, $pill_y, $pill_x + $pill_w, $pill_y + $pill_h, $accent );
			$renderer->draw_text(
				$pill_label,
				$pill_font_size,
				$pill_x + $pill_padding_x,
				$pill_y + $pill_padding_y + $pill_font_size,
				$text_on_accent,
				'header'
			);
		}

		// Location pill (top-right), when provided via _brand_override.
		// Uses the same accent color as the date pill — the theme's
		// location badge palette is already in play.
		if ( '' !== $location_label ) {
			$loc_padding_x = 24;
			$loc_padding_y = 14;
			$loc_font_size = 28;
			$loc_text      = strtoupper( $location_label );
			$loc_text_w    = $renderer->measure_text_width( $loc_text, $loc_font_size, 'header' );
			$loc_w         = $loc_text_w + ( $loc_padding_x * 2 );
			$loc_h         = $loc_font_size + ( $loc_padding_y * 2 );
			$loc_x         = $width - $padding - $loc_w;
			$loc_y         = $padding;

			$renderer->filled_rect( $loc_x, $loc_y, $loc_x + $loc_w, $loc_y + $loc_h, $accent );
			$renderer->draw_text(
				$loc_text,
				$loc_font_size,
				$loc_x + $loc_padding_x,
				$loc_y + $loc_padding_y + $loc_font_size,
				$text_on_accent,
				'header'
			);
		}

		// Event title — large, wrapped, positioned in upper-mid area.
		$event_name = $this->normalize_text( $data['event_name'] ?? '' );
		if ( '' !== $event_name ) {
			$title_font_size = $this->fit_title_size( $event_name );
			$title_y         = (int) ( $height * 0.26 );
			$renderer->draw_text_wrapped(
				$event_name,
				$title_font_size,
				$padding,
				$title_y,
				$text_pri,
				'header',
				$content_max,
				1.15,
				'left'
			);
		}

		// Venue + city, bottom-left, anchored to the canvas bottom edge.
		$venue = $this->normalize_text( $data['venue'] ?? '' );
		$city  = $this->normalize_text( $data['city'] ?? '' );

		$venue_font_size = 34;
		$city_font_size  = 26;

		$has_venue = '' !== $venue;
		$has_city  = '' !== $city;

		[ $venue_baseline, $city_baseline ] = $this->compute_footer_baselines( $has_venue, $has_city, $height );

		if ( $has_venue ) {
			$renderer->draw_text(
				$venue,
				$venue_font_size,
				$padding,
				$venue_baseline,
				$text_pri,
				'header'
			);
		}

		if ( $has_city ) {
			$renderer->draw_text(
				$city,
				$city_font_size,
				$padding,
				$city_baseline,
				$text_mute,
				'body'
			);
		}

		// Brand mark, bottom-right — a logo when one resolves (explicit
		// token, then the site icon), otherwise the legacy
		// "<brand_text> · <site_label>" text. See resolve_logo() for the
		// full three-level chain and why it degrades this way.
		$bg_is_dark   = $this->is_dark_hex( (string) $colors['background'] );
		$box_right_x  = $width - $padding;
		$box_bottom_y = $height - self::LOGO_BOTTOM_MARGIN;

		$logo = $this->resolve_logo( $tokens, $bg_is_dark );

		if ( null !== $logo ) {
			[ $draw_w, $draw_h ] = $this->fit_within( $logo['width'], $logo['height'], self::LOGO_MAX_W, self::LOGO_MAX_H );
			$logo_x              = $box_right_x - $draw_w;
			$logo_y              = $box_bottom_y - $draw_h;
			$renderer->overlay_image( $logo['path'], $logo_x, $logo_y, $draw_w, $draw_h, 100 );
		} else {
			// Level 3 — no logo resolved from a token or the site icon.
			// Uses the `body` font (typically Helvetica) instead of
			// `brand`/`heading` because display fonts like Wilco Loft Sans
			// lack punctuation glyphs.
			if ( '' === $brand_text && '' === $site_label ) {
				$combined_brand = '';
			} else {
				$combined_brand = $brand_text;
				if ( '' !== $brand_text && '' !== $site_label ) {
					$combined_brand .= '  ·  ' . $site_label;
				} elseif ( '' === $brand_text ) {
					$combined_brand = $site_label;
				}
			}

			if ( '' !== $combined_brand ) {
				$brand_font_size = 24;
				$brand_text_w    = $renderer->measure_text_width( $combined_brand, $brand_font_size, 'body' );
				$brand_text_x    = $box_right_x - $brand_text_w;
				$brand_text_y    = $box_bottom_y - (int) ( ( self::LOGO_MAX_H - $brand_font_size ) / 2 );
				$renderer->draw_text(
					$combined_brand,
					$brand_font_size,
					$brand_text_x,
					$brand_text_y,
					$bg_is_dark ? $text_inv : $text_mute,
					'body'
				);
			}
		}

		// Save.
		$filename = sprintf( 'event-og-%d.%s', time(), 'jpeg' === $format ? 'jpg' : 'png' );

		if ( ! empty( $context ) && isset( $context['pipeline_id'], $context['flow_id'] ) ) {
			$path = $renderer->save_to_repository( $filename, $context, $format );
		} else {
			$path = $renderer->save_temp( $format );
		}

		$renderer->destroy();

		return $path ? array( $path ) : array();
	}

	/**
	 * Compute the venue and city text baselines within the footer area.
	 *
	 * Anchored bottom-up from `$bottom_anchor_y` (the canvas bottom edge,
	 * i.e. `$height`) rather than top-down from a footer band: the last
	 * line's baseline is placed a fixed clearance above the anchor, so
	 * the block can never collide with it regardless of how many lines
	 * are present. A top-down offset (the original, buggy approach — see
	 * issue #853) stays correct only for as long as nobody changes a font
	 * size; anchoring to the bottom is self-correcting.
	 *
	 * #856 removed the black brand strip this method previously anchored
	 * to ($brand_strip_y = $height - 64). The anchor is now the canvas
	 * bottom edge itself, with the same clearance constant carried over
	 * unchanged — the fix from #855 is preserved exactly, just re-pointed
	 * at what still exists after the strip's removal.
	 *
	 * Pulled out as its own method (rather than inlined in render()) so
	 * the geometry can be verified directly — this is the exact
	 * computation regression tests assert against, independent of
	 * whether the host environment can rasterise text with GD/FreeType.
	 *
	 * @param bool $has_venue       Whether a venue line will be drawn.
	 * @param bool $has_city        Whether a city line will be drawn.
	 * @param int  $bottom_anchor_y Y position to anchor the last baseline
	 *                              above (the canvas height/bottom edge).
	 * @return array{0:int,1:int} [venue_baseline, city_baseline]. Both are
	 *         always populated (even when the corresponding line isn't
	 *         drawn) so callers never index into an unset value.
	 */
	private function compute_footer_baselines( bool $has_venue, bool $has_city, int $bottom_anchor_y ): array {
		// Vertical gap between the last text baseline and the canvas
		// bottom edge. Sized to clear descenders (~20% of font size, so
		// ~7px for the 34px venue line) with comfortable margin to spare.
		// Unchanged from #855.
		$bottom_clearance = 16;
		// Baseline-to-baseline distance between the venue and city lines
		// when both are present. Matches the original design's spacing.
		$baseline_gap = 44;

		// Default: whichever line is drawn alone sits at the single-line
		// anchor (bottom_clearance above the anchor). When both lines are
		// present, city stays at that anchor as the last line and venue
		// is recomputed to stack above it. Defining both up front — rather
		// than only inside a has_venue-only branch — means the
		// venue-absent and city-absent cases each still resolve to a
		// real, correctly positioned baseline instead of an unset value.
		$venue_baseline = $bottom_anchor_y - $bottom_clearance;
		$city_baseline  = $bottom_anchor_y - $bottom_clearance;

		if ( $has_venue && $has_city ) {
			$venue_baseline = $city_baseline - $baseline_gap;
		}

		return array( $venue_baseline, $city_baseline );
	}

	/**
	 * Resolve a brand mark to draw in the bottom-right corner.
	 *
	 * Pure layout logic — reads whichever logo `BrandTokens::get()`
	 * already resolved and picks the variant that matches this card's
	 * background, guarding against an unreadable file. The resolution
	 * chain itself (explicit token → WordPress core site icon → nothing)
	 * lives one layer up in `BrandTokens::get()` (data-machine), not
	 * here — that capability belongs to every GD-rendered template, not
	 * just this one. See Extra-Chill/data-machine#3538.
	 *
	 * If a token exists but only for the *other* background variant
	 * (e.g. `logo_path` is set but this card's background is dark and no
	 * `logo_path_inverse` exists), it is treated as absent rather than
	 * drawn invisibly — that is the whole reason it's a variant pair
	 * instead of a single path. Returning `null` here means the caller
	 * falls back to brand-strip text.
	 *
	 * @param array $tokens     Resolved brand tokens (see BrandTokens::get()).
	 * @param bool  $bg_is_dark Whether the card background is dark.
	 * @return array{path:string,width:int,height:int}|null
	 */
	private function resolve_logo( array $tokens, bool $bg_is_dark ): ?array {
		$token_key = $bg_is_dark ? 'logo_path_inverse' : 'logo_path';
		$logo_path = $tokens[ $token_key ] ?? null;

		if ( ! is_string( $logo_path ) || '' === $logo_path ) {
			return null;
		}

		$dims = $this->readable_image_dims( $logo_path );
		if ( null === $dims ) {
			return null;
		}

		return array(
			'path'   => $logo_path,
			'width'  => $dims[0],
			'height' => $dims[1],
		);
	}

	/**
	 * Confirm a path is a real, GD-decodable raster image and return its
	 * dimensions.
	 *
	 * Guards resolve_logo() against a DB record that no longer has a file
	 * on disk, and against file types GDRenderer::overlay_image() cannot
	 * decode (it supports PNG/JPEG/WEBP/GIF).
	 *
	 * @param string $path Absolute filesystem path.
	 * @return array{0:int,1:int}|null [width, height], or null if the
	 *                                 file is missing or not a supported
	 *                                 raster image.
	 */
	private function readable_image_dims( string $path ): ?array {
		if ( ! file_exists( $path ) ) {
			return null;
		}

		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- getimagesize() warns on unreadable/corrupt files; a warning here must degrade to "no logo", not surface to the user.
		if ( ! $info ) {
			return null;
		}

		$supported = array( IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP, IMAGETYPE_GIF );
		if ( ! in_array( $info[2], $supported, true ) ) {
			return null;
		}

		return array( $info[0], $info[1] );
	}

	/**
	 * Scale (never stretch) an image to fit within a bounding box.
	 *
	 * Preserves aspect ratio so a ~1.18:1 wordmark and a 1:1 square site
	 * icon both render correctly without distortion — the box is sized
	 * by whichever dimension is the binding constraint for that asset's
	 * shape.
	 *
	 * @param int $src_w Source width.
	 * @param int $src_h Source height.
	 * @param int $max_w Bounding box max width.
	 * @param int $max_h Bounding box max height.
	 * @return array{0:int,1:int} [draw_width, draw_height].
	 */
	private function fit_within( int $src_w, int $src_h, int $max_w, int $max_h ): array {
		if ( $src_w <= 0 || $src_h <= 0 ) {
			return array( 0, 0 );
		}

		$scale = min( $max_w / $src_w, $max_h / $src_h );

		return array(
			(int) round( $src_w * $scale ),
			(int) round( $src_h * $scale ),
		);
	}

	/**
	 * Whether a hex color reads as visually "dark" (so inverse/light-ink
	 * assets and text should be used against it).
	 *
	 * @param string $hex Hex color, with or without a leading '#'.
	 * @return bool True when the color's perceptual luminance is below
	 *              the midpoint.
	 */
	private function is_dark_hex( string $hex ): bool {
		$hex = ltrim( $hex, '#' );
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			// Not a recognizable hex color — assume light, matching this
			// template's default white background rather than guessing dark.
			return false;
		}

		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );

		// ITU-R BT.601 perceptual luminance.
		$luminance = ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) / 255;

		return $luminance < 0.5;
	}

	/**
	 * Choose a title font size based on text length so long titles still fit.
	 *
	 * @param string $title Event title.
	 * @return int Font size in points.
	 */
	private function fit_title_size( string $title ): int {
		$len = mb_strlen( $title );

		if ( $len <= 30 ) {
			return 84;
		}
		if ( $len <= 50 ) {
			return 72;
		}
		if ( $len <= 80 ) {
			return 60;
		}
		return 52;
	}

	/**
	 * Strip HTML entities and trim whitespace for clean GD rendering.
	 *
	 * @param string $text Raw text from post data.
	 * @return string Cleaned text.
	 */
	private function normalize_text( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = wp_strip_all_tags( $text );
		return trim( $text );
	}
}
