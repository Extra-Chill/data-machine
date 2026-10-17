<?php
/**
 * Poster Template
 *
 * Generic, brand-driven promotional poster rendered entirely from
 * structured fields. Nothing here knows about any domain or vendor:
 * colors, fonts and the logo come from BrandTokens, and the QR
 * image is resolved through a filter so the site decides how QR codes
 * are produced.
 *
 * Domain templates compose this one: map domain data onto the poster
 * fields and call render(). A product promo, a listing ad and a
 * one-off announcement are all the same layout with different copy.
 *
 * Layouts:
 *   - Portrait (feed, story, print): kicker with the logo top-right,
 *     title, accent rule, tagline/subline, large detail lines, accent
 *     line, small detail note, full-width banner, then a footer with the
 *     call-to-action line, co-brand logos, footer note and the QR code.
 *   - Landscape (any canvas wider than tall, e.g. landscape_16_9): text
 *     in a left column; logo top-right and QR + label above the banner
 *     in a right column; footer row under the banner.
 *   - QR hero (qr_emphasis: hero): the QR code is the dominant element,
 *     drawn on a white quiet-zone card with the label set large beneath
 *     it. Portrait pins the card to the bottom (at least 45% of the
 *     canvas width, horizontally centered); landscape puts the card on
 *     the right (at least 40% of the canvas height) with the text column
 *     on the left. Copy and banner reflow above/beside the card, and
 *     optional text shrinks or drops before the QR gives up size. The QR
 *     bitmap is requested at its final pixel size and scaled
 *     nearest-neighbor, never resampled, so it stays crisp in print.
 *
 * Knobs beyond copy:
 *   - scheme: `dark` (default), `accent` (canvas flooded with the brand
 *     accent, dark banner) or `light` (brand background, dark type).
 *   - qr_emphasis: `footer` (default, small QR in the footer block) or
 *     `hero` (see above). Hero falls back to the footer layout when no
 *     QR provider answers the QR filter.
 *   - background: texture behind everything — `stripes` (default),
 *     `none`, `grid`, `dots`, `halftone`, `crosshatch`, `waves`,
 *     `gradient`, `glow` or `image`. Patterns are drawn procedurally in
 *     the same low-alpha ink as the stripes (optionally recolored with
 *     background_tint); `image` cover-fits background_image behind the
 *     content and floods the scheme background over it at
 *     background_overlay opacity so the copy stays legible. Unreadable
 *     or unsupported images fall back to stripes.
 *   - list_heading + list_items: a ruled list of rows, each with a
 *     primary line, an optional secondary line and an optional
 *     right-aligned meta value (e.g. a time or a price). Portrait draws
 *     it under the tagline; landscape gives it its own column. Rows that
 *     do not fit are dropped from the end, never squeezed.
 *
 * Spare vertical space on tall canvases is distributed instead of
 * pooling at the bottom, and the title shrinks until the content clears
 * the banner.
 *
 * QR filter: `datamachine/image_generation/qr_image_path`
 *   ( ?string $path, string $url, int $size ) → path to a PNG the
 *   template owns and deletes after drawing. Return null (the default)
 *   to omit the QR block.
 *
 * @package DataMachine\Abilities\Media\Templates
 */

namespace DataMachine\Abilities\Media\Templates;

