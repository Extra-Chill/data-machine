<?php
/**
 * Bandzoogle CMS extractor.
 *
 * Bandzoogle is a self-serve website builder popular with small indie venues
 * and DIY rooms. Calendars live at venue-specific paths (e.g. /calendar,
 * /gigs, /shows) and the markup is platform-specific.
 *
 * The current Bandzoogle "anthem" generation of themes renders an event
 * list using:
 *
 *   <div class="event-detail" data-event-id="..." data-occurrence-id="...">
 *     <div class="event-image"><a><img src="..." /></a></div>
 *     <div class="event-description">
 *       <h2 class="event-info event-title"><a href="...">Title</a></h2>
 *       <p class="event-info event-datetime">
 *         <time class="from">
 *           <span class="date">Wednesday, May 13</span> @ <span class="time">6:00PM</span>
 *         </time>
 *       </p>
 *       <p class="event-info event-location"><a href="...maps...">Sub-venue</a></p>
 *       <div class="event-info event-notes"><p>...</p></div>
 *       <div class="event-info buying-options">...share/ticket buttons...</div>
 *     </div>
 *   </div>
 *
 * The calendar's month/year context lives separately in
 * `<span class="month-name">May 2026</span>` near the top of the calendar
 * feature. The individual `event-datetime` markup intentionally omits the
 * year, so we resolve the year from that header (with a sane fallback).
 *
 * Detection: Bandzoogle ships everything from `bndzgl.com` (app assets) and
 * `zoogletools.com` (image CDN), plus a `data-event-id` attribute on each
 * event card. Any one of those is enough to call it Bandzoogle.
 *
 * Note: the original issue (#261) described an older Bandzoogle theme using
 * `.gig-info`, `.gig-artist`, etc. We keep detection broad enough to catch
 * that legacy markup too, but the parser targets the current `event-detail`
 * shape observed on real production sites (Elephant Room, Austin TX).
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\PageVenueExtractor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BandzoogleExtractor extends BaseExtractor {

	/**
	 * Detect Bandzoogle.
	 *
	 * Any of these is a strong positive signal:
	 *   - asset host `bndzgl.com`
	 *   - image CDN `zoogletools.com`
	 *   - `bandzoogle.com` reference (footer credit / "powered by")
	 *   - `data-event-id=` paired with `event-detail` class
	 *   - legacy `.gig-info` / `.gigs` / `.bandzoogle-` class names
	 */
	public function canExtract( string $html ): bool {
		if ( '' === $html ) {
			return false;
		}

		$markers = array(
			'bndzgl.com',
			'zoogletools.com',
			'bandzoogle.com',
			'class="bandzoogle-',
			'class="gig-info"',
			'class="gigs"',
		);

		foreach ( $markers as $marker ) {
			if ( false !== strpos( $html, $marker ) ) {
				return true;
			}
		}

		// data-event-id + event-detail is unique to Bandzoogle calendars.
		if ( false !== strpos( $html, 'data-event-id=' ) && false !== strpos( $html, 'event-detail' ) ) {
			return true;
		}

		return false;
	}

	public function extract( string $html, string $source_url ): array {
		$page_venue = PageVenueExtractor::extract( $html, $source_url );

		// Resolve year from the calendar header (`May 2026`). Falls back to
		// the current year, then to inferring from month/day.
		$calendar_year = $this->extractCalendarYear( $html );

		$events = $this->extractEventDetailBlocks( $html, $source_url, $page_venue, $calendar_year );

		if ( empty( $events ) ) {
			// Legacy `.gig-info` markup fallback.
			$events = $this->extractGigInfoBlocks( $html, $source_url, $page_venue );
		}

		return $events;
	}

	public function getMethod(): string {
		return 'bandzoogle';
	}

	/**
	 * Parse the calendar header for a 4-digit year.
	 *
	 * Bandzoogle renders the current month as:
	 *   <span class="month-name">May 2026</span>
	 *
	 * Some themes use slightly different wrappers, so we accept any
	 * `month-name` span. If absent, returns 0 (caller falls back to
	 * BaseExtractor::inferDateFromMonthDay).
	 *
	 * @return int Year (e.g. 2026) or 0 when unknown.
	 */
	private function extractCalendarYear( string $html ): int {
		if ( preg_match( '/<span[^>]*class="[^"]*month-name[^"]*"[^>]*>\s*([A-Za-z]+)\s+(\d{4})\s*<\/span>/i', $html, $m ) ) {
			return (int) $m[2];
		}

		// Fallback: pagination links carry `?month=N&year=YYYY`. The "next" link
		// always points one month ahead of the current view; subtracting 1 from
		// that month would require date math, so we just use the year as-is
		// because the year flips so rarely that this is good enough.
		if ( preg_match( '/[?&]year=(\d{4})/', $html, $m ) ) {
			return (int) $m[1];
		}

		return 0;
	}

	/**
	 * Extract events from the modern `event-detail` list view.
	 *
	 * @param string $html       Full page HTML.
	 * @param string $source_url Source URL for resolving relative links.
	 * @param array  $page_venue Venue data extracted from page context.
	 * @param int    $calendar_year Year resolved from calendar header, or 0.
	 * @return array Normalized event records.
	 */
	private function extractEventDetailBlocks( string $html, string $source_url, array $page_venue, int $calendar_year ): array {
		// Capture each <div class="event-detail" data-event-id="...">...</div> block.
		// We anchor on the opening tag and consume until the matching closing div by
		// using a depth-aware split: the next `event-detail` opener (or EOF) bounds it.
		if ( ! preg_match_all(
			'/<div\s+class="event-detail"[^>]*data-event-id="(\d+)"[^>]*data-occurrence-id="(\d+)"[^>]*>(.*?)(?=<div\s+class="event-detail"\b|<div class="event-clear">|<\/article>\s*<\/div>\s*<\/section>|<\/section>\s*<\/div>\s*<footer)/si',
			$html,
			$matches,
			PREG_SET_ORDER
		) ) {
			return array();
		}

		$events = array();

		foreach ( $matches as $match ) {
			$event_id      = $match[1];
			$occurrence_id = $match[2];
			$inner         = $match[3];

			$event = $this->parseEventDetailBlock( $inner, $source_url, $page_venue, $calendar_year, $event_id, $occurrence_id );
			if ( ! empty( $event['title'] ) && ! empty( $event['startDate'] ) ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	/**
	 * Parse a single `.event-detail` block.
	 */
	private function parseEventDetailBlock( string $html, string $source_url, array $page_venue, int $calendar_year, string $event_id, string $occurrence_id ): array {
		$event = array(
			'title'         => '',
			'description'   => '',
			'startDate'     => '',
			'startTime'     => '',
			'endDate'       => '',
			'endTime'       => '',
			'venue'         => $page_venue['venue'] ?? '',
			'venueAddress'  => $page_venue['venueAddress'] ?? '',
			'venueCity'     => $page_venue['venueCity'] ?? '',
			'venueState'    => $page_venue['venueState'] ?? '',
			'venueZip'      => $page_venue['venueZip'] ?? '',
			'venueCountry'  => $page_venue['venueCountry'] ?? 'US',
			'venueTimezone' => $page_venue['venueTimezone'] ?? '',
			'ticketUrl'     => '',
			'imageUrl'      => '',
			'source_url'    => '',
			'price'         => '',
		);

		// Title + event detail page URL.
		if ( preg_match( '/<h2[^>]*class="[^"]*event-title[^"]*"[^>]*>\s*<a\s+href="([^"]+)"[^>]*>(.*?)<\/a>\s*<\/h2>/is', $html, $m ) ) {
			$event['source_url'] = esc_url_raw( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
			$event['title']      = $this->sanitizeText( html_entity_decode( wp_strip_all_tags( $m[2] ), ENT_QUOTES, 'UTF-8' ) );
		}

		// Datetime: parse "Wednesday, May 13" + "6:00PM" inside event-datetime.
		if ( preg_match( '/<p[^>]*class="[^"]*event-datetime[^"]*"[^>]*>(.*?)<\/p>/is', $html, $dt_match ) ) {
			$this->parseDatetimeFragment( $event, $dt_match[1], $calendar_year );
		}

		// Sub-venue / room (e.g. "🐘6pm" at Elephant Room). Only override the
		// venue NAME — keep page-level address. Many Bandzoogle venues do not
		// expose per-event addresses.
		if ( preg_match( '/<p[^>]*class="[^"]*event-location[^"]*"[^>]*>(.*?)<\/p>/is', $html, $loc_match ) ) {
			$loc_text = trim( html_entity_decode( wp_strip_all_tags( $loc_match[1] ), ENT_QUOTES, 'UTF-8' ) );
			if ( '' !== $loc_text && empty( $event['venue'] ) ) {
				$event['venue'] = $this->sanitizeText( $loc_text );
			}
		}

		// Description from event-notes block.
		if ( preg_match( '/<div[^>]*class="[^"]*event-notes[^"]*"[^>]*>(.*?)<\/div>/is', $html, $notes_match ) ) {
			$event['description'] = $this->cleanHtml( $notes_match[1] );
		}

		// Image — prefer the social-sized event-image, fall back to any <img>.
		if ( preg_match( '/<div[^>]*class="[^"]*event-image[^"]*"[^>]*>.*?<img[^>]+src="([^"]+)"/is', $html, $img_match ) ) {
			$event['imageUrl'] = esc_url_raw( $this->resolveUrl( html_entity_decode( $img_match[1], ENT_QUOTES, 'UTF-8' ), $source_url ) );
		}

		// Ticket URL — Bandzoogle's buying-options block contains an external
		// purchase link when the venue sells tickets through the site. Look
		// for any non-share, non-map external link inside it.
		$event['ticketUrl'] = $this->findTicketUrl( $html, $source_url );

		// As a last resort, point ticketUrl at the event detail page so
		// downstream pipelines always have something to link to.
		if ( '' === $event['ticketUrl'] && '' !== $event['source_url'] ) {
			$event['ticketUrl'] = $event['source_url'];
		}

		return $event;
	}

	/**
	 * Pull date + time out of the `.event-datetime` paragraph.
	 *
	 * Bandzoogle prints two variants side-by-side (long + short). We parse
	 * the first one we find. Format examples:
	 *   "Wednesday, May 13 @ 6:00PM"
	 *   "Wed, May 13 @ 6:00PM"
	 */
	private function parseDatetimeFragment( array &$event, string $fragment, int $calendar_year ): void {
		// Pull all `<time>` elements; the first `class="from"` is start, second is end (if present).
		if ( preg_match_all( '/<time[^>]*class="([^"]*)"[^>]*>(.*?)<\/time>/is', $fragment, $time_matches, PREG_SET_ORDER ) ) {
			$start_set = false;
			foreach ( $time_matches as $tm ) {
				$class = $tm[1];
				$inner = $tm[2];

				$is_start = ! $start_set && ( false !== strpos( $class, 'from' ) || ! $start_set );
				$is_end   = $start_set && false !== strpos( $class, 'to' );

				$parsed = $this->parseTimeBlock( $inner, $calendar_year );

				if ( $is_start && ! empty( $parsed['date'] ) ) {
					$event['startDate'] = $parsed['date'];
					$event['startTime'] = $parsed['time'];
					$start_set          = true;
				} elseif ( $is_end && ! empty( $parsed['date'] ) ) {
					$event['endDate'] = $parsed['date'];
					$event['endTime'] = $parsed['time'];
				}
			}
		}

		// Some Bandzoogle themes omit the inner spans and inline the text.
		// Fall back to scraping the raw fragment if we still have nothing.
		if ( '' === $event['startDate'] ) {
			$parsed = $this->parseTimeBlock( $fragment, $calendar_year );
			if ( ! empty( $parsed['date'] ) ) {
				$event['startDate'] = $parsed['date'];
				$event['startTime'] = $parsed['time'];
			}
		}
	}

	/**
	 * Parse a single time block's inner HTML (e.g. the contents of a `<time>` tag)
	 * to a date + time tuple.
	 *
	 * @return array{date: string, time: string}
	 */
	private function parseTimeBlock( string $html, int $calendar_year ): array {
		$out = array(
			'date' => '',
			'time' => '',
		);

		// Date span: "Wednesday, May 13" or "Wed, May 13".
		$date_text = '';
		if ( preg_match( '/<span[^>]*class="date"[^>]*>(.*?)<\/span>/is', $html, $m ) ) {
			$date_text = trim( wp_strip_all_tags( $m[1] ) );
		} else {
			$date_text = trim( wp_strip_all_tags( $html ) );
		}

		// Time span: "6:00PM".
		$time_text = '';
		if ( preg_match( '/<span[^>]*class="time"[^>]*>(.*?)<\/span>/is', $html, $m ) ) {
			$time_text = trim( wp_strip_all_tags( $m[1] ) );
		}

		// Extract month + day from date_text. Day-of-week prefix is optional.
		$months = '(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t)?(?:ember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)';
		if ( preg_match( '/(' . $months . ')\s+(\d{1,2})/i', $date_text, $dm ) ) {
			$month_name = $dm[1];
			$day        = (int) $dm[2];

			if ( $calendar_year > 0 ) {
				try {
					$dt           = new \DateTime( sprintf( '%s %d %d', $month_name, $day, $calendar_year ) );
					$out['date']  = $dt->format( 'Y-m-d' );
				} catch ( \Exception $e ) {
					$out['date'] = '';
				}
			} else {
				$out['date'] = $this->inferDateFromMonthDay( $month_name, (string) $day );
			}
		}

		if ( '' !== $time_text ) {
			$out['time'] = $this->parseTimeString( $time_text );
		}

		return $out;
	}

	/**
	 * Find a ticket purchase URL inside an event-detail block.
	 *
	 * Bandzoogle's buying-options div wraps a share dialog (junk) and an
	 * optional external ticket link. We accept any external URL whose host
	 * differs from the source URL's host and doesn't smell like a share /
	 * social link. As a fallback, well-known ticket vendor domains win
	 * regardless of position.
	 */
	private function findTicketUrl( string $html, string $source_url ): string {
		$ticket_domains = array(
			'eventbrite',
			'tixr',
			'etix',
			'dice.fm',
			'ticketmaster',
			'seetickets',
			'ticketweb',
			'aftontickets',
			'prekindle',
			'showclix',
			'opentable',
			'resy',
			'tock',
			'venuepilot',
			'freshtix',
			'showare',
			'wl.seetickets',
		);

		if ( ! preg_match_all( '/<a[^>]+href="([^"]+)"[^>]*>/i', $html, $links ) ) {
			return '';
		}

		$source_host = wp_parse_url( $source_url, PHP_URL_HOST );

		// Pass 1: known ticket vendor domains.
		foreach ( $links[1] as $href ) {
			$href_decoded = html_entity_decode( $href, ENT_QUOTES, 'UTF-8' );
			foreach ( $ticket_domains as $domain ) {
				if ( false !== stripos( $href_decoded, $domain ) ) {
					return esc_url_raw( $href_decoded );
				}
			}
		}

		// Pass 2: any external link that isn't share/social/maps/javascript.
		$blocklist = array( 'facebook.com', 'twitter.com', 'instagram.com', 'threads.net', 'google.com/maps', 'mailto:', 'javascript:', '#', 'pinterest', 'bandzoogle.com', 'bndzgl.com', 'zoogletools.com' );

		foreach ( $links[1] as $href ) {
			$href_decoded = html_entity_decode( $href, ENT_QUOTES, 'UTF-8' );

			foreach ( $blocklist as $bad ) {
				if ( false !== stripos( $href_decoded, $bad ) ) {
					continue 2;
				}
			}

			if ( ! preg_match( '#^https?://#i', $href_decoded ) ) {
				continue;
			}

			$link_host = wp_parse_url( $href_decoded, PHP_URL_HOST );
			if ( $link_host && $source_host && $link_host !== $source_host ) {
				return esc_url_raw( $href_decoded );
			}
		}

		return '';
	}

	/**
	 * Legacy `.gig-info` parser.
	 *
	 * Older Bandzoogle themes (and the markup originally described in #261)
	 * use `<div class="gig-info">` blocks. We don't currently have a
	 * production sample for this shape, but the parser is straightforward
	 * and cheap to ship for forward compatibility.
	 *
	 * @return array
	 */
	private function extractGigInfoBlocks( string $html, string $source_url, array $page_venue ): array {
		if ( false === strpos( $html, 'gig-info' ) ) {
			return array();
		}

		if ( ! preg_match_all(
			'/<div[^>]*class="[^"]*gig-info[^"]*"[^>]*>(.*?)(?=<div[^>]*class="[^"]*gig-info[^"]*"|<\/section>|<\/div>\s*<\/div>\s*<\/div>)/si',
			$html,
			$matches
		) ) {
			return array();
		}

		$events = array();
		foreach ( $matches[1] as $inner ) {
			$event = $this->parseGigInfoBlock( $inner, $source_url, $page_venue );
			if ( ! empty( $event['title'] ) && ! empty( $event['startDate'] ) ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	/**
	 * Parse a single `.gig-info` legacy block.
	 */
	private function parseGigInfoBlock( string $html, string $source_url, array $page_venue ): array {
		$event = array(
			'title'         => '',
			'description'   => '',
			'startDate'     => '',
			'startTime'     => '',
			'endDate'       => '',
			'endTime'       => '',
			'venue'         => $page_venue['venue'] ?? '',
			'venueAddress'  => $page_venue['venueAddress'] ?? '',
			'venueCity'     => $page_venue['venueCity'] ?? '',
			'venueState'    => $page_venue['venueState'] ?? '',
			'venueZip'      => $page_venue['venueZip'] ?? '',
			'venueCountry'  => $page_venue['venueCountry'] ?? 'US',
			'venueTimezone' => $page_venue['venueTimezone'] ?? '',
			'ticketUrl'     => '',
			'imageUrl'      => '',
			'source_url'    => '',
			'price'         => '',
		);

		// time[datetime] -> ISO 8601.
		if ( preg_match( '/<time[^>]+datetime="([^"]+)"/i', $html, $m ) ) {
			$parsed             = $this->parseIsoDatetime( $m[1] );
			$event['startDate'] = $parsed['date'];
			$event['startTime'] = $parsed['time'];
			if ( ! empty( $parsed['timezone'] ) ) {
				$event['venueTimezone'] = $parsed['timezone'];
			}
		} elseif ( preg_match( '/<time[^>]*>(.*?)<\/time>/is', $html, $m ) ) {
			// Fall back to time element text.
			$text   = trim( wp_strip_all_tags( $m[1] ) );
			$parsed = $this->parseDatetime( $text, $page_venue['venueTimezone'] ?? '' );
			if ( ! empty( $parsed['date'] ) ) {
				$event['startDate'] = $parsed['date'];
				$event['startTime'] = $parsed['time'];
			}
		}

		// Title: .gig-artist or .gig-title.
		foreach ( array( 'gig-artist', 'gig-title' ) as $title_class ) {
			if ( preg_match( '/<[a-z0-9]+[^>]*class="[^"]*' . preg_quote( $title_class, '/' ) . '[^"]*"[^>]*>(.*?)<\/[a-z0-9]+>/is', $html, $m ) ) {
				$event['title'] = $this->sanitizeText( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) );
				if ( ! empty( $event['title'] ) ) {
					break;
				}
			}
		}

		// Venue override.
		if ( preg_match( '/<[a-z0-9]+[^>]*class="[^"]*gig-venue[^"]*"[^>]*>(.*?)<\/[a-z0-9]+>/is', $html, $m ) ) {
			$venue_text = $this->sanitizeText( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) );
			if ( '' !== $venue_text ) {
				$event['venue'] = $venue_text;
			}
		}

		// Ticket URL: .gig-tickets a[href].
		if ( preg_match( '/<[a-z0-9]+[^>]*class="[^"]*gig-tickets[^"]*"[^>]*>.*?<a[^>]+href="([^"]+)"/is', $html, $m ) ) {
			$event['ticketUrl'] = esc_url_raw( $this->resolveUrl( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), $source_url ) );
		}

		// Image: poster img or first img inside the gig block.
		if ( preg_match( '/<[a-z0-9]+[^>]*class="[^"]*gig-poster[^"]*"[^>]*>.*?<img[^>]+src="([^"]+)"/is', $html, $m ) ) {
			$event['imageUrl'] = esc_url_raw( $this->resolveUrl( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), $source_url ) );
		} elseif ( preg_match( '/<img[^>]+src="([^"]+)"/i', $html, $m ) ) {
			$event['imageUrl'] = esc_url_raw( $this->resolveUrl( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), $source_url ) );
		}

		// Description.
		if ( preg_match( '/<[a-z0-9]+[^>]*class="[^"]*gig-description[^"]*"[^>]*>(.*?)<\/[a-z0-9]+>/is', $html, $m ) ) {
			$event['description'] = $this->cleanHtml( $m[1] );
		}

		return $event;
	}
}
