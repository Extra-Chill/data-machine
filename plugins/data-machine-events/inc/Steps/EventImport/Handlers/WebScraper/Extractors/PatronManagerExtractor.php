<?php
/**
 * PatronManager (Salesforce "PatronTicket") extractor.
 *
 * PatronManager is a Salesforce-hosted ticketing app used by many nonprofit
 * arts venues. The public ticket site lives at
 * `https://<org>.my.salesforce-sites.com/ticket/` and renders entirely
 * client-side, so the server-fetched HTML carries no event markup. Venue
 * websites usually link to it (e.g. ironhorse.org → theparlorroom ticket site)
 * and show only posters themselves.
 *
 * The ticket page embeds a Visualforce remoting config:
 *
 *   Visualforce.remoting.Manager.add(new $VFRM.RemotingProviderImpl({
 *       "vf": {"vid": "066…", …},
 *       "actions": {"PatronTicket.Controller_PublicTicketApp": {"ms": [
 *           {"name": "fetchEvents", "len": 4, "ns": "PatronTicket", "ver": 51.0,
 *            "csrf": "…", "authorization": "…"}, …]}},
 *       "service": "ticket/apexremote"
 *   }));
 *
 * This extractor reads that config and replays the app's own call:
 *
 *   POST https://<org>.my.salesforce-sites.com/ticket/apexremote
 *   {"action": "PatronTicket.Controller_PublicTicketApp", "method": "fetchEvents",
 *    "data": ["<siteURL>/", "", "", ""], "type": "rpc", "tid": 2,
 *    "ctx": {"csrf", "vid", "ns", "ver", "authorization"}}
 *
 * The response is `[{"statusCode": 200, "result": [event…]}]`. Each event has
 * `instances[]` (performances) carrying `eventName`, `formattedDates`
 * (YYYYMMDD + TIME_STRING local wall time, ISO8601 epoch ms), `purchaseUrl`,
 * `soldOut`, `venueId` and `nonTimed`. Instances that share an event, date,
 * time and venue are seating sections of one performance and are collapsed.
 *
 * Multi-venue orgs (Iron Horse + Parlor Room share one site) can be split per
 * flow with a venueId filter: either the constructor `venue_ids` option or a
 * `patronmanager_venue=<id>[,<id>]` query parameter on the flow's source URL.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachine\Core\HttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PatronManagerExtractor extends BaseExtractor {

	/**
	 * Apex controller that serves the public ticket app.
	 */
	private const CONTROLLER = 'PatronTicket.Controller_PublicTicketApp';

	/**
	 * Remote method that lists all public events.
	 */
	private const METHOD = 'fetchEvents';

	/**
	 * Source-URL query parameter carrying a per-flow venueId filter.
	 */
	public const VENUE_QUERY_PARAM = 'patronmanager_venue';

	/**
	 * Instances dated further out than this are placeholders (e.g.
	 * "Memberships" dated 2199-12-31), not shows.
	 */
	private const MAX_FUTURE_YEARS = 2;

	/**
	 * Event `type` values that are never performances.
	 */
	private const NON_EVENT_TYPES = array( 'membership', 'donation', 'gift card', 'giftcard' );

	/**
	 * Ticket-site hosts PatronManager is served from.
	 */
	private const TICKET_SITE_PATTERN = '#https?:(?://|\\\\/\\\\/)([a-z0-9][a-z0-9\-]*\.(?:my\.salesforce-sites\.com|secure\.force\.com))(?:/|\\\\/)ticket\b#i';

	/**
	 * Venue ids to keep (empty = all).
	 *
	 * @var string[]
	 */
	private array $venue_ids;

	/**
	 * Reference "now" for past/far-future filtering (injectable for tests).
	 *
	 * @var \DateTimeImmutable|null
	 */
	private ?\DateTimeImmutable $now;

	/**
	 * @param array $options {
	 *     Optional settings.
	 *
	 *     @type string[]|string     $venue_ids PatronManager venueIds to keep.
	 *     @type \DateTimeImmutable  $now       Reference time for date filtering.
	 * }
	 */
	public function __construct( array $options = array() ) {
		$this->venue_ids = $this->normalizeVenueIds( $options['venue_ids'] ?? array() );
		$this->now       = ( ( $options['now'] ?? null ) instanceof \DateTimeImmutable ) ? $options['now'] : null;
	}

	public function canExtract( string $html ): bool {
		if ( '' === $html ) {
			return false;
		}

		// Direct ticket page: the remoting config names the controller.
		if ( false !== strpos( $html, self::CONTROLLER ) ) {
			return true;
		}

		// Venue page linking to a PatronManager ticket site.
		return '' !== $this->findTicketSiteUrl( $html );
	}

	public function extract( string $html, string $source_url ): array {
		$config = $this->parseRemotingConfig( $html );
		$origin = $this->originFromUrl( $source_url );

		if ( null === $config ) {
			$ticket_site = $this->findTicketSiteUrl( $html );
			if ( '' === $ticket_site ) {
				return array();
			}

			$page = $this->fetchUrl(
				$ticket_site,
				array(
					'timeout'      => 20,
					'browser_mode' => true,
					'context'      => 'PatronManager Extractor — ticket page',
				),
				'PatronManager ticket page'
			);
			if ( null === $page || '' === $page ) {
				return array();
			}

			$config = $this->parseRemotingConfig( $page );
			$origin = $this->originFromUrl( $ticket_site );
		}

		if ( null === $config || '' === $origin ) {
			return array();
		}

		$prefix   = preg_replace( '#/?apexremote$#', '', $config['service'] );
		$site_url = $origin . '/' . trim( (string) $prefix, '/' ) . '/';
		$endpoint = $origin . '/' . ltrim( $config['service'], '/' );

		$results = $this->fetchEvents( $endpoint, $site_url, $config );
		if ( empty( $results ) ) {
			return array();
		}

		$venue_ids = array_values( array_unique( array_merge( $this->venue_ids, $this->venueIdsFromUrl( $source_url ) ) ) );

		return $this->normalizeEvents( $results, $venue_ids );
	}

	public function getMethod(): string {
		return 'patronmanager';
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Detection
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Find the single PatronManager ticket site linked from a page.
	 *
	 * Declines (returns '') when links point at more than one org, since we
	 * cannot tell which ticket site belongs to this venue.
	 *
	 * @param string $html Page HTML.
	 * @return string Ticket site URL (`https://<host>/ticket/`), or ''.
	 */
	private function findTicketSiteUrl( string $html ): string {
		if ( ! preg_match_all( self::TICKET_SITE_PATTERN, $html, $matches ) ) {
			return '';
		}

		$hosts = array_values( array_unique( array_map( 'strtolower', $matches[1] ) ) );
		if ( 1 !== count( $hosts ) ) {
			return '';
		}

		return 'https://' . $hosts[0] . '/ticket/';
	}

	/**
	 * Parse the Visualforce remoting config for the fetchEvents method.
	 *
	 * @param string $html Ticket page HTML.
	 * @return array{vid:string,ns:string,ver:mixed,csrf:string,authorization:string,service:string}|null
	 */
	private function parseRemotingConfig( string $html ): ?array {
		if ( false === strpos( $html, self::CONTROLLER ) ) {
			return null;
		}

		if ( ! preg_match_all( '/RemotingProviderImpl\(\s*(\{.*?\})\s*\)\s*\)\s*;/s', $html, $matches ) ) {
			return null;
		}

		foreach ( $matches[1] as $json ) {
			$provider = json_decode( $json, true );
			if ( ! is_array( $provider ) ) {
				continue;
			}

			$methods = $provider['actions'][ self::CONTROLLER ]['ms'] ?? null;
			if ( ! is_array( $methods ) ) {
				continue;
			}

			foreach ( $methods as $method ) {
				if ( ! is_array( $method ) || self::METHOD !== ( $method['name'] ?? '' ) ) {
					continue;
				}

				$vid     = (string) ( $provider['vf']['vid'] ?? '' );
				$csrf    = (string) ( $method['csrf'] ?? '' );
				$service = (string) ( $provider['service'] ?? '' );
				if ( '' === $vid || '' === $csrf || '' === $service ) {
					return null;
				}

				return array(
					'vid'           => $vid,
					'ns'            => (string) ( $method['ns'] ?? 'PatronTicket' ),
					'ver'           => $method['ver'] ?? null,
					'csrf'          => $csrf,
					'authorization' => (string) ( $method['authorization'] ?? '' ),
					'service'       => $service,
				);
			}
		}

		return null;
	}

	// ────────────────────────────────────────────────────────────────────────────
	// API
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Call the fetchEvents remote action.
	 *
	 * @param string $endpoint Apex remoting endpoint.
	 * @param string $site_url Ticket site URL (must end in "/").
	 * @param array  $config   Parsed remoting config.
	 * @return array Raw event records, or empty array on failure.
	 */
	private function fetchEvents( string $endpoint, string $site_url, array $config ): array {
		$ctx = array(
			'csrf' => $config['csrf'],
			'vid'  => $config['vid'],
			'ns'   => $config['ns'],
			'ver'  => $config['ver'],
		);
		if ( '' !== $config['authorization'] ) {
			$ctx['authorization'] = $config['authorization'];
		}

		// Arg 1 is the siteURL; null makes the controller 400 with
		// "Attempt to de-reference a null object". The rest are
		// accessCode, passcode and eventView.
		$payload = wp_json_encode(
			array(
				'action' => self::CONTROLLER,
				'method' => self::METHOD,
				'data'   => array( $site_url, '', '', '' ),
				'type'   => 'rpc',
				'tid'    => 2,
				'ctx'    => $ctx,
			)
		);

		$result = HttpClient::post(
			$endpoint,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-User-Agent' => 'Visualforce-Remoting',
					'Referer'      => $site_url,
				),
				'body'    => $payload,
				'context' => 'PatronManager Extractor — fetchEvents',
			)
		);

		if ( empty( $result['success'] ) || 200 !== (int) ( $result['status_code'] ?? 0 ) ) {
			return array();
		}

		$data = json_decode( (string) ( $result['data'] ?? '' ), true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		foreach ( $data as $response ) {
			if ( ! is_array( $response ) || 200 !== (int) ( $response['statusCode'] ?? 0 ) ) {
				continue;
			}
			if ( isset( $response['result'] ) && is_array( $response['result'] ) ) {
				return $response['result'];
			}
		}

		do_action(
			'datamachine_log',
			'debug',
			'PatronManager Extractor: fetchEvents returned no usable result',
			array(
				'endpoint' => $endpoint,
				'message'  => is_array( $data[0] ?? null ) ? (string) ( $data[0]['message'] ?? '' ) : '',
			)
		);

		return array();
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Normalization
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Map fetchEvents records to one event per performance.
	 *
	 * @param array    $records   Raw event records.
	 * @param string[] $venue_ids Venue ids to keep (empty = all).
	 * @return array Normalized events.
	 */
	private function normalizeEvents( array $records, array $venue_ids ): array {
		$now      = $this->now ?? new \DateTimeImmutable( 'now', wp_timezone() );
		$today    = $now->format( 'Y-m-d' );
		$max_date = $now->modify( '+' . self::MAX_FUTURE_YEARS . ' years' )->format( 'Y-m-d' );

		$groups = array();
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) || empty( $record['instances'] ) || ! is_array( $record['instances'] ) ) {
				continue;
			}

			$type = strtolower( trim( (string) ( $record['type'] ?? '' ) ) );
			if ( in_array( $type, self::NON_EVENT_TYPES, true ) ) {
				continue;
			}

			foreach ( $record['instances'] as $instance ) {
				if ( ! is_array( $instance ) || ! empty( $instance['nonTimed'] ) ) {
					continue;
				}

				$venue_id = (string) ( $instance['venueId'] ?? '' );
				if ( ! empty( $venue_ids ) && ! in_array( $venue_id, $venue_ids, true ) ) {
					continue;
				}

				$dates = is_array( $instance['formattedDates'] ?? null ) ? $instance['formattedDates'] : array();
				$date  = $this->parseYmd( (string) ( $dates['YYYYMMDD'] ?? '' ) );
				if ( '' === $date || $date < $today || $date > $max_date ) {
					continue;
				}

				$time = $this->parseTimeString( (string) ( $dates['TIME_STRING'] ?? '' ) );
				$key  = implode( '|', array( (string) ( $instance['eventId'] ?? $record['id'] ?? '' ), $date, $time, $venue_id ) );

				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array(
						'record'        => $record,
						'instance'      => $instance,
						'date'          => $date,
						'time'          => $time,
						'ticketUrl'     => '',
						'sold_out'      => true,
						'sold_out_link' => true,
					);
				}

				$sold_out = ! empty( $instance['soldOut'] );
				if ( ! $sold_out ) {
					$groups[ $key ]['sold_out'] = false;
				}

				// Prefer a section that is still on sale for the ticket link.
				$purchase = (string) ( $instance['purchaseUrl'] ?? '' );
				$upgrade  = ! $sold_out && $groups[ $key ]['sold_out_link'];
				if ( '' !== $purchase && ( '' === $groups[ $key ]['ticketUrl'] || $upgrade ) ) {
					$groups[ $key ]['ticketUrl']     = $purchase;
					$groups[ $key ]['sold_out_link'] = $sold_out;
				}
			}
		}

		$events = array();
		foreach ( $groups as $group ) {
			$event = $this->buildEvent( $group );
			if ( '' !== $event['title'] ) {
				$events[] = $event;
			}
		}

		usort(
			$events,
			static fn ( array $a, array $b ): int => strcmp( $a['startDate'] . ' ' . $a['startTime'], $b['startDate'] . ' ' . $b['startTime'] )
		);

		return $events;
	}

	/**
	 * Build a standardized event from a collapsed performance group.
	 *
	 * @param array $group Group with record, instance, date, time, ticketUrl, sold_out.
	 * @return array Standardized event data.
	 */
	private function buildEvent( array $group ): array {
		$record   = $group['record'];
		$instance = $group['instance'];

		$title = (string) ( $instance['eventName'] ?? '' );
		if ( '' === trim( $title ) ) {
			$title = (string) ( $record['name'] ?? '' );
		}

		$detail = (string) ( $instance['detail'] ?? '' );
		if ( '' === trim( $detail ) ) {
			$detail = (string) ( $record['detail'] ?? '' );
		}

		$ticket_url = '' !== $group['ticketUrl'] ? $group['ticketUrl'] : (string) ( $record['purchaseUrl'] ?? '' );

		$event = array(
			'title'             => $this->sanitizeText( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
			'description'       => $this->htmlToText( $detail ),
			'startDate'         => $group['date'],
			'endDate'           => '',
			'startTime'         => $group['time'],
			'endTime'           => '',
			'ticketUrl'         => '' !== $ticket_url ? esc_url_raw( $ticket_url ) : '',
			'offerAvailability' => $group['sold_out'] ? 'SoldOut' : 'InStock',
		);

		$image = $this->findImageUrl( $detail . ' ' . (string) ( $record['description'] ?? '' ) );
		if ( '' !== $image ) {
			$event['imageUrl'] = $image;
		}

		return $event;
	}

	/**
	 * Convert a YYYYMMDD string to Y-m-d.
	 *
	 * @param string $ymd Date like "20261120".
	 * @return string Y-m-d, or '' when invalid.
	 */
	private function parseYmd( string $ymd ): string {
		if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})$/', trim( $ymd ), $m ) ) {
			return '';
		}

		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}

		return $m[1] . '-' . $m[2] . '-' . $m[3];
	}

	/**
	 * Flatten rich-text detail HTML to plain text with paragraph breaks.
	 *
	 * @param string $html Detail HTML.
	 * @return string Plain text.
	 */
	private function htmlToText( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		$text = preg_replace( '#<br\s*/?>#i', "\n", $html );
		$text = preg_replace( '#</(p|div|li|h[1-6])>#i', "\n", (string) $text );
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\xC2\xA0", ' ', $text );
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/\s*\n\s*/', "\n", (string) $text );

		return sanitize_textarea_field( trim( (string) $text ) );
	}

	/**
	 * First absolute http(s) image in rich text (skips file:// leftovers).
	 *
	 * @param string $html Rich text.
	 * @return string Image URL, or ''.
	 */
	private function findImageUrl( string $html ): string {
		if ( preg_match( '#<img[^>]+src=["\'](https?://[^"\']+)["\']#i', $html, $m ) ) {
			return esc_url_raw( $m[1] );
		}

		return '';
	}

	// ────────────────────────────────────────────────────────────────────────────
	// URL helpers
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Scheme + host of a URL.
	 *
	 * @param string $url URL.
	 * @return string Origin like "https://org.my.salesforce-sites.com", or ''.
	 */
	private function originFromUrl( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = ! empty( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';

		return $scheme . '://' . strtolower( $parts['host'] );
	}

	/**
	 * Read the venueId filter from the source URL query string.
	 *
	 * @param string $url Source URL.
	 * @return string[] Venue ids.
	 */
	private function venueIdsFromUrl( string $url ): array {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( '' === $query ) {
			return array();
		}

		parse_str( $query, $params );

		return $this->normalizeVenueIds( $params[ self::VENUE_QUERY_PARAM ] ?? array() );
	}

	/**
	 * Normalize a venue id list from a string (comma-separated) or array.
	 *
	 * @param mixed $ids Raw ids.
	 * @return string[] Clean ids.
	 */
	private function normalizeVenueIds( $ids ): array {
		if ( is_string( $ids ) ) {
			$ids = explode( ',', $ids );
		}
		if ( ! is_array( $ids ) ) {
			return array();
		}

		$clean = array();
		foreach ( $ids as $id ) {
			if ( ! is_scalar( $id ) ) {
				continue;
			}
			$id = preg_replace( '/[^A-Za-z0-9]/', '', (string) $id );
			if ( '' !== $id ) {
				$clean[] = $id;
			}
		}

		return array_values( array_unique( $clean ) );
	}
}
