<?php
/**
 * Google Analytics (GA4) Abilities
 *
 * Primitive ability for Google Analytics Data API (GA4).
 * All GA4 data — tools, CLI, REST, chat — flows through this ability.
 *
 * Uses the GA4 Data API v1beta to fetch visitor analytics:
 * page performance, traffic sources, daily trends, real-time data,
 * top events, and user demographics.
 *
 * Authentication uses the same service account JWT flow as Google Search Console.
 *
 * @package DataMachine\Abilities\Analytics
 * @since 0.31.0
 */

namespace DataMachine\Abilities\Analytics;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;

defined( 'ABSPATH' ) || exit;

class GoogleAnalyticsAbilities {

	/**
	 * Option key for storing GA configuration.
	 *
	 * @var string
	 */
	const CONFIG_OPTION = 'datamachine_ga_config';

	/**
	 * Transient key for cached access token.
	 *
	 * @var string
	 */
	const TOKEN_TRANSIENT = 'datamachine_ga_access_token';

	/**
	 * GA4 Data API base URL.
	 *
	 * @var string
	 */
	const API_BASE = 'https://analyticsdata.googleapis.com/v1beta/properties/';

	/**
	 * Default result limit.
	 *
	 * @var int
	 */
	const DEFAULT_LIMIT = 25;

	/**
	 * Maximum result limit.
	 *
	 * @var int
	 */
	const MAX_LIMIT = 10000;

	/**
	 * Action-to-report configuration mapping.
	 *
	 * Each action defines the dimensions and metrics for its GA4 report request.
	 *
	 * @var array
	 */
	const ACTION_REPORTS = array(
		'page_stats'        => array(
			'dimensions' => array( 'pagePath', 'pageTitle' ),
			'metrics'    => array( 'screenPageViews', 'sessions', 'bounceRate', 'averageSessionDuration', 'activeUsers' ),
		),
		'traffic_sources'   => array(
			'dimensions' => array( 'sessionSource', 'sessionMedium' ),
			'metrics'    => array( 'sessions', 'activeUsers', 'screenPageViews', 'bounceRate' ),
		),
		'date_stats'        => array(
			'dimensions' => array( 'date' ),
			'metrics'    => array( 'sessions', 'screenPageViews', 'activeUsers', 'bounceRate', 'averageSessionDuration' ),
		),
		'top_events'        => array(
			'dimensions' => array( 'eventName' ),
			'metrics'    => array( 'eventCount', 'eventCountPerUser' ),
		),
		'user_demographics' => array(
			'dimensions' => array( 'country', 'deviceCategory' ),
			'metrics'    => array( 'sessions', 'activeUsers', 'screenPageViews' ),
		),
	);

