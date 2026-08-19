<?php
/**
 * Google Analytics — AI agent wrapper for the google-analytics ability.
 *
 * Provides the AI-callable tool interface and settings page configuration.
 * Delegates actual data fetching to the datamachine/google-analytics ability.
 *
 * @package DataMachineBusiness\Engine\AI\Tools\Global
 * @since 0.31.0
 */

namespace DataMachineBusiness\Engine\AI\Tools\Global;

defined( 'ABSPATH' ) || exit;

use DataMachineBusiness\Abilities\Analytics\GoogleAnalyticsAbilities;
use DataMachine\Engine\AI\Tools\BaseTool;

class GoogleAnalytics extends BaseTool {

	public function __construct() {
		$this->registerConfigurationHandlers( 'google_analytics' );
		$this->registerTool( 'google_analytics', array( $this, 'getToolDefinition' ), array( 'chat', 'pipeline' ), array( 'ability' => 'datamachine/google-analytics' ) );
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
		$valid_actions = array_merge( array_keys( GoogleAnalyticsAbilities::ACTION_REPORTS ), array( 'realtime', 'path_sequence', 'aggregate_report' ) );
		$legacy_actions = array_values( array_diff( $valid_actions, array( 'aggregate_report' ) ) );
		$legacy_properties = array(
			'action'      => array( 'type' => 'string', 'enum' => $legacy_actions, 'description' => 'Choose a bounded preset report. landing_page_acquisition uses session-entry landingPage x session source/medium and discloses material `(not set)` coverage without filtering it. page_acquisition uses touched pagePath x session source/medium. page_audience uses touched pagePath x country/device.' ),
			'property_id' => array( 'type' => 'string', 'description' => 'GA4 property ID (numeric). Defaults to the configured property ID.' ),
			'start_date'  => array( 'type' => 'string', 'description' => 'Start date in YYYY-MM-DD format (defaults to 28 days ago). Not used for realtime action.' ),
			'end_date'    => array( 'type' => 'string', 'description' => 'End date in YYYY-MM-DD format (defaults to yesterday). Not used for realtime action.' ),
			'limit'       => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => GoogleAnalyticsAbilities::MAX_LIMIT, 'description' => 'Row limit (default: 25, max: 10000).' ),
			'page_filter' => array( 'type' => 'string', 'description' => 'Filter results to pages with paths containing this string.' ),
			'hostname'    => array( 'type' => 'string', 'description' => 'Filter to pages on this hostname (for multisite GA4 properties).' ),
			'sort_by'     => array( 'type' => 'string', 'description' => 'Sort results by this metric or dimension field name (e.g. bounceRate, sessions, engagementRate).' ),
			'order'       => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'description' => 'Sort direction: asc or desc (default: desc).' ),
			'compare'     => array( 'type' => 'boolean', 'description' => 'Compare against the previous period of equal length. Adds delta percentage columns.' ),
		);

		return array(
			'class'           => __CLASS__,
			'method'          => 'handle_tool_call',
			'description'     => 'Fetch visitor analytics from Google Analytics (GA4). Fixed actions retain their existing presets. aggregate_report is a bounded, read-only aggregate query with exact dates, reviewed fields, totals, quota, and explicit coverage limitations.',
			'requires_config' => true,
			'parameters'      => array(
				'oneOf' => array(
					array( 'type' => 'object', 'required' => array( 'action' ), 'properties' => $legacy_properties ),
					GoogleAnalyticsAbilities::aggregateInputSchema(),
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
	 */
	protected function get_config_option_name(): string {
		return GoogleAnalyticsAbilities::CONFIG_OPTION;
	}

	protected function validate_and_build_config( array $config_data ): array {
		$service_account_json = $config_data['service_account_json'] ?? '';
		$property_id          = sanitize_text_field( $config_data['property_id'] ?? '' );

		if ( empty( $service_account_json ) ) {
			return array( 'error' => __( 'Service Account JSON is required', 'data-machine-business' ) );
		}

		if ( empty( $property_id ) ) {
			return array( 'error' => __( 'GA4 Property ID is required', 'data-machine-business' ) );
		}

		$parsed = json_decode( $service_account_json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array( 'error' => __( 'Invalid JSON in Service Account field', 'data-machine-business' ) );
		}

		if ( empty( $parsed['client_email'] ) || empty( $parsed['private_key'] ) ) {
			return array( 'error' => __( 'Service Account JSON must contain client_email and private_key', 'data-machine-business' ) );
		}

		return array(
			'config'  => array(
				'service_account_json' => $service_account_json,
				'property_id'          => $property_id,
			),
			'message' => __( 'Google Analytics configuration saved successfully', 'data-machine-business' ),
		);
	}

	protected function before_config_save( array $config_data ): void {
		delete_transient( GoogleAnalyticsAbilities::TOKEN_TRANSIENT );
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
				'label'       => __( 'Service Account JSON', 'data-machine-business' ),
				'placeholder' => __( 'Paste your Google service account JSON key...', 'data-machine-business' ),
				'required'    => true,
				'description' => __( 'The full JSON key file contents for a service account with Google Analytics Data API access. Can be the same service account used for Search Console.', 'data-machine-business' ),
			),
			'property_id'          => array(
				'type'        => 'text',
				'label'       => __( 'GA4 Property ID', 'data-machine-business' ),
				'placeholder' => '123456789',
				'required'    => true,
				'description' => __( 'Numeric GA4 property ID. Found in Google Analytics Admin > Property Settings.', 'data-machine-business' ),
			),
		);
	}
}
