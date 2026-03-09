<?php
/**
 * Pinterest Analytics Ability
 *
 * Abilities API primitive for fetching Pinterest analytics metrics.
 * Supports user account analytics, pin analytics, and board analytics.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Pinterest
 * @since      0.3.0
 */

namespace DataMachineSocials\Abilities\Pinterest;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachineSocials\Handlers\Pinterest\PinterestAuth;

defined( 'ABSPATH' ) || exit;

class PinterestAnalyticsAbility {

	private static bool $registered = false;

	const API_URL = 'https://api.pinterest.com/v5';

	/**
	 * Available metric types for Pinterest analytics.
	 */
	const METRIC_TYPES = array(
		'IMPRESSION',
		'SAVE',
		'PIN_CLICK',
		'COMMENT',
		'CREATE',
		'FOLLOW',
		'CLOSEUP',
		'VIDEO_AVG_WATCH_TIME',
		'VIDEO_V50_WATCH_TIME',
		'VIDEO_MRC_VIEW',
		'VIDEO_START',
		'QUARTILE_25',
		'QUARTILE_50',
		'QUARTILE_75',
		'QUARTILE_100',
	);

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
				'datamachine/pinterest-analytics',
				array(
					'label'               => __( 'Pinterest Analytics', 'data-machine-socials' ),
					'description'         => __( 'Get analytics metrics for user account, pins, or boards. Includes impressions, saves, clicks, and engagement data.', 'data-machine-socials' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'action' => array(
								'type'        => 'string',
								'enum'        => array( 'user', 'pin', 'board' ),
								'default'     => 'user',
								'description' => __( 'Analytics type: user (account-wide), pin (single pin), board (board metrics)', 'data-machine-socials' ),
							),
							'pin_id' => array(
								'type'        => 'string',
								'description' => __( 'Pinterest pin ID (required for pin action)', 'data-machine-socials' ),
							),
							'board_id' => array(
								'type'        => 'string',
								'description' => __( 'Pinterest board ID (required for board action)', 'data-machine-socials' ),
							),
							'start_date' => array(
								'type'        => 'string',
								'description' => __( 'Start date in YYYY-MM-DD format (default: 30 days ago)', 'data-machine-socials' ),
							),
							'end_date' => array(
								'type'        => 'string',
								'description' => __( 'End date in YYYY-MM-DD format (default: today)', 'data-machine-socials' ),
							),
							'metrics' => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => __( 'Metric types to fetch (default: all). Options: IMPRESSION, SAVE, PIN_CLICK, COMMENT, CLOSEUP', 'data-machine-socials' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'data'    => array( 'type' => 'object' ),
							'summary' => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	public function execute( array $input ): array {
		$action = $input['action'] ?? 'user';

		$auth = $this->getAuthProvider();
		if ( ! $auth ) {
			return array(
				'success' => false,
				'error'   => 'Pinterest auth provider not available',
			);
		}

		$token = $auth->get_valid_access_token();
		if ( empty( $token ) ) {
			return array(
				'success' => false,
				'error'   => 'Pinterest access token is missing or expired — re-authorize in WP Admin > Data Machine > Settings',
			);
		}

		// Parse date range
		$start_date = $input['start_date'] ?? gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$end_date   = $input['end_date'] ?? gmdate( 'Y-m-d' );

		// Validate date format
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid date format. Use YYYY-MM-DD.',
			);
		}

		// Parse metrics (default to key engagement metrics)
		$default_metrics = array( 'IMPRESSION', 'SAVE', 'PIN_CLICK', 'COMMENT', 'CLOSEUP' );
		$metrics         = $input['metrics'] ?? $default_metrics;

		// Validate metrics
		$metrics = array_filter( (array) $metrics, function ( $m ) {
			return in_array( strtoupper( $m ), self::METRIC_TYPES, true );
		});

		if ( empty( $metrics ) ) {
			$metrics = $default_metrics;
		}

		$metrics_param = implode( ',', array_map( 'strtoupper', $metrics ) );

		switch ( $action ) {
			case 'user':
				return $this->getUserAnalytics( $token, $start_date, $end_date, $metrics_param );

			case 'pin':
				if ( empty( $input['pin_id'] ) ) {
					return array(
						'success' => false,
						'error'   => 'pin_id is required for the pin action',
					);
				}
				return $this->getPinAnalytics( $token, $input['pin_id'], $start_date, $end_date, $metrics_param );

			case 'board':
				if ( empty( $input['board_id'] ) ) {
					return array(
						'success' => false,
						'error'   => 'board_id is required for the board action',
					);
				}
				return $this->getBoardAnalytics( $token, $input['board_id'], $start_date, $end_date, $metrics_param );

			default:
				return array(
					'success' => false,
					'error'   => "Unknown action: {$action}. Use user, pin, or board.",
				);
		}
	}

	/**
	 * Get user account analytics.
	 *
	 * @param string $token         Bearer token.
	 * @param string $start_date    Start date YYYY-MM-DD.
	 * @param string $end_date      End date YYYY-MM-DD.
	 * @param string $metrics_param Comma-separated metric types.
	 * @return array
	 */
	private function getUserAnalytics( string $token, string $start_date, string $end_date, string $metrics_param ): array {
		$params = array(
			'start_date'         => $start_date,
			'end_date'           => $end_date,
			'metric_types'       => $metrics_param,
			'split_field'        => 'NO_SPLIT',
			'from_claimed_content' => 'BOTH',
		);

		$url = self::API_URL . '/user_account/analytics?' . http_build_query( $params );

		$result = HttpClient::get(
			$url,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'context' => 'Pinterest Analytics',
			)
		);

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'error'   => 'Pinterest API request failed: ' . ( $result['error'] ?? 'unknown' ),
			);
		}

		$data      = json_decode( $result['data'], true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || isset( $data['code'] ) ) {
			$error_msg = $data['message'] ?? 'Failed to fetch user analytics';
			return array(
				'success' => false,
				'error'   => $error_msg,
			);
		}

		// Build summary
		$summary = $this->buildSummary( $data );

		return array(
			'success' => true,
			'data'    => $data,
			'summary' => $summary,
		);
	}

	/**
	 * Get analytics for a specific pin.
	 *
	 * @param string $token         Bearer token.
	 * @param string $pin_id        Pinterest pin ID.
	 * @param string $start_date    Start date YYYY-MM-DD.
	 * @param string $end_date      End date YYYY-MM-DD.
	 * @param string $metrics_param Comma-separated metric types.
	 * @return array
	 */
	private function getPinAnalytics( string $token, string $pin_id, string $start_date, string $end_date, string $metrics_param ): array {
		$params = array(
			'start_date'   => $start_date,
			'end_date'     => $end_date,
			'metric_types' => $metrics_param,
		);

		$url = self::API_URL . "/pins/{$pin_id}/analytics?" . http_build_query( $params );

		$result = HttpClient::get(
			$url,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'context' => 'Pinterest Pin Analytics',
			)
		);

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'error'   => 'Pinterest API request failed: ' . ( $result['error'] ?? 'unknown' ),
			);
		}

		$data      = json_decode( $result['data'], true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || isset( $data['code'] ) ) {
			$error_msg = $data['message'] ?? 'Failed to fetch pin analytics';
			return array(
				'success' => false,
				'error'   => $error_msg,
			);
		}

		// Build summary
		$summary = $this->buildSummary( $data );
		$summary['pin_id'] = $pin_id;

		return array(
			'success' => true,
			'data'    => $data,
			'summary' => $summary,
		);
	}

	/**
	 * Get analytics for a specific board.
	 *
	 * @param string $token         Bearer token.
	 * @param string $board_id      Pinterest board ID.
	 * @param string $start_date    Start date YYYY-MM-DD.
	 * @param string $end_date      End date YYYY-MM-DD.
	 * @param string $metrics_param Comma-separated metric types.
	 * @return array
	 */
	private function getBoardAnalytics( string $token, string $board_id, string $start_date, string $end_date, string $metrics_param ): array {
		$params = array(
			'start_date'   => $start_date,
			'end_date'     => $end_date,
			'metric_types' => $metrics_param,
		);

		$url = self::API_URL . "/boards/{$board_id}/analytics?" . http_build_query( $params );

		$result = HttpClient::get(
			$url,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'context' => 'Pinterest Board Analytics',
			)
		);

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'error'   => 'Pinterest API request failed: ' . ( $result['error'] ?? 'unknown' ),
			);
		}

		$data      = json_decode( $result['data'], true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || isset( $data['code'] ) ) {
			$error_msg = $data['message'] ?? 'Failed to fetch board analytics';
			return array(
				'success' => false,
				'error'   => $error_msg,
			);
		}

		// Build summary
		$summary = $this->buildSummary( $data );
		$summary['board_id'] = $board_id;

		return array(
			'success' => true,
			'data'    => $data,
			'summary' => $summary,
		);
	}

	/**
	 * Build a human-readable summary from Pinterest analytics data.
	 *
	 * Pinterest returns metrics in a nested structure:
	 * { "IMPRESSION": { "2024-01-01": 100, "2024-01-02": 150, ... } }
	 *
	 * @param array $data Raw analytics data from Pinterest API.
	 * @return array Summarized metrics.
	 */
	private function buildSummary( array $data ): array {
		$summary = array();

		foreach ( self::METRIC_TYPES as $metric ) {
			if ( isset( $data[ $metric ] ) && is_array( $data[ $metric ] ) ) {
				$total = array_sum( array_map( 'intval', $data[ $metric ] ) );
				if ( $total > 0 ) {
					$summary[ strtolower( $metric ) ] = $total;
				}
			}
		}

		// Calculate derived metrics
		if ( isset( $summary['impression'], $summary['save'] ) && $summary['impression'] > 0 ) {
			$summary['save_rate'] = round( ( $summary['save'] / $summary['impression'] ) * 100, 2 );
		}

		if ( isset( $summary['impression'], $summary['pin_click'] ) && $summary['impression'] > 0 ) {
			$summary['click_rate'] = round( ( $summary['pin_click'] / $summary['impression'] ) * 100, 2 );
		}

		return $summary;
	}

	private function getAuthProvider(): ?PinterestAuth {
		$auth_abilities = new \DataMachine\Abilities\AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'pinterest' );

		if ( $provider instanceof PinterestAuth ) {
			return $provider;
		}

		return null;
	}
}
