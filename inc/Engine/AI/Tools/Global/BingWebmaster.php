<?php
/**
 * Bing Webmaster Tools — AI agent wrapper for the bing-webmaster ability.
 *
 * Provides the AI-callable tool interface and settings page configuration.
 * Delegates actual data fetching to the datamachine/bing-webmaster ability.
 *
 * @package DataMachine\Engine\AI\Tools\Global
 */

namespace DataMachine\Engine\AI\Tools\Global;

defined( 'ABSPATH' ) || exit;

use DataMachine\Abilities\Analytics\BingWebmasterAbilities;
use DataMachine\Engine\AI\Tools\BaseTool;

class BingWebmaster extends BaseTool {

	public function __construct() {
		$this->registerConfigurationHandlers( 'bing_webmaster' );
		$this->registerGlobalTool( 'bing_webmaster', array( $this, 'getToolDefinition' ) );
	}

	/**
	 * Execute Bing Webmaster query by delegating to the ability.
	 *
	 * @param array $parameters Contains 'action' and optional 'site_url', 'limit'.
	 * @param array $tool_def   Tool definition (unused).
	 * @return array Result from the ability.
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$ability = wp_get_ability( 'datamachine/bing-webmaster' );

		if ( ! $ability ) {
			return $this->buildErrorResponse(
				'Bing Webmaster ability not registered. Ensure WordPress 6.9+ and BingWebmasterAbilities is loaded.',
				'bing_webmaster'
			);
		}

		$result = $ability->execute( $parameters );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse(
				$result->get_error_message(),
				'bing_webmaster'
			);
		}

		if ( ! empty( $result['error'] ) ) {
			return $this->buildErrorResponse(
				$result['error'],
				'bing_webmaster'
			);
		}

		$result['tool_name'] = 'bing_webmaster';
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
			'description'     => 'Fetch search analytics data from Bing Webmaster Tools. Returns query performance stats, traffic rankings, page-level stats, or crawl information for the configured site. Use to analyze search visibility, top queries, and crawl health on Bing.',
			'requires_config' => true,
			'parameters'      => array(
				'action'   => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Analytics action to perform: query_stats (search query performance), traffic_stats (rank and traffic data), page_stats (per-page metrics), crawl_stats (crawl information).',
				),
				'site_url' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Site URL to query. Defaults to the configured site URL.',
				),
				'limit'    => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Maximum number of results to return (default: 20).',
				),
			),
		);
	}

	/**
	 * Check if Bing Webmaster Tools is configured.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return BingWebmasterAbilities::is_configured();
	}

	/**
	 * Get stored configuration.
	 *
	 * @return array
	 */
	public static function get_config(): array {
		return BingWebmasterAbilities::get_config();
	}

	/**
	 * Check if this tool is configured.
	 *
	 * @param bool   $configured Current status.
	 * @param string $tool_id    Tool identifier.
	 * @return bool
	 */
	public function check_configuration( $configured, $tool_id ) {
		if ( 'bing_webmaster' !== $tool_id ) {
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
		if ( 'bing_webmaster' !== $tool_id ) {
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
		if ( 'bing_webmaster' !== $tool_id ) {
			return;
		}

		$api_key  = sanitize_text_field( $config_data['api_key'] ?? '' );
		$site_url = esc_url_raw( $config_data['site_url'] ?? '' );

		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Bing Webmaster API key is required', 'data-machine' ) ) );
			return;
		}

		$config = array(
			'api_key'  => $api_key,
			'site_url' => $site_url,
		);

		if ( update_site_option( BingWebmasterAbilities::CONFIG_OPTION, $config ) ) {
			wp_send_json_success(
				array(
					'message'    => __( 'Bing Webmaster Tools configuration saved successfully', 'data-machine' ),
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
		if ( ! empty( $tool_id ) && 'bing_webmaster' !== $tool_id ) {
			return $fields;
		}

		return array(
			'api_key'  => array(
				'type'        => 'password',
				'label'       => __( 'Bing Webmaster API Key', 'data-machine' ),
				'placeholder' => __( 'Enter your Bing Webmaster API key', 'data-machine' ),
				'required'    => true,
				'description' => __( 'Get your API key from Bing Webmaster Tools → Settings → API Access', 'data-machine' ),
			),
			'site_url' => array(
				'type'        => 'text',
				'label'       => __( 'Site URL', 'data-machine' ),
				'placeholder' => 'https://yoursite.com',
				'required'    => false,
				'description' => __( 'The site URL registered in Bing Webmaster Tools. Defaults to your WordPress site URL.', 'data-machine' ),
			),
		);
	}
}
