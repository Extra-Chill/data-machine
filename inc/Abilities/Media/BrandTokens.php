<?php
/**
 * Brand tokens primitive for image templates.
 *
 * Provides a single, filterable source of brand identity (colors, fonts,
 * logo, label text) for GD-rendered templates. Themes hook
 * `datamachine/image_template/brand_tokens` to supply site-specific
 * branding; templates call BrandTokens::get() to read what to paint.
 *
 * The primitive is intentionally brand-agnostic — defaults are neutral.
 * Downstream consumers (themes, plugins) layer brand on top via the filter.
 *
 * @package DataMachine\Abilities\Media
 * @since 0.79.0
 */

namespace DataMachine\Abilities\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BrandTokens {

	/**
	 * Default brand tokens.
	 *
	 * Neutral values used when no theme/plugin supplies overrides.
	 *
	 * @var array
	 */
	private const DEFAULTS = array(
		'colors' => array(
			'background'      => '#ffffff',
			'background_dark' => '#0f0f0f',
			'surface'         => '#f1f5f9',
			'accent'          => '#53940b',
			'accent_hover'    => '#3d6b08',
			'accent_2'        => '#36454f',
			'accent_3'        => '#00c8e3',
			'text_primary'    => '#000000',
			'text_muted'      => '#6b7280',
			'text_inverse'    => '#ffffff',
			'header_bg'       => '#000000',
			'border'          => '#dddddd',
		),
		'fonts'  => array(
			// Absolute paths to .ttf files. GD cannot use .woff2 — themes
			// must ship TTF/OTF for any font they want in rendered images.
			'heading' => null,
			'body'    => null,
			'brand'   => null,
			'mono'    => null,
		),
		'logo_path'  => null,
		'brand_text' => '',
		'site_label' => '',
	);

	/**
	 * Get brand tokens for the current site/template.
	 *
	 * Themes and plugins filter these via
	 * `datamachine/image_template/brand_tokens`. The filter receives the
	 * template ID and an optional context (typically a WP_Post or array)
	 * so callers can supply per-template or per-content overrides.
	 *
	 * @param string $template_id Template identifier (e.g. 'event_og_card').
	 * @param mixed  $context     Optional context (WP_Post, array, etc.).
	 * @return array Resolved brand token array (always has colors + fonts keys).
	 */
	public static function get( string $template_id = '', $context = null ): array {
		$defaults = self::DEFAULTS;

		/**
		 * Filter brand tokens used by GD image templates.
		 *
		 * @param array  $tokens      Default tokens (colors, fonts, logo_path, brand_text, site_label).
		 * @param string $template_id Template identifier requesting tokens.
		 * @param mixed  $context     Optional context — typically a WP_Post or data array.
		 */
		// phpcs:ignore WordPress.NamingConventions.ValidHookName -- Intentional slash-separated hook namespace.
		$tokens = apply_filters( 'datamachine/image_template/brand_tokens', $defaults, $template_id, $context );

		// Ensure shape is stable even if a filter returns something unexpected.
		$tokens['colors'] = array_merge( $defaults['colors'], (array) ( $tokens['colors'] ?? array() ) );
		$tokens['fonts']  = array_merge( $defaults['fonts'], (array) ( $tokens['fonts'] ?? array() ) );

		foreach ( array( 'logo_path', 'brand_text', 'site_label' ) as $key ) {
			if ( ! array_key_exists( $key, $tokens ) ) {
				$tokens[ $key ] = $defaults[ $key ];
			}
		}

		return $tokens;
	}

	/**
	 * Convenience accessor for a single color token.
	 *
	 * @param string $color_key    Key within the `colors` array.
	 * @param string $template_id  Template identifier.
	 * @param mixed  $context      Optional context.
	 * @param string $fallback_hex Fallback hex string if the key is missing.
	 * @return string Hex color.
	 */
	public static function color( string $color_key, string $template_id = '', $context = null, string $fallback_hex = '#000000' ): string {
		$tokens = self::get( $template_id, $context );
		return (string) ( $tokens['colors'][ $color_key ] ?? $fallback_hex );
	}

	/**
	 * Convenience accessor for a single font path.
	 *
	 * Returns null when the theme has not supplied a font for the given
	 * role. Templates should treat null as "fall back to system default".
	 *
	 * @param string $font_key    Key within the `fonts` array.
	 * @param string $template_id Template identifier.
	 * @param mixed  $context     Optional context.
	 * @return string|null Absolute path to TTF file, or null if unset.
	 */
	public static function font( string $font_key, string $template_id = '', $context = null ): ?string {
		$tokens = self::get( $template_id, $context );
		$path   = $tokens['fonts'][ $font_key ] ?? null;
		return is_string( $path ) && '' !== $path ? $path : null;
	}
}
