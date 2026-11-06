<?php
/**
 * Google Custom Search API integration with site restrictions and result limits.
 *
 * @package DataMachineBusiness\Tools
 */

namespace DataMachineBusiness\Tools;

defined( 'ABSPATH' ) || exit;

use DataMachineBusiness\Abilities\Search\GoogleSearchAbility;
use DataMachine\Engine\AI\Tools\BaseTool;

class GoogleSearch extends BaseTool {

	public function __construct() {
		$this->registerConfigurationHandlers( 'google_search' );
		$this->registerTool( 'google_search', array( $this, 'getToolDefinition' ), array( 'chat', 'pipeline' ), array( 'access_level' => 'admin' ) );
	}

	/**
	 * Execute a Google search through the shared ability implementation.
	 *
	 * @param array $parameters Contains 'query' and optional 'site_restrict'.
	 * @param array $tool_def Tool definition (unused).
	 * @return array Search results with success status.
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$result = GoogleSearchAbility::search( (string) ( $parameters['query'] ?? '' ), (string) ( $parameters['site_restrict'] ?? '' ) );

		if ( is_wp_error( $result ) ) {
			return array(
				'success'   => false,
				'error'     => $result->get_error_message(),
				'tool_name' => 'google_search',
			);
		}

		$result['message'] = $result['results_count'] > 0
			? "SEARCH COMPLETE: Found {$result['results_count']} results for \"{$result['query']}\".\nSearch Results:"
			: "SEARCH COMPLETE: No results found for \"{$result['query']}\".";

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'google_search',
		);
	}

	/**
	 * Get Google Search tool definition.
	 * Called lazily when tool is first accessed to ensure translations are loaded.
	 *
	 * @return array Tool definition array.
	 */
	public function getToolDefinition(): array {
		return array(
			'class'           => __CLASS__,
			'method'          => 'handle_tool_call',
			'description'     => 'Search the web using Google Custom Search and return 1-10 structured JSON results with titles, links, and snippets. Best for discovering external information when you don\'t have specific URLs. Use for current events, factual verification, or broad topic research. Returns complete web search data in JSON format with title, link, snippet for each result.',
			'requires_config' => true,
			'parameters'      => array(
				'type'       => 'object',
				'properties' => array(
					'query'         => array(
						'type'        => 'string',
						'description' => 'Search query for external web information. Returns JSON with "results" array containing web search results.',
					),
					'site_restrict' => array(
						'type'        => 'string',
						'description' => 'Restrict search to specific domain (e.g., "wikipedia.org" for Wikipedia only)',
					),
				),
				'required'   => array( 'query' ),
			),
		);
	}

	public static function is_configured(): bool {
		$config        = get_site_option( 'datamachine_search_config', array() );
		$google_config = $config['google_search'] ?? array();

		return ! empty( $google_config['api_key'] ) && ! empty( $google_config['search_engine_id'] );
	}

	public static function get_config(): array {
		$config = get_site_option( 'datamachine_search_config', array() );
		return $config['google_search'] ?? array();
	}

	/**
	 * Check if Google Search tool is properly configured.
	 *
	 * @param bool   $configured Current configuration status.
	 * @param string $tool_id Tool identifier to check.
	 * @return bool True if configured, false otherwise.
	 */
	public function check_configuration( $configured, $tool_id ) {
		if ( 'google_search' !== $tool_id ) {
			return $configured;
		}

		return self::is_configured();
	}

	public function get_configuration( $config, $tool_id ) {
		if ( 'google_search' !== $tool_id ) {
			return $config;
		}

		return self::get_config();
	}

	/**
	 * GoogleSearch uses a nested key inside a shared option, so it overrides
	 * save_configuration() directly instead of using the base flow.
	 *
	 * @param array|null $result      Previous handler result.
	 * @param string     $tool_id     Tool identifier.
	 * @param array      $config_data Sanitized configuration data.
	 * @return array|null
	 */
	public function save_configuration( $result, $tool_id, $config_data ) {
		if ( 'google_search' !== $tool_id ) {
			return $result;
		}

		$api_key          = sanitize_text_field( $config_data['api_key'] ?? '' );
		$search_engine_id = sanitize_text_field( $config_data['search_engine_id'] ?? '' );

		if ( empty( $api_key ) || empty( $search_engine_id ) ) {
			return array(
				'success' => false,
				'error'   => __( 'API Key and Search Engine ID are required', 'data-machine-business' ),
			);
		}

		$stored_config                  = get_site_option( 'datamachine_search_config', array() );
		$stored_config['google_search'] = array(
			'api_key'          => $api_key,
			'search_engine_id' => $search_engine_id,
		);

		if ( update_site_option( 'datamachine_search_config', $stored_config ) ) {
			return array(
				'success' => true,
				'message' => __( 'Google Search configuration saved successfully', 'data-machine-business' ),
			);
		}

		return array(
			'success' => false,
			'error'   => __( 'Failed to save configuration', 'data-machine-business' ),
		);
	}

	public function get_config_fields( $fields = array(), $tool_id = '' ) {
		if ( ! empty( $tool_id ) && 'google_search' !== $tool_id ) {
			return $fields;
		}

		return array(
			'api_key'          => array(
				'type'        => 'text',
				'label'       => __( 'Google Search API Key', 'data-machine-business' ),
				'placeholder' => __( 'Enter your Google Search API key', 'data-machine-business' ),
				'required'    => true,
				'description' => __( 'Get your API key from Google Cloud Console -> APIs & Services -> Credentials', 'data-machine-business' ),
			),
			'search_engine_id' => array(
				'type'        => 'text',
				'label'       => __( 'Custom Search Engine ID', 'data-machine-business' ),
				'placeholder' => __( 'Enter your Search Engine ID', 'data-machine-business' ),
				'required'    => true,
				'description' => __( 'Create a Custom Search Engine and copy the Search Engine ID (cx parameter)', 'data-machine-business' ),
			),
		);
	}
}
