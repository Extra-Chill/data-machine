<?php
/**
 * Google Analytics — AI agent wrapper for the google-analytics ability.
 *
 * Provides the AI-callable tool interface and settings page configuration.
 * Delegates actual data fetching to the datamachine/google-analytics ability.
 *
 * @package DataMachine\Engine\AI\Tools\Global
 * @since 0.31.0
 */

namespace DataMachine\Engine\AI\Tools\Global;

defined( 'ABSPATH' ) || exit;

use DataMachine\Abilities\Analytics\GoogleAnalyticsAbilities;
use DataMachine\Engine\AI\Tools\BaseTool;

class GoogleAnalytics extends BaseTool {

	public function __construct() {
		$this->registerConfigurationHandlers( 'google_analytics' );
		$this->registerGlobalTool( 'google_analytics', array( $this, 'getToolDefinition' ) );
	}

	/**
	 * Execute Google Analytics query by delegating to the ability.
	 *
	 * @param array $parameters Contains 'action' and optional parameters.
	 * @param array $tool_def   Tool definition (unused).
	 * @return array Result from the ability.
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$ability = wp_get_ability( 'datamachine/google-analytics' );

		if ( ! $ability ) {
			return $this->buildErrorResponse(
				'Google Analytics ability not registered. Ensure WordPress 6.9+ and GoogleAnalyticsAbilities is loaded.',
				'google_analytics'
			);
		}

		$result = $ability->execute( $parameters );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse(
				$result->get_error_message(),
				'google_analytics'
			);
		}

		if ( ! empty( $result['error'] ) ) {
			return $this->buildErrorResponse(
				$result['error'],
				'google_analytics'
			);
		}

		$result['tool_name'] = 'google_analytics';
		return $result;
	}

	/**
	 * Get tool definition for AI agents.
	 *
	 * @return array Tool definition array.
	 */
	public function getToolDefinition(): array {
		return array(
			'class'           => __CLASS__,
			'method'          => 'handle_tool_call',
			'description'     => 'Fetch visitor analytics from Google Analytics (GA4). Get page performance metrics, traffic sources, daily trends, real-time active users, top events, and user demographics. Use to analyze site traffic, identify popular content, understand visitor behavior, and spot trends.',
			'requires_config' => true,
			'parameters'      => array(
				'action'      => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Action to perform: page_stats (per-page views, sessions, bounce rate), traffic_sources (where visitors come from), date_stats (daily trends over time), realtime (active users right now), top_events (most triggered events), user_demographics (visitor country and device breakdown).',
				),
				'property_id' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'GA4 property ID (numeric). Defaults to the configured property ID.',
				),
				'start_date'  => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Start date in YYYY-MM-DD format (defaults to 28 days ago). Not used for realtime action.',
				),
				'end_date'    => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'End date in YYYY-MM-DD format (defaults to yesterday). Not used for realtime action.',
				),
				'limit'       => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Row limit (default: 25, max: 10000).',
				),
				'page_filter' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Filter results to pages with paths containing this string.',
				),
			),
		);
	}

	/**
	 * Check if Google Analytics is configured.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return GoogleAnalyticsAbilities::is_configured();
	}

	/**
	 * Get stored configuration.
	 *
	 * @return array
	 */
	public static function get_config(): array {
		return GoogleAnalyticsAbilities::get_config();
	}

	/**
	 * Check if this tool is configured.
	 *
	 * @param bool   $configured Current status.
	 * @param string $tool_id    Tool identifier.
	 * @return bool
	 */
	public function check_configuration( $configured, $tool_id ) {
		if ( 'google_analytics' !== $tool_id ) {
			return $configured;
		}

		return self::is_configured();
	}

	/**
	 * Get current configuration.
	 *
	 * @param array  $config  Current config.
	 * @param string $tool_id Tool identifier.
	 * @return array
	 */
	public function get_configuration( $config, $tool_id ) {
		if ( 'google_analytics' !== $tool_id ) {
			return $config;
		}

		return self::get_config();
	}

	/**
	 * Save configuration from settings page.
	 *
	 * @param string $tool_id     Tool identifier.
	 * @param array  $config_data Configuration data.
	 */
	public function save_configuration( $tool_id, $config_data ) {
		if ( 'google_analytics' !== $tool_id ) {
			return;
		}

		$service_account_json = $config_data['service_account_json'] ?? '';
		$property_id          = sanitize_text_field( $config_data['property_id'] ?? '' );

		if ( empty( $service_account_json ) ) {
			wp_send_json_error( array( 'message' => __( 'Service Account JSON is required', 'data-machine' ) ) );
			return;
		}

		if ( empty( $property_id ) ) {
			wp_send_json_error( array( 'message' => __( 'GA4 Property ID is required', 'data-machine' ) ) );
			return;
		}

		// Validate the JSON is parseable and has required fields.
		$parsed = json_decode( $service_account_json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			wp_send_json_error( array( 'message' => __( 'Invalid JSON in Service Account field', 'data-machine' ) ) );
			return;
		}

		if ( empty( $parsed['client_email'] ) || empty( $parsed['private_key'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Service Account JSON must contain client_email and private_key', 'data-machine' ) ) );
			return;
		}

		$config = array(
			'service_account_json' => $service_account_json,
			'property_id'          => $property_id,
		);

		// Clear cached token when config changes.
		delete_transient( GoogleAnalyticsAbilities::TOKEN_TRANSIENT );

		if ( update_site_option( GoogleAnalyticsAbilities::CONFIG_OPTION, $config ) ) {
			wp_send_json_success(
				array(
					'message'    => __( 'Google Analytics configuration saved successfully', 'data-machine' ),
					'configured' => true,
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to save configuration', 'data-machine' ) ) );
		}
	}

	/**
	 * Get configuration field definitions for the settings page.
	 *
	 * @param array  $fields  Current fields.
	 * @param string $tool_id Tool identifier.
	 * @return array
	 */
	public function get_config_fields( $fields = array(), $tool_id = '' ) {
		if ( ! empty( $tool_id ) && 'google_analytics' !== $tool_id ) {
			return $fields;
		}

		return array(
			'service_account_json' => array(
				'type'        => 'textarea',
				'label'       => __( 'Service Account JSON', 'data-machine' ),
				'placeholder' => __( 'Paste your Google service account JSON key...', 'data-machine' ),
				'required'    => true,
				'description' => __( 'The full JSON key file contents for a service account with Google Analytics Data API access. Can be the same service account used for Search Console.', 'data-machine' ),
			),
			'property_id'          => array(
				'type'        => 'text',
				'label'       => __( 'GA4 Property ID', 'data-machine' ),
				'placeholder' => '123456789',
				'required'    => true,
				'description' => __( 'Numeric GA4 property ID. Found in Google Analytics Admin > Property Settings.', 'data-machine' ),
			),
		);
	}
}
