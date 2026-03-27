<?php
/**
 * Bing Webmaster Tools Abilities
 *
 * Primitive ability for Bing Webmaster API analytics.
 * All Bing Webmaster data — tools, CLI, REST, chat — flows through this ability.
 *
 * @package DataMachine\Abilities\Analytics
 * @since 0.23.0
 */

namespace DataMachine\Abilities\Analytics;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachine\Abilities\Analytics\Traits\HasGetConfig;
use DataMachine\Abilities\Media\ImageGenerationAbilities;

defined( 'ABSPATH' ) || exit;

class BingWebmasterAbilities {

	/**
	 * Option key for storing Bing Webmaster configuration.
	 *
	 * @var string
	 */
	const CONFIG_OPTION = 'datamachine_bing_webmaster_config';

	/**
	 * API endpoint mapping for supported actions.
	 *
	 * @var array
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
	 * Captures the millisecond Unix timestamp from Bing's WCF date format.
	 * Example: "/Date(1316156400000-0700)/" → 1316156400000
	 *
	 * @var string
	 */
	const DATE_REGEX = '/^\/Date\((\d+)([+-]\d{4})?\)\/$/';


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
				'datamachine/bing-webmaster',
				array(
					'label'               => 'Bing Webmaster Tools',
					'description'         => 'Fetch search analytics data from Bing Webmaster Tools API',
					'category'            => 'datamachine',
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
	 * Fetch stats from Bing Webmaster Tools API.
	 *
	 * Parses Bing's /Date(timestamp)/ format into ISO 8601 dates, adds
	 * date range metadata, and supports client-side day filtering since
	 * the Bing API does not accept date parameters.
	 *
	 * @param array $input Ability input.
	 * @return array Ability response.
	 */
	public static function fetchStats( array $input ): array {
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

		// Parse Bing /Date(timestamp)/ format into ISO 8601 and compute date range.
		$parsed_dates = array();
		foreach ( $results as &$row ) {
			if ( isset( $row['Date'] ) ) {
				$parsed = self::parseBingDate( $row['Date'] );
				if ( $parsed ) {
					$row['Date']    = $parsed['iso'];
					$parsed_dates[] = $parsed['timestamp'];
				}
			}
		}
		unset( $row );

		// Client-side date filtering (Bing API does not accept date params).
		if ( $days > 0 && ! empty( $parsed_dates ) ) {
			$cutoff  = time() - ( $days * DAY_IN_SECONDS );
			$results = array_values( array_filter( $results, function ( $row ) use ( $cutoff ) {
				if ( empty( $row['Date'] ) ) {
					return true;
				}
				$ts = strtotime( $row['Date'] );
				return false !== $ts && $ts >= $cutoff;
			} ) );
		}

		// Apply limit after date filtering.
		if ( count( $results ) > $limit ) {
			$results = array_slice( $results, 0, $limit );
		}

		// Build date range metadata — uses start_date/end_date to match
		// the key names expected by AnalyticsCommand::execute_ability().
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
	 * @return array|null Array with 'timestamp' (Unix) and 'iso' (Y-m-d) keys, or null.
	 */
	public static function parseBingDate( string $date_string ): ?array {
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

	/**
	 * Check if Bing Webmaster Tools is configured.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		$config = self::get_config();
		return ! empty( $config['api_key'] );
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
