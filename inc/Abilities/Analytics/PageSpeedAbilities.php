<?php
/**
 * PageSpeed Insights Abilities
 *
 * Primitive ability for Google PageSpeed Insights API v5.
 * All PageSpeed data — tools, CLI, REST, chat — flows through this ability.
 *
 * Runs Lighthouse audits on any URL, returning performance scores,
 * Core Web Vitals metrics, and optimization opportunities.
 *
 * Authentication is optional — works without an API key but is rate-limited.
 *
 * @package DataMachine\Abilities\Analytics
 * @since 0.31.0
 */

namespace DataMachine\Abilities\Analytics;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachine\Abilities\Analytics\Traits\HasGetConfig;
use DataMachine\Engine\AI\Tools\Global\Traits\HasIsConfigured;

defined( 'ABSPATH' ) || exit;

class PageSpeedAbilities {
	use HasGetConfig;
	use HasIsConfigured;


	/**
	 * Option key for storing PageSpeed configuration.
	 *
	 * @var string
	 */
	const CONFIG_OPTION = 'datamachine_pagespeed_config';

	/**
	 * PageSpeed Insights API endpoint.
	 *
	 * @var string
	 */
	const API_ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

	/**
	 * Valid Lighthouse categories.
	 *
	 * @var array
	 */
	const CATEGORIES = array( 'performance', 'accessibility', 'best-practices', 'seo' );

	/**
	 * Valid strategies.
	 *
	 * @var array
	 */
	const STRATEGIES = array( 'mobile', 'desktop' );

