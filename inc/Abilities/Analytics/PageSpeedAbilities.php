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

defined( 'ABSPATH' ) || exit;

class PageSpeedAbilities {

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
		'FIRST_CONTENTFUL_PAINT'   => 'first_contentful_paint',
		'LARGEST_CONTENTFUL_PAINT' => 'largest_contentful_paint',
		'TOTAL_BLOCKING_TIME'      => 'total_blocking_time',
		'CUMULATIVE_LAYOUT_SHIFT'  => 'cumulative_layout_shift',
		'SPEED_INDEX'              => 'speed_index',
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

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
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
			$query_args['category'] = 'PERFORMANCE';
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
				$api_url .= '&category=' . strtoupper( rawurlencode( $cat ) );
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
			return array(
				'success' => false,
				'error'   => 'Failed to connect to PageSpeed Insights API: ' . ( $result['error'] ?? 'Unknown error' ),
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
			$audit_id  = strtolower( str_replace( '_', '-', $audit_key ) );
			$audit     = $audits[ $audit_id ] ?? null;

			if ( ! $audit ) {
				continue;
			}

			$metrics[ $metric_key ] = array(
				'value'        => $audit['displayValue'] ?? null,
				'numeric'      => $audit['numericValue'] ?? null,
				'score'        => isset( $audit['score'] ) ? (int) round( $audit['score'] * 100 ) : null,
			);
		}

		return $metrics;
	}

	/**
	 * Check if PageSpeed Insights is configured.
	 *
	 * PageSpeed works without an API key (just rate-limited),
	 * so we always consider it configured.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return true;
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
