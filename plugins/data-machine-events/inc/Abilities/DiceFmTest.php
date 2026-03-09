<?php
/**
 * Dice FM Test Ability
 *
 * Tests Dice FM API handler with configurable parameters.
 * Shows raw API response data for debugging.
 *
 * @package DataMachineEvents\Abilities
 * @since 0.11.4
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Steps\EventImport\Handlers\DiceFm\DiceFmAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DiceFmTest {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$register_callback = function () {
				wp_register_ability(
					'data-machine-events/test-dice-fm',
					array(
						'label'               => __( 'Test Dice FM', 'data-machine-events' ),
						'description'         => __( 'Test Dice FM API handler with raw response data', 'data-machine-events' ),
						'category'            => 'datamachine',
						'input_schema'        => array(
							'type'       => 'object',
							'required'   => array( 'city' ),
							'properties' => array(
								'city'  => array(
									'type'        => 'string',
									'description' => 'City name to search for events',
								),
								'limit' => array(
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
		$city = $input['city'] ?? '';

		if ( empty( $city ) ) {
			return $this->buildErrorResponse( 'Missing required city parameter.' );
		}

		return $this->test(
			$city,
			$input['limit'] ?? 5
		);
	}

	public function test( string $city, int $limit = 5 ): array {
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

		$auth       = new DiceFmAuth();
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

		$base_url = 'https://partners-endpoint.dice.fm/api/v2/events';

		$params = array(
			'page[size]'       => min( $limit, 100 ),
			'types'            => 'linkout,event',
			'filter[cities][]' => $city,
		);

		$url = add_query_arg( $params, $base_url );

		$headers = array(
			'Accept'    => 'application/json',
			'x-api-key' => $api_config['api_key'],
		);

		if ( ! empty( $api_config['partner_id'] ) ) {
			$headers['X-Partner-Id'] = trim( $api_config['partner_id'] );
		}

		$result = \DataMachine\Core\HttpClient::get(
			$url,
			array(
				'timeout' => 30,
				'headers' => $headers,
				'context' => 'Dice FM Test',
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
					'api_key'    => '***configured***',
					'partner_id' => ! empty( $api_config['partner_id'] ) ? '***configured***' : '(not set)',
					'city'       => $city,
				),
				'api_response'    => $api_response,
				'events'          => array(),
				'coverage_issues' => array( 'API request failed: ' . ( $result['error'] ?? 'Unknown error' ) ),
				'logs'            => array_slice( $logs, -20 ),
			);
		}

		$data = json_decode( $result['data'], true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'success'         => false,
				'status'          => 'error',
				'api_config'      => array(
					'api_key'    => '***configured***',
					'partner_id' => ! empty( $api_config['partner_id'] ) ? '***configured***' : '(not set)',
					'city'       => $city,
				),
				'api_response'    => $api_response,
				'events'          => array(),
				'coverage_issues' => array( 'Invalid JSON response from API' ),
				'logs'            => array_slice( $logs, -20 ),
			);
		}

		$raw_events                   = $data['data'] ?? array();
		$api_response['events_found'] = count( $raw_events );

		$events          = array();
		$coverage_issues = array();

		foreach ( array_slice( $raw_events, 0, $limit ) as $index => $dice_event ) {
			$raw_data = array(
				'name'     => $dice_event['name'] ?? '',
				'date'     => $dice_event['date'] ?? '',
				'date_end' => $dice_event['date_end'] ?? '',
				'venue'    => $dice_event['venue'] ?? '',
				'url'      => $dice_event['url'] ?? '',
				'price'    => $dice_event['price'] ?? null,
				'currency' => $dice_event['currency'] ?? '',
				'ticket_types' => $dice_event['ticket_types'] ?? array(),
				'timezone' => $dice_event['timezone'] ?? '',
				'location' => $dice_event['location'] ?? null,
			);

			$mapped = $this->mapEvent( $dice_event );

			$event_issues = array();
			if ( empty( $mapped['venue'] ) ) {
				$event_issues[] = 'Missing venue';
			}
			if ( empty( $mapped['startTime'] ) ) {
				$event_issues[] = 'Missing start time';
			}
			if ( empty( $mapped['venueAddress'] ) ) {
				$event_issues[] = 'Missing venue address';
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
				'api_key'    => '***configured***',
				'partner_id' => ! empty( $api_config['partner_id'] ) ? '***configured***' : '(not set)',
				'city'       => $city,
			),
			'api_response'    => $api_response,
			'events'          => $events,
			'coverage_issues' => $coverage_issues,
			'logs'            => array_slice( $logs, -20 ),
		);
	}

	private function mapEvent( array $dice_event ): array {
		$title    = $dice_event['name'] ?? '';
		$timezone = $dice_event['timezone'] ?? '';

		$start_parsed = $this->parseDateTimeUtc( $dice_event['date'] ?? '', $timezone );
		$end_parsed   = $this->parseDateTimeUtc( $dice_event['date_end'] ?? '', $timezone );

		$venue_name = '';
		if ( ! empty( $dice_event['venue'] ) ) {
			$venue_name = $dice_event['venue'];
		} elseif ( ! empty( $dice_event['venues'] ) && is_array( $dice_event['venues'] ) && ! empty( $dice_event['venues'][0]['name'] ) ) {
			$venue_name = $dice_event['venues'][0]['name'];
		}

		$venue_address     = '';
		$venue_city        = '';
		$venue_state       = '';
		$venue_coordinates = '';

		$location = $dice_event['location'] ?? array();
		if ( ! empty( $location ) ) {
			$venue_address = $location['street'] ?? '';
			$venue_city    = $location['city'] ?? '';
			$venue_state   = $location['state'] ?? '';

			if ( ! empty( $location['lat'] ) && ! empty( $location['lng'] ) ) {
				$venue_coordinates = $location['lat'] . ',' . $location['lng'];
			}
		}

		return array(
			'title'            => $title,
			'startDate'        => $start_parsed['date'],
			'startTime'        => $start_parsed['time'],
			'endDate'          => $end_parsed['date'],
			'endTime'          => $end_parsed['time'],
			'venue'            => $venue_name,
			'venueTimezone'    => $timezone,
			'venueAddress'     => $venue_address,
			'venueCity'        => $venue_city,
			'venueState'       => $venue_state,
			'venueCoordinates' => $venue_coordinates,
			'price'            => $this->mapPrice( $dice_event ),
			'ticketUrl'        => $dice_event['url'] ?? '',
		);
	}

	/**
	 * Map Dice price fields to display format.
	 *
	 * @param array $dice_event Raw Dice event.
	 * @return string
	 */
	private function mapPrice( array $dice_event ): string {
		$currency = strtoupper( trim( (string) ( $dice_event['currency'] ?? 'USD' ) ) );

		if ( isset( $dice_event['price'] ) && is_numeric( $dice_event['price'] ) && (float) $dice_event['price'] > 0 ) {
			$amount = (float) $dice_event['price'] / 100;
			return $this->formatCurrencyPrice( $amount, $amount, $currency );
		}

		$ticket_types = $dice_event['ticket_types'] ?? array();
		if ( ! is_array( $ticket_types ) || empty( $ticket_types ) ) {
			return '';
		}

		$face_values  = array();
		$total_values = array();

		foreach ( $ticket_types as $ticket_type ) {
			if ( ! is_array( $ticket_type ) || empty( $ticket_type['price'] ) || ! is_array( $ticket_type['price'] ) ) {
				continue;
			}

			$price_data = $ticket_type['price'];

			if ( isset( $price_data['face_value'] ) && is_numeric( $price_data['face_value'] ) ) {
				$face_values[] = (float) $price_data['face_value'] / 100;
			}

			if ( isset( $price_data['total'] ) && is_numeric( $price_data['total'] ) ) {
				$total_values[] = (float) $price_data['total'] / 100;
			}
		}

		if ( ! empty( $face_values ) ) {
			return $this->formatCurrencyPrice( min( $face_values ), max( $face_values ), $currency );
		}

		if ( ! empty( $total_values ) ) {
			return $this->formatCurrencyPrice( min( $total_values ), max( $total_values ), $currency );
		}

		return '';
	}

	/**
	 * Format mapped prices with currency indicator.
	 *
	 * @param float  $min Minimum.
	 * @param float  $max Maximum.
	 * @param string $currency Currency code.
	 * @return string
	 */
	private function formatCurrencyPrice( float $min, float $max, string $currency ): string {
		$formatted = \DataMachineEvents\Core\PriceFormatter::formatRange( $min, $max );

		if ( '' === $formatted ) {
			return '';
		}

		if ( 'USD' === $currency || '' === $currency ) {
			return $formatted;
		}

		return $currency . ' ' . $formatted;
	}

	private function parseDateTimeUtc( string $datetime_utc, string $timezone ): array {
		if ( empty( $datetime_utc ) ) {
			return array(
				'date' => '',
				'time' => '',
			);
		}

		try {
			$dt = new \DateTime( $datetime_utc, new \DateTimeZone( 'UTC' ) );

			if ( ! empty( $timezone ) ) {
				$dt->setTimezone( new \DateTimeZone( $timezone ) );
			}

			return array(
				'date' => $dt->format( 'Y-m-d' ),
				'time' => $dt->format( 'H:i' ),
			);
		} catch ( \Exception $e ) {
			return array(
				'date' => '',
				'time' => '',
			);
		}
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
