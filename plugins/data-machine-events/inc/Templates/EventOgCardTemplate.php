<?php
/**
 * Event OG Card Template
 *
 * Renders a 1200x630 Open Graph card for event posts via the Data Machine
 * GDRenderer + TemplateInterface contract. Brand identity (colors, fonts)
 * comes from BrandTokens which themes/plugins populate via filter — the
 * template is intentionally brand-agnostic.
 *
 * Layout:
 *   - Top accent stripe
 *   - Date pill (top-left), location pill (top-right, optional)
 *   - Event title (left-aligned, wrapped)
 *   - Venue + city in footer surface band
 *   - Brand strip with brand_text · site_label
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
		$bg        = $renderer->color_hex( 'bg', $colors['background'] );
		$surface   = $renderer->color_hex( 'surface', $colors['surface'] );
		$accent    = $renderer->color_hex( 'accent', $colors['accent'] );
		$text_pri  = $renderer->color_hex( 'text_pri', $colors['text_primary'] );
		$text_mute = $renderer->color_hex( 'text_mute', $colors['text_muted'] );
		$text_inv  = $renderer->color_hex( 'text_inv', $colors['text_inverse'] );
		$text_on_accent = $renderer->color_hex( 'text_on_accent', $accent_text );
		$header_bg = $renderer->color_hex( 'header_bg', $colors['header_bg'] );

		// Layout sections.
		$renderer->fill( $bg );

		// Top accent band.
		$renderer->filled_rect( 0, 0, $width, 8, $accent );

		// Footer band (surface) where venue/city sit.
		$footer_band_y = (int) ( $height * 0.72 );
		$renderer->filled_rect( 0, $footer_band_y, $width, $height, $surface );

		// Bottom branding strip (header_bg = deep black by default for
		// brand text contrast).
		$brand_strip_h = 64;
		$brand_strip_y = $height - $brand_strip_h;
		$renderer->filled_rect( 0, $brand_strip_y, $width, $height, $header_bg );

		// Register fonts from brand tokens. GDRenderer handles fallback
		// to system DejaVu when a path is missing or null.
		$heading_path = $fonts['heading'] ?? '';
		$body_path    = $fonts['body'] ?? '';
		$brand_path   = $fonts['brand'] ?? '';

		$renderer->register_font( 'header', is_string( $heading_path ) && $heading_path ? $heading_path : 'Heading.ttf' );
		$renderer->register_font( 'body',   is_string( $body_path )    && $body_path    ? $body_path    : 'Body.ttf' );
		$renderer->register_font( 'brand',  is_string( $brand_path )   && $brand_path   ? $brand_path   : ( is_string( $heading_path ) && $heading_path ? $heading_path : 'Brand.ttf' ) );

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

		// Venue + city in surface footer band.
		$venue = $this->normalize_text( $data['venue'] ?? '' );
		$city  = $this->normalize_text( $data['city'] ?? '' );

		$venue_font_size = 34;
		$city_font_size  = 26;
		$venue_y         = $footer_band_y + 40;

		if ( '' !== $venue ) {
			$renderer->draw_text(
				$venue,
				$venue_font_size,
				$padding,
				$venue_y + $venue_font_size,
				$text_pri,
				'header'
			);
		}

		if ( '' !== $city ) {
			$city_y = '' !== $venue ? $venue_y + $venue_font_size + 18 : $venue_y;
			$renderer->draw_text(
				$city,
				$city_font_size,
				$padding,
				$city_y + $city_font_size,
				$text_mute,
				'body'
			);
		}

		// Brand strip at the bottom — "<brand_text> · <site_label>".
		// Uses the `body` font (typically Helvetica) instead of `brand`/`heading`
		// because display fonts like Wilco Loft Sans lack punctuation glyphs.
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
			$brand_text_y    = $brand_strip_y + (int) ( ( $brand_strip_h + $brand_font_size ) / 2 ) - 4;
			$renderer->draw_text(
				$combined_brand,
				$brand_font_size,
				$padding,
				$brand_text_y,
				$text_inv,
				'body'
			);
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
