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
				|| ! preg_match( '~/(?:events?|shows?|concerts?|performances?)/[^/]+~i', $path )
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
		$text = html_entity_decode( wp_strip_all_tags( $html ) );
		return (bool) preg_match( '/\b(?:\d{4}-\d{2}-\d{2}|\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{2,4})?|(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\.?\s+\d{1,2}|\d{1,2}\s+(?:January|February|March|April|May|June|July|August|September|October|November|December))\b/i', $text );
	}
}