	private static bool $registered = false;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/google-analytics',
				array(
					'label'               => 'Google Analytics',
					'description'         => 'Fetch visitor analytics data from Google Analytics (GA4) Data API',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'action' ),
						'properties' => array(
							'action'      => array(
								'type'        => 'string',
								'description' => 'Action to perform: page_stats, traffic_sources, date_stats, realtime, top_events, user_demographics.',
							),
							'property_id' => array(
								'type'        => 'string',
								'description' => 'GA4 property ID (numeric). Defaults to configured property ID.',
							),
							'start_date'  => array(
								'type'        => 'string',
								'description' => 'Start date in YYYY-MM-DD format (defaults to 28 days ago). Not used for realtime.',
							),
							'end_date'    => array(
								'type'        => 'string',
								'description' => 'End date in YYYY-MM-DD format (defaults to yesterday). Not used for realtime.',
							),
							'limit'       => array(
								'type'        => 'integer',
								'description' => 'Row limit (default: 25, max: 10000).',
							),
							'page_filter' => array(
								'type'        => 'string',
								'description' => 'Filter results to pages with paths containing this string.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'action'        => array( 'type' => 'string' ),
							'results_count' => array( 'type' => 'integer' ),
							'results'       => array( 'type' => 'array' ),
							'error'         => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'fetchStats' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Fetch stats from Google Analytics Data API.
	 *
	 * @param array $input Ability input.
	 * @return array Ability response.
	 */
	public static function fetchStats( array $input ): array {
		$action = sanitize_text_field( $input['action'] ?? '' );

		$valid_actions = array_merge( array_keys( self::ACTION_REPORTS ), array( 'realtime' ) );
		if ( empty( $action ) || ! in_array( $action, $valid_actions, true ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid action. Must be one of: ' . implode( ', ', $valid_actions ),
			);
		}

		$config = self::get_config();

		if ( empty( $config['service_account_json'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Google Analytics not configured. Add service account JSON in Settings.',
			);
		}

		$service_account = json_decode( $config['service_account_json'], true );

		if ( json_last_error() !== JSON_ERROR_NONE || empty( $service_account['client_email'] ) || empty( $service_account['private_key'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid service account JSON. Ensure it contains client_email and private_key.',
			);
		}

		$access_token = self::get_access_token( $service_account );

		if ( is_wp_error( $access_token ) ) {
			return array(
				'success' => false,
				'error'   => 'Failed to authenticate: ' . $access_token->get_error_message(),
			);
		}

		$property_id = ! empty( $input['property_id'] ) ? sanitize_text_field( $input['property_id'] ) : ( $config['property_id'] ?? '' );

		if ( empty( $property_id ) ) {
			return array(
				'success' => false,
				'error'   => 'No GA4 property ID configured or provided.',
			);
		}

		// Route to realtime handler.
		if ( 'realtime' === $action ) {
			return self::fetchRealtime( $access_token, $property_id );
		}

		return self::fetchReport( $input, $action, $access_token, $property_id );
	}

	/**
	 * Fetch a standard GA4 report.
	 *
	 * @param array  $input        Ability input.
	 * @param string $action       Report action.
	 * @param string $access_token OAuth2 access token.
	 * @param string $property_id  GA4 property ID.
	 * @return array
	 */
	private static function fetchReport( array $input, string $action, string $access_token, string $property_id ): array {
		$report_config = self::ACTION_REPORTS[ $action ];

		$start_date = ! empty( $input['start_date'] ) ? sanitize_text_field( $input['start_date'] ) : gmdate( 'Y-m-d', strtotime( '-28 days' ) );
		$end_date   = ! empty( $input['end_date'] ) ? sanitize_text_field( $input['end_date'] ) : gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		$limit      = ! empty( $input['limit'] ) ? min( (int) $input['limit'], self::MAX_LIMIT ) : self::DEFAULT_LIMIT;

		$dimensions = array_map(
			function ( $dim ) {
				return array( 'name' => $dim );
			},
			$report_config['dimensions']
		);

		$metrics = array_map(
			function ( $met ) {
				return array( 'name' => $met );
			},
			$report_config['metrics']
		);

		$request_body = array(
			'dateRanges' => array(
				array(
					'startDate' => $start_date,
					'endDate'   => $end_date,
				),
			),
			'dimensions' => $dimensions,
			'metrics'    => $metrics,
			'limit'      => $limit,
		);

		// Build dimension filter if page_filter provided and action includes pagePath.
		if ( ! empty( $input['page_filter'] ) && in_array( 'pagePath', $report_config['dimensions'], true ) ) {
			$request_body['dimensionFilter'] = array(
				'filter' => array(
					'fieldName'    => 'pagePath',
					'stringFilter' => array(
						'matchType' => 'CONTAINS',
						'value'     => sanitize_text_field( $input['page_filter'] ),
					),
				),
			);
		}

		$api_url = self::API_BASE . $property_id . ':runReport';

		$result = HttpClient::post(
			$api_url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $request_body ),
				'context' => 'Google Analytics Data API',
			)
		);

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'error'   => 'Failed to connect to Google Analytics API: ' . ( $result['error'] ?? 'Unknown error' ),
			);
		}

		$data = json_decode( $result['data'], true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'success' => false,
				'error'   => 'Failed to parse Google Analytics API response.',
			);
		}

		if ( ! empty( $data['error'] ) ) {
			$error_message = $data['error']['message'] ?? 'Unknown API error';
			return array(
				'success' => false,
				'error'   => 'GA4 API error: ' . $error_message,
			);
		}

		$rows = self::formatReportRows( $data, $report_config );

