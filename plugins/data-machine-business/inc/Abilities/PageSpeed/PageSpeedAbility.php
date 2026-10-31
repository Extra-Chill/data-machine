<?php
/**
 * PageSpeed Insights ability.
 *
 * @package DataMachineBusiness\Abilities\PageSpeed
 */

namespace DataMachineBusiness\Abilities\PageSpeed;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;

defined( 'ABSPATH' ) || exit;

class PageSpeedAbility {

	const CONFIG_OPTION = 'datamachine_pagespeed_config';
	const API_ENDPOINT  = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
	const CATEGORIES    = array( 'performance', 'accessibility', 'best-practices', 'seo' );
	const STRATEGIES    = array( 'mobile', 'desktop' );

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
		if ( self::$registered ) {
			return;
		}

		$this->register_abilities();
		self::$registered = true;
	}

	private function register_abilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/pagespeed',
				array(
					'label'               => __( 'PageSpeed Insights', 'data-machine-business' ),
					'description'         => __( 'Run Lighthouse audits via PageSpeed Insights API for performance, accessibility, SEO, and best practices scores', 'data-machine-business' ),
					'category'            => 'datamachine-analytics',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'action' ),
						'properties' => array(
							'action'   => array(
								'type'        => 'string',
								'description' => __( 'Action to perform: analyze, performance, or opportunities.', 'data-machine-business' ),
							),
							'url'      => array(
								'type'        => 'string',
								'description' => __( 'URL to analyze. Defaults to the WordPress site home URL.', 'data-machine-business' ),
							),
							'strategy' => array(
								'type'        => 'string',
								'description' => __( 'Device strategy: mobile or desktop.', 'data-machine-business' ),
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
					'execute_callback'    => array( self::class, 'run_audit' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	public static function run_audit( array $input ): array {
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

		$config     = self::get_config();
		$query_args = array(
			'url'      => $url,
			'strategy' => $strategy,
		);

		if ( 'performance' === $action ) {
			$query_args['category'] = 'performance';
		} else {
			$categories = self::CATEGORIES;
		}

		if ( ! empty( $config['api_key'] ) ) {
			$query_args['key'] = $config['api_key'];
		}

		$api_url = self::API_ENDPOINT . '?' . http_build_query( $query_args );
		if ( isset( $categories ) ) {
			foreach ( $categories as $category ) {
				$api_url .= '&category=' . rawurlencode( $category );
			}
		}

		$result = HttpClient::get(
			$api_url,
			array(
				'timeout' => 60,
				'context' => 'PageSpeed Insights API',
			)
		);

		if ( ! $result['success'] ) {
			$error_msg   = $result['error'] ?? 'Unknown error';
			$status_code = $result['status_code'] ?? 0;
			if ( 429 === $status_code || false !== strpos( $error_msg, '429' ) ) {
				$error_msg = ! empty( $config['api_key'] )
					? 'PageSpeed API rate limit exceeded even with an API key. Wait a few minutes and try again, or check your Google Cloud Console quota.'
					: 'PageSpeed API rate limit exceeded. Configure a Google API key in Data Machine settings (Tools -> PageSpeed) for higher limits. Get a free key at https://console.cloud.google.com/apis/credentials (enable PageSpeed Insights API).';
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

		if ( 'analyze' === $action ) {
			return self::format_analyze_response( $lighthouse, $url, $strategy );
		}

		if ( 'performance' === $action ) {
			return self::format_performance_response( $lighthouse, $url, $strategy );
		}

		return self::format_opportunities_response( $lighthouse, $url, $strategy );
	}

	private static function format_analyze_response( array $lighthouse, string $url, string $strategy ): array {
		$categories = $lighthouse['categories'] ?? array();
		$audits     = $lighthouse['audits'] ?? array();
		$scores     = array();

		foreach ( $categories as $key => $category ) {
			$scores[ $key ] = isset( $category['score'] ) ? (int) round( $category['score'] * 100 ) : null;
		}

		return array(
			'success'  => true,
			'action'   => 'analyze',
			'url'      => $url,
			'strategy' => $strategy,
			'scores'   => $scores,
			'metrics'  => self::extract_performance_metrics( $audits ),
		);
	}

	private static function format_performance_response( array $lighthouse, string $url, string $strategy ): array {
		$categories = $lighthouse['categories'] ?? array();
		$audits     = $lighthouse['audits'] ?? array();
		$score      = isset( $categories['performance']['score'] ) ? (int) round( $categories['performance']['score'] * 100 ) : null;

		return array(
			'success'           => true,
			'action'            => 'performance',
			'url'               => $url,
			'strategy'          => $strategy,
			'performance_score' => $score,
			'metrics'           => self::extract_performance_metrics( $audits ),
		);
	}

	private static function format_opportunities_response( array $lighthouse, string $url, string $strategy ): array {
		$categories    = $lighthouse['categories'] ?? array();
		$audits        = $lighthouse['audits'] ?? array();
		$scores        = array();
		$opportunities = array();

		foreach ( $categories as $key => $category ) {
			$scores[ $key ] = isset( $category['score'] ) ? (int) round( $category['score'] * 100 ) : null;
		}

		foreach ( $audits as $audit_id => $audit ) {
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

		usort( $opportunities, fn( $a, $b ) => ( $b['savings_ms'] ?? 0 ) - ( $a['savings_ms'] ?? 0 ) );

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

	private static function extract_performance_metrics( array $audits ): array {
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

	public static function is_configured(): bool {
		return true;
	}

	public static function get_config(): array {
		$config = get_site_option( self::CONFIG_OPTION, array() );
		return is_array( $config ) ? $config : array();
	}
}
