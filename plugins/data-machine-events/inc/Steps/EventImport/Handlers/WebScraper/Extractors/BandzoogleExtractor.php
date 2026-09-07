<?php
/**
 * Bandzoogle CMS extractor.
 *
 * Bandzoogle is a self-serve website builder popular with small indie venues
 * and DIY rooms. Calendars live at venue-specific paths (e.g. /calendar,
 * /gigs, /shows) and the markup is platform-specific. Artist sites use the
 * same `event-detail` markup for tour/show pages; there the per-event
 * location is the real venue (often with a full address) and the page
 * itself carries no venue identity (#770).
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
	 * Country tokens that mark a page as non-US.
	 *
	 * Artist tour pages list international stops ("Visby, SWE") while the
	 * page-level venue default is 'US'. When a stop's title/location
	 * clearly carries a non-US country token, leaving venueCountry empty
	 * is preferable to a wrong default. Word-boundary, case-sensitive —
	 * "Cabana" never matches "CAN".
	 *
	 * @since 0.57.1
	 */
	private const NON_US_COUNTRY_TOKENS = array(
		'SWE',
		'NOR',
		'DEN',
		'FIN',
		'GBR',
		'UK',
		'IRL',
		'FRA',
		'DEU',
		'NLD',
		'BEL',
		'ESP',
		'ITA',
		'PRT',
		'AUT',
		'CHE',
		'POL',
		'CAN',
		'MEX',
		'AUS',
		'NZL',
		'JPN',
	);

	/**
	 * Injectable "now" for deterministic date inference (tests).
	 *
	 * @var \DateTimeImmutable|null
	 */
	private ?\DateTimeImmutable $now = null;

	/**
	 * Inject a reference "now" used for year inference on year-less dates.
	 *
	 * Bandzoogle artist tour pages omit the year entirely; the fallback
	 * inference compares each date against "now". Tests inject a fixed
	 * instant so expectations do not depend on the wall clock.
	 *
	 * @since 0.57.1
	 */
	public function setNow( \DateTimeImmutable $now ): void {
		$this->now = $now;
	}

	private function now(): \DateTimeImmutable {
		return $this->now ?? new \DateTimeImmutable( 'now' );
	}

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
	 * Fields this extractor wants second-hop detail-page enrichment for.
	 *
	 * Bandzoogle artist tour pages publish no start time on the listing or
	 * the Bandzoogle event-detail page; the time lives one hop further on
	 * the ticket/venue page (#776). The enricher stage consumes this
	 * declaration and only follows URLs for events missing these fields.
	 *
	 * @return string[]
	 */
	public function needsDetailEnrichment(): array {
		return array( 'startTime' );
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

		// Per-event location. On artist tour pages (#770) the page-level
		// venue is a bare <title>-derived guess ("Tour", the artist name)
		// and each event's event-location carries the real venue — often
		// with a full comma-separated address. On venue sites (Elephant
		// Room) event-location holds sub-room labels ("🐘6pm") that must
		// not replace the venue name.
		//
		// Rule: a location naming a real place (not a bare time label) is
		// authoritative for the venue NAME; address components it carries
		// override the page-level address fields. A time-like label
		// overrides nothing.
		if ( preg_match( '/<p[^>]*class="[^"]*event-location[^"]*"[^>]*>(.*?)<\/p>/is', $html, $loc_match ) ) {
			$loc_text = trim( html_entity_decode( wp_strip_all_tags( $loc_match[1] ), ENT_QUOTES, 'UTF-8' ) );
			if ( '' !== $loc_text ) {
				$location = $this->parseLocationText( $loc_text );

				if ( '' !== $location['venue'] && ! $this->isSubRoomTimeLabel( $loc_text ) ) {
					$event['venue'] = $location['venue'];
				}

				// When the location parsed into address components it is
				// authoritative for ALL address fields — otherwise a
				// partial location (e.g. "Lake Oconee, Greensboro, GA")
				// would mix with a page-level address scraped from
				// another event on the page.
				$owns_address_fields = '' !== $location['venueAddress']
					|| '' !== $location['venueCity']
					|| '' !== $location['venueZip'];

				foreach ( array( 'venueAddress', 'venueCity', 'venueState', 'venueZip' ) as $venue_field ) {
					if ( '' !== $location[ $venue_field ] || $owns_address_fields ) {
						$event[ $venue_field ] = $location[ $venue_field ];
					}
				}

				$this->clearWrongCountryDefault( $event, $loc_text );
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

				$is_start = ! $start_set;
				$is_end   = $start_set && false !== strpos( $class, 'to' );

				$parsed = $this->parseTimeBlock( $inner, $calendar_year );

				if ( $is_start && ! empty( $parsed['date'] ) ) {
					$event['startDate'] = $parsed['date'];
					$event['startTime'] = $parsed['time'];
					$start_set          = true;
				} elseif ( $is_end ) {
					// Bandzoogle's `to` block often carries the time only
					// (no date span). Reuse the start date so the end time
					// is not dropped (#770).
					if ( ! empty( $parsed['date'] ) ) {
						$event['endDate'] = $parsed['date'];
					} elseif ( '' !== $event['startDate'] ) {
						$event['endDate'] = $event['startDate'];
					}
					if ( '' !== $parsed['time'] ) {
						$event['endTime'] = $parsed['time'];
					}
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
					$dt          = new \DateTime( sprintf( '%s %d %d', $month_name, $day, $calendar_year ) );
					$out['date'] = $dt->format( 'Y-m-d' );
				} catch ( \Exception $e ) {
					$out['date'] = '';
				}
			} else {
				$out['date'] = $this->inferDateFromMonthDay( $month_name, (string) $day, $this->now() );
			}
		}

		if ( '' !== $time_text ) {
			$out['time'] = $this->parseTimeString( $time_text );
		}

		return $out;
	}

	/**
	 * Split an event-location string into venue name + address components.
	 *
	 * Artist tour locations arrive as "Island Cabana Bar, 50 Immigration
	 * St , Charleston, SC 29403". The first comma segment is the venue
	 * name; the remainder is parsed with the same address heuristics the
	 * page-level extractor uses (PageVenueExtractor). A trailing
	 * "City, ST" without ZIP ("Lake Oconee, Greensboro, GA") also splits.
	 * A single-segment location ("Rootsy Visby") is the whole name.
	 *
	 * @since 0.57.1
	 * @param string $loc_text Decoded, tag-stripped location text.
	 * @return array Keys: venue, venueAddress, venueCity, venueState, venueZip.
	 */
	private function parseLocationText( string $loc_text ): array {
		$out = array(
			'venue'        => $this->sanitizeText( $loc_text ),
			'venueAddress' => '',
			'venueCity'    => '',
			'venueState'   => '',
			'venueZip'     => '',
		);

		if ( false === strpos( $loc_text, ',' ) ) {
			return $out;
		}

		$segments = array_map( 'trim', explode( ',', $loc_text ) );
		$segments = array_values(
			array_filter(
				$segments,
				static function ( $segment ) {
					return '' !== $segment;
				}
			)
		);

		if ( count( $segments ) < 2 ) {
			return $out;
		}

		$name = $segments[0];
		$rest = implode( ', ', array_slice( $segments, 1 ) );
		$last = $segments[ count( $segments ) - 1 ];

		$csz    = PageVenueExtractor::extractCityStateZip( $rest );
		$street = PageVenueExtractor::extractStreetAddress( $rest );

		if ( '' !== $csz['venueCity'] || '' !== $street ) {
			// "Name, Street, City, ST ZIP".
			$out['venue']        = $this->sanitizeText( $name );
			$out['venueAddress'] = $street;
			$out['venueCity']    = $csz['venueCity'];
			$out['venueState']   = $csz['venueState'];
			$out['venueZip']     = $csz['venueZip'];
		} elseif ( count( $segments ) >= 3 && preg_match( '/^[A-Z]{2}$/', $last ) ) {
			// "Name, City, ST" without ZIP.
			$out['venue']      = $this->sanitizeText( $name );
			$out['venueCity']  = $this->sanitizeText( $segments[ count( $segments ) - 2 ] );
			$out['venueState'] = $last;
		}

		return $out;
	}

	/**
	 * Whether a location string is a sub-room / time label rather than a
	 * venue name.
	 *
	 * Venue Bandzoogle sites decorate each event with a room or set-time
	 * label ("🐘6pm", "9:30", "Doors 8pm") in event-location. These must
	 * not replace the page-level venue name. A label is time-like once
	 * emoji are stripped: at most a doors/show prefix plus digits and an
	 * optional AM/PM.
	 *
	 * @since 0.57.1
	 * @param string $loc_text Decoded, tag-stripped location text.
	 * @return bool
	 */
	private function isSubRoomTimeLabel( string $loc_text ): bool {
		$stripped = preg_replace(
			'/[\x{1F000}-\x{1FAFF}\x{2190}-\x{21FF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE00}-\x{FE0F}\x{200D}]/u',
			'',
			trim( $loc_text )
		);
		$stripped = trim( (string) $stripped );

		if ( '' === $stripped ) {
			return true;
		}

		return (bool) preg_match(
			'/^(?:(?:doors|show(?:time)?)\s*)?\d{1,2}(?::\d{2})?\s*(?:[ap]\.?m\.?)?(?:\s*(?:show|set|doors|start))?$/i',
			$stripped
		);
	}

	/**
	 * Clear the 'US' venueCountry default when the event clearly points
	 * at a non-US country.
	 *
	 * The page-level venue default is 'US'; on artist tour pages listing
	 * international stops that default is wrong. An empty country is
	 * preferred over a wrong one. No general country inference.
	 *
	 * @since 0.57.1
	 * @param array  $event    Event array (venueCountry modified in place).
	 * @param string $loc_text Decoded, tag-stripped location text.
	 */
	private function clearWrongCountryDefault( array &$event, string $loc_text ): void {
		$haystack = $event['title'] . ' ' . $loc_text;

		foreach ( self::NON_US_COUNTRY_TOKENS as $token ) {
			if ( preg_match( '/\b' . $token . '\b/', $haystack ) ) {
				$event['venueCountry'] = '';
				return;
			}
		}
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
