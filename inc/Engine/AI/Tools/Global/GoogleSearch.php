<?php
/**
 * Google Custom Search API integration with site restrictions and result limits.
 *
 * @package DataMachine\Engine\AI\Tools\Global
 */

namespace DataMachine\Engine\AI\Tools\Global;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\HttpClient;
use DataMachine\Engine\AI\Tools\BaseTool;

class GoogleSearch extends BaseTool {

	public function __construct() {
		$this->registerConfigurationHandlers( 'google_search' );
		$this->registerGlobalTool( 'google_search', array( $this, 'getToolDefinition' ) );
	}

	/**
	 * Execute Google search with site restrictions and result limiting.
	 *
	 * @param array $parameters Contains 'query' and optional 'site_restrict'
	 * @param array $tool_def Tool definition (unused)
	 * @return array Search results with success status
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {

		if ( empty( $parameters['query'] ) ) {
			return array(
				'success'   => false,
				'error'     => 'Google Search tool call missing required query parameter',
				'tool_name' => 'google_search',
			);
		}

		$config        = get_site_option( 'datamachine_search_config', array() );
		$google_config = $config['google_search'] ?? array();

		if ( empty( $google_config['api_key'] ) || empty( $google_config['search_engine_id'] ) ) {
			return array(
				'success'   => false,
				'error'     => 'Google Search tool not configured. Please configure API key and Search Engine ID.',
				'tool_name' => 'google_search',
			);
		}

		$query         = sanitize_text_field( $parameters['query'] );
		$max_results   = 10;
		$site_restrict = ! empty( $parameters['site_restrict'] ) ? sanitize_text_field( $parameters['site_restrict'] ) : '';

		$search_url    = 'https://www.googleapis.com/customsearch/v1';
		$search_params = array(
			'key'  => $google_config['api_key'],
			'cx'   => $google_config['search_engine_id'],
			'q'    => $query,
			'num'  => $max_results,
			'safe' => 'active',
		);

		if ( $site_restrict ) {
			$search_params['siteSearch'] = $site_restrict;
		}

		$request_url = add_query_arg( $search_params, $search_url );

		$result = HttpClient::get(
			$request_url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept' => 'application/json',
				),
				'context' => 'Google Search Tool',
			)
		);

		if ( ! $result['success'] ) {
			return array(
				'success'   => false,
				'error'     => 'Failed to connect to Google Search API: ' . ( $result['error'] ?? 'Unknown error' ),
				'tool_name' => 'google_search',
			);
		}

		$search_data = json_decode( $result['data'], true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'success'   => false,
				'error'     => 'Failed to parse Google Search API response',
				'tool_name' => 'google_search',
			);
		}

		$results = array();
		if ( ! empty( $search_data['items'] ) ) {
			foreach ( $search_data['items'] as $item ) {
				$results[] = array(
					'title'       => $item['title'] ?? '',
					'link'        => $item['link'] ?? '',
					'snippet'     => $item['snippet'] ?? '',
					'displayLink' => $item['displayLink'] ?? '',
				);
			}
		}

		$search_info   = $search_data['searchInformation'] ?? array();
		$total_results = $search_info['totalResults'] ?? '0';
		$search_time   = $search_info['searchTime'] ?? 0;

		$result_count = count( $results );
		$message      = $result_count > 0
			? "SEARCH COMPLETE: Found {$result_count} results for \"{$query}\".\nSearch Results:"
			: "SEARCH COMPLETE: No results found for \"{$query}\".";

		return array(
			'success'   => true,
			'data'      => array(
				'message'         => $message,
				'query'           => $query,
				'results_count'   => $result_count,
				'total_available' => $total_results,
				'search_time'     => $search_time,
				'results'         => $results,
			),
			'tool_name' => 'google_search',
		);
	}

	/**
	 * Get Google Search tool definition.
	 * Called lazily when tool is first accessed to ensure translations are loaded.
	 *
	 * @return array Tool definition array
	 */
	public function getToolDefinition(): array {
		return array(
			'class'           => __CLASS__,
			'method'          => 'handle_tool_call',
			'description'     => 'Search the web using Google Custom Search and return 1-10 structured JSON results with titles, links, and snippets. Best for discovering external information when you don\'t have specific URLs. Use for current events, factual verification, or broad topic research. Returns complete web search data in JSON format with title, link, snippet for each result.',
			'requires_config' => true,
			'parameters'      => array(
				'query'         => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Search query for external web information. Returns JSON with "results" array containing web search results.',
				),
				'site_restrict' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Restrict search to specific domain (e.g., "wikipedia.org" for Wikipedia only)',
				),
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
	 * @param bool   $configured Current configuration status
	 * @param string $tool_id Tool identifier to check
	 * @return bool True if configured, false otherwise
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

	public function save_configuration( $tool_id, $config_data ) {
		if ( 'google_search' !== $tool_id ) {
			return;
		}

		$api_key          = sanitize_text_field( $config_data['api_key'] ?? '' );
		$search_engine_id = sanitize_text_field( $config_data['search_engine_id'] ?? '' );

		if ( empty( $api_key ) || empty( $search_engine_id ) ) {
			wp_send_json_error( array( 'message' => __( 'API Key and Search Engine ID are required', 'data-machine' ) ) );
			return;
		}

		$stored_config                  = get_site_option( 'datamachine_search_config', array() );
		$stored_config['google_search'] = array(
			'api_key'          => $api_key,
			'search_engine_id' => $search_engine_id,
		);

		if ( update_site_option( 'datamachine_search_config', $stored_config ) ) {
			wp_send_json_success(
				array(
					'message'    => __( 'Google Search configuration saved successfully', 'data-machine' ),
					'configured' => true,
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to save configuration', 'data-machine' ) ) );
		}
	}

	public function get_config_fields( $fields = array(), $tool_id = '' ) {
		if ( ! empty( $tool_id ) && 'google_search' !== $tool_id ) {
			return $fields;
		}

		return array(
			'api_key'          => array(
				'type'        => 'text',
				'label'       => __( 'Google Search API Key', 'data-machine' ),
				'placeholder' => __( 'Enter your Google Search API key', 'data-machine' ),
				'required'    => true,
				'description' => __( 'Get your API key from Google Cloud Console → APIs & Services → Credentials', 'data-machine' ),
			),
			'search_engine_id' => array(
				'type'        => 'text',
				'label'       => __( 'Custom Search Engine ID', 'data-machine' ),
				'placeholder' => __( 'Enter your Search Engine ID', 'data-machine' ),
				'required'    => true,
				'description' => __( 'Create a Custom Search Engine and copy the Search Engine ID (cx parameter)', 'data-machine' ),
			),
		);
	}
}

// Self-register the tool
new GoogleSearch();
