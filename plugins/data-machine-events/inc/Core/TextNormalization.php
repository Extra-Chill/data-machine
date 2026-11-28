<?php
/**
 * Ingestion text storage contract.
 *
 * Owns the "store decoded text, escape at output" contract for event
 * ingestion (issue #844). Source feeds (HTML scrapers, ticketing APIs) deliver
 * titles, venue names, and performer names carrying HTML entities
 * ("Foo &amp; Bar", "&#8217;"). WordPress convention is to store the literal
 * characters and escape at render time; storing pre-escaped text pushes the
 * entities onto every non-HTML consumer (JSON-LD, REST/JSON, email) and
 * defeats exact-match search.
 *
 * Two failure halves must both be closed:
 *
 *  1. Decode at the ingestion boundary — {@see decode_entities()} before
 *     sanitization so tag content hidden behind entities cannot survive
 *     stripping.
 *
 *  2. Suspend the kses save filters for ingestion writes —
 *     `wp_filter_kses` on `title_save_pre` re-encodes every bare `&` to
 *     `&amp;` whenever the writing context lacks `unfiltered_html`
 *     (multisite authors, wp-cron with no user). Without suspension, a
 *     decoded title is re-encoded on the way into the database and the fix
 *     undoes itself. Ingestion titles are already tag-free
 *     (sanitize_text_field runs first), so suspending kses on this bounded
 *     write removes a re-encoding side effect, not a sanitization layer.
 *
 * @package DataMachineEvents\Core
 * @since   0.65.0
 */

namespace DataMachineEvents\Core;

defined( 'ABSPATH' ) || exit;

class TextNormalization {

	/**
	 * A complete HTML entity reference: named (&amp;), decimal (&#038;), or
	 * hexadecimal (&#x26;). Used by the quality audit and repair tooling to
	 * detect entity-bearing stored text.
	 */
	public const ENTITY_PATTERN = '/&(?:[a-zA-Z][a-zA-Z0-9]{1,31}|#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6});/';

	/**
	 * Decode HTML entities into their literal UTF-8 characters.
	 *
	 * Decode, never strip: a source title "Kanika Moore &#038; The Brown Eyed
	 * Bois" must become "Kanika Moore & The Brown Eyed Bois", not lose the
	 * ampersand. Run this BEFORE sanitize_text_field() so any markup hidden
	 * behind entities (e.g. "&lt;script&gt;") is exposed and stripped by the
	 * sanitizer rather than smuggled into storage.
	 *
	 * @param string $value Text possibly containing HTML entities.
	 * @return string Decoded text.
	 */
	public static function decode_entities( string $value ): string {
		return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Whether the value still contains HTML entity references.
	 *
	 * @param string $value Text to inspect.
	 * @return bool True when at least one entity reference is present.
	 */
	public static function contains_entities( string $value ): bool {
		return 1 === preg_match( self::ENTITY_PATTERN, $value );
	}

	/**
	 * Run a write callback with the kses save filters suspended, then restore
	 * the exact prior filter state.
	 *
	 * `kses_init()` first removes all kses filters and then re-adds them iff
	 * the current user lacks `unfiltered_html`, so calling it in the finally
	 * branch restores the pre-call state for every context: multisite
	 * authors, super admins, wp-cron with no user, and the test suite.
	 *
	 * @param callable $callback Write to perform without kses re-encoding.
	 * @return mixed The callback's return value.
	 */
	public static function with_kses_suspended( callable $callback ) {
		$filters_were_active = (bool) has_filter( 'title_save_pre', 'wp_filter_kses' );

		if ( $filters_were_active ) {
			kses_remove_filters();
		}

		try {
			return $callback();
		} finally {
			if ( $filters_were_active ) {
				kses_init();
			}
		}
	}
}
