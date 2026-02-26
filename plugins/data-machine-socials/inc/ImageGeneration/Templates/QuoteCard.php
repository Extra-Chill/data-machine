<?php
/**
 * Quote Card Template
 *
 * Generates Instagram-friendly quote graphics from interview content.
 * Renders quote text, attribution, and branding onto a branded canvas.
 *
 * Auto-scales font size based on quote length for optimal readability.
 * Supports multiple quotes per interview (generates one image per quote).
 *
 * @package DataMachineSocials\ImageGeneration\Templates
 * @since 0.2.0
 */

namespace DataMachineSocials\ImageGeneration\Templates;

use DataMachine\Abilities\Media\GDRenderer;
use DataMachine\Abilities\Media\TemplateInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QuoteCard implements TemplateInterface {

	/**
	 * Layout constants.
	 */
	private const PADDING         = 80;
	private const QUOTE_MARK_SIZE = 120;

	/**
	 * Font size ranges for auto-scaling.
	 * Longer quotes get smaller text.
	 */
	private const FONT_SIZES = array(
		'short'  => 48, // Under 80 chars.
		'medium' => 40, // 80-160 chars.
		'long'   => 32, // 160-280 chars.
		'extra'  => 26, // 280+ chars.
	);

	/**
	 * Color palette — Extra Chill brand.
	 */
	private const COLORS = array(
		'background'  => array( 26, 26, 26 ),      // #1a1a1a — dark.
		'quote_text'  => array( 245, 245, 245 ),    // #f5f5f5 — near-white.
		'attribution' => array( 176, 176, 176 ),    // #b0b0b0 — muted.
		'accent'      => array( 83, 148, 11 ),      // #53940b — EC green.
		'quote_mark'  => array( 83, 148, 11 ),      // #53940b — EC green.
		'branding'    => array( 120, 120, 120 ),    // #787878 — subtle.
	);

	public function get_id(): string {
		return 'quote_card';
	}

	public function get_name(): string {
		return 'Quote Card';
	}

	public function get_description(): string {
		return 'Instagram-friendly quote graphic from interview content. Renders quote text with attribution and Extra Chill branding.';
	}

	public function get_fields(): array {
		return array(
			'quote_text'   => array(
				'label'    => 'Quote Text',
				'type'     => 'string',
				'required' => true,
			),
			'attribution'  => array(
				'label'    => 'Attribution',
				'type'     => 'string',
				'required' => true,
			),
			'source_title' => array(
				'label'    => 'Source Title',
				'type'     => 'string',
				'required' => false,
			),
			'branding'     => array(
				'label'    => 'Branding Text',
				'type'     => 'string',
				'required' => false,
			),
		);
	}

	public function get_default_preset(): string {
		return 'instagram_feed_portrait';
	}

	/**
	 * Render quote card(s).
	 *
	 * If `data['quotes']` is provided (array of {quote_text, attribution}),
	 * generates one image per quote. Otherwise generates a single image
	 * from the top-level data fields.
	 *
	 * @param array      $data     Quote data.
	 * @param GDRenderer $renderer GD renderer instance.
	 * @param array      $options  Render options.
	 * @return string[] Generated image file paths.
	 */
	public function render( array $data, GDRenderer $renderer, array $options = array() ): array {
		// Support batch mode: array of quotes.
		$quotes = $data['quotes'] ?? array();
		if ( empty( $quotes ) ) {
			$quotes = array(
				array(
					'quote_text'   => $data['quote_text'] ?? '',
					'attribution'  => $data['attribution'] ?? '',
					'source_title' => $data['source_title'] ?? '',
				),
			);
		}

		$branding = $data['branding'] ?? 'extrachill.com';
		$preset   = $options['preset'] ?? $this->get_default_preset();
		$format   = $options['format'] ?? 'png';
		$context  = $options['context'] ?? array();

		$file_paths = array();

		foreach ( $quotes as $index => $quote ) {
			$quote_text   = trim( $quote['quote_text'] ?? '' );
			$attribution  = trim( $quote['attribution'] ?? '' );
			$source_title = trim( $quote['source_title'] ?? $data['source_title'] ?? '' );

			if ( empty( $quote_text ) || empty( $attribution ) ) {
				continue;
			}

			$path = $this->render_single(
				$renderer,
				$quote_text,
				$attribution,
				$source_title,
				$branding,
				$preset,
				$format,
				$context,
				$index + 1
			);

			if ( $path ) {
				$file_paths[] = $path;
			}
		}

		return $file_paths;
	}

	/**
	 * Render a single quote card image.
	 *
	 * @param GDRenderer $renderer     GD renderer.
	 * @param string     $quote_text   The quote.
	 * @param string     $attribution  Who said it.
	 * @param string     $source_title Interview/article title.
	 * @param string     $branding     Branding text (e.g. extrachill.com).
	 * @param string     $preset       Platform preset name.
	 * @param string     $format       Output format (png/jpeg).
	 * @param array      $context      Storage context for repository.
	 * @param int        $index        Quote index (for filename).
	 * @return string|null File path on success.
	 */
	private function render_single(
		GDRenderer $renderer,
		string $quote_text,
		string $attribution,
		string $source_title,
		string $branding,
		string $preset,
		string $format,
		array $context,
		int $index
	): ?string {
		// Create canvas.
		$renderer->create_canvas( $preset );

		if ( ! $renderer->get_image() ) {
			return null;
		}

		// Register fonts.
		$renderer->register_font( 'header', 'WilcoLoftSans-Treble.ttf' );
		$renderer->register_font( 'body', 'helvetica.ttf' );

		// Allocate colors.
		$bg_color          = $renderer->color_rgb( 'background', self::COLORS['background'] );
		$quote_text_color  = $renderer->color_rgb( 'quote_text', self::COLORS['quote_text'] );
		$attribution_color = $renderer->color_rgb( 'attribution', self::COLORS['attribution'] );
		$accent_color      = $renderer->color_rgb( 'accent', self::COLORS['accent'] );
		$quote_mark_color  = $renderer->color_rgb( 'quote_mark', self::COLORS['quote_mark'] );
		$branding_color    = $renderer->color_rgb( 'branding', self::COLORS['branding'] );

		// Fill background.
		$renderer->fill( $bg_color );

		$width     = $renderer->get_width();
		$height    = $renderer->get_height();
		$max_width = $width - ( self::PADDING * 2 );

		// Determine font size based on quote length.
		$font_size = $this->get_quote_font_size( $quote_text );

		// --- Layout calculation (vertical centering) ---

		// Measure all text blocks.
		$quote_mark_height  = self::QUOTE_MARK_SIZE + 20; // Mark + gap.
		$quote_text_height  = $renderer->measure_text_height( $quote_text, $font_size, 'body', $max_width, 1.5 );
		$accent_line_height = 2 + 30; // Line + gap.

		$attribution_size   = 22;
		$attribution_line   = "\xe2\x80\x94 " . $attribution; // Em dash prefix.
		$attribution_height = $renderer->measure_text_height( $attribution_line, $attribution_size, 'header', $max_width );

		$source_height = 0;
		if ( $source_title ) {
			$source_height = $renderer->measure_text_height( $source_title, 18, 'body', $max_width ) + 10;
		}

		$branding_height = 0;
		if ( $branding ) {
			$branding_height = 50; // Fixed space at bottom.
		}

		$total_content_height = $quote_mark_height + $quote_text_height + $accent_line_height + $attribution_height + $source_height;
		$available_height     = $height - $branding_height;
		$y_start              = max( self::PADDING, (int) ( ( $available_height - $total_content_height ) / 2 ) );

		$y = $y_start;

		// --- Render quote mark ---
		$renderer->draw_text(
			"\xe2\x80\x9c", // Left double quotation mark.
			self::QUOTE_MARK_SIZE,
			self::PADDING,
			$y + self::QUOTE_MARK_SIZE,
			$quote_mark_color,
			'header'
		);
		$y += $quote_mark_height;

		// --- Render quote text ---
		$y = $renderer->draw_text_wrapped(
			$quote_text,
			$font_size,
			self::PADDING,
			$y,
			$quote_text_color,
			'body',
			$max_width,
			1.5,
			'left'
		);

		$y += 15;

		// --- Accent line ---
		$renderer->filled_rect(
			self::PADDING,
			$y,
			self::PADDING + 60,
			$y + 2,
			$accent_color
		);
		$y += 30;

		// --- Attribution ---
		$y = $renderer->draw_text_wrapped(
			$attribution_line,
			$attribution_size,
			self::PADDING,
			$y,
			$attribution_color,
			'header',
			$max_width
		);

		// --- Source title ---
		if ( $source_title ) {
			$y += 10;
			$renderer->draw_text_wrapped(
				$source_title,
				18,
				self::PADDING,
				$y,
				$branding_color,
				'body',
				$max_width
			);
		}

		// --- Branding (bottom) ---
		if ( $branding ) {
			$renderer->draw_text_centered(
				$branding,
				16,
				$height - 40,
				$branding_color,
				'body'
			);
		}

		// --- Save ---
		$filename = sprintf( 'quote-card-%d.%s', $index, 'jpeg' === $format ? 'jpg' : 'png' );

		if ( ! empty( $context ) ) {
			$path = $renderer->save_to_repository( $filename, $context, $format );
		} else {
			$path = $renderer->save_temp( $format );
		}

		$renderer->destroy();

		return $path;
	}

	/**
	 * Determine quote font size based on character length.
	 *
	 * @param string $quote_text Quote text.
	 * @return int Font size in points.
	 */
	private function get_quote_font_size( string $quote_text ): int {
		$length = mb_strlen( $quote_text );

		if ( $length < 80 ) {
			return self::FONT_SIZES['short'];
		}

		if ( $length < 160 ) {
			return self::FONT_SIZES['medium'];
		}

		if ( $length < 280 ) {
			return self::FONT_SIZES['long'];
		}

		return self::FONT_SIZES['extra'];
	}
}