use DataMachine\Abilities\Media\BrandTokens;
use DataMachine\Abilities\Media\GDRenderer;
use DataMachine\Abilities\Media\TemplateInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PosterTemplate implements TemplateInterface {

	/** Design unit: portrait sizes are pixels at a 1080px-wide canvas. */
	private const BASE_WIDTH = 1080;

	private const MARGIN           = 80;
	private const KICKER_SIZE      = 26;
	private const TITLE_MAX_SIZE   = 84;
	private const TITLE_MIN_SIZE   = 52;
	private const BODY_SIZE        = 34;
	private const DETAIL_SIZE      = 54;
	private const NOTE_LINE_SIZE   = 28;
	private const CTA_SIZE         = 26;
	private const FOOTNOTE_SIZE    = 22;
	private const QR_LABEL_SIZE    = 22;
	private const LOGO_HEIGHT      = 150;
	private const COBRAND_HEIGHT   = 44;
	private const QR_SIZE          = 190;
	private const BANNER_HEIGHT    = 120;
	private const BANNER_TEXT_SIZE = 34;
	private const MIN_BANNER_GAP   = 40;

	/** Landscape design unit: sizes are pixels at a 1080px-tall canvas. */
	private const LANDSCAPE_BASE_HEIGHT   = 1080;
	private const LANDSCAPE_MARGIN        = 100;
	private const LANDSCAPE_LOGO_HEIGHT   = 200;
	private const LANDSCAPE_QR_SIZE       = 260;
	private const LANDSCAPE_BANNER_HEIGHT = 110;
	private const LANDSCAPE_TITLE_MAX     = 96;
	private const LANDSCAPE_TITLE_MIN     = 60;
	private const LANDSCAPE_DETAIL_SIZE   = 62;

	/** GD alpha (0 opaque .. 127 transparent) for the background stripes. */
	private const STRIPE_ALPHA = 122;

	/** GD alpha for the finer background patterns (grid, dots, waves), which need a touch more ink than the broad stripes. */
	private const PATTERN_ALPHA = 114;

	/** Supported color schemes; the first is the default. */
	public const SCHEMES = array( 'dark', 'accent', 'light' );

	/** Background treatments; the first is the default. */
	public const BACKGROUNDS = array( 'stripes', 'none', 'grid', 'dots', 'halftone', 'crosshatch', 'waves', 'gradient', 'glow', 'image' );

	/** Default opacity of the scheme-color overlay flooded over a background image. */
	private const BACKGROUND_OVERLAY_DEFAULT = 0.55;

	/** QR emphasis modes; the first is the default. */
	public const QR_EMPHASES = array( 'footer', 'hero' );

	/** Hero QR: preferred share of the canvas width (portrait) or height (landscape). */
	private const HERO_QR_RATIO = 0.52;

	/** Hero QR: minimum share of the canvas width on portrait canvases. */
	public const HERO_QR_MIN_RATIO = 0.45;

	/** Hero QR: minimum share of the canvas height on landscape canvases. */
	public const HERO_QR_MIN_RATIO_LANDSCAPE = 0.40;

	/**
	 * Hero quiet-zone card: the white card pads the QR by at least
	 * HERO_QUIET_MODULES modules on every side, sized for a QR of up to
	 * HERO_QR_MODULES modules per side (version 4, which comfortably holds
	 * a campaign URL). Larger-version codes still get proportional padding.
	 */
	public const HERO_QUIET_MODULES = 4;
	private const HERO_QR_MODULES   = 33;

	/** Hero QR label size, in design units. */
	private const HERO_LABEL_SIZE = 42;

	/** List block sizes, in each layout's design unit. */
	private const LIST_HEADING_SIZE = 26;
	private const LIST_PRIMARY_SIZE = 38;
	private const LIST_SECOND_SIZE  = 24;
	private const LIST_META_SIZE    = 34;
	private const LIST_ROW_PAD      = 14;
	private const LIST_MIN_ROWS     = 3;

	/** Share of spare vertical space placed above the content block. */
	private const SLACK_ABOVE = 0.4;

	public function get_id(): string {
		return 'poster';
	}

	public function get_name(): string {
		return 'Poster';
	}

	public function get_description(): string {
		return 'Branded promotional poster from free-form copy: kicker, title, tagline, an optional ruled list of rows, detail lines, banner, call to action and an optional QR code. Dark, accent or light color scheme. Portrait, print and 16:9 landscape layouts.';
	}

	public function get_fields(): array {
		$string = static fn( string $label, bool $required = false ): array => array(
			'label'    => $label,
			'type'     => 'string',
			'required' => $required,
		);
		$list   = static fn( string $label ): array => array(
			'label'    => $label,
			'type'     => 'array',
			'required' => false,
		);

		return array(
			'title'              => $string( 'Title', true ),
			'kicker'             => $string( 'Kicker (defaults to "<BRAND> PRESENTS")' ),
			'tagline'            => $string( 'Tagline' ),
			'subline'            => $string( 'Subline' ),
			'detail_lines'       => $list( 'Detail lines (large type)' ),
			'accent_line'        => $string( 'Accent line (large type, accent color)' ),
			'detail_note'        => $string( 'Detail note (small, under the accent line)' ),
			'banner_text'        => $string( 'Banner text (banner omitted when empty)' ),
			'qr_url'             => $string( 'QR code URL' ),
			'qr_label'           => $string( 'QR label' ),
			'qr_emphasis'        => $string( 'QR emphasis: footer (default) or hero (large QR on a white card)' ),
			'cta_text'           => $string( 'Call-to-action line' ),
			'footer_note'        => $string( 'Footer note' ),
			'cobrand_logos'      => $list( 'Co-brand logo paths' ),
			'scheme'             => $string( 'Color scheme: dark (default), accent or light' ),
			'background'         => $string( 'Background: stripes (default), none, grid, dots, halftone, crosshatch, waves, gradient, glow or image' ),
			'background_image'   => $string( 'Background image: absolute path to a PNG, JPEG or WebP, cover-fit behind everything (used when background is image)' ),
			'background_tint'    => $string( 'Background pattern tint: hex color overriding the pattern ink' ),
			'background_overlay' => array(
				'label'    => 'Background image overlay: opacity (0-1, default 0.55) of the scheme background flooded over the image',
				'type'     => 'number',
				'required' => false,
			),
			'list_heading'       => $string( 'List heading (small caps line above the list)' ),
			'list_items'         => $list( 'List rows: strings, or objects with primary, secondary and meta' ),
		);
	}

	public function get_default_preset(): string {
		return 'instagram_feed_portrait';
	}

	/**
	 * Render a poster.
	 *
	 * @param array      $data     Poster fields (see get_fields()).
	 * @param GDRenderer $renderer Renderer instance.
	 * @param array      $options  preset, format, context, filename, and
	 *                             brand_template_id (template id passed to
	 *                             BrandTokens, so composing templates keep
	 *                             their own brand-token identity).
	 * @return string[] Rendered file paths, empty on failure.
	 */
	public function render( array $data, GDRenderer $renderer, array $options = array() ): array {
		$fields = self::normalize_fields( $data );
		if ( '' === $fields['title'] ) {
			return array();
		}

		$preset   = (string) ( $options['preset'] ?? $this->get_default_preset() );
		$format   = (string) ( $options['format'] ?? 'png' );
		$context  = (array) ( $options['context'] ?? array() );
		$brand_id = (string) ( $options['brand_template_id'] ?? $this->get_id() );

		$tokens = BrandTokens::get( $brand_id, $data );

		$renderer->create_canvas( $preset );
		if ( ! $renderer->get_image() ) {
			return array();
		}

		$this->draw( $renderer, $fields, $tokens );

		$filename = (string) ( $options['filename'] ?? '' );
		if ( '' === $filename ) {
			$filename = sprintf( 'poster-%s-%s.%s', substr( md5( (string) wp_json_encode( $fields ) ), 0, 10 ), $preset, 'jpeg' === $format ? 'jpg' : 'png' );
		}
		$path = ( ! empty( $context['pipeline_id'] ) && ! empty( $context['flow_id'] ) )
			? $renderer->save_to_repository( $filename, $context, $format )
			: $renderer->save_temp( $format );

		return $path ? array( $path ) : array();
	}

	/**
	 * Normalize raw template input into the strings and lists drawn.
	 *
	 * @param array $data Raw template input.
	 * @return array{title: string, kicker: string, tagline: string, subline: string, detail_lines: list<string>, accent_line: string, detail_note: string, banner_text: string, qr_url: string, qr_label: string, qr_emphasis: string, cta_text: string, footer_note: string, cobrand_logos: list<string>, scheme: string, background: string, background_image: string, background_tint: string, background_overlay: float, list_heading: string, list_items: list<array{primary: non-empty-string, secondary: string, meta: string}>}
	 */
	public static function normalize_fields( array $data ): array {
		$str = static fn( string $key ): string => is_scalar( $data[ $key ] ?? null ) ? trim( (string) $data[ $key ] ) : '';

		$detail_lines = array();
		foreach ( (array) ( $data['detail_lines'] ?? array() ) as $line ) {
			if ( is_scalar( $line ) && '' !== trim( (string) $line ) ) {
				$detail_lines[] = trim( (string) $line );
			}
		}

		$cobrand = array();
		foreach ( (array) ( $data['cobrand_logos'] ?? array() ) as $logo ) {
			if ( is_string( $logo ) && '' !== $logo ) {
				$cobrand[] = $logo;
			}
		}

		$list_items = array();
		foreach ( (array) ( $data['list_items'] ?? array() ) as $item ) {
			$item = is_scalar( $item ) ? array( 'primary' => $item ) : $item;
			if ( ! is_array( $item ) ) {
				continue;
			}
			$row = array(
				'primary'   => '',
				'secondary' => '',
				'meta'      => '',
			);
			foreach ( array( 'primary', 'secondary', 'meta' ) as $key ) {
				$row[ $key ] = is_scalar( $item[ $key ] ?? null ) ? trim( (string) $item[ $key ] ) : '';
			}
			if ( '' !== $row['primary'] ) {
				$list_items[] = $row;
			}
		}

		$scheme     = strtolower( $str( 'scheme' ) );
		$emphasis   = strtolower( $str( 'qr_emphasis' ) );
		$background = strtolower( $str( 'background' ) );

		$tint = strtolower( $str( 'background_tint' ) );
		$tint = 1 === preg_match( '/^#?[0-9a-f]{6}$/', $tint ) ? '#' . ltrim( $tint, '#' ) : '';

		$overlay = $data['background_overlay'] ?? null;
		$overlay = is_numeric( $overlay ) ? min( 1.0, max( 0.0, (float) $overlay ) ) : self::BACKGROUND_OVERLAY_DEFAULT;

		return array(
			'title'              => $str( 'title' ),
			'kicker'             => $str( 'kicker' ),
			'tagline'            => $str( 'tagline' ),
			'subline'            => $str( 'subline' ),
			'detail_lines'       => $detail_lines,
			'accent_line'        => $str( 'accent_line' ),
			'detail_note'        => $str( 'detail_note' ),
			'banner_text'        => $str( 'banner_text' ),
			'qr_url'             => $str( 'qr_url' ),
			'qr_label'           => $str( 'qr_label' ),
			'qr_emphasis'        => in_array( $emphasis, self::QR_EMPHASES, true ) ? $emphasis : self::QR_EMPHASES[0],
			'cta_text'           => $str( 'cta_text' ),
			'footer_note'        => $str( 'footer_note' ),
			'cobrand_logos'      => $cobrand,
			'scheme'             => in_array( $scheme, self::SCHEMES, true ) ? $scheme : self::SCHEMES[0],
			'background'         => in_array( $background, self::BACKGROUNDS, true ) ? $background : self::BACKGROUNDS[0],
			'background_image'   => $str( 'background_image' ),
			'background_tint'    => $tint,
			'background_overlay' => $overlay,
			'list_heading'       => $str( 'list_heading' ),
			'list_items'         => $list_items,
		);
	}

	/**
	 * Default kicker: "<BRAND> PRESENTS", or empty without a brand name.
	 *
	 * @param string $brand_text BrandTokens brand_text.
	 */
	public static function default_kicker( string $brand_text ): string {
		$brand_text = trim( $brand_text );
		return '' !== $brand_text ? mb_strtoupper( $brand_text . ' presents' ) : '';
	}

	/**
	 * Draw the poster onto an already-created canvas.
	 *
	 * @param GDRenderer $r      Renderer with a canvas.
	 * @param array      $fields Output of normalize_fields().
	 * @param array      $tokens Brand tokens.
	 */
	private function draw( GDRenderer $r, array $fields, array $tokens ): void {
		$width  = $r->get_width();
		$height = $r->get_height();
		$u      = $width / self::BASE_WIDTH;
		$px     = static fn( float $n ): int => (int) round( $n * $u );

		$r->register_font( 'heading', (string) ( $tokens['fonts']['heading'] ?? '' ) );
		$r->register_font( 'body', (string) ( $tokens['fonts']['body'] ?? '' ) );

		$palette = self::palette( (array) ( $tokens['colors'] ?? array() ), $fields['scheme'] );
		$colors  = array(
			'text'   => $r->color_hex( 'text', $palette['text'] ),
			'muted'  => $r->color_hex( 'muted', $palette['muted'] ),
			'accent' => $r->color_hex( 'accent', $palette['accent'] ),
			'ink'    => $r->color_hex( 'ink', $palette['banner_text'] ),
			'banner' => $r->color_hex( 'banner', $palette['banner'] ),
			'rule'   => $r->color_hex( 'rule', $palette['rule'] ),
		);
		$r->fill( $r->color_hex( 'bg', $palette['background'] ) );
		self::draw_background( $r, $fields, $palette, $width, $height, $u );

		// Light canvases take the standard logo; dark ones the inverse.
		$tokens['logo_path_inverse'] = 'dark' === $fields['scheme']
			? (string) ( $tokens['logo_path_inverse'] ?? '' )
			: (string) ( $tokens['logo_path'] ?? $tokens['logo_path_inverse'] ?? '' );

		if ( '' === $fields['kicker'] ) {
			$fields['kicker'] = self::default_kicker( (string) ( $tokens['brand_text'] ?? '' ) );
		}
		$fields['cobrand_logos'] = array_values( array_filter( $fields['cobrand_logos'], 'is_readable' ) );

		// QR-hero layout; falls through to the standard layouts when no
		// QR provider answers the filter.
		if ( 'hero' === $fields['qr_emphasis'] && $this->draw_hero( $r, $fields, $tokens, $colors ) ) {
			return;
		}

		if ( $width > $height ) {
			$this->draw_landscape( $r, $fields, $tokens, $colors );
			return;
		}

		$text   = $colors['text'];
		$muted  = $colors['muted'];
		$accent = $colors['accent'];
		$margin = $px( self::MARGIN );
		$max_w  = $width - 2 * $margin;

		// Brand mark, top-right.
		$logo   = (string) $tokens['logo_path_inverse'];
		$logo_h = $px( self::LOGO_HEIGHT );
		$logo_w = 0;
		$dims   = ( '' !== $logo && is_readable( $logo ) ) ? getimagesize( $logo ) : false;
		if ( $dims ) {
			$logo_w = (int) round( $dims[0] * $logo_h / max( 1, $dims[1] ) );
		}

		// Footer block: QR (+ label) on the right, CTA + note on the left.
		$qr_path  = self::qr_image_path( $fields['qr_url'], $px( self::QR_SIZE ) );
		$qr_size  = $qr_path ? $px( self::QR_SIZE ) : 0;
		$qr_lab_h = ( $qr_path && '' !== $fields['qr_label'] ) ? $px( 6 ) + $r->measure_text_height( $fields['qr_label'], $px( self::QR_LABEL_SIZE ), 'body', $qr_size, 1.3 ) : 0;
		$foot_w   = $max_w - ( $qr_size ? $qr_size + $px( 30 ) : 0 );
		$cta      = $fields['cta_text'];
		$note     = $fields['footer_note'];
		$cobrand  = $fields['cobrand_logos'];
		$cb_h     = $px( self::COBRAND_HEIGHT );

		$cta_h    = $r->measure_text_height( $cta, $px( self::CTA_SIZE ), 'body', $foot_w, 1.5 );
		$note_h   = ( '' !== $note || $cobrand ) ? max( $cb_h, $r->measure_text_height( $note, $px( self::FOOTNOTE_SIZE ), 'body', $foot_w, 1.3 ) ) + $px( 14 ) : 0;
		$footer_h = max( $cta_h + $note_h, $qr_size + $qr_lab_h ) + $px( 20 );

		$has_banner = '' !== $fields['banner_text'];
		$banner_h   = $has_banner ? $px( self::BANNER_HEIGHT ) : 0;

		// Content block: shrink the title until it clears the banner.
		$title_w = $max_w - ( $logo_w ? $logo_w + $px( 30 ) : 0 );
		$lines   = array_values( array_filter( array( $fields['tagline'], $fields['subline'] ) ) );

		$title_size = $px( self::TITLE_MAX_SIZE );
		do {
			$content_h = $this->content_height( $r, $fields, $lines, $title_size, $title_w, $max_w, $px );
			$plan      = self::plan_layout( $height, $margin, $content_h, $banner_h, $footer_h, $px( self::MIN_BANNER_GAP ) );
			if ( $plan['fits'] ) {
				break;
			}
			// Trim the list to a few rows before shrinking the headline,
			// then shrink the headline, then trim the list further.
			if ( count( $fields['list_items'] ) > self::LIST_MIN_ROWS ) {
				array_pop( $fields['list_items'] );
				continue;
			}
			if ( $title_size > $px( self::TITLE_MIN_SIZE ) ) {
				$title_size -= $px( 4 );
				continue;
			}
			if ( ! $fields['list_items'] ) {
				break;
			}
			array_pop( $fields['list_items'] );
		} while ( true );

		$y = $plan['content_top'];

		if ( $logo_w ) {
			$r->overlay_image( $logo, $width - $margin - $logo_w, max( $px( 20 ), $y - $px( 20 ) ), $logo_w, $logo_h );
		}

		$y = $r->draw_text_wrapped( $fields['kicker'], $px( self::KICKER_SIZE ), $margin, $y, $accent, 'body', $max_w, 1.3 ) + $px( 24 );
		$y = $r->draw_text_wrapped( $fields['title'], $title_size, $margin, $y, $text, 'heading', $title_w, 1.08 ) + $px( 30 );
		$r->filled_rect( $margin, $y, $margin + $px( 140 ), $y + $px( 10 ), $accent );
		$y += $px( 40 );
		foreach ( $lines as $i => $line ) {
			$y = $r->draw_text_wrapped( $line, $px( self::BODY_SIZE ), $margin, $y, 0 === $i ? $text : $muted, 'body', $max_w, 1.35 ) + $px( 10 );
		}
		$y += $px( 40 );
		if ( $fields['list_items'] ) {
			$y = self::draw_list( $r, $fields, $margin, $y, $max_w, $colors, $px ) + $px( 40 );
		}
		foreach ( $fields['detail_lines'] as $line ) {
			$y = $r->draw_text_wrapped( $line, self::fit_word_size( $r, $line, $px( self::DETAIL_SIZE ), 'heading', $max_w ), $margin, $y, $text, 'heading', $max_w, 1.15 );
		}
		if ( '' !== $fields['accent_line'] ) {
			$y = $r->draw_text_wrapped( $fields['accent_line'], self::fit_word_size( $r, $fields['accent_line'], $px( self::DETAIL_SIZE ), 'heading', $max_w ), $margin, $y, $accent, 'heading', $max_w, 1.15 );
		}
		if ( '' !== $fields['detail_note'] ) {
			$r->draw_text_wrapped( $fields['detail_note'], $px( self::NOTE_LINE_SIZE ), $margin, $y + $px( 6 ), $muted, 'body', $max_w, 1.4 );
		}

		// Banner.
		$banner_top = $plan['banner_top'];
		if ( $has_banner ) {
			$r->filled_rect( $margin, $banner_top, $width - $margin, $banner_top + $banner_h, $colors['banner'] );
			$size = $px( self::BANNER_TEXT_SIZE );
			while ( $size > 12 && $r->measure_text_width( $fields['banner_text'], $size, 'heading' ) > $max_w - $px( 60 ) ) {
				--$size;
			}
			$r->draw_text( $fields['banner_text'], $size, $margin + $px( 30 ), $banner_top + (int) ( ( $banner_h + $size ) / 2 ), $colors['ink'], 'heading' );
		}

		// Footer.
		$fy = $banner_top + $banner_h + $px( 20 );
		if ( $qr_path ) {
			$qr_x = $width - $margin - $qr_size;
			$r->overlay_image( $qr_path, $qr_x, $fy, $qr_size, $qr_size );
			if ( '' !== $fields['qr_label'] ) {
				$r->draw_text_wrapped( $fields['qr_label'], $px( self::QR_LABEL_SIZE ), $qr_x, $fy + $qr_size + $px( 6 ), $muted, 'body', $qr_size, 1.3, 'center' );
			}
			wp_delete_file( $qr_path );
		}
		$fy = $r->draw_text_wrapped( $cta, $px( self::CTA_SIZE ), $margin, $fy, $text, 'body', $foot_w, 1.5 ) + $px( 14 );
		$nx = self::draw_cobrand( $r, $cobrand, $margin, $fy, $cb_h, $px( 16 ) );
		if ( '' !== $note ) {
			$r->draw_text_wrapped( $note, $px( self::FOOTNOTE_SIZE ), $nx, $fy + (int) ( ( $cb_h - $px( self::FOOTNOTE_SIZE ) ) / 2 ) - $px( 4 ), $muted, 'body', $foot_w - ( $nx - $margin ), 1.3 );
		}
	}

	/**
	 * Two-column landscape layout (16:9 featured / Open Graph images).
	 *
	 * Left column: kicker, title, accent rule, tagline, subline, detail
	 * lines, accent line, detail note. Right column: logo at the top, QR
	 * and its label above the banner. Full-width banner along the bottom,
	 * then the footer row: co-brand logos, call to action, footer note.
	 *
	 * @param GDRenderer $r      Renderer with a filled canvas.
	 * @param array      $fields Output of normalize_fields(), kicker resolved.
	 * @param array      $tokens Brand tokens.
	 * @param array      $colors Allocated colors: text, muted, accent, ink.
	 */
	private function draw_landscape( GDRenderer $r, array $fields, array $tokens, array $colors ): void {
		$width  = $r->get_width();
		$height = $r->get_height();
		$u      = $height / self::LANDSCAPE_BASE_HEIGHT;
		$px     = static fn( float $n ): int => (int) round( $n * $u );
		$margin = $px( self::LANDSCAPE_MARGIN );

		$logo   = (string) ( $tokens['logo_path_inverse'] ?? '' );
		$logo_h = $px( self::LANDSCAPE_LOGO_HEIGHT );
		$dims   = ( '' !== $logo && is_readable( $logo ) ) ? getimagesize( $logo ) : false;
		$logo_w = $dims ? (int) round( $dims[0] * $logo_h / max( 1, $dims[1] ) ) : 0;

		$qr_path = self::qr_image_path( $fields['qr_url'], $px( self::LANDSCAPE_QR_SIZE ) );
		$qr_size = $qr_path ? $px( self::LANDSCAPE_QR_SIZE ) : 0;

		$cta     = $fields['cta_text'];
		$note    = $fields['footer_note'];
		$cobrand = $fields['cobrand_logos'];

		$has_banner = '' !== $fields['banner_text'];
		$banner_h   = $has_banner ? $px( self::LANDSCAPE_BANNER_HEIGHT ) : 0;
		$footer_h   = ( '' !== $note || '' !== $cta || $cobrand ) ? $px( 60 ) : 0;
		$right_w    = max( $logo_w, $qr_size );
		$left_w     = self::landscape_left_width( $width, $margin, $right_w, $px( 80 ) );
		$detail     = $px( self::LANDSCAPE_DETAIL_SIZE );

		// With a list, the left area splits: copy on the left, list beside it.
		$list_w = 0;
		if ( $fields['list_items'] ) {
			$area   = $left_w;
			$left_w = (int) round( $area * 0.42 );
			$list_w = $area - $left_w - $px( 70 );
		}

		$title_size = $px( self::LANDSCAPE_TITLE_MAX );
		do {
			$content_h = $r->measure_text_height( $fields['kicker'], $px( 30 ), 'body', $left_w, 1.3 ) + $px( 20 )
				+ $r->measure_text_height( $fields['title'], $title_size, 'heading', $left_w, 1.05 ) + $px( 26 ) + $px( 44 )
				+ ( '' !== $fields['tagline'] ? $r->measure_text_height( $fields['tagline'], $px( 36 ), 'body', $left_w, 1.35 ) + $px( 30 ) : 0 )
				+ ( '' !== $fields['subline'] ? $r->measure_text_height( $fields['subline'], $px( 30 ), 'body', $left_w, 1.35 ) + $px( 24 ) : 0 );
			foreach ( $fields['detail_lines'] as $line ) {
				$content_h += $r->measure_text_height( $line, self::fit_word_size( $r, $line, $detail, 'heading', $left_w ), 'heading', $left_w, 1.15 );
			}
			$content_h += ( '' !== $fields['accent_line'] ? $r->measure_text_height( $fields['accent_line'], self::fit_word_size( $r, $fields['accent_line'], $detail, 'heading', $left_w ), 'heading', $left_w, 1.15 ) : 0 )
				+ ( '' !== $fields['detail_note'] ? $px( 6 ) + $r->measure_text_height( $fields['detail_note'], $px( 30 ), 'body', $left_w, 1.4 ) : 0 );

			$plan = self::plan_layout( $height, $margin, $content_h, $banner_h, $footer_h, $px( 40 ) );
			if ( $plan['fits'] || $title_size <= $px( self::LANDSCAPE_TITLE_MIN ) ) {
				break;
			}
			$title_size -= $px( 4 );
		} while ( true );

		$banner_top = $plan['banner_top'];

		if ( $list_w ) {
			$list_x = $margin + $left_w + $px( 70 );
			$room   = $banner_top - $px( 40 ) - $margin;
			while ( $fields['list_items'] && self::list_height( $fields, $px ) > $room ) {
				array_pop( $fields['list_items'] );
			}
			$r->filled_rect( $list_x - $px( 36 ), $margin, $list_x - $px( 32 ), $banner_top - $px( 40 ), $colors['rule'] );
			self::draw_list( $r, $fields, $list_x, $margin, $list_w, $colors, $px );
		}

		if ( $logo_w ) {
			$r->overlay_image( $logo, $width - $margin - $logo_w, max( 0, $margin - $px( 20 ) ), $logo_w, $logo_h );
		}
		if ( $qr_path ) {
			// Reserve the label's real height so multi-line labels never
			// run into the banner; px( 34 ) is one line of 22px label text.
			$label_h = '' !== $fields['qr_label']
				? max( $px( 34 ), $px( 6 ) + $r->measure_text_height( $fields['qr_label'], $px( 22 ), 'body', $qr_size, 1.3 ) )
				: $px( 34 );
			$qr_x    = $width - $margin - $qr_size;
			$qr_y    = $banner_top - $px( 40 ) - $label_h - $qr_size;
			$r->overlay_image( $qr_path, $qr_x, $qr_y, $qr_size, $qr_size );
			if ( '' !== $fields['qr_label'] ) {
				$r->draw_text_wrapped( $fields['qr_label'], $px( 22 ), $qr_x, $qr_y + $qr_size + $px( 6 ), $colors['muted'], 'body', $qr_size, 1.3, 'center' );
			}
			wp_delete_file( $qr_path );
		}

		$y = $margin;
		$y = $r->draw_text_wrapped( $fields['kicker'], $px( 30 ), $margin, $y, $colors['accent'], 'body', $left_w, 1.3 ) + $px( 20 );
		$y = $r->draw_text_wrapped( $fields['title'], $title_size, $margin, $y, $colors['text'], 'heading', $left_w, 1.05 ) + $px( 26 );
		$r->filled_rect( $margin, $y, $margin + $px( 180 ), $y + $px( 12 ), $colors['accent'] );
		$y += $px( 44 );
		if ( '' !== $fields['tagline'] ) {
			$y = $r->draw_text_wrapped( $fields['tagline'], $px( 36 ), $margin, $y, $colors['text'], 'body', $left_w, 1.35 ) + $px( 30 );
		}
		if ( '' !== $fields['subline'] ) {
			$y = $r->draw_text_wrapped( $fields['subline'], $px( 30 ), $margin, $y, $colors['muted'], 'body', $left_w, 1.35 ) + $px( 24 );
		}
		foreach ( $fields['detail_lines'] as $line ) {
			$y = $r->draw_text_wrapped( $line, self::fit_word_size( $r, $line, $detail, 'heading', $left_w ), $margin, $y, $colors['text'], 'heading', $left_w, 1.15 );
		}
		if ( '' !== $fields['accent_line'] ) {
			$y = $r->draw_text_wrapped( $fields['accent_line'], self::fit_word_size( $r, $fields['accent_line'], $detail, 'heading', $left_w ), $margin, $y, $colors['accent'], 'heading', $left_w, 1.15 );
		}
		if ( '' !== $fields['detail_note'] ) {
			$r->draw_text_wrapped( $fields['detail_note'], $px( 30 ), $margin, $y + $px( 6 ), $colors['muted'], 'body', $left_w, 1.4 );
		}

		if ( $has_banner ) {
			$r->filled_rect( $margin, $banner_top, $width - $margin, $banner_top + $banner_h, $colors['banner'] );
			$size = $px( 46 );
			while ( $size > 12 && $r->measure_text_width( $fields['banner_text'], $size, 'heading' ) > $width - 2 * $margin - $px( 80 ) ) {
				--$size;
			}
			$r->draw_text( $fields['banner_text'], $size, $margin + $px( 40 ), $banner_top + (int) ( ( $banner_h + $size ) / 2 ), $colors['ink'], 'heading' );
		}

		$fy = $banner_top + $banner_h + $px( 16 );
		$nx = self::draw_cobrand( $r, $cobrand, $margin, $fy, $px( 36 ), $px( 14 ) );
		if ( '' !== $cta ) {
			$r->draw_text( $cta, $px( 24 ), $nx, $fy + $px( 27 ), $colors['text'], 'body' );
			$nx += $r->measure_text_width( $cta, $px( 24 ), 'body' ) + $px( 24 );
		}
		if ( '' !== $note ) {
			$r->draw_text( $note, $px( 24 ), $nx, $fy + $px( 27 ), $colors['muted'], 'body' );
		}
	}

	/**
	 * QR-hero layout: the QR code is the dominant element.
	 *
	 * Portrait: the QR sits on a white quiet-zone card pinned to the bottom
	 * margin, horizontally centered, at least HERO_QR_MIN_RATIO of the
	 * canvas width. The label is set large under the QR on the card. Kicker,
	 * title, tagline, detail lines, accent line and banner reflow above the
	 * card; the footer copy (CTA, note, co-brand logos) joins the content
	 * block since the QR owns the bottom of the canvas.
	 *
	 * Landscape: the card goes on the right, vertically centered, at least
	 * HERO_QR_MIN_RATIO_LANDSCAPE of the canvas height; the text column and
	 * the banner take the left.
	 *
	 * Fitting order: trim overlong lists, shrink the title, shrink the QR
	 * toward its minimum, then drop optional copy (footer note, CTA, detail
	 * note, subline, tagline, remaining list rows, detail lines) — only
	 * after all of that may the QR shrink below its minimum rather than
	 * overlap.
	 *
	 * The QR bitmap is re-requested from the provider at its final pixel
	 * size and drawn with nearest-neighbor scaling so module edges stay
	 * crisp at print resolutions.
	 *
	 * @param GDRenderer $r      Renderer with a filled canvas.
	 * @param array      $fields Output of normalize_fields(), kicker resolved.
	 * @param array      $tokens Brand tokens.
	 * @param array      $colors Allocated colors: text, muted, accent, ink, banner, rule.
	 * @return bool False when no QR provider answered (caller falls back).
	 */
	private function draw_hero( GDRenderer $r, array $fields, array $tokens, array $colors ): bool {
		$width     = $r->get_width();
		$height    = $r->get_height();
		$landscape = $width > $height;
		$u         = $landscape ? $height / self::LANDSCAPE_BASE_HEIGHT : $width / self::BASE_WIDTH;
		$px        = static fn( float $n ): int => (int) round( $n * $u );
		$margin    = $px( $landscape ? self::LANDSCAPE_MARGIN : self::MARGIN );

		$qr_pref = (int) round( $landscape ? $height * self::HERO_QR_RATIO : $width * self::HERO_QR_RATIO );
		$qr_min  = (int) round( $landscape ? $height * self::HERO_QR_MIN_RATIO_LANDSCAPE : $width * self::HERO_QR_MIN_RATIO );
		$qr_path = self::qr_image_path( $fields['qr_url'], $qr_pref );
		if ( ! $qr_path ) {
			return false;
		}

		$label        = $fields['qr_label'];
		$label_base   = $px( self::HERO_LABEL_SIZE );
		$measure_card = static function ( int $qr_size ) use ( $r, $label, $label_base ): array {
			$size = '' !== $label ? self::fit_word_size( $r, $label, $label_base, 'heading', $qr_size ) : 0;
			$h    = '' !== $label ? $r->measure_text_height( $label, $size, 'heading', $qr_size, 1.2 ) : 0;
			return array( self::hero_card( $qr_size, $h ), $size );
		};

		$qr_size = $qr_pref;
		if ( $landscape ) {
			// The card must clear the vertical margins before anything else.
			while ( $qr_size > $px( 120 ) ) {
				list( $card ) = $measure_card( $qr_size );
				if ( $card['height'] <= $height - 2 * $margin ) {
					break;
				}
				$qr_size -= $px( 16 );
			}
		}

		$logo   = (string) $tokens['logo_path_inverse'];
		$logo_h = $px( self::LOGO_HEIGHT );
		$dims   = ( '' !== $logo && is_readable( $logo ) ) ? getimagesize( $logo ) : false;
		$logo_w = $dims ? (int) round( $dims[0] * $logo_h / max( 1, $dims[1] ) ) : 0;

		$has_banner = '' !== $fields['banner_text'];
		$banner_h   = $has_banner ? $px( self::BANNER_HEIGHT ) : 0;
		$min_gap    = $px( self::MIN_BANNER_GAP );

		$title_size = $px( self::TITLE_MAX_SIZE );
		$drops      = array( 'footer_note', 'cta_text', 'detail_note', 'subline', 'tagline' );
		do {
			list( $card, $label_sz ) = $measure_card( $qr_size );

			$max_w   = $landscape
				? self::landscape_left_width( $width, $margin, $card['width'], $px( 80 ) )
				: $width - 2 * $margin;
			$title_w = $max_w - ( $logo_w ? $logo_w + $px( 30 ) : 0 );

			$lines     = array_values( array_filter( array( $fields['tagline'], $fields['subline'] ) ) );
			$content_h = $this->hero_content_height( $r, $fields, $lines, $title_size, $title_w, $max_w, $px );
			$plan      = $landscape
				? self::plan_layout( $height, $margin, $content_h, $banner_h, 0, $min_gap )
				: self::plan_hero_layout( $height, $margin, $content_h, $banner_h, $card['height'], $min_gap );
			if ( $plan['fits'] ) {
				break;
			}
			if ( count( $fields['list_items'] ) > self::LIST_MIN_ROWS ) {
				array_pop( $fields['list_items'] );
				continue;
			}
			if ( $title_size > $px( self::TITLE_MIN_SIZE ) ) {
				$title_size -= $px( 4 );
				continue;
			}
			if ( ! $landscape && $qr_size > $qr_min ) {
				$qr_size = max( $qr_min, $qr_size - $px( 16 ) );
				continue;
			}
			if ( $drops ) {
				$fields[ array_shift( $drops ) ] = '';
				continue;
			}
			if ( $fields['list_items'] ) {
				array_pop( $fields['list_items'] );
				continue;
			}
			if ( $fields['detail_lines'] ) {
				array_pop( $fields['detail_lines'] );
				continue;
			}
			// Last resort: shrink the QR below its minimum rather than overlap.
			if ( $qr_size > (int) round( ( $landscape ? $height : $width ) / 4 ) ) {
				$qr_size -= $px( 16 );
				continue;
			}
			break;
		} while ( true );

		// Re-request the QR at its final size so the provider renders the
		// exact bitmap drawn — no lossy downscale of module edges.
		if ( $qr_size !== $qr_pref ) {
			$crisp = self::qr_image_path( $fields['qr_url'], $qr_size );
			if ( $crisp ) {
				wp_delete_file( $qr_path );
				$qr_path = $crisp;
			}
		}

		// White quiet-zone card with the QR and its label.
		if ( $landscape ) {
			$card_x   = $width - $margin - $card['width'];
			$card_top = $margin + (int) floor( ( $height - 2 * $margin - $card['height'] ) / 2 );
		} else {
			// Portrait always plans with plan_hero_layout(), which sets card_top.
			$card_x   = (int) round( ( $width - $card['width'] ) / 2 );
			$card_top = $plan['card_top'] ?? ( $height - $margin - $card['height'] );
		}
		$card_bg  = $r->color_hex( 'qr_card', '#ffffff' );
		$card_ink = $r->color_hex( 'qr_card_ink', '#111111' );
		$r->filled_rect( $card_x, $card_top, $card_x + $card['width'], $card_top + $card['height'], $card_bg );
		$qr_x = $card_x + $card['pad'];
		$qr_y = $card_top + $card['pad'];
		$r->overlay_image( $qr_path, $qr_x, $qr_y, $qr_size, $qr_size, 100, false );
		if ( '' !== $label ) {
			$r->draw_text_wrapped( $label, $label_sz, $qr_x, $qr_y + $qr_size + $card['pad'], $card_ink, 'heading', $qr_size, 1.2, 'center' );
		}
		wp_delete_file( $qr_path );

		$text   = $colors['text'];
		$muted  = $colors['muted'];
		$accent = $colors['accent'];

		$y = $plan['content_top'];
		if ( $logo_w ) {
			$r->overlay_image( $logo, $margin + $max_w - $logo_w, max( $px( 20 ), $y - $px( 20 ) ), $logo_w, $logo_h );
		}

		$y = $r->draw_text_wrapped( $fields['kicker'], $px( self::KICKER_SIZE ), $margin, $y, $accent, 'body', $max_w, 1.3 ) + $px( 24 );
		$y = $r->draw_text_wrapped( $fields['title'], $title_size, $margin, $y, $text, 'heading', $title_w, 1.08 ) + $px( 30 );
		$r->filled_rect( $margin, $y, $margin + $px( 140 ), $y + $px( 10 ), $accent );
		$y += $px( 40 );
		foreach ( $lines as $i => $line ) {
			$y = $r->draw_text_wrapped( $line, $px( self::BODY_SIZE ), $margin, $y, 0 === $i ? $text : $muted, 'body', $max_w, 1.35 ) + $px( 10 );
		}
		$y += $px( 40 );
		if ( $fields['list_items'] ) {
			$y = self::draw_list( $r, $fields, $margin, $y, $max_w, $colors, $px ) + $px( 40 );
		}
		foreach ( $fields['detail_lines'] as $line ) {
			$y = $r->draw_text_wrapped( $line, self::fit_word_size( $r, $line, $px( self::DETAIL_SIZE ), 'heading', $max_w ), $margin, $y, $text, 'heading', $max_w, 1.15 );
		}
		if ( '' !== $fields['accent_line'] ) {
			$y = $r->draw_text_wrapped( $fields['accent_line'], self::fit_word_size( $r, $fields['accent_line'], $px( self::DETAIL_SIZE ), 'heading', $max_w ), $margin, $y, $accent, 'heading', $max_w, 1.15 );
		}
		if ( '' !== $fields['detail_note'] ) {
			$y = $r->draw_text_wrapped( $fields['detail_note'], $px( self::NOTE_LINE_SIZE ), $margin, $y + $px( 6 ), $muted, 'body', $max_w, 1.4 );
		}
		if ( '' !== $fields['cta_text'] ) {
			$y = $r->draw_text_wrapped( $fields['cta_text'], $px( self::CTA_SIZE ), $margin, $y + $px( 20 ), $text, 'body', $max_w, 1.5 );
		}
		if ( '' !== $fields['footer_note'] ) {
			$y = $r->draw_text_wrapped( $fields['footer_note'], $px( self::FOOTNOTE_SIZE ), $margin, $y + $px( 10 ), $muted, 'body', $max_w, 1.3 );
		}
		if ( $fields['cobrand_logos'] ) {
			self::draw_cobrand( $r, $fields['cobrand_logos'], $margin, $y + $px( 20 ), $px( self::COBRAND_HEIGHT ), $px( 16 ) );
		}

		// Banner: full content width (the left column on landscape).
		if ( $has_banner ) {
			$banner_top = $plan['banner_top'];
			$r->filled_rect( $margin, $banner_top, $margin + $max_w, $banner_top + $banner_h, $colors['banner'] );
			$size = $px( self::BANNER_TEXT_SIZE );
			while ( $size > 12 && $r->measure_text_width( $fields['banner_text'], $size, 'heading' ) > $max_w - $px( 60 ) ) {
				--$size;
			}
			$r->draw_text( $fields['banner_text'], $size, $margin + $px( 30 ), $banner_top + (int) ( ( $banner_h + $size ) / 2 ), $colors['ink'], 'heading' );
		}

		return true;
	}

	/**
	 * Height of the hero content block: the portrait content block plus the
	 * footer copy (CTA, footer note, co-brand logos), which moves into the
	 * content column because the hero QR card owns the bottom of the canvas.
	 *
	 * Mirrors the vertical advances in draw_hero(); keep the two in sync.
	 */
	private function hero_content_height( GDRenderer $r, array $fields, array $lines, int $title_size, int $title_w, int $max_w, callable $px ): int {
		$h = $this->content_height( $r, $fields, $lines, $title_size, $title_w, $max_w, $px );
		if ( '' !== $fields['cta_text'] ) {
			$h += $px( 20 ) + $r->measure_text_height( $fields['cta_text'], $px( self::CTA_SIZE ), 'body', $max_w, 1.5 );
		}
		if ( '' !== $fields['footer_note'] ) {
			$h += $px( 10 ) + $r->measure_text_height( $fields['footer_note'], $px( self::FOOTNOTE_SIZE ), 'body', $max_w, 1.3 );
		}
		if ( $fields['cobrand_logos'] ) {
			$h += $px( 20 ) + $px( self::COBRAND_HEIGHT );
		}
		return $h;
	}

	/**
	 * Metrics of the hero quiet-zone card for a QR of the given pixel size.
	 *
	 * The card pads the QR by HERO_QUIET_MODULES modules on every side
	 * (module size estimated from HERO_QR_MODULES), and reserves the label
	 * height plus another quiet zone below the QR when a label is present.
	 *
	 * @param int $qr_size      QR side length in pixels.
	 * @param int $label_height Measured label height (0 without a label).
	 * @return array{pad: int, width: int, height: int}
	 */
	public static function hero_card( int $qr_size, int $label_height ): array {
		$pad = (int) ceil( $qr_size * self::HERO_QUIET_MODULES / self::HERO_QR_MODULES );
		return array(
			'pad'    => $pad,
			'width'  => $qr_size + 2 * $pad,
			'height' => $qr_size + 2 * $pad + ( $label_height > 0 ? $label_height + $pad : 0 ),
		);
	}

	/**
	 * Place the content block, banner and hero QR card on a portrait canvas.
	 *
	 * The card is pinned to the bottom margin; the banner sits min_gap above
	 * it, and the content block above that, with spare space distributed the
	 * same way plan_layout() does.
	 *
	 * @param int $height         Canvas height.
	 * @param int $margin         Outer margin.
	 * @param int $content_height Height of the content block.
	 * @param int $banner_height  Banner height (0 without a banner).
	 * @param int $card_height    Hero QR card height.
	 * @param int $min_gap        Minimum gap between stacked blocks.
	 * @return array{fits: bool, content_top: int, content_bottom: int, banner_top: int, card_top: int}
	 */
	public static function plan_hero_layout( int $height, int $margin, int $content_height, int $banner_height, int $card_height, int $min_gap ): array {
		$card_top   = $height - $margin - $card_height;
		$banner_top = $card_top - ( $banner_height > 0 ? $min_gap + $banner_height : 0 );
		$slack      = $banner_top - $min_gap - ( $margin + $content_height );
		$top        = $margin + ( $slack > 0 ? (int) floor( $slack * self::SLACK_ABOVE ) : 0 );

		return array(
			'fits'           => $slack >= 0,
			'content_top'    => $top,
			'content_bottom' => $top + $content_height,
			'banner_top'     => $banner_top,
			'card_top'       => $card_top,
		);
	}

	/**
	 * Draw co-brand logos in a row; returns the x after the last logo.
	 *
	 * @param GDRenderer $r      Renderer.
	 * @param string[]   $logos  Readable image paths.
	 * @param int        $x      Start x.
	 * @param int        $y      Top y.
	 * @param int        $height Logo height.
	 * @param int        $gap    Gap after each logo.
	 */
	private static function draw_cobrand( GDRenderer $r, array $logos, int $x, int $y, int $height, int $gap ): int {
		foreach ( $logos as $logo_path ) {
			$dims = getimagesize( $logo_path );
			if ( ! $dims ) {
				continue;
			}
			$w = (int) round( $dims[0] * $height / max( 1, $dims[1] ) );
			$r->overlay_image( $logo_path, $x, $y, $w, $height );
			$x += $w + $gap;
		}
		return $x;
	}

	/**
	 * Resolve a scheme to hex colors from brand tokens.
	 *
	 * dark: the brand's dark palette (falls back to light-derived tokens).
	 * accent: canvas flooded with the brand accent, dark type, dark
	 * banner carrying accent-colored text. light: brand background, dark
	 * type, accent highlights and banner.
	 *
	 * @param array  $c      BrandTokens colors.
	 * @param string $scheme One of self::SCHEMES.
	 * @return array{background: string, text: string, muted: string, accent: string, banner: string, banner_text: string, rule: string, stripe: string}
	 */
	public static function palette( array $c, string $scheme ): array {
		$get       = static fn( array $keys, string $fallback ): string => (string) ( array_values( array_filter( array_map( static fn( $k ) => $c[ $k ] ?? null, $keys ) ) )[0] ?? $fallback );
		$dark_bg   = $get( array( 'dark_background', 'background_dark' ), '#1a1a1a' );
		$dark_text = $get( array( 'dark_text', 'text_inverse' ), '#e5e5e5' );
		$accent    = $get( array( 'dark_accent', 'accent' ), '#53940b' );

		if ( 'accent' === $scheme ) {
			return array(
				'background'  => $accent,
				'text'        => $dark_bg,
				'muted'       => self::mix( $dark_bg, $accent, 0.7 ),
				'accent'      => '#ffffff',
				'banner'      => $dark_bg,
				'banner_text' => $accent,
				'rule'        => self::mix( $dark_bg, $accent, 0.35 ),
				'stripe'      => 'dark',
			);
		}
		if ( 'light' === $scheme ) {
			$ink   = $get( array( 'text_primary' ), '#000000' );
			$green = $get( array( 'accent' ), '#53940b' );
			return array(
				'background'  => $get( array( 'background' ), '#ffffff' ),
				'text'        => $ink,
				'muted'       => $get( array( 'text_muted' ), '#6b7280' ),
				'accent'      => $green,
				'banner'      => $green,
				'banner_text' => '#ffffff',
				'rule'        => $get( array( 'border' ), '#dddddd' ),
				'stripe'      => 'dark',
			);
		}
		return array(
			'background'  => $dark_bg,
			'text'        => $dark_text,
			'muted'       => $get( array( 'dark_muted', 'text_muted' ), '#b0b0b0' ),
			'accent'      => $accent,
			'banner'      => $accent,
			'banner_text' => $dark_bg,
			'rule'        => self::mix( $dark_text, $dark_bg, 0.25 ),
			'stripe'      => 'light',
		);
	}

	/**
	 * Blend two hex colors: $weight of $a, the rest $b.
	 *
	 * @param string $a      Hex color.
	 * @param string $b      Hex color.
	 * @param float  $weight Share of $a (0..1).
	 */
	private static function mix( string $a, string $b, float $weight ): string {
		$a   = array_map( 'hexdec', str_split( substr( ltrim( $a, '#' ) . '000000', 0, 6 ), 2 ) );
		$b   = array_map( 'hexdec', str_split( substr( ltrim( $b, '#' ) . '000000', 0, 6 ), 2 ) );
		$out = '#';
		foreach ( array( 0, 1, 2 ) as $i ) {
			$out .= sprintf( '%02x', (int) round( $a[ $i ] * $weight + $b[ $i ] * ( 1 - $weight ) ) );
		}
		return $out;
	}

	/**
	 * Height of one list row.
	 *
	 * @param array    $row Normalized list row.
	 * @param callable $px  Design-unit scaler.
	 */
	private static function list_row_height( array $row, callable $px ): int {
		$h = $px( self::LIST_ROW_PAD ) * 2 + (int) ( $px( self::LIST_PRIMARY_SIZE ) * 1.2 );
		if ( '' !== $row['secondary'] ) {
			$h += $px( 6 ) + (int) ( $px( self::LIST_SECOND_SIZE ) * 1.3 );
		}
		return $h;
	}

	/**
	 * Height of the list block (heading plus rows).
	 *
	 * @param array    $fields Normalized fields.
	 * @param callable $px     Design-unit scaler.
	 */
	public static function list_height( array $fields, callable $px ): int {
		if ( ! $fields['list_items'] ) {
			return 0;
		}
		$h = '' !== $fields['list_heading'] ? (int) ( $px( self::LIST_HEADING_SIZE ) * 1.3 ) + $px( 10 ) : 0;
		foreach ( $fields['list_items'] as $row ) {
			$h += self::list_row_height( $row, $px );
		}
		return $h;
	}

	/**
	 * Draw the ruled list block; returns the y after the last row.
	 *
	 * Each row: primary line (heading font) with the meta value
	 * right-aligned beside it, secondary line (body, muted) under it, and
	 * a hairline rule below. Lines are truncated, never wrapped, so every
	 * row has the same rhythm.
	 *
	 * @param GDRenderer $r      Renderer.
	 * @param array      $fields Normalized fields.
	 * @param int        $x      Left edge.
	 * @param int        $y      Top.
	 * @param int        $w      Block width.
	 * @param array      $colors Allocated colors.
	 * @param callable(float): int $px Design-unit scaler.
	 */
	private static function draw_list( GDRenderer $r, array $fields, int $x, int $y, int $w, array $colors, callable $px ): int {
		if ( '' !== $fields['list_heading'] ) {
			$y = $r->draw_text_wrapped( self::truncate( $r, $fields['list_heading'], $px( self::LIST_HEADING_SIZE ), 'body', $w ), $px( self::LIST_HEADING_SIZE ), $x, $y, $colors['accent'], 'body', $w + 1, 1.3 ) + $px( 10 );
		}
		$rule_h = max( 1, $px( 2 ) );
		$r->filled_rect( $x, $y - $rule_h, $x + $w, $y, $colors['rule'] );

		$primary = $px( self::LIST_PRIMARY_SIZE );
		$second  = $px( self::LIST_SECOND_SIZE );
		$meta_sz = $px( self::LIST_META_SIZE );
		foreach ( $fields['list_items'] as $row ) {
			$top    = $y;
			$y     += $px( self::LIST_ROW_PAD );
			$meta_w = '' !== $row['meta'] ? $r->measure_text_width( $row['meta'], $meta_sz, 'heading' ) + $px( 24 ) : 0;
			if ( $meta_w ) {
				$r->draw_text( $row['meta'], $meta_sz, $x + $w - $meta_w + $px( 24 ), $y + $primary, $colors['accent'], 'heading' );
			}
			// Shrink long names up to 20% before resorting to an ellipsis.
			$size = $primary;
			while ( $size > (int) ( $primary * 0.8 ) && $r->measure_text_width( $row['primary'], $size, 'heading' ) > $w - $meta_w ) {
				--$size;
			}
			$r->draw_text( self::truncate( $r, $row['primary'], $size, 'heading', $w - $meta_w ), $size, $x, $y + $primary, $colors['text'], 'heading' );
			$y += (int) ( $primary * 1.2 );
			if ( '' !== $row['secondary'] ) {
				$y += $px( 6 );
				$r->draw_text( self::truncate( $r, $row['secondary'], $second, 'body', $w ), $second, $x, $y + $second, $colors['muted'], 'body' );
				$y += (int) ( $second * 1.3 );
			}
			$y = $top + self::list_row_height( $row, $px );
			$r->filled_rect( $x, $y - $rule_h, $x + $w, $y, $colors['rule'] );
		}
		return $y;
	}

	/**
	 * Largest size (up to $size) at which no single word overflows $w.
	 *
	 * Wrapping cannot break a word, so long unbroken tokens (URLs,
	 * hostnames) would otherwise spill past their column.
	 *
	 * @param GDRenderer $r    Renderer.
	 * @param string     $text Text.
	 * @param int        $size Preferred font size.
	 * @param string     $font Registered font name.
	 * @param int        $w    Column width.
	 */
	private static function fit_word_size( GDRenderer $r, string $text, int $size, string $font, int $w ): int {
		$words = preg_split( '/\s+/', trim( $text ) );
		while ( $size > 12 ) {
			$widest = 0;
			foreach ( (array) $words as $word ) {
				$widest = max( $widest, $r->measure_text_width( (string) $word, $size, $font ) );
			}
			if ( $widest <= $w ) {
				break;
			}
			--$size;
		}
		return $size;
	}

	/**
	 * Truncate a single line to a width with an ellipsis.
	 *
	 * @param GDRenderer $r    Renderer.
	 * @param string     $text Text.
	 * @param int        $size Font size.
	 * @param string     $font Registered font name.
	 * @param int        $w    Max width.
	 */
	private static function truncate( GDRenderer $r, string $text, int $size, string $font, int $w ): string {
		if ( $r->measure_text_width( $text, $size, $font ) <= $w ) {
			return $text;
		}
		while ( mb_strlen( $text ) > 1 && $r->measure_text_width( $text . "\u{2026}", $size, $font ) > $w ) {
			$text = rtrim( mb_substr( $text, 0, -1 ) );
		}
		return $text . "\u{2026}";
	}

	/**
	 * Width of the landscape layout's left text column.
	 *
	 * @param int $width   Canvas width.
	 * @param int $margin  Outer margin.
	 * @param int $right_w Right column width (max of logo and QR widths).
	 * @param int $gutter  Gap between the columns.
	 */
	public static function landscape_left_width( int $width, int $margin, int $right_w, int $gutter ): int {
		return $width - 2 * $margin - ( $right_w > 0 ? $right_w + $gutter : 0 );
	}

	/**
	 * Draw the background treatment behind all content.
	 *
	 * Every pattern is drawn procedurally in the same low-alpha ink as the
	 * default stripes — light ink on dark schemes, dark ink on light ones,
	 * or the background_tint override — with spacing and weight scaled by
	 * the canvas unit so print presets keep the same texture density.
	 * `image` cover-fits background_image behind the content and floods the
	 * scheme background color over it at background_overlay opacity; an
	 * unreadable, unsupported or unloadable image falls back to stripes.
	 *
	 * @param GDRenderer $r       Renderer with a filled canvas.
	 * @param array      $fields  Output of normalize_fields().
	 * @param array      $palette Output of palette().
	 * @param int        $width   Canvas width.
	 * @param int        $height  Canvas height.
	 * @param float      $u       Scale unit (width / 1080).
	 */
	private static function draw_background( GDRenderer $r, array $fields, array $palette, int $width, int $height, float $u ): void {
		$background = (string) $fields['background'];
		if ( 'image' === $background ) {
			if ( self::draw_background_image( $r, (string) $fields['background_image'], (string) $palette['background'], (float) $fields['background_overlay'], $width, $height ) ) {
				return;
			}
			$background = self::BACKGROUNDS[0];
		}
		if ( 'none' === $background ) {
			return;
		}

		$tint = (string) $fields['background_tint'];
		$rgb  = self::hex_rgb( '' !== $tint ? $tint : ( 'light' === $palette['stripe'] ? '#ffffff' : '#000000' ) );
		$ink  = static fn( string $name, int $alpha ): int => $r->color( $name, $rgb[0], $rgb[1], $rgb[2], $alpha );

		switch ( $background ) {
			case 'grid':
				self::draw_grid( $r, $width, $height, $u, $ink( 'pattern', self::PATTERN_ALPHA ) );
				break;
			case 'dots':
				self::draw_dots( $r, $width, $height, $u, $ink( 'pattern', self::PATTERN_ALPHA ), false );
				break;
			case 'halftone':
				self::draw_dots( $r, $width, $height, $u, $ink( 'pattern', self::PATTERN_ALPHA ), true );
				break;
			case 'crosshatch':
				self::draw_stripes( $r, $width, $height, $u, $ink( 'stripe', self::STRIPE_ALPHA ) );
				self::draw_stripes( $r, $width, $height, $u, $ink( 'stripe', self::STRIPE_ALPHA ), -1 );
				break;
			case 'waves':
				self::draw_waves( $r, $width, $height, $u, $ink( 'pattern', self::PATTERN_ALPHA ) );
				break;
			case 'gradient':
				self::draw_gradient( $r, $width, $height, $ink );
				break;
			case 'glow':
				self::draw_glow( $r, $width, $height, $ink );
				break;
			default:
				self::draw_stripes( $r, $width, $height, $u, $ink( 'stripe', self::STRIPE_ALPHA ) );
		}
	}

	/**
	 * Cover-fit a background image behind everything, then flood the scheme
	 * background color over the full canvas at the overlay opacity so the
	 * copy drawn on top stays legible.
	 *
	 * @param GDRenderer $r       Renderer with a filled canvas.
	 * @param string     $path    Absolute path to a PNG, JPEG or WebP.
	 * @param string     $bg_hex  Scheme background color.
	 * @param float      $overlay Overlay opacity (0..1, already clamped).
	 * @param int        $width   Canvas width.
	 * @param int        $height  Canvas height.
	 * @return bool False when the file is missing, unsupported or fails to load.
	 */
	private static function draw_background_image( GDRenderer $r, string $path, string $bg_hex, float $overlay, int $width, int $height ): bool {
		if ( '' === $path || ! $r->overlay_image_cover( $path, 0, 0, $width, $height ) ) {
			return false;
		}
		$alpha = min( 127, max( 0, (int) round( 127 * ( 1 - $overlay ) ) ) );
		if ( $alpha < 127 ) {
			$rgb = self::hex_rgb( $bg_hex );
			$r->filled_rect( 0, 0, $width, $height, $r->color( 'bg_overlay', $rgb[0], $rgb[1], $rgb[2], $alpha ) );
		}
		return true;
	}

	/**
	 * Subtle diagonal stripes for texture.
	 *
	 * Near-transparent lines at a 45-degree angle, spaced 1/18 of the
	 * canvas width, so the background reads as textured, not flat.
	 *
	 * @param GDRenderer $r         Renderer with a filled canvas.
	 * @param int        $width     Canvas width.
	 * @param int        $height    Canvas height.
	 * @param float      $u         Scale unit (width / 1080).
	 * @param int        $ink       Allocated low-alpha GD color.
	 * @param int        $direction Diagonal direction: 1 rising, -1 falling.
	 */
	private static function draw_stripes( GDRenderer $r, int $width, int $height, float $u, int $ink, int $direction = 1 ): void {
		$step   = max( 1, (int) ( $width / 18 ) );
		$weight = max( 2, (int) round( 4 * $u ) );
		for ( $x = -$height; $x < $width; $x += $step ) {
			if ( $direction >= 0 ) {
				$r->draw_line( $x, $height, $x + $height, 0, $ink, $weight );
			} else {
				$r->draw_line( $x, 0, $x + $height, $height, $ink, $weight );
			}
		}
	}

	/**
	 * Thin square grid.
	 *
	 * @param GDRenderer $r      Renderer with a filled canvas.
	 * @param int        $width  Canvas width.
	 * @param int        $height Canvas height.
	 * @param float      $u      Scale unit (width / 1080).
	 * @param int        $ink    Allocated low-alpha GD color.
	 */
	private static function draw_grid( GDRenderer $r, int $width, int $height, float $u, int $ink ): void {
		$step   = max( 4, (int) round( 64 * $u ) );
		$weight = max( 1, (int) round( 2 * $u ) );
		for ( $x = 0; $x <= $width; $x += $step ) {
			$r->draw_line( $x, 0, $x, $height, $ink, $weight );
		}
		for ( $y = 0; $y <= $height; $y += $step ) {
			$r->draw_line( 0, $y, $width, $y, $ink, $weight );
		}
	}

	/**
	 * Dot matrix (staggered rows); halftone grows the dot radius toward
	 * the bottom-right corner.
	 *
	 * @param GDRenderer $r        Renderer with a filled canvas.
	 * @param int        $width    Canvas width.
	 * @param int        $height   Canvas height.
	 * @param float      $u        Scale unit (width / 1080).
	 * @param int        $ink      Allocated low-alpha GD color.
	 * @param bool       $halftone Vary the radius with corner distance.
	 */
	private static function draw_dots( GDRenderer $r, int $width, int $height, float $u, int $ink, bool $halftone ): void {
		$step  = max( 6, (int) round( 56 * $u ) );
		$base  = max( 2, (int) round( 5 * $u ) );
		$reach = hypot( $width, $height );
		$row   = 0;
		$col_w = $step;
		for ( $y = intdiv( $step, 2 ); $y < $height + $step; $y += $step ) {
			$offset = ( $row % 2 ) ? intdiv( $col_w, 2 ) : 0;
			++$row;
			for ( $x = $offset; $x < $width + $col_w; $x += $col_w ) {
				$d = 2 * $base;
				if ( $halftone ) {
					$t = 1.0 - min( 1.0, hypot( $width - $x, $height - $y ) / $reach );
					$d = max( 2, (int) round( 2 * $base * ( 0.4 + 2.4 * $t ) ) );
				}
				$r->draw_ellipse( $x, $y, $d, $d, $ink );
			}
		}
	}

	/**
	 * Horizontal sine lines, phase-shifted per row.
	 *
	 * @param GDRenderer $r      Renderer with a filled canvas.
	 * @param int        $width  Canvas width.
	 * @param int        $height Canvas height.
	 * @param float      $u      Scale unit (width / 1080).
	 * @param int        $ink    Allocated low-alpha GD color.
	 */
	private static function draw_waves( GDRenderer $r, int $width, int $height, float $u, int $ink ): void {
		$gap    = max( 8, (int) round( 72 * $u ) );
		$amp    = max( 2, (int) round( 16 * $u ) );
		$length = max( 16, (int) round( 260 * $u ) );
		$seg    = max( 4, (int) round( 10 * $u ) );
		$weight = max( 1, (int) round( 3 * $u ) );
		$row    = 0;
		for ( $y = 0; $y <= $height + $amp; $y += $gap ) {
			$phase = $row * M_PI / 3;
			++$row;
			$prev_y = $y + (int) round( $amp * sin( $phase ) );
			for ( $x = $seg; $x <= $width + $seg; $x += $seg ) {
				$next_y = $y + (int) round( $amp * sin( $x * 2 * M_PI / $length + $phase ) );
				$r->draw_line( $x - $seg, $prev_y, $x, $next_y, $ink, $weight );
				$prev_y = $next_y;
			}
		}
	}

	/**
	 * Vertical linear gradient: the scheme background at the top ramping
	 * toward a darker (light schemes) or lighter (dark schemes) shade at
	 * the bottom, built from ink bands of increasing opacity.
	 *
	 * @param GDRenderer $r      Renderer with a filled canvas.
	 * @param int        $width  Canvas width.
	 * @param int        $height Canvas height.
	 * @param callable(string, int): int $ink Ink allocator (name, GD alpha).
	 */
	private static function draw_gradient( GDRenderer $r, int $width, int $height, callable $ink ): void {
		$bands  = 48;
		$band_h = (int) ceil( $height / $bands );
		for ( $i = 1; $i < $bands; $i++ ) {
			// Fully transparent at the top, ~30% ink at the bottom.
			$alpha = 127 - (int) round( 38 * $i / ( $bands - 1 ) );
			$r->filled_rect( 0, $i * $band_h, $width, min( $height, ( $i + 1 ) * $band_h ), $ink( "gradient_{$i}", $alpha ) );
		}
	}

	/**
	 * Radial vignette: concentric near-transparent ink layers that stack
	 * toward the center, so the middle of the canvas reads lighter (dark
	 * schemes) or softly inked (light schemes) than the corners.
	 *
	 * @param GDRenderer $r      Renderer with a filled canvas.
	 * @param int        $width  Canvas width.
	 * @param int        $height Canvas height.
	 * @param callable(string, int): int $ink Ink allocator (name, GD alpha).
	 */
	private static function draw_glow( GDRenderer $r, int $width, int $height, callable $ink ): void {
		$steps = 36;
		$layer = $ink( 'glow', 126 ); // One ~1% ink layer per ellipse; they accumulate at the center.
		$cx    = intdiv( $width, 2 );
		$cy    = intdiv( $height, 2 );
		for ( $i = 0; $i < $steps; $i++ ) {
			$t = 1 - $i / $steps;
			$r->draw_ellipse( $cx, $cy, (int) round( 2 * $width * ( 0.25 + 0.75 * $t ) ), (int) round( 2 * $height * ( 0.25 + 0.75 * $t ) ), $layer );
		}
	}

	/**
	 * Parse a hex color into its RGB channels.
	 *
	 * @param string $hex Hex color (e.g. '#ff6b6b').
	 * @return array{int, int, int}
	 */
	private static function hex_rgb( string $hex ): array {
		$hex = substr( ltrim( $hex, '#' ) . '000000', 0, 6 );
		return array(
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * Height of the portrait content block (kicker through detail note).
	 *
	 * Mirrors the vertical advances in draw(); keep the two in sync.
	 */
	private function content_height( GDRenderer $r, array $fields, array $lines, int $title_size, int $title_w, int $max_w, callable $px ): int {
		$h  = $r->measure_text_height( $fields['kicker'], $px( self::KICKER_SIZE ), 'body', $max_w, 1.3 ) + $px( 24 );
		$h += $r->measure_text_height( $fields['title'], $title_size, 'heading', $title_w, 1.08 ) + $px( 30 ) + $px( 40 );
		foreach ( $lines as $line ) {
			$h += $r->measure_text_height( $line, $px( self::BODY_SIZE ), 'body', $max_w, 1.35 ) + $px( 10 );
		}
		$h += $px( 40 );
		if ( $fields['list_items'] ) {
			$h += self::list_height( $fields, $px ) + $px( 40 );
		}
		foreach ( $fields['detail_lines'] as $line ) {
			$h += $r->measure_text_height( $line, self::fit_word_size( $r, $line, $px( self::DETAIL_SIZE ), 'heading', $max_w ), 'heading', $max_w, 1.15 );
		}
		if ( '' !== $fields['accent_line'] ) {
			$h += $r->measure_text_height( $fields['accent_line'], self::fit_word_size( $r, $fields['accent_line'], $px( self::DETAIL_SIZE ), 'heading', $max_w ), 'heading', $max_w, 1.15 );
		}
		if ( '' !== $fields['detail_note'] ) {
			$h += $px( 6 ) + $r->measure_text_height( $fields['detail_note'], $px( self::NOTE_LINE_SIZE ), 'body', $max_w, 1.4 );
		}
		return $h;
	}

	/**
	 * Place the content block and banner on a canvas of the given height.
	 *
	 * The banner and footer are pinned to the bottom margin. Spare space
	 * between the content block and the banner is split so the content is
	 * not stranded at the top of tall canvases.
	 *
	 * @param int $height         Canvas height.
	 * @param int $margin         Outer margin.
	 * @param int $content_height Height of the content block.
	 * @param int $banner_height  Banner height (0 without a banner).
	 * @param int $footer_height  Footer height below the banner.
	 * @param int $min_gap        Minimum gap between content and banner.
	 * @return array{fits: bool, content_top: int, content_bottom: int, banner_top: int, footer_bottom: int}
	 */
	public static function plan_layout( int $height, int $margin, int $content_height, int $banner_height, int $footer_height, int $min_gap ): array {
		$banner_top = $height - $margin - $footer_height - $banner_height;
		$slack      = $banner_top - $min_gap - ( $margin + $content_height );
		$top        = $margin + ( $slack > 0 ? (int) floor( $slack * self::SLACK_ABOVE ) : 0 );

		return array(
			'fits'           => $slack >= 0,
			'content_top'    => $top,
			'content_bottom' => $top + $content_height,
			'banner_top'     => $banner_top,
			'footer_bottom'  => $banner_top + $banner_height + $footer_height,
		);
	}

	/**
	 * Resolve a QR image for a URL through the site's QR provider.
	 *
	 * @param string $url  QR payload.
	 * @param int    $size Drawn size in pixels (providers may render larger).
	 * @return string|null Readable temp PNG path the caller deletes, or null.
	 */
	private static function qr_image_path( string $url, int $size ): ?string {
		if ( '' === $url ) {
			return null;
		}
		/**
		 * Resolve a QR code image for a poster.
		 *
		 * @param string|null $path Null by default (no QR provider).
		 * @param string      $url  URL to encode.
		 * @param int         $size Drawn size in pixels.
		 * @return string|null Path to a temp PNG the template owns and deletes.
		 */
		// phpcs:ignore WordPress.NamingConventions.ValidHookName -- Intentional slash-separated hook namespace.
		$path = apply_filters( 'datamachine/image_generation/qr_image_path', null, $url, $size );
		return ( is_string( $path ) && '' !== $path && is_readable( $path ) ) ? $path : null;
	}
}
