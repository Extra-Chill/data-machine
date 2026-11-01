<?php
/**
 * Shared, conservative HTML-section detail-link and date detection.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper;

use WP_Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EventSectionDetails {

	/**
	 * Path segments that introduce a single-event detail page, across the
	 * English, Dutch, French, German and Spanish venue sites we import plus
	 * common CMS event slugs.
	 */
	private const DETAIL_PATH_PATTERN = '~/(?:events?|shows?|concerts?|performances?|productions?|tm-events?|agenda|programm[ae]?|programs?|concerten|voorstellingen?|evenements?|spectacles?|veranstaltungen?|konzerte?|conciertos?|eventos?)/[^/]+~i';

	/** Day-first numeric date with an abbreviated or non-English month token, e.g. `zo. 01 nov.`, `1 déc`, `3 mär`. */
	private const DAY_FIRST_MONTH_PATTERN = '/(?<![\d\p{L}])\d{1,2}\.?\s+(?:jan|feb|f[ée]v|m[äa]r|mrz|mrt|apr|avr|abr|mai|mei|may|jun|juin|jul|juil|aug|ao[ûu]t?|ago|sep|sept|okt|oct|nov|dez|dec|d[ée]c|dic|ene)(?:\.|(?!\p{L}))/iu';

	/** Tokens that carry no event identity on their own (doors/show times, ages, weekdays, ticket chrome). */
	private const NON_TITLE_TOKENS = '/\b(?:doors?|show(?:time)?|all ages|tickets?|buy|more info|info|free|sold out|rsvp|(?:mon|tues?|wed|thu(?:rs?)?|fri|sat|sun|monday|tuesday|wednesday|thursday|friday|saturday|sunday)\.?|\d{1,2}(?::\d{2})?\s*(?:am|pm)?)\b|\d+\+/i';

	/** Same-site event detail links, keyed by resolved URL (not ticket or index links). */
	public static function links( \DOMNode $node, string $listing_url ): array {
		$xpath   = new \DOMXPath( $node->ownerDocument );
		$links   = array();
		$anchors = $xpath->query( 'descendant-or-self::a[@href]', $node );
		if ( false === $anchors ) {
			return $links;
		}
		foreach ( $anchors as $anchor ) {
			if ( ! $anchor instanceof \DOMElement ) {
				continue;
			}
			$href = trim( $anchor->getAttribute( 'href' ) );
			if ( '' === $href || preg_match( '/^(?:#|javascript:|mailto:|tel:)/i', $href ) ) {
				continue;
			}
			$url         = preg_replace( '/#.*$/', '', WP_Http::make_absolute_url( $href, $listing_url ) );
			$host        = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$source_host = strtolower( (string) wp_parse_url( $listing_url, PHP_URL_HOST ) );
			$path        = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true )
				|| preg_replace( '/^www\./', '', $host ) !== preg_replace( '/^www\./', '', $source_host )
				|| ! preg_match( self::DETAIL_PATH_PATTERN, $path )
				|| rtrim( $path, '/' ) === rtrim( (string) wp_parse_url( $listing_url, PHP_URL_PATH ), '/' ) ) {
				continue;
			}
			$links[ $url ] = $anchor;
		}
		return $links;
	}

	/** Require both a date and time before deciding the teaser needs no second hop. */
	public static function hasDateAndTime( string $html ): bool {
		$text = html_entity_decode( wp_strip_all_tags( $html ) );
		$time = preg_match( '/\b(?:\d{1,2}:\d{2}|\d{1,2}\s*(?:am|pm))\b/i', $text );
		return self::hasDate( $html ) && (bool) $time;
	}

	public static function hasDate( string $html ): bool {
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( preg_match( self::DAY_FIRST_MONTH_PATTERN, $text ) ) {
			return true;
		}
		return (bool) preg_match( '/\b(?:\d{4}-\d{2}-\d{2}|\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{2,4})?|(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\.?\s+\d{1,2}|\d{1,2}\s+(?:January|February|March|April|May|June|July|August|September|October|November|December))\b/i', $text );
	}

	/**
	 * Whether a section carries title-like text, not just a weekday, an
	 * entity, or door/show times. Image alt text counts as visible text.
	 *
	 * @param string $html Section HTML.
	 */
	public static function hasTitleText( string $html ): bool {
		$alts = '';
		if ( preg_match_all( '/<img\b[^>]*\balt=["\']([^"\']+)["\']/i', $html, $matches ) ) {
			$alts = ' ' . implode( ' ', $matches[1] );
		}
		$text = html_entity_decode( wp_strip_all_tags( $html ) . $alts, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( self::NON_TITLE_TOKENS, ' ', $text );
		$text = (string) preg_replace( '/[^\p{L}]+/u', '', $text );
		return mb_strlen( $text ) >= 4;
	}
}
