<?php
/**
 * Shared handler utilities for timeframe parsing and keyword matching.
 *
 * @package DataMachine\Engine\Filters
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

function datamachine_register_handler_filters() {
	/**
	 * Dual-mode: null = discover options, string = convert to timestamp.
	 */
	add_filter(
		'datamachine_timeframe_limit',
		function ( $default, $timeframe_limit ) {
			if ( null === $timeframe_limit ) {
				return array(
					'all_time' => 'All Time',
					'24_hours' => 'Last 24 Hours',
					'72_hours' => 'Last 72 Hours',
					'7_days'   => 'Last 7 Days',
					'30_days'  => 'Last 30 Days',
				);
			}

			if ( 'all_time' === $timeframe_limit ) {
				return null;
			}

			$interval_map = array(
				'24_hours' => '-24 hours',
				'72_hours' => '-72 hours',
				'7_days'   => '-7 days',
				'30_days'  => '-30 days',
			);

			if ( ! isset( $interval_map[ $timeframe_limit ] ) ) {
				return null;
			}

			return strtotime( $interval_map[ $timeframe_limit ], time() );
		},
		10,
		2
	);

	/**
	 * OR logic for comma-separated keywords. Empty matches all content.
	 */
	add_filter(
		'datamachine_keyword_search_match',
		function ( $default, $content, $search_term ) {
			if ( empty( $search_term ) ) {
				return true;
			}

			$keywords = array_map( 'trim', explode( ',', $search_term ) );
			$keywords = array_filter( $keywords );

			if ( empty( $keywords ) ) {
				return true;
			}

			$content_lower = strtolower( $content );
			foreach ( $keywords as $keyword ) {
				if ( mb_stripos( $content_lower, strtolower( $keyword ) ) !== false ) {
					return true;
				}
			}

			return false;
		},
		10,
		3
	);

	/**
	 * Base implementation for handler settings display with smart label defaults.
	 * Priority 5 allows handlers to override at higher priorities.
	 */
	add_filter(
		'datamachine_get_handler_settings_display',
		function ( $default, $flow_step_id, $step_type ) {
			$service = new \DataMachine\Core\Steps\Settings\SettingsDisplayService();
			return $service->getDisplaySettings( $flow_step_id, $step_type );
		},
		5,
		3
	);
}

datamachine_register_handler_filters();
