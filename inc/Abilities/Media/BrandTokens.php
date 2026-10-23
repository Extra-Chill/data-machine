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
		'colors'            => array(
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
		'fonts'             => array(
			// Absolute paths to .ttf files. GD cannot use .woff2 — themes
			// must ship TTF/OTF for any font they want in rendered images.
			'heading' => null,
			'display' => null, // Poster/card titles only; falls back to heading.
			'body'    => null,
			'brand'   => null,
			'mono'    => null,
		),
		'logo_path'         => null,
		'logo_path_inverse' => null,
		'brand_text'        => '',
		'site_label'        => '',
	);

	/** @var array Render-local overrides, restored after each render. */
	private static array $render_overrides = array();

	/**
	 * Run a template with overrides shared by every token accessor.
	 * Nested renders and exceptions restore the previous scope.
	 *
	 * @param array    $overrides Per-render tokens.
	 * @param callable $render   Render callback.
	 * @return mixed Callback result.
	 */
	public static function with_overrides( array $overrides, callable $render ) {
		$previous = self::$render_overrides;
		self::$render_overrides = $overrides;
		try {
			return $render();
		} finally {
			self::$render_overrides = $previous;
		}
	}

	/**
	 * Schema for optional per-render brand tokens. Invalid entries are ignored.
	 *
	 * @return array JSON schema.
	 */
	public static function override_schema(): array {
		$properties = array();
		foreach ( array( 'colors', 'fonts' ) as $group ) {
			$properties[ $group ] = array(
				'type'       => 'object',
				'properties' => array_fill_keys( array_keys( self::DEFAULTS[ $group ] ), array( 'description' => 'Optional token override; invalid values are ignored.' ) ),
			);
		}
		foreach ( array( 'logo_path', 'logo_path_inverse', 'brand_text' ) as $key ) {
			$properties[ $key ] = array( 'description' => 'Optional token override; invalid values are ignored.' );
		}
		return array(
			'type'        => 'object',
			'description' => 'Per-render brand overrides, applied after site tokens. Colors accept #RGB or #RRGGBB. Fonts and logos accept readable local file paths or media attachment IDs. Invalid entries fall through to site tokens. brand_text accepts a string.',
			'properties'  => $properties,
		);
	}

	/**
	 * Resolve an override asset without fetching remote URLs.
	 *
	 * @param mixed $value Local path or attachment ID.
	 * @return string|null Readable file path.
	 */
	private static function resolve_override_path( $value ): ?string {
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			$value = get_attached_file( (int) $value );
		}
		return is_string( $value ) && '' !== $value && ! str_contains( $value, '://' ) && is_file( $value ) && is_readable( $value ) ? $value : null;
	}

	/**
	 * Get brand tokens for the current site/template.
	 *
	 * Themes and plugins filter these via
	 * `datamachine/image_template/brand_tokens`. The filter receives the
	 * template ID and an optional context (typically a WP_Post or array)
	 * so callers can supply per-template or per-content overrides.
	 *
	 * `logo_path` / `logo_path_inverse` resolve in two steps: a filter can
	 * set either explicitly (an org-level brand asset, chosen by the
	 * consuming template based on its background darkness), and if
	 * neither is set, this falls back to the current site's core
	 * WordPress site icon — zero-config branding for any site on a
	 * multisite network, including ones that never register a logo
	 * token. This lives here rather than in each consuming template so
	 * every GD-rendered template gets it for free instead of reimplementing
	 * the same resolution chain.
	 *
	 * @param string $template_id Template identifier (e.g. 'event_og_card').
	 * @param mixed  $context     Optional context (WP_Post, array, etc.).
	 * @param array  $overrides   Optional tokens applied after site and render-scoped tokens.
	 * @return array Resolved brand token array (always has colors + fonts keys).
	 */
	public static function get( string $template_id = '', $context = null, array $overrides = array() ): array {
		$defaults = self::DEFAULTS;

		/**
		 * Filter brand tokens used by GD image templates.
		 *
		 * @param array  $tokens      Default tokens (colors, fonts, logo_path, logo_path_inverse, brand_text, site_label).
		 * @param string $template_id Template identifier requesting tokens.
		 * @param mixed  $context     Optional context — typically a WP_Post or data array.
		 */
		// phpcs:ignore WordPress.NamingConventions.ValidHookName -- Intentional slash-separated hook namespace.
		$tokens = apply_filters( 'datamachine/image_template/brand_tokens', $defaults, $template_id, $context );

		// Ensure shape is stable even if a filter returns something unexpected.
		$tokens['colors'] = array_merge( $defaults['colors'], (array) ( $tokens['colors'] ?? array() ) );
		$tokens['fonts']  = array_merge( $defaults['fonts'], (array) ( $tokens['fonts'] ?? array() ) );

		foreach ( array( 'logo_path', 'logo_path_inverse', 'brand_text', 'site_label' ) as $key ) {
			if ( ! array_key_exists( $key, $tokens ) ) {
				$tokens[ $key ] = $defaults[ $key ];
			}
		}

		// No explicit logo token from any filter — fall back to the site
		// icon rather than leaving branding empty. Never overrides an
		// explicit token (including one deliberately left null): only
		// fires when *both* variants are unset, so a consumer that wants
		// "no logo, use text" is not overridden — that path isn't
		// reachable here since a filter returning null for both is
		// indistinguishable from a filter not addressing logos at all,
		// which is the same "nothing supplied" case the site icon exists
		// to cover.
		if ( empty( $tokens['logo_path'] ) && empty( $tokens['logo_path_inverse'] ) ) {
			$icon_path = self::resolve_site_icon_path();
			if ( null !== $icon_path ) {
				$tokens['logo_path'] = $icon_path;
			}
		}

		foreach ( array( self::$render_overrides, $overrides ) as $layer ) {
			foreach ( array_keys( $defaults['colors'] ) as $key ) {
				$value = $layer['colors'][ $key ] ?? null;
				if ( is_string( $value ) && preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) ) {
					$tokens['colors'][ $key ] = $value;
				}
			}
			foreach ( array_keys( $defaults['fonts'] ) as $key ) {
				$path = self::resolve_override_path( $layer['fonts'][ $key ] ?? null );
				if ( null !== $path ) {
					$tokens['fonts'][ $key ] = $path;
				}
			}
			foreach ( array( 'logo_path', 'logo_path_inverse' ) as $key ) {
				$path = self::resolve_override_path( $layer[ $key ] ?? null );
				if ( null !== $path ) {
					$tokens[ $key ] = $path;
				}
			}
			if ( isset( $layer['brand_text'] ) && is_string( $layer['brand_text'] ) ) {
				$tokens['brand_text'] = $layer['brand_text'];
			}
		}

		return $tokens;
	}

	/**
	 * Resolve a local filesystem path to the current site's core
	 * WordPress site icon, requesting a size comparable to core's own
	 * `get_site_icon_url()` default (512px, i.e. the original upload).
	 *
	 * Deliberately mirrors `get_site_icon_url()`'s ID-based resolution
	 * but returns a local path instead of a URL — this runs during image
	 * generation, so a network fetch is neither necessary nor safe to
	 * depend on. `site_icon` is stored as an attachment ID; every step
	 * from there is guarded so an unset icon, a missing file, or a file
	 * GD cannot decode all resolve to `null` rather than fatal — a
	 * consuming template's own fallback (typically brand text) is always
	 * the safety net, not this method.
	 *
	 * There is no dark/light "inverse" variant of an arbitrary site icon
	 * to resolve — this only ever populates the base `logo_path`, never
	 * `logo_path_inverse`. A consuming template rendering on a dark
	 * background and finding only `logo_path` set already treats that as
	 * "no usable logo for this background" and falls through further
	 * (see EventOgCardTemplate::resolve_logo()).
	 *
	 * @return string|null Absolute path, or null if there is no usable
	 *                      site icon.
	 */
	private static function resolve_site_icon_path(): ?string {
		$icon_id = (int) get_option( 'site_icon' );
		if ( $icon_id <= 0 ) {
			return null;
		}

		// Mirrors get_site_icon_url()'s own size >= 512 => original-file
		// behavior, just resolving a path instead of requesting a URL.
		$path      = null;
		$size_data = image_get_intermediate_size( $icon_id, array( 512, 512 ) );
		if ( is_array( $size_data ) && ! empty( $size_data['path'] ) ) {
			$upload_dir = wp_upload_dir();
			if ( empty( $upload_dir['error'] ) ) {
				$candidate = trailingslashit( $upload_dir['basedir'] ) . $size_data['path'];
				if ( file_exists( $candidate ) ) {
					$path = $candidate;
				}
			}
		}

		if ( null === $path ) {
			$original = get_attached_file( $icon_id );
			if ( is_string( $original ) && '' !== $original && file_exists( $original ) ) {
				$path = $original;
			}
		}

		if ( null === $path ) {
			return null;
		}

		// Confirm it's a real, GD-decodable raster image before exposing
		// it as a resolved token — a DB record with no matching decodable
		// file on disk must not surface as a broken logo_path.
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- getimagesize() warns on unreadable/corrupt files; that must degrade to "no logo", not surface to the user.
		if ( ! $info || ! in_array( $info[2], array( IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP, IMAGETYPE_GIF ), true ) ) {
			return null;
		}

		return $path;
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
