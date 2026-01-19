<?php
/**
 * Venue Health Check Tool
 *
 * Scans venues for data quality issues: missing address, coordinates, timezone,
 * or website. Also detects suspicious websites where a ticket URL was mistakenly
 * stored as the venue website.
 *
 * @package DataMachineEvents\Api\Chat\Tools
 */

namespace DataMachineEvents\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\ToolRegistrationTrait;
use DataMachineEvents\Core\Venue_Taxonomy;

class VenueHealthCheck {
	use ToolRegistrationTrait;

	private const DEFAULT_LIMIT = 25;

	private const TICKET_PLATFORM_DOMAINS = array(
		'eventbrite.com',
		'ticketmaster.com',
		'axs.com',
		'dice.fm',
		'seetickets.com',
		'bandsintown.com',
		'songkick.com',
		'livenation.com',
		'ticketweb.com',
		'etix.com',
		'ticketfly.com',
		'showclix.com',
		'prekindle.com',
		'freshtix.com',
		'tixr.com',
		'seated.com',
		'stubhub.com',
		'vividseats.com',
	);

	private const SUSPICIOUS_PATH_PATTERNS = array(
		'/event/',
		'/events/',
		'/e/',
		'/tickets/',
		'/shows/',
		'/tour/',
	);

	public function __construct() {
		$this->registerTool( 'chat', 'venue_health_check', array( $this, 'getToolDefinition' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Check venues for data quality issues: missing address, coordinates, timezone, or website. Also detects suspicious websites where a ticket URL was mistakenly stored as venue website. Returns counts and lists of problematic venues.',
			'parameters'  => array(
				'limit' => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Max venues to return per issue category (default: 25)',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$limit = (int) ( $parameters['limit'] ?? self::DEFAULT_LIMIT );
		if ( $limit <= 0 ) {
			$limit = self::DEFAULT_LIMIT;
		}

		$venues = get_terms(
			array(
				'taxonomy'   => 'venue',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $venues ) ) {
			return array(
				'success'   => false,
				'error'     => 'Failed to query venues: ' . $venues->get_error_message(),
				'tool_name' => 'venue_health_check',
			);
		}

		if ( empty( $venues ) ) {
			return array(
				'success'   => true,
				'data'      => array(
					'total_venues' => 0,
					'message'      => 'No venues found in the system.',
				),
				'tool_name' => 'venue_health_check',
			);
		}

		$missing_address     = array();
		$missing_coordinates = array();
		$missing_timezone    = array();
		$missing_website     = array();
		$suspicious_website  = array();

		foreach ( $venues as $venue ) {
			$address     = get_term_meta( $venue->term_id, '_venue_address', true );
			$city        = get_term_meta( $venue->term_id, '_venue_city', true );
			$coordinates = get_term_meta( $venue->term_id, '_venue_coordinates', true );
			$timezone    = get_term_meta( $venue->term_id, '_venue_timezone', true );

			$venue_info = array(
				'term_id'     => $venue->term_id,
				'name'        => $venue->name,
				'event_count' => $venue->count,
			);

			if ( empty( $address ) && empty( $city ) ) {
				$missing_address[] = $venue_info;
			}

			if ( empty( $coordinates ) ) {
				$missing_coordinates[] = $venue_info;
			}

			if ( ! empty( $coordinates ) && empty( $timezone ) ) {
				$missing_timezone[] = $venue_info;
			}

			$website = get_term_meta( $venue->term_id, '_venue_website', true );

			if ( empty( $website ) ) {
				$missing_website[] = $venue_info;
			} else {
				$suspicion = self::checkSuspiciousWebsite( $website );
				if ( $suspicion ) {
					$venue_info['website']          = $website;
					$venue_info['suspicion_reason'] = $suspicion;
					$suspicious_website[]           = $venue_info;
				}
			}
		}

		$total = count( $venues );

		// Sort each array by event_count descending (most events first)
		$sort_by_events = fn( $a, $b ) => $b['event_count'] <=> $a['event_count'];
		usort( $missing_address, $sort_by_events );
		usort( $missing_coordinates, $sort_by_events );
		usort( $missing_timezone, $sort_by_events );
		usort( $missing_website, $sort_by_events );
		usort( $suspicious_website, $sort_by_events );

		// Build message
		$message_parts = array();
		if ( ! empty( $missing_address ) ) {
			$message_parts[] = count( $missing_address ) . ' missing address';
		}
		if ( ! empty( $missing_coordinates ) ) {
			$message_parts[] = count( $missing_coordinates ) . ' missing coordinates';
		}
		if ( ! empty( $missing_timezone ) ) {
			$message_parts[] = count( $missing_timezone ) . ' missing timezone';
		}
		if ( ! empty( $missing_website ) ) {
			$message_parts[] = count( $missing_website ) . ' missing website';
		}
		if ( ! empty( $suspicious_website ) ) {
			$message_parts[] = count( $suspicious_website ) . ' suspicious website (possible ticket URL)';
		}

		if ( empty( $message_parts ) ) {
			$message = "All {$total} venues have complete data.";
		} else {
			$message = 'Found issues: ' . implode( ', ', $message_parts ) . '. Use update_venue tool to fix.';
		}

		return array(
			'success'   => true,
			'data'      => array(
				'total_venues'        => $total,
				'missing_address'     => array(
					'count'  => count( $missing_address ),
					'venues' => array_slice( $missing_address, 0, $limit ),
				),
				'missing_coordinates' => array(
					'count'  => count( $missing_coordinates ),
					'venues' => array_slice( $missing_coordinates, 0, $limit ),
				),
				'missing_timezone'    => array(
					'count'  => count( $missing_timezone ),
					'venues' => array_slice( $missing_timezone, 0, $limit ),
				),
				'missing_website'     => array(
					'count'  => count( $missing_website ),
					'venues' => array_slice( $missing_website, 0, $limit ),
				),
				'suspicious_website'  => array(
					'count'  => count( $suspicious_website ),
					'venues' => array_slice( $suspicious_website, 0, $limit ),
				),
				'message'             => $message,
			),
			'tool_name' => 'venue_health_check',
		);
	}

	/**
	 * Check if a URL looks like a ticket/event URL rather than a venue website.
	 *
	 * @param string $url Website URL to check
	 * @return string|null Suspicion reason, or null if URL looks legitimate
	 */
	private static function checkSuspiciousWebsite( string $url ): ?string {
		if ( empty( $url ) ) {
			return null;
		}

		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['host'] ) ) {
			return null;
		}

		$host = strtolower( $parsed['host'] );

		foreach ( self::TICKET_PLATFORM_DOMAINS as $domain ) {
			if ( str_contains( $host, $domain ) ) {
				return 'ticket_platform_domain';
			}
		}

		$path = strtolower( $parsed['path'] ?? '' );
		foreach ( self::SUSPICIOUS_PATH_PATTERNS as $pattern ) {
			if ( str_contains( $path, $pattern ) ) {
				return 'event_url_path';
			}
		}

		return null;
	}
}
