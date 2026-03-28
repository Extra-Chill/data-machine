<?php
/**
 * Universal Web Scraper Event Section Finder
 *
 * Locates the first eligible event section in an HTML document using ordered
 * selector rules and a small set of safety checks.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper;

use DataMachine\Core\ExecutionContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EventSectionFinder {

	/**
	 * @var callable(string): bool
	 */
	private $is_item_processed;

	/**
	 * @var callable(string): string
	 */
	private $clean_html_for_ai;

	/**
	 * @var callable(string): bool
	 */
	private $is_past_event;

	/**
	 * @param callable(string): bool $is_item_processed Check if item identifier has been processed
	 * @param callable(string): string $clean_html_for_ai Clean HTML for AI processing
	 * @param callable(string): bool $is_past_event Check if Y-m-d date is in the past
	 */
	public function __construct( callable $is_item_processed, callable $clean_html_for_ai, callable $is_past_event ) {
		$this->is_item_processed = $is_item_processed;
		$this->clean_html_for_ai = $clean_html_for_ai;
		$this->is_past_event     = $is_past_event;
	}

	/**
	 * @return array{html: string, raw_html: string, identifier: string, selector: string, url: string}|null
	 */
	public function find_first_eligible_section( string $html_content, string $url, ExecutionContext $context ): ?array {
		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( $html_content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$xpath = new \DOMXPath( $dom );

		$rules = EventSectionSelectors::get_rules();

		foreach ( $rules as $rule ) {
			$selector = $rule['xpath'] ?? '';
			if ( '' === $selector ) {
				continue;
			}

			$nodes = $xpath->query( $selector );
			if ( ! $nodes ) {
				continue;
			}

			foreach ( $nodes as $node ) {
				$tag_name = strtolower( $node->nodeName );
				if ( in_array( $tag_name, array( 'body', 'header', 'footer', 'nav', 'aside', 'main' ), true ) ) {
					continue;
				}

				// Handle base64-encoded calendar events (Google Calendar widgets)
				if ( ! empty( $rule['extract_base64_event'] ) ) {
					$event_data = $this->extract_base64_calendar_event( $node );
					if ( null === $event_data ) {
						continue;
					}

					// Skip "Closed" entries - definitively not events
					$summary = $event_data['summary'] ?? '';
					if ( strtolower( trim( $summary ) ) === 'closed' ) {
						continue;
					}

					// Parse and validate date - skip past events
					$start_date = $this->parse_calendar_event_date( $event_data['start'] ?? '' );
					if ( $start_date && call_user_func( $this->is_past_event, $start_date ) ) {
						continue;
					}

					// Build stable identifier from decoded data
					$event_identifier = md5( $url . $summary . ( $event_data['start'] ?? '' ) );

					if ( call_user_func( $this->is_item_processed, $event_identifier ) ) {
						continue;
					}

					// Return JSON as "html" field - AI will process the structured data
					return array(
						'html'       => wp_json_encode( $event_data, JSON_PRETTY_PRINT ),
						'raw_html'   => $dom->saveHTML( $node ),
						'identifier' => $event_identifier,
						'selector'   => $selector,
						'url'        => $url,
					);
				}

				if ( 'tr' === $tag_name ) {
					if ( $this->is_table_header_row( $xpath, $node ) ) {
						continue;
					}

					if ( ! empty( $rule['enable_table_row_date_filter'] ) && $this->should_skip_past_table_row( $xpath, $node ) ) {
						continue;
					}
				}

				$raw_html = $dom->saveHTML( $node );
				if ( ! is_string( $raw_html ) || strlen( $raw_html ) < 50 ) {
					continue;
				}

				$content_hash     = md5( $raw_html );
				$event_identifier = md5( $url . $content_hash );

				if ( call_user_func( $this->is_item_processed, $event_identifier ) ) {
					continue;
				}

				$cleaned_html = call_user_func( $this->clean_html_for_ai, $raw_html );
				if ( empty( $cleaned_html ) || strlen( $cleaned_html ) < 30 ) {
					continue;
				}

				return array(
					'html'       => $cleaned_html,
					'raw_html'   => $raw_html,
					'identifier' => $event_identifier,
					'selector'   => $selector,
					'url'        => $url,
				);
			}
		}

		return null;
	}

	private function is_table_header_row( \DOMXPath $xpath, \DOMNode $row ): bool {
		$th_count = $xpath->query( './/th', $row )->length;
		$td_count = $xpath->query( './/td', $row )->length;

		return $th_count > 0 && 0 === $td_count;
	}

	private function should_skip_past_table_row( \DOMXPath $xpath, \DOMNode $row ): bool {
		$date_text = $this->extract_date_text_from_table_row( $xpath, $row );
		if ( null === $date_text ) {
			return false;
		}

		$ymd = $this->parse_date_text_to_ymd( $date_text );
		if ( null === $ymd ) {
			return false;
		}

		return (bool) call_user_func( $this->is_past_event, $ymd );
	}

	private function extract_date_text_from_table_row( \DOMXPath $xpath, \DOMNode $row ): ?string {
		$datetime = $xpath->query( './/td[contains(@class, "event-date")]//time/@datetime', $row );
		if ( $datetime && $datetime->length > 0 ) {
			$value = trim( (string) $datetime->item( 0 )->nodeValue );
			return '' !== $value ? $value : null;
		}

		$time_text = $xpath->query( './/td[contains(@class, "event-date")]//time', $row );
		if ( $time_text && $time_text->length > 0 ) {
			$value = trim( (string) $time_text->item( 0 )->textContent );
			return '' !== $value ? $value : null;
		}

		$span_date_text = $xpath->query( './/td[contains(@class, "event-date")]//span[contains(@class, "date")]', $row );
		if ( $span_date_text && $span_date_text->length > 0 ) {
			$value = trim( (string) $span_date_text->item( 0 )->textContent );
			return '' !== $value ? $value : null;
		}

		$cell_text = $xpath->query( './/td[contains(@class, "event-date")]', $row );
		if ( $cell_text && $cell_text->length > 0 ) {
			$value = trim( (string) $cell_text->item( 0 )->textContent );
			return '' !== $value ? $value : null;
		}

		return null;
	}

	private function parse_date_text_to_ymd( string $date_text ): ?string {
		$date_text = html_entity_decode( $date_text );
		$date_text = preg_replace( '/\s+/', ' ', trim( $date_text ) );
		if ( empty( $date_text ) ) {
			return null;
		}

		$date_text = preg_replace( '/\s*@\s*.*$/', '', $date_text );
		$date_text = preg_replace( '/^[A-Za-z]+,\s*/', '', trim( $date_text ) );

		if ( ! preg_match( '/\b\d{4}\b/', $date_text ) ) {
			$date_text .= ' ' . date( 'Y' );
		}

		$timestamp = strtotime( $date_text );
		if ( ! $timestamp ) {
			return null;
		}

		return date( 'Y-m-d', $timestamp );
	}

	/**
	 * Extract event data from base64-encoded data-calendar-event attribute.
	 *
	 * @param \DOMNode $node DOM node with data-calendar-event attribute
	 * @return array|null Decoded event data or null if invalid
	 */
	private function extract_base64_calendar_event( \DOMNode $node ): ?array {
		if ( ! ( $node instanceof \DOMElement ) ) {
			return null;
		}

		$base64_data = $node->getAttribute( 'data-calendar-event' );
		if ( empty( $base64_data ) ) {
			return null;
		}

		$decoded = base64_decode( $base64_data, true );
		if ( false === $decoded ) {
			return null;
		}

		$event_data = json_decode( $decoded, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $event_data ) ) {
			return null;
		}

		if ( empty( $event_data['summary'] ) || empty( $event_data['start'] ) ) {
			return null;
		}

		return $event_data;
	}

	/**
	 * Parse date string from Google Calendar widget format.
	 * Handles formats like "Wed, December 3, 9:00pm"
	 *
	 * @param string $date_string Raw date string
	 * @return string|null Y-m-d format or null
	 */
	private function parse_calendar_event_date( string $date_string ): ?string {
		$date_string = trim( $date_string );
		if ( empty( $date_string ) ) {
			return null;
		}

		$date_string = preg_replace( '/^[A-Za-z]+,\s*/', '', $date_string );

		if ( ! preg_match( '/\b\d{4}\b/', $date_string ) ) {
			$date_string .= ' ' . date( 'Y' );
		}

		$timestamp = strtotime( $date_string );
		if ( ! $timestamp ) {
			return null;
		}

		return date( 'Y-m-d', $timestamp );
	}
}
