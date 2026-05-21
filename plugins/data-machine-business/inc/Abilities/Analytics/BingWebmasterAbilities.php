<?php
/**
 * Bing Webmaster Tools ability.
 *
 * @package DataMachineBusiness\Abilities\Analytics
 */

namespace DataMachineBusiness\Abilities\Analytics;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;

defined( 'ABSPATH' ) || exit;

class BingWebmasterAbilities {

	/**
	 * Existing Data Machine core option key. Reusing it adopts stored config.
	 *
	 * @var string
	 */
	const CONFIG_OPTION = 'datamachine_bing_webmaster_config';

	/**
	 * API endpoint mapping for supported actions.
	 *
	 * @var array<string,string>
	 */
	const ACTION_ENDPOINTS = array(
		'query_stats'   => 'GetQueryStats',
		'traffic_stats' => 'GetRankAndTrafficStats',
		'page_stats'    => 'GetPageStats',
		'crawl_stats'   => 'GetCrawlStats',
	);

	/**
	 * Default result limit.
	 *
	 * @var int
	 */
	const DEFAULT_LIMIT = 20;

	/**
	 * Regex to parse Bing's /Date(timestamp)/ format.
	 *
	 * @var string
	 */
	const DATE_REGEX = '/^\/Date\((\d+)([+-]\d{4})?\)\/$/';

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->register_abilities();
		self::$registered = true;
	}

	private function register_abilities(): void {
		$register_callback = function (): void {
			wp_register_ability(
				'datamachine/bing-webmaster',
				array(
					'label'               => 'Bing Webmaster Tools',
					'description'         => 'Fetch search analytics data from Bing Webmaster Tools API',
					'category'            => 'datamachine-analytics',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'action' ),
						'properties' => array(
							'action'   => array(
								'type'        => 'string',
								'description' => 'Analytics action: query_stats, traffic_stats, page_stats, crawl_stats.',
							),
							'site_url' => array(
								'type'        => 'string',
								'description' => 'Site URL to query (defaults to configured site URL).',
							),
							'limit'    => array(
								'type'        => 'integer',
								'description' => 'Maximum number of results to return (default: 20).',
							),
							'days'     => array(
								'type'        => 'integer',
								'description' => 'Only return data from the last N days (client-side filter).',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'action'        => array( 'type' => 'string' ),
							'results_count' => array( 'type' => 'integer' ),
							'date_range'    => array(
								'type'       => 'object',
								'properties' => array(
									'start_date' => array( 'type' => 'string' ),
									'end_date'   => array( 'type' => 'string' ),
									'days_ago'   => array( 'type' => 'integer' ),
									'span_days'  => array( 'type' => 'integer' ),
								),
							),
							'results'       => array( 'type' => 'array' ),
							'error'         => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'fetch_stats' ),
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
	 * Fetch stats from Bing Webmaster Tools API.
	 *
	 * @param array $input Ability input.
	 * @return array<string,mixed> Ability response.
	 */
	public static function fetch_stats( array $input ): array {
		$action = sanitize_text_field( $input['action'] ?? '' );

		if ( empty( $action ) || ! isset( self::ACTION_ENDPOINTS[ $action ] ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid action. Must be one of: ' . implode( ', ', array_keys( self::ACTION_ENDPOINTS ) ),
			);
		}

		$config = self::get_config();

		if ( empty( $config['api_key'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Bing Webmaster Tools not configured. Add an API key in Settings.',
			);
		}

		$site_url = ! empty( $input['site_url'] ) ? sanitize_text_field( $input['site_url'] ) : ( $config['site_url'] ?? get_site_url() );
		$limit    = ! empty( $input['limit'] ) ? (int) $input['limit'] : self::DEFAULT_LIMIT;
		$days     = ! empty( $input['days'] ) ? (int) $input['days'] : 0;
		$endpoint = self::ACTION_ENDPOINTS[ $action ];

		$request_url = add_query_arg(
			array(
				'apikey'  => $config['api_key'],
				'siteUrl' => $site_url,
			),
			'https://ssl.bing.com/webmaster/api.svc/json/' . $endpoint
		);

		$result = HttpClient::get(
			$request_url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept' => 'application/json',
				),
				'context' => 'Bing Webmaster Tools Ability',
			)
		);

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'error'   => 'Failed to connect to Bing Webmaster API: ' . ( $result['error'] ?? 'Unknown error' ),
			);
		}

		$data = json_decode( $result['data'], true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'success' => false,
				'error'   => 'Failed to parse Bing Webmaster API response.',
			);
		}

		$results = $data['d'] ?? array();

		if ( ! is_array( $results ) ) {
			$results = array();
		}

		$parsed_dates = array();
		foreach ( $results as &$row ) {
			if ( isset( $row['Date'] ) ) {
				$parsed = self::parse_bing_date( $row['Date'] );
				if ( $parsed ) {
					$row['Date']    = $parsed['iso'];
					$parsed_dates[] = $parsed['timestamp'];
				}
			}
		}
		unset( $row );

		if ( $days > 0 && ! empty( $parsed_dates ) ) {
			$cutoff  = time() - ( $days * DAY_IN_SECONDS );
			$results = array_values( array_filter( $results, function ( $row ) use ( $cutoff ) {
				if ( empty( $row['Date'] ) ) {
					return true;
				}
				$timestamp = strtotime( $row['Date'] );
				return false !== $timestamp && $timestamp >= $cutoff;
			} ) );
		}

		if ( count( $results ) > $limit ) {
			$results = array_slice( $results, 0, $limit );
		}

		$date_range = array();
		if ( ! empty( $parsed_dates ) ) {
			$min_ts     = min( $parsed_dates );
			$max_ts     = max( $parsed_dates );
			$date_range = array(
				'start_date' => gmdate( 'Y-m-d', $min_ts ),
				'end_date'   => gmdate( 'Y-m-d', $max_ts ),
				'days_ago'   => (int) floor( ( time() - $max_ts ) / DAY_IN_SECONDS ),
				'span_days'  => (int) floor( ( $max_ts - $min_ts ) / DAY_IN_SECONDS ),
			);
		}

		return array(
			'success'       => true,
			'action'        => $action,
			'results_count' => count( $results ),
			'date_range'    => $date_range,
			'results'       => $results,
		);
	}

	/**
	 * Parse Bing's WCF /Date(timestamp)/ format.
	 *
	 * @param string $date_string Bing date string like "/Date(1316156400000-0700)/".
	 * @return array{timestamp:int,iso:string}|null Parsed date, or null.
	 */
	public static function parse_bing_date( string $date_string ): ?array {
		if ( ! preg_match( self::DATE_REGEX, $date_string, $matches ) ) {
			return null;
		}

		$ms        = (int) $matches[1];
		$timestamp = (int) floor( $ms / 1000 );

		return array(
			'timestamp' => $timestamp,
			'iso'       => gmdate( 'Y-m-d', $timestamp ),
		);
	}

	public static function is_configured(): bool {
		$config = self::get_config();
		return ! empty( $config['api_key'] );
	}

	/**
	 * Get stored configuration.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_config(): array {
		return get_site_option( self::CONFIG_OPTION, array() );
	}
}