	/**
	 * Core Web Vitals and key performance metric IDs.
	 *
	 * @var array
	 */
	const PERFORMANCE_METRICS = array(
		'FIRST_CONTENTFUL_PAINT'    => 'first_contentful_paint',
		'LARGEST_CONTENTFUL_PAINT'  => 'largest_contentful_paint',
		'TOTAL_BLOCKING_TIME'       => 'total_blocking_time',
		'CUMULATIVE_LAYOUT_SHIFT'   => 'cumulative_layout_shift',
		'SPEED_INDEX'               => 'speed_index',
		'INTERACTION_TO_NEXT_PAINT' => 'interaction_to_next_paint',
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
				'datamachine/pagespeed',
				array(
					'label'               => 'PageSpeed Insights',
					'description'         => 'Run Lighthouse audits via PageSpeed Insights API for performance, accessibility, SEO, and best practices scores',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'action' ),
						'properties' => array(
							'action'   => array(
								'type'        => 'string',
								'description' => 'Action to perform: analyze (full Lighthouse audit with all scores), performance (focused performance metrics and Core Web Vitals), opportunities (optimization suggestions with estimated savings).',
							),
							'url'      => array(
								'type'        => 'string',
								'description' => 'URL to analyze. Defaults to the WordPress site home URL.',
							),
							'strategy' => array(
								'type'        => 'string',
								'description' => 'Device strategy: mobile or desktop (default: mobile).',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'action'        => array( 'type' => 'string' ),
							'url'           => array( 'type' => 'string' ),
							'strategy'      => array( 'type' => 'string' ),
							'scores'        => array( 'type' => 'object' ),
							'metrics'       => array( 'type' => 'object' ),
							'opportunities' => array( 'type' => 'array' ),
							'error'         => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'runAudit' ),
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
	 * Run a PageSpeed Insights audit.
	 *
	 * @param array $input Ability input.
	 * @return array Ability response.
	 */
	public static function runAudit( array $input ): array {
		$action = sanitize_text_field( $input['action'] ?? '' );

		$valid_actions = array( 'analyze', 'performance', 'opportunities' );
		if ( empty( $action ) || ! in_array( $action, $valid_actions, true ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid action. Must be one of: ' . implode( ', ', $valid_actions ),
			);
		}

		$url      = ! empty( $input['url'] ) ? esc_url_raw( $input['url'] ) : home_url( '/' );
		$strategy = ! empty( $input['strategy'] ) ? sanitize_text_field( $input['strategy'] ) : 'mobile';

		if ( ! in_array( $strategy, self::STRATEGIES, true ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid strategy. Must be mobile or desktop.',
			);
		}

		$config = self::get_config();

		// Build API request URL.
		$query_args = array(
			'url'      => $url,
			'strategy' => $strategy,
		);

		// Add all categories for analyze/opportunities, only performance for performance action.
		if ( 'performance' === $action ) {
			$query_args['category'] = 'performance';
		} else {
			// Multiple categories need to be added as repeated params.
			// We'll build the URL manually for this.
			$categories = self::CATEGORIES;
		}

		if ( ! empty( $config['api_key'] ) ) {
			$query_args['key'] = $config['api_key'];
		}

		// Build URL with proper multi-value category support.
		$api_url = self::API_ENDPOINT . '?' . http_build_query( $query_args );

		if ( isset( $categories ) ) {
			foreach ( $categories as $cat ) {
				$api_url .= '&category=' . rawurlencode( $cat );
			}
		}

		$result = HttpClient::get(
			$api_url,
			array(
				'timeout' => 60, // PageSpeed can be slow.
				'context' => 'PageSpeed Insights API',
			)
		);

		if ( ! $result['success'] ) {
			$error_msg = $result['error'] ?? 'Unknown error';

			// Detect rate limiting and provide actionable guidance.
			$status_code = $result['status_code'] ?? 0;
			if ( 429 === $status_code || false !== strpos( $error_msg, '429' ) ) {
				$has_key   = ! empty( $config['api_key'] );
				$error_msg = $has_key
					? 'PageSpeed API rate limit exceeded even with an API key. Wait a few minutes and try again, or check your Google Cloud Console quota.'
					: 'PageSpeed API rate limit exceeded. Configure a Google API key in Data Machine settings (Tools → PageSpeed) for higher limits. Get a free key at https://console.cloud.google.com/apis/credentials (enable PageSpeed Insights API).';
			}

			return array(
				'success' => false,
				'error'   => $error_msg,
			);
		}

		$data = json_decode( $result['data'], true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'success' => false,
				'error'   => 'Failed to parse PageSpeed Insights API response.',
			);
		}

		if ( ! empty( $data['error'] ) ) {
			$error_message = $data['error']['message'] ?? 'Unknown API error';
			return array(
				'success' => false,
				'error'   => 'PageSpeed API error: ' . $error_message,
			);
		}

		$lighthouse = $data['lighthouseResult'] ?? array();

		if ( empty( $lighthouse ) ) {
			return array(
				'success' => false,
				'error'   => 'No Lighthouse results returned.',
			);
		}

		// Route to action-specific formatters.
		switch ( $action ) {
			case 'analyze':
				return self::formatAnalyzeResponse( $lighthouse, $url, $strategy );
			case 'performance':
				return self::formatPerformanceResponse( $lighthouse, $url, $strategy );
			case 'opportunities':
				return self::formatOpportunitiesResponse( $lighthouse, $url, $strategy );
			default:
				return array(
					'success' => false,
					'error'   => 'Invalid action.',
				);
		}
	}

	/**
	 * Format a full analyze response with all category scores and key metrics.
	 *
	 * @param array  $lighthouse Lighthouse result data.
	 * @param string $url        Analyzed URL.
	 * @param string $strategy   Device strategy.
	 * @return array
	 */
	private static function formatAnalyzeResponse( array $lighthouse, string $url, string $strategy ): array {
		$categories = $lighthouse['categories'] ?? array();
		$audits     = $lighthouse['audits'] ?? array();

		$scores = array();
		foreach ( $categories as $key => $cat ) {
			$scores[ $key ] = isset( $cat['score'] ) ? (int) round( $cat['score'] * 100 ) : null;
		}

		$metrics = self::extractPerformanceMetrics( $audits );

		return array(
			'success'  => true,
			'action'   => 'analyze',
			'url'      => $url,
			'strategy' => $strategy,
			'scores'   => $scores,
			'metrics'  => $metrics,
		);
	}

	/**
	 * Format a performance-focused response with detailed Core Web Vitals.
	 *
	 * @param array  $lighthouse Lighthouse result data.
	 * @param string $url        Analyzed URL.
	 * @param string $strategy   Device strategy.
	 * @return array
	 */
	private static function formatPerformanceResponse( array $lighthouse, string $url, string $strategy ): array {
		$categories = $lighthouse['categories'] ?? array();
		$audits     = $lighthouse['audits'] ?? array();

		$perf_score = isset( $categories['performance']['score'] )
			? (int) round( $categories['performance']['score'] * 100 )
			: null;

		$metrics = self::extractPerformanceMetrics( $audits );

		return array(
			'success'           => true,
			'action'            => 'performance',
			'url'               => $url,
			'strategy'          => $strategy,
			'performance_score' => $perf_score,
			'metrics'           => $metrics,
		);
	}

	/**
	 * Format an opportunities response with optimization suggestions.
	 *
	 * @param array  $lighthouse Lighthouse result data.
	 * @param string $url        Analyzed URL.
	 * @param string $strategy   Device strategy.
	 * @return array
	 */
	private static function formatOpportunitiesResponse( array $lighthouse, string $url, string $strategy ): array {
		$categories = $lighthouse['categories'] ?? array();
		$audits     = $lighthouse['audits'] ?? array();

		$scores = array();
		foreach ( $categories as $key => $cat ) {
			$scores[ $key ] = isset( $cat['score'] ) ? (int) round( $cat['score'] * 100 ) : null;
		}

		$opportunities = array();

		foreach ( $audits as $audit_id => $audit ) {
			// Only include audits that have savings (score < 1 and have numeric value).
			if ( ! isset( $audit['score'] ) || $audit['score'] >= 1 ) {
				continue;
			}

			if ( empty( $audit['details']['type'] ) || 'opportunity' !== $audit['details']['type'] ) {
				continue;
			}

			$opportunity = array(
				'id'          => $audit_id,
				'title'       => $audit['title'] ?? '',
				'description' => $audit['description'] ?? '',
				'score'       => isset( $audit['score'] ) ? (int) round( $audit['score'] * 100 ) : null,
			);

			if ( ! empty( $audit['details']['overallSavingsMs'] ) ) {
				$opportunity['savings_ms'] = (int) round( $audit['details']['overallSavingsMs'] );
			}

			if ( ! empty( $audit['details']['overallSavingsBytes'] ) ) {
				$opportunity['savings_bytes'] = (int) $audit['details']['overallSavingsBytes'];
			}

			$opportunities[] = $opportunity;
		}

		// Sort by savings (highest first).
		usort(
			$opportunities,
			function ( $a, $b ) {
				return ( $b['savings_ms'] ?? 0 ) - ( $a['savings_ms'] ?? 0 );
			}
		);

		return array(
			'success'       => true,
			'action'        => 'opportunities',
			'url'           => $url,
			'strategy'      => $strategy,
			'scores'        => $scores,
			'results_count' => count( $opportunities ),
			'results'       => $opportunities,
		);
	}

	/**
	 * Extract performance metrics from Lighthouse audits.
	 *
	 * @param array $audits Lighthouse audits data.
	 * @return array Formatted metrics.
	 */
	private static function extractPerformanceMetrics( array $audits ): array {
		$metrics = array();

		foreach ( self::PERFORMANCE_METRICS as $audit_key => $metric_key ) {
			$audit_id = strtolower( str_replace( '_', '-', $audit_key ) );
			$audit    = $audits[ $audit_id ] ?? null;

			if ( ! $audit ) {
				continue;
			}

			$metrics[ $metric_key ] = array(
				'value'   => $audit['displayValue'] ?? null,
				'numeric' => $audit['numericValue'] ?? null,
				'score'   => isset( $audit['score'] ) ? (int) round( $audit['score'] * 100 ) : null,
			);
		}

		return $metrics;
	}


	public static function fetchStats( array $input ): array {
		$action = sanitize_text_field( $input['action'] ?? '' );

		$valid_actions = array_merge(
			array_keys( self::ACTION_DIMENSIONS ),
			array( 'inspect_url', 'list_sitemaps', 'get_sitemap', 'submit_sitemap' )
		);
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
				'error'   => 'Google Search Console not configured. Add service account JSON in Settings.',
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

		$site_url = ! empty( $input['site_url'] ) ? sanitize_text_field( $input['site_url'] ) : ( $config['site_url'] ?? '' );

		// Route to specialized handlers for non-analytics actions.
		if ( 'inspect_url' === $action ) {
			return self::inspectUrl( $input, $access_token, $site_url );
		}
		if ( 'list_sitemaps' === $action ) {
			return self::listSitemaps( $access_token, $site_url );
		}
		if ( 'get_sitemap' === $action ) {
			return self::getSitemap( $input, $access_token, $site_url );
		}
		if ( 'submit_sitemap' === $action ) {
			return self::submitSitemap( $input, $access_token, $site_url );
		}

		$start_date = ! empty( $input['start_date'] ) ? sanitize_text_field( $input['start_date'] ) : gmdate( 'Y-m-d', strtotime( '-28 days' ) );
		$end_date   = ! empty( $input['end_date'] ) ? sanitize_text_field( $input['end_date'] ) : gmdate( 'Y-m-d', strtotime( '-3 days' ) );
		$limit      = ! empty( $input['limit'] ) ? min( (int) $input['limit'], self::MAX_LIMIT ) : self::DEFAULT_LIMIT;
		$dimensions = self::ACTION_DIMENSIONS[ $action ];

		if ( empty( $site_url ) ) {
			return array(
				'success' => false,
				'error'   => 'No site URL configured or provided.',
			);
		}

		$request_body = array(
			'startDate'  => $start_date,
			'endDate'    => $end_date,
			'dimensions' => $dimensions,
			'rowLimit'   => $limit,
			'dataState'  => 'final',
		);

		// Build dimension filter groups if filters provided.
		$filters = array();

		if ( ! empty( $input['url_filter'] ) ) {
			$filters[] = array(
				'dimension'  => 'page',
				'operator'   => 'contains',
				'expression' => sanitize_text_field( $input['url_filter'] ),
			);
		}

		if ( ! empty( $input['query_filter'] ) ) {
			$filters[] = array(
				'dimension'  => 'query',
				'operator'   => 'contains',
				'expression' => sanitize_text_field( $input['query_filter'] ),
			);
		}

		if ( ! empty( $filters ) ) {
			$request_body['dimensionFilterGroups'] = array(
				array(
					'groupType' => 'and',
					'filters'   => $filters,
				),
			);
		}

		$encoded_site_url = rawurlencode( $site_url );
		$api_url          = "https://www.googleapis.com/webmasters/v3/sites/{$encoded_site_url}/searchAnalytics/query";

		$result = HttpClient::post(
			$api_url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $request_body ),
				'context' => 'Google Search Console Ability',
			)
		);

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'error'   => 'Failed to connect to Google Search Console API: ' . ( $result['error'] ?? 'Unknown error' ),
			);
		}

		$data = json_decode( $result['data'], true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'success' => false,
				'error'   => 'Failed to parse Google Search Console API response.',
			);
		}

		if ( ! empty( $data['error'] ) ) {
			$error_message = $data['error']['message'] ?? 'Unknown API error';
			return array(
				'success' => false,
				'error'   => 'GSC API error: ' . $error_message,
			);
		}

		$rows = $data['rows'] ?? array();

		return array(
			'success'       => true,
			'action'        => $action,
			'results_count' => count( $rows ),
			'results'       => $rows,
		);
	}
}
