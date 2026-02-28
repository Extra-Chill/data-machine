<?php
/**
 * Ticketmaster Test Ability
 *
 * Tests Ticketmaster API handler with configurable parameters.
 * Shows raw API response data including priceRanges for debugging.
 *
 * @package DataMachineEvents\Abilities
 * @since 0.11.4
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster\Ticketmaster;
use DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster\TicketmasterAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TicketmasterTest {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$register_callback = function () {
				wp_register_ability(
					'data-machine-events/test-ticketmaster',
					array(
						'label'               => __( 'Test Ticketmaster', 'data-machine-events' ),
						'description'         => __( 'Test Ticketmaster API handler with raw response data', 'data-machine-events' ),
						'category'            => 'datamachine',
						'input_schema'        => array(
							'type'       => 'object',
							'required'   => array( 'classification_type' ),
							'properties' => array(
								'classification_type' => array(
									'type'        => 'string',
									'description' => 'Event classification (music, sports, arts-theatre, etc.)',
								),
								'location'            => array(
									'type'        => 'string',
									'description' => 'Coordinates as "lat,lng" (defaults to Charleston)',
								),
								'radius'              => array(
									'type'        => 'integer',
									'description' => 'Search radius in miles (default: 50)',
								),
								'venue_id'            => array(
									'type'        => 'string',
									'description' => 'Specific Ticketmaster venue ID',
								),
								'limit'               => array(
									'type'        => 'integer',
									'description' => 'Max events to show (default: 5)',
								),
							),
						),
						'output_schema'       => array(
							'type'       => 'object',
							'properties' => array(
								'success'         => array( 'type' => 'boolean' ),
								'status'          => array(
									'type' => 'string',
									'enum' => array( 'ok', 'warning', 'error' ),
								),
								'api_config'      => array( 'type' => 'object' ),
								'api_response'    => array( 'type' => 'object' ),
								'events'          => array( 'type' => 'array' ),
								'coverage_issues' => array( 'type' => 'array' ),
								'logs'            => array( 'type' => 'array' ),
							),
						),
						'execute_callback'    => array( $this, 'executeAbility' ),
						'permission_callback' => function () {
							return current_user_can( 'manage_options' );
						},
						'meta'                => array( 'show_in_rest' => true ),
					)
				);
			};

			if ( did_action( 'wp_abilities_api_init' ) ) {
				$register_callback();
			} else {
				add_action( 'wp_abilities_api_init', $register_callback );
			}

			self::$registered = true;
		}
	}

	public function executeAbility( array $input ): array {
		$classification_type = $input['classification_type'] ?? '';

		if ( empty( $classification_type ) ) {
			return $this->buildErrorResponse( 'Missing required classification_type parameter.' );
		}

		return $this->test(
			$classification_type,
			$input['location'] ?? '',
			$input['radius'] ?? 50,
			$input['venue_id'] ?? '',
			$input['limit'] ?? 5
		);
	}

	public function test(
		string $classification_type,
		string $location = '',
		int $radius = 50,
		string $venue_id = '',
		int $limit = 5
	): array {
		$logs = array();
		add_action(
			'datamachine_log',
			static function ( string $level, string $message, array $context = array() ) use ( &$logs ): void {
				$logs[] = array(
					'level'   => $level,
					'message' => $message,
					'context' => $context,
				);
			},
			10,
			3
		);

		$auth       = new TicketmasterAuth();
		$api_config = $auth->get_account();

		if ( empty( $api_config['api_key'] ) ) {
			return array(
				'success'         => false,
				'status'          => 'error',
				'api_config'      => array( 'api_key' => '***not configured***' ),
				'api_response'    => null,
				'events'          => array(),
				'coverage_issues' => array( 'API key not configured' ),
				'logs'            => $logs,
			);
		}

		$classifications = Ticketmaster::get_classifications( $api_config['api_key'] );

		if ( ! isset( $classifications[ $classification_type ] ) ) {
			$valid_types = array_keys( $classifications );
			return array(
				'success'         => false,
				'status'          => 'error',
				'api_config'      => array(
					'api_key'               => '***configured***',
					'classification_type'   => $classification_type,
					'valid_classifications' => $valid_types,
				),
				'api_response'    => null,
				'events'          => array(),
				'coverage_issues' => array( 'Invalid classification_type. Valid: ' . implode( ', ', $valid_types ) ),
				'logs'            => $logs,
			);
		}

		$segment_name = $classifications[ $classification_type ];
		$location     = ! empty( $location ) ? $location : '32.7765,-79.9311';

		$params = array(
			'apikey'        => $api_config['api_key'],
			'segmentName'   => $segment_name,
			'size'          => min( $limit, 50 ),
			'sort'          => 'date,asc',
			'page'          => 0,
			'startDateTime' => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( '+1 hour' ) ),
		);

		$coordinates = $this->parseCoordinates( $location );
		if ( $coordinates ) {
			$params['geoPoint'] = $coordinates['lat'] . ',' . $coordinates['lng'];
			$params['radius']   = $radius;
			$params['unit']     = 'miles';
		}

		if ( ! empty( $venue_id ) ) {
			$params['venueId'] = $venue_id;
		}

		$url = 'https://app.ticketmaster.com/discovery/v2/events.json?' . http_build_query( $params );

		$result = \DataMachine\Core\HttpClient::get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array( 'Accept' => 'application/json' ),
				'context' => 'Ticketmaster Test',
			)
		);

		$api_response = array(
			'status_code' => $result['status_code'] ?? 0,
			'success'     => $result['success'] ?? false,
		);

		if ( ! $result['success'] ) {
			return array(
				'success'         => false,
				'status'          => 'error',
				'api_config'      => array(
					'api_key'             => '***configured***',
					'classification_type' => $classification_type,
					'segment_name'        => $segment_name,
					'location'            => $location,
					'radius'              => $radius,
					'venue_id'            => $venue_id,
				),
				'api_response'    => $api_response,
				'events'          => array(),
				'coverage_issues' => array( 'API request failed: ' . ( $result['error'] ?? 'Unknown error' ) ),
				'logs'            => array_slice( $logs, -20 ),
			);
		}

		$data       = json_decode( $result['data'], true );
		$raw_events = $data['_embedded']['events'] ?? array();
		$page_info  = $data['page'] ?? array();

		$api_response['events_found'] = $page_info['totalElements'] ?? count( $raw_events );
		$api_response['total_pages']  = $page_info['totalPages'] ?? 1;

		$events          = array();
		$coverage_issues = array();

		foreach ( array_slice( $raw_events, 0, $limit ) as $index => $tm_event ) {
			$raw_data = array(
				'name'        => $tm_event['name'] ?? '',
				'status'      => $tm_event['dates']['status']['code'] ?? '',
				'priceRanges' => $tm_event['priceRanges'] ?? null,
			);

			$mapped = $this->mapEvent( $tm_event );

			$event_issues = array();
			if ( empty( $mapped['price'] ) ) {
				if ( empty( $tm_event['priceRanges'] ) ) {
					$event_issues[] = 'No priceRanges in API response';
				} else {
					$event_issues[] = 'priceRanges present but mapping failed';
				}
			}
			if ( empty( $mapped['venue'] ) ) {
				$event_issues[] = 'Missing venue';
			}
			if ( empty( $mapped['startTime'] ) ) {
				$event_issues[] = 'Missing start time';
			}

			$events[] = array(
				'raw'    => $raw_data,
				'mapped' => $mapped,
				'issues' => $event_issues,
			);

			if ( ! empty( $event_issues ) ) {
				$coverage_issues[] = sprintf(
					'Event %d (%s): %s',
					$index + 1,
					$mapped['title'],
					implode( ', ', $event_issues )
				);
			}
		}

		$status = empty( $coverage_issues ) ? 'ok' : 'warning';

		return array(
			'success'         => true,
			'status'          => $status,
			'api_config'      => array(
				'api_key'             => '***configured***',
				'classification_type' => $classification_type,
				'segment_name'        => $segment_name,
				'location'            => $location,
				'radius'              => $radius,
				'venue_id'            => $venue_id,
			),
			'api_response'    => $api_response,
			'events'          => $events,
			'coverage_issues' => $coverage_issues,
			'logs'            => array_slice( $logs, -20 ),
		);
	}

	private function mapEvent( array $tm_event ): array {
		$title = $tm_event['name'] ?? '';

		$start_date = $tm_event['dates']['start']['localDate'] ?? '';
		$start_time = $tm_event['dates']['start']['localTime'] ?? '';

		$venue_name     = '';
		$venue_timezone = '';
		if ( ! empty( $tm_event['_embedded']['venues'][0] ) ) {
			$venue          = $tm_event['_embedded']['venues'][0];
			$venue_name     = $venue['name'] ?? '';
			$venue_timezone = $venue['timezone'] ?? '';
		}

		$price = '';
		if ( ! empty( $tm_event['priceRanges'][0] ) ) {
			$price_range = $tm_event['priceRanges'][0];
			$min         = $price_range['min'] ?? null;
			$max         = $price_range['max'] ?? null;
			$currency    = $price_range['currency'] ?? 'USD';

			if ( null !== $min && null !== $max ) {
				if ( $min === $max ) {
					$price = sprintf( '$%.2f', $min );
				} else {
					$price = sprintf( '$%.2f - $%.2f', $min, $max );
				}
			} elseif ( null !== $min ) {
				$price = sprintf( 'From $%.2f', $min );
			} elseif ( null !== $max ) {
				$price = sprintf( 'Up to $%.2f', $max );
			}
		}

		$ticket_url = $tm_event['url'] ?? '';

		return array(
			'title'         => $title,
			'startDate'     => $start_date,
			'startTime'     => $start_time,
			'venue'         => $venue_name,
			'venueTimezone' => $venue_timezone,
			'price'         => $price,
			'ticketUrl'     => $ticket_url,
		);
	}

	private function parseCoordinates( string $location ): ?array {
		if ( empty( $location ) ) {
			return null;
		}

		$parts = explode( ',', $location );
		if ( count( $parts ) !== 2 ) {
			return null;
		}

		$lat = floatval( trim( $parts[0] ) );
		$lng = floatval( trim( $parts[1] ) );

		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
			return null;
		}

		return array(
			'lat' => $lat,
			'lng' => $lng,
		);
	}

	private function buildErrorResponse( string $message ): array {
		return array(
			'success'         => false,
			'status'          => 'error',
			'api_config'      => null,
			'api_response'    => null,
			'events'          => array(),
			'coverage_issues' => array( $message ),
			'logs'            => array(),
		);
	}
}
