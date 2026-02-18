<?php
/**
 * Google Search Console — AI agent wrapper for the google-search-console ability.
 *
 * Provides the AI-callable tool interface and settings page configuration.
 * Delegates actual data fetching to the datamachine/google-search-console ability.
 *
 * @package DataMachine\Engine\AI\Tools\Global
 */

namespace DataMachine\Engine\AI\Tools\Global;

defined( 'ABSPATH' ) || exit;

use DataMachine\Abilities\Analytics\GoogleSearchConsoleAbilities;
use DataMachine\Engine\AI\Tools\BaseTool;

class GoogleSearchConsole extends BaseTool {

	public function __construct() {
		$this->registerConfigurationHandlers( 'google_search_console' );
		$this->registerGlobalTool( 'google_search_console', [ $this, 'getToolDefinition' ] );
	}

	/**
	 * Execute Google Search Console query by delegating to the ability.
	 *
	 * @param array $parameters Contains 'action' and optional parameters.
	 * @param array $tool_def   Tool definition (unused).
	 * @return array Result from the ability.
	 */
	public function handle_tool_call( array $parameters, array $tool_def = [] ): array {
		$ability = wp_get_ability( 'datamachine/google-search-console' );

		if ( ! $ability ) {
			return $this->buildErrorResponse(
				'Google Search Console ability not registered. Ensure WordPress 6.9+ and GoogleSearchConsoleAbilities is loaded.',
				'google_search_console'
			);
		}

		$result = $ability->execute( $parameters );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse(
				$result->get_error_message(),
				'google_search_console'
			);
		}

		if ( ! empty( $result['error'] ) ) {
			return $this->buildErrorResponse(
				$result['error'],
				'google_search_console'
			);
		}

		$result['tool_name'] = 'google_search_console';
		return $result;
	}

	/**
	 * Get tool definition for AI agents.
	 *
	 * @return array Tool definition array.
	 */
	public function getToolDefinition(): array {
		return [
			'class'           => __CLASS__,
			'method'          => 'handle_tool_call',
			'description'     => 'Interact with Google Search Console. Fetch search analytics (query stats, page metrics, daily trends), inspect URLs for index status and mobile usability, and manage sitemaps (list, get details, submit).',
			'requires_config' => true,
			'parameters'      => [
				'action'       => [
					'type'        => 'string',
					'required'    => true,
					'description' => 'Action to perform: query_stats (top search queries), page_stats (per-page metrics), query_page_stats (query+page combos), date_stats (daily trends), inspect_url (check index/crawl status for a URL), list_sitemaps (list all submitted sitemaps), get_sitemap (details for one sitemap), submit_sitemap (submit a sitemap to Google).',
				],
				'url'          => [
					'type'        => 'string',
					'required'    => false,
					'description' => 'Full URL to inspect. Required for inspect_url action.',
				],
				'sitemap_url'  => [
					'type'        => 'string',
					'required'    => false,
					'description' => 'Sitemap URL. Required for get_sitemap and submit_sitemap actions.',
				],
				'site_url'     => [
					'type'        => 'string',
					'required'    => false,
					'description' => 'Site URL (sc-domain: or https://). Defaults to the configured site URL.',
				],
				'start_date'   => [
					'type'        => 'string',
					'required'    => false,
					'description' => 'Start date in YYYY-MM-DD format (defaults to 28 days ago).',
				],
				'end_date'     => [
					'type'        => 'string',
					'required'    => false,
					'description' => 'End date in YYYY-MM-DD format (defaults to 3 days ago for final data).',
				],
				'limit'        => [
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Row limit (default: 25, max: 25000).',
				],
				'url_filter'   => [
					'type'        => 'string',
					'required'    => false,
					'description' => 'Filter results to URLs containing this string.',
				],
				'query_filter' => [
					'type'        => 'string',
					'required'    => false,
					'description' => 'Filter results to queries containing this string.',
				],
			],
		];
	}

	/**
	 * Check if Google Search Console is configured.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return GoogleSearchConsoleAbilities::is_configured();
	}

	/**
	 * Get stored configuration.
	 *
	 * @return array
	 */
	public static function get_config(): array {
		return GoogleSearchConsoleAbilities::get_config();
	}

	/**
	 * Check if this tool is configured.
	 *
	 * @param bool   $configured Current status.
	 * @param string $tool_id    Tool identifier.
	 * @return bool
	 */
	public function check_configuration( $configured, $tool_id ) {
		if ( 'google_search_console' !== $tool_id ) {
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
		if ( 'google_search_console' !== $tool_id ) {
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
		if ( 'google_search_console' !== $tool_id ) {
			return;
		}

		$service_account_json = $config_data['service_account_json'] ?? '';
		$site_url             = sanitize_text_field( $config_data['site_url'] ?? '' );

		if ( empty( $service_account_json ) ) {
			wp_send_json_error( [ 'message' => __( 'Service Account JSON is required', 'data-machine' ) ] );
			return;
		}

		// Validate the JSON is parseable and has required fields.
		$parsed = json_decode( $service_account_json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			wp_send_json_error( [ 'message' => __( 'Invalid JSON in Service Account field', 'data-machine' ) ] );
			return;
		}

		if ( empty( $parsed['client_email'] ) || empty( $parsed['private_key'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Service Account JSON must contain client_email and private_key', 'data-machine' ) ] );
			return;
		}

		$config = [
			'service_account_json' => $service_account_json,
			'site_url'             => $site_url,
		];

		// Clear cached token when config changes.
		delete_transient( GoogleSearchConsoleAbilities::TOKEN_TRANSIENT );

		if ( update_site_option( GoogleSearchConsoleAbilities::CONFIG_OPTION, $config ) ) {
			wp_send_json_success(
				[
					'message'    => __( 'Google Search Console configuration saved successfully', 'data-machine' ),
					'configured' => true,
				]
			);
		} else {
			wp_send_json_error( [ 'message' => __( 'Failed to save configuration', 'data-machine' ) ] );
		}
	}

	/**
	 * Get configuration field definitions for the settings page.
	 *
	 * @param array  $fields  Current fields.
	 * @param string $tool_id Tool identifier.
	 * @return array
	 */
	public function get_config_fields( $fields = [], $tool_id = '' ) {
		if ( ! empty( $tool_id ) && 'google_search_console' !== $tool_id ) {
			return $fields;
		}

		return [
			'service_account_json' => [
				'type'        => 'textarea',
				'label'       => __( 'Service Account JSON', 'data-machine' ),
				'placeholder' => __( 'Paste your Google service account JSON key...', 'data-machine' ),
				'required'    => true,
				'description' => __( 'The full JSON key file contents for a service account with Search Console access.', 'data-machine' ),
			],
			'site_url'             => [
				'type'        => 'text',
				'label'       => __( 'Site URL', 'data-machine' ),
				'placeholder' => 'sc-domain:example.com',
				'required'    => false,
				'description' => __( 'GSC property URL. Use sc-domain: prefix for domain properties.', 'data-machine' ),
			],
		];
	}
}