		return array(
			'success'       => true,
			'action'        => $action,
			'date_range'    => array(
				'start_date' => $start_date,
				'end_date'   => $end_date,
			),
			'results_count' => count( $rows ),
			'results'       => $rows,
		);
	}

	/**
	 * Fetch real-time analytics data.
	 *
	 * @param string $access_token OAuth2 access token.
	 * @param string $property_id  GA4 property ID.
	 * @return array
	 */
	private static function fetchRealtime( string $access_token, string $property_id ): array {
		$request_body = array(
			'dimensions' => array(
				array( 'name' => 'unifiedScreenName' ),
			),
			'metrics'    => array(
				array( 'name' => 'activeUsers' ),
				array( 'name' => 'screenPageViews' ),
			),
			'limit'      => 25,
		);

		$api_url = self::API_BASE . $property_id . ':runRealtimeReport';

		$result = HttpClient::post(
			$api_url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $request_body ),
				'context' => 'Google Analytics Realtime API',
			)
		);

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'error'   => 'Failed to connect to Google Analytics Realtime API: ' . ( $result['error'] ?? 'Unknown error' ),
			);
		}

		$data = json_decode( $result['data'], true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'success' => false,
				'error'   => 'Failed to parse Google Analytics Realtime response.',
			);
		}

		if ( ! empty( $data['error'] ) ) {
			return array(
				'success' => false,
				'error'   => 'GA4 Realtime API error: ' . ( $data['error']['message'] ?? 'Unknown' ),
			);
		}

		$dimension_headers = wp_list_pluck( $data['dimensionHeaders'] ?? array(), 'name' );
		$metric_headers    = wp_list_pluck( $data['metricHeaders'] ?? array(), 'name' );

		$total_active_users = 0;
		$total_page_views   = 0;
		$pages              = array();

		foreach ( ( $data['rows'] ?? array() ) as $row ) {
			$dim_values    = wp_list_pluck( $row['dimensionValues'] ?? array(), 'value' );
			$metric_values = wp_list_pluck( $row['metricValues'] ?? array(), 'value' );

			$page_data = array();
			foreach ( $dimension_headers as $i => $name ) {
				$page_data[ $name ] = $dim_values[ $i ] ?? '';
			}
			foreach ( $metric_headers as $i => $name ) {
				$page_data[ $name ] = (int) ( $metric_values[ $i ] ?? 0 );
			}

			$total_active_users += $page_data['activeUsers'] ?? 0;
			$total_page_views   += $page_data['screenPageViews'] ?? 0;

			$pages[] = $page_data;
		}

		return array(
			'success'            => true,
			'action'             => 'realtime',
			'total_active_users' => $total_active_users,
			'total_page_views'   => $total_page_views,
			'results_count'      => count( $pages ),
			'results'            => $pages,
		);
	}

	/**
	 * Format GA4 report rows into a flat, readable structure.
	 *
	 * @param array $data          Raw GA4 API response.
	 * @param array $report_config Report configuration with dimension/metric names.
	 * @return array Formatted rows.
	 */
	private static function formatReportRows( array $data, array $report_config ): array {
		$dimension_headers = wp_list_pluck( $data['dimensionHeaders'] ?? array(), 'name' );
		$metric_headers    = wp_list_pluck( $data['metricHeaders'] ?? array(), 'name' );

		$rows = array();

		foreach ( ( $data['rows'] ?? array() ) as $row ) {
			$dim_values    = wp_list_pluck( $row['dimensionValues'] ?? array(), 'value' );
			$metric_values = wp_list_pluck( $row['metricValues'] ?? array(), 'value' );

			$formatted = array();
			foreach ( $dimension_headers as $i => $name ) {
				$formatted[ $name ] = $dim_values[ $i ] ?? '';
			}
			foreach ( $metric_headers as $i => $name ) {
				$value = $metric_values[ $i ] ?? '0';
				// Cast numeric strings to appropriate types.
				if ( is_numeric( $value ) ) {
					$formatted[ $name ] = strpos( $value, '.' ) !== false ? (float) $value : (int) $value;
				} else {
					$formatted[ $name ] = $value;
				}
			}

			$rows[] = $formatted;
		}

		return $rows;
	}

	/**
	 * Get an OAuth2 access token using service account JWT flow.
	 *
	 * @param array $service_account Parsed service account JSON.
	 * @return string|\WP_Error Access token or error.
	 */
	private static function get_access_token( array $service_account ) {
		$cached = get_transient( self::TOKEN_TRANSIENT );

		if ( ! empty( $cached ) ) {
			return $cached;
		}

		$header = self::base64url_encode( wp_json_encode( array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		) ) );
		$now    = time();
		$claims = self::base64url_encode( wp_json_encode( array(
			'iss'   => $service_account['client_email'],
			'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
			'aud'   => 'https://oauth2.googleapis.com/token',
			'iat'   => $now,
			'exp'   => $now + 3600,
		) ) );

		$unsigned = $header . '.' . $claims;

		$sign_result = openssl_sign( $unsigned, $signature, $service_account['private_key'], 'SHA256' );

		if ( ! $sign_result ) {
			return new \WP_Error( 'ga_jwt_sign_failed', 'Failed to sign JWT. Check private key in service account JSON.' );
		}

		$jwt = $unsigned . '.' . self::base64url_encode( $signature );

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) ) {
			$error_desc = $body['error_description'] ?? ( $body['error'] ?? 'Unknown token error' );
			return new \WP_Error( 'ga_token_failed', 'Failed to get access token: ' . $error_desc );
		}

		set_transient( self::TOKEN_TRANSIENT, $body['access_token'], 3500 );

		return $body['access_token'];
	}

	/**
	 * Base64url encode (RFC 7515).
	 *
	 * @param string $data Data to encode.
	 * @return string Base64url encoded string.
	 */
	private static function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Check if Google Analytics is configured.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		$config = self::get_config();
		return ! empty( $config['service_account_json'] ) && ! empty( $config['property_id'] );
	}

	/**
	 * Get stored configuration.
	 *
	 * @return array
	 */
	public static function get_config(): array {
		return get_site_option( self::CONFIG_OPTION, array() );
	}
}
