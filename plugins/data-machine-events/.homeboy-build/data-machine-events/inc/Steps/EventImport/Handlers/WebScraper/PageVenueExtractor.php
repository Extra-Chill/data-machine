<?php
/**
 * Page Venue Extractor
 *
 * Reusable utility for extracting venue information from page HTML.
 * Looks for venue name in page title, address in footer, and timezone
 * from various page metadata sources.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PageVenueExtractor {

	/**
	 * US state abbreviations for address parsing.
	 */
	private const US_STATES = 'AL|AK|AZ|AR|CA|CO|CT|DE|FL|GA|HI|ID|IL|IN|IA|KS|KY|LA|ME|MD|MA|MI|MN|MS|MO|MT|NE|NV|NH|NJ|NM|NY|NC|ND|OH|OK|OR|PA|RI|SC|SD|TN|TX|UT|VT|VA|WA|WV|WI|WY';

	/**
	 * Words to filter out when extracting venue name from title.
	 */
	private const TITLE_FILTER_WORDS = array(
		'events',
		'calendar',
		'shows',
		'upcoming events',
		'concerts',
		'schedule',
		'tickets',
		'reservations',
		'live music',
		'event calendar',
		'upcoming',
	);

	/**
	 * Extract venue information from page HTML.
	 *
	 * Priority order:
	 * 1. JSON-LD structured data (most reliable)
	 * 2. Page title parsing for venue name
	 * 3. Footer/body scanning for address
	 *
	 * @param string $html Page HTML content
	 * @param string $source_url Source URL for context
	 * @return array Venue data with keys: venue, venueAddress, venueCity, venueState, venueZip, venueCountry, venueTimezone
	 */
	public static function extract( string $html, string $source_url = '' ): array {
		$venue = array(
			'venue'         => '',
			'venueAddress'  => '',
			'venueCity'     => '',
			'venueState'    => '',
			'venueZip'      => '',
			'venueCountry'  => 'US',
			'venueTimezone' => '',
		);

		$jsonld_data = self::extractFromJsonLd( $html );
		$venue       = array_merge( $venue, array_filter( $jsonld_data ) );

		// Squarespace map block (highly reliable when present)
		if ( empty( $venue['venueAddress'] ) || empty( $venue['venueCity'] ) ) {
			$map_data = self::extractFromSquarespaceMapBlock( $html );
			foreach ( $map_data as $key => $value ) {
				if ( empty( $venue[ $key ] ) && ! empty( $value ) ) {
					$venue[ $key ] = $value;
				}
			}
		}

		if ( empty( $venue['venue'] ) ) {
			$venue['venue'] = self::extractVenueName( $html );
		}

		if ( empty( $venue['venueTimezone'] ) ) {
			$venue['venueTimezone'] = self::extractTimezone( $html );
		}

		if ( empty( $venue['venueAddress'] ) || empty( $venue['venueCity'] ) ) {
			$address_data = self::extractAddress( $html );
			foreach ( $address_data as $key => $value ) {
				if ( empty( $venue[ $key ] ) && ! empty( $value ) ) {
					$venue[ $key ] = $value;
				}
			}
		}

		return $venue;
	}

	/**
	 * Extract venue information from JSON-LD structured data.
	 *
	 * Looks for EntertainmentBusiness, LocalBusiness, Place, or Organization
	 * schema types and extracts venue name and address fields.
	 *
	 * @param string $html Page HTML content
	 * @return array Venue data (may be partially filled)
	 */
	public static function extractFromJsonLd( string $html ): array {
		$data = array(
			'venue'        => '',
			'venueAddress' => '',
			'venueCity'    => '',
			'venueState'   => '',
			'venueZip'     => '',
			'venueCountry' => '',
		);

		if ( strpos( $html, 'application/ld+json' ) === false ) {
			return $data;
		}

		if ( ! preg_match_all( '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches ) ) {
			return $data;
		}

		$target_types = array( 'EntertainmentBusiness', 'LocalBusiness', 'Place', 'Organization', 'MusicVenue', 'NightClub', 'BarOrPub' );

		foreach ( $matches[1] as $json_content ) {
			$json_data = json_decode( trim( $json_content ), true );
			if ( json_last_error() !== JSON_ERROR_NONE || empty( $json_data ) ) {
				continue;
			}

			$venue_entity = self::findVenueEntity( $json_data, $target_types );
			if ( null !== $venue_entity ) {
				$data = self::parseVenueEntity( $venue_entity );
				if ( ! empty( $data['venueAddress'] ) || ! empty( $data['venue'] ) ) {
					return $data;
				}
			}
		}

		return $data;
	}

	/**
	 * Extract venue information from Squarespace map block.
	 *
	 * Squarespace sites often include a map widget in the footer with
	 * structured venue data in a data-context attribute.
	 *
	 * @param string $html Page HTML content
	 * @return array Venue data (may be partially filled)
	 */
	public static function extractFromSquarespaceMapBlock( string $html ): array {
		$data = array(
			'venue'        => '',
			'venueAddress' => '',
			'venueCity'    => '',
			'venueState'   => '',
			'venueZip'     => '',
			'venueCountry' => '',
		);

		// Look for map block with address data in data-context attribute
		if ( ! preg_match( '/data-context="([^"]*addressLine1[^"]*)"/i', $html, $matches ) ) {
			return $data;
		}

		// Decode HTML entities and parse JSON
		$json_str = html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' );
		$context  = json_decode( $json_str, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $context ) ) {
			return $data;
		}

		// Location data may be nested under 'location' key
		$location = $context['location'] ?? $context;

		if ( ! is_array( $location ) ) {
			return $data;
		}

		// Extract venue name
		if ( ! empty( $location['addressTitle'] ) ) {
			$data['venue'] = html_entity_decode( sanitize_text_field( $location['addressTitle'] ), ENT_QUOTES, 'UTF-8' );
		}

		// Extract street address
		if ( ! empty( $location['addressLine1'] ) ) {
			$data['venueAddress'] = html_entity_decode( sanitize_text_field( $location['addressLine1'] ), ENT_QUOTES, 'UTF-8' );
		}

		// Parse addressLine2 for city, state, zip (e.g., "Austin, TX, 78704")
		if ( ! empty( $location['addressLine2'] ) ) {
			$line2 = $location['addressLine2'];
			$csz   = self::extractCityStateZip( $line2 );
			$data  = array_merge( $data, $csz );
		}

		// Extract country if present
		if ( ! empty( $location['addressCountry'] ) ) {
			$data['venueCountry'] = sanitize_text_field( $location['addressCountry'] );
		}

		return $data;
	}

	/**
	 * Find venue entity in JSON-LD data.
	 *
	 * @param array $json_data Parsed JSON-LD data
	 * @param array $target_types Schema types to look for
	 * @return array|null Venue entity or null
	 */
	private static function findVenueEntity( array $json_data, array $target_types ): ?array {
		if ( isset( $json_data['@type'] ) && in_array( $json_data['@type'], $target_types, true ) ) {
			return $json_data;
		}

		if ( isset( $json_data['@graph'] ) && is_array( $json_data['@graph'] ) ) {
			foreach ( $json_data['@graph'] as $item ) {
				if ( isset( $item['@type'] ) && in_array( $item['@type'], $target_types, true ) ) {
					return $item;
				}
			}
		}

		if ( array_keys( $json_data ) === range( 0, count( $json_data ) - 1 ) ) {
			foreach ( $json_data as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				if ( isset( $item['@type'] ) && in_array( $item['@type'], $target_types, true ) ) {
					return $item;
				}

				if ( isset( $item['@graph'] ) && is_array( $item['@graph'] ) ) {
					foreach ( $item['@graph'] as $graph_item ) {
						if ( isset( $graph_item['@type'] ) && in_array( $graph_item['@type'], $target_types, true ) ) {
							return $graph_item;
						}
					}
				}
			}
		}

		return null;
	}

	/**
	 * Parse venue entity into standardized format.
	 *
	 * @param array $entity JSON-LD venue entity
	 * @return array Venue data
	 */
	private static function parseVenueEntity( array $entity ): array {
		$data = array(
			'venue'        => '',
			'venueAddress' => '',
			'venueCity'    => '',
			'venueState'   => '',
			'venueZip'     => '',
			'venueCountry' => '',
		);

		if ( ! empty( $entity['name'] ) ) {
			$data['venue'] = html_entity_decode( sanitize_text_field( $entity['name'] ), ENT_QUOTES, 'UTF-8' );
		}

		$address = $entity['address'] ?? null;

		if ( is_string( $address ) ) {
			$data['venueAddress'] = sanitize_text_field( $address );
			return $data;
		}

		if ( ! is_array( $address ) ) {
			if ( ! empty( $entity['location'] ) && is_array( $entity['location'] ) ) {
				$address = $entity['location']['address'] ?? $entity['location'];
			}
		}

		if ( is_array( $address ) ) {
			if ( ! empty( $address['streetAddress'] ) ) {
				$data['venueAddress'] = html_entity_decode( sanitize_text_field( $address['streetAddress'] ), ENT_QUOTES, 'UTF-8' );
			}
			if ( ! empty( $address['addressLocality'] ) ) {
				$data['venueCity'] = html_entity_decode( sanitize_text_field( $address['addressLocality'] ), ENT_QUOTES, 'UTF-8' );
			}
			if ( ! empty( $address['addressRegion'] ) ) {
				$data['venueState'] = html_entity_decode( sanitize_text_field( $address['addressRegion'] ), ENT_QUOTES, 'UTF-8' );
			}
			if ( ! empty( $address['postalCode'] ) ) {
				$data['venueZip'] = sanitize_text_field( $address['postalCode'] );
			}
			if ( ! empty( $address['addressCountry'] ) ) {
				$country              = $address['addressCountry'];
				$data['venueCountry'] = is_array( $country ) ? ( $country['name'] ?? '' ) : sanitize_text_field( $country );
			}
		}

		return $data;
	}

	/**
	 * Extract venue name from page title.
	 *
	 * Parses the <title> tag and filters out common event-related words
	 * to find the actual venue/site name.
	 *
	 * @param string $html Page HTML content
	 * @return string Venue name or empty string
	 */
	public static function extractVenueName( string $html ): string {
		// Squarespace specific site title extraction from Static.SQUARESPACE_CONTEXT
		// This is the most reliable source for Squarespace sites
		if ( preg_match( '/Static\.SQUARESPACE_CONTEXT\s*=\s*\{[^}]*"siteTitle"\s*:\s*"([^"]+)"/s', $html, $ss_matches ) ) {
			return sanitize_text_field( $ss_matches[1] );
		}

		if ( ! preg_match( '/<title[^>]*>([^<]+)<\/title>/i', $html, $matches ) ) {
			return '';
		}

		$title = html_entity_decode( trim( $matches[1] ), ENT_QUOTES, 'UTF-8' );

		$separators = array( ' — ', ' – ', ' - ', ' | ', ': ', ' · ' );
		foreach ( $separators as $sep ) {
			if ( strpos( $title, $sep ) !== false ) {
				$parts = explode( $sep, $title );

				// Try to find the part that isn't a filtered word
				// Usually the venue name is at the end or beginning
				$candidate = '';
				foreach ( $parts as $part ) {
					$part  = trim( $part );
					$lower = strtolower( $part );

					if ( empty( $part ) ) {
						continue;
					}

					$is_filtered = false;
					foreach ( self::TITLE_FILTER_WORDS as $word ) {
						if ( strpos( $lower, $word ) !== false ) {
							$is_filtered = true;
							break;
						}
					}

					if ( ! $is_filtered ) {
						// If we find a candidate, prefer the one that doesn't look like "Home" or "Welcome"
						if ( in_array( $lower, array( 'home', 'welcome', 'index' ), true ) ) {
							continue;
						}
						$candidate = $part;
					}
				}

				if ( ! empty( $candidate ) ) {
					return sanitize_text_field( $candidate );
				}
			}
		}

		return sanitize_text_field( $title );
	}

	/**
	 * Extract timezone from page metadata.
	 *
	 * Checks multiple sources:
	 * - Squarespace context JSON
	 * - Generic timezone JSON properties
	 * - Meta tags
	 *
	 * @param string $html Page HTML content
	 * @return string IANA timezone identifier or empty string
	 */
	public static function extractTimezone( string $html ): string {
		// Squarespace context
		if ( preg_match( '/Static\.SQUARESPACE_CONTEXT\s*=\s*\{[^}]*"timeZone"\s*:\s*"([^"]+)"/s', $html, $matches ) ) {
			return $matches[1];
		}

		// Generic JSON timezone property
		if ( preg_match( '/"timezone"\s*:\s*"([^"]+)"/i', $html, $matches ) ) {
			$tz = $matches[1];
			// Validate it looks like an IANA timezone
			if ( strpos( $tz, '/' ) !== false ) {
				return $tz;
			}
		}

		// Meta tag timezone
		if ( preg_match( '/<meta[^>]+name=["\']timezone["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches ) ) {
			return $matches[1];
		}

		return '';
	}

	/**
	 * Extract address information from page.
	 *
	 * @param string $html Page HTML content
	 * @return array Address data
	 */
	public static function extractAddress( string $html ): array {
		$data = array(
			'venueAddress' => '',
			'venueCity'    => '',
			'venueState'   => '',
			'venueZip'     => '',
		);

		// 1. Check Squarespace-specific announcement bar first (common for addresses/hours)
		if ( preg_match( '/class="[^"]*sqs-announcement-bar[^"]*"[^>]*>(.*?)<\/div>/is', $html, $matches ) ) {
			$announcement_text = wp_strip_all_tags( str_replace( array( '<br>', '<br/>', '<br />' ), "\n", $matches[1] ) );
			$csz               = self::extractCityStateZip( $announcement_text );
			if ( ! empty( $csz['venueZip'] ) ) {
				$data                 = array_merge( $data, $csz );
				$data['venueAddress'] = self::extractStreetAddress( $announcement_text );
				if ( ! empty( $data['venueAddress'] ) ) {
					return $data;
				}
			}
		}

		// 2. Try standard footer content
		$footer_html = self::findFooterContent( $html );
		if ( ! empty( $footer_html ) ) {
			$footer_text = wp_strip_all_tags( str_replace( array( '<br>', '<br/>', '<br />' ), "\n", $footer_html ) );
			$csz         = self::extractCityStateZip( $footer_text );
			if ( ! empty( $csz['venueZip'] ) ) {
				$data                 = array_merge( $data, $csz );
				$data['venueAddress'] = self::extractStreetAddress( $footer_text );
				if ( ! empty( $data['venueAddress'] ) ) {
					return $data;
				}
			}
		}

		// 3. Last resort: scan whole body but only for high-confidence address patterns
		if ( preg_match( '/<body[^>]*>(.*?)<\/body>/is', $html, $matches ) ) {
			$body_text = wp_strip_all_tags( str_replace( array( '<br>', '<br/>', '<br />' ), "\n", $matches[1] ) );
			$csz       = self::extractCityStateZip( $body_text );
			if ( ! empty( $csz['venueZip'] ) ) {
				$data                 = array_merge( $data, $csz );
				$data['venueAddress'] = self::extractStreetAddress( $body_text );
			}
		}

		return $data;
	}

	/**
	 * Find footer content from HTML.
	 *
	 * @param string $html Page HTML content
	 * @return string Footer HTML content or empty string
	 */
	private static function findFooterContent( string $html ): string {
		// Standard <footer> tag
		if ( preg_match( '/<footer[^>]*>(.*?)<\/footer>/is', $html, $matches ) ) {
			return $matches[1];
		}

		// Squarespace footer sections
		if ( preg_match( '/id="footer-sections"[^>]*>(.*?)($|(?=<section))/is', $html, $matches ) ) {
			return $matches[1];
		}

		// Section with footer ID
		if ( preg_match( '/<section[^>]*id="[^"]*footer[^"]*"[^>]*>(.*?)<\/section>/is', $html, $matches ) ) {
			return $matches[1];
		}

		// Div with footer class
		if ( preg_match( '/<div[^>]*class="[^"]*footer[^"]*"[^>]*>(.*?)<\/div>/is', $html, $matches ) ) {
			return $matches[1];
		}

		// Squarespace nav-footer
		if ( preg_match( '/class="[^"]*footer-nav[^"]*"[^>]*>(.*?)<\/div>/is', $html, $matches ) ) {
			return $matches[1];
		}

		return '';
	}

	/**
	 * Extract street address from text.
	 *
	 * @param string $text Text to search
	 * @return string Street address or empty string
	 */
	private static function extractStreetAddress( string $text ): string {
		// Look for common street suffixes with a leading number
		// Allows optional letter/hyphen suffix after number (e.g., "610-A", "123B")
		$street_pattern = '/(\d+[-A-Za-z]*\s+[A-Za-z0-9. ]+(?:Street|St|Avenue|Ave|Road|Rd|Drive|Dr|Boulevard|Blvd|Lane|Ln|Way|Court|Ct|Circle|Cir|Highway|Hwy|Pkwy|Parkway|Plaza|Pl|Square|Sq|Trail|Trl|Loop|Broadway)[.]?)/i';

		if ( preg_match( $street_pattern, $text, $matches ) ) {
			return sanitize_text_field( trim( $matches[1] ) );
		}

		// Fallback: number followed by words on same line (potential address)
		// Requires: digits, space, then words without newlines
		// Excludes phone numbers by requiring the number to be followed by a word starting with uppercase
		if ( preg_match( '/^.*?(\d{1,5}\s+[A-Z][A-Za-z]+(?:\s+[A-Za-z]+){1,6}).*$/m', $text, $matches ) ) {
			$potential = trim( $matches[1] );
			// Avoid matches that look like times (e.g. "10 am - 10 pm")
			if ( ! preg_match( '/\d+\s*(?:am|pm|am\s*-|pm\s*-)/i', $potential ) ) {
				// Avoid matches that are just numbers followed by generic words
				if ( preg_match( '/\d+\s+[A-Z][a-z]+\s+[A-Z]/', $potential ) ) {
					return sanitize_text_field( $potential );
				}
			}
		}

		return '';
	}

	/**
	 * Extract city, state, and ZIP from text.
	 *
	 * @param string $text Text to search
	 * @return array With keys: venueCity, venueState, venueZip
	 */
	private static function extractCityStateZip( string $text ): array {
		$data = array(
			'venueCity'  => '',
			'venueState' => '',
			'venueZip'   => '',
		);

		// Pattern matches "City, ST 12345" or "City, ST, 12345" on a single line
		// We now require the state to be one of the US_STATES and a 5-digit ZIP
		$pattern = '/([A-Z][a-z]+(?:\s[A-Z][a-z]+)*),?\s+(' . self::US_STATES . '),?\s+(\d{5}(?:-\d{4})?)/m';

		if ( preg_match( $pattern, $text, $matches ) ) {
			$data['venueCity']  = sanitize_text_field( trim( $matches[1] ) );
			$data['venueState'] = strtoupper( trim( $matches[2] ) );
			$data['venueZip']   = sanitize_text_field( trim( $matches[3] ) );
		}

		return $data;
	}
}
