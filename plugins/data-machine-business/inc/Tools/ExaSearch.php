<?php
/**
 * Exa web search agent tool and site configuration.
 *
 * @package DataMachineBusiness\Tools
 */

namespace DataMachineBusiness\Tools;

use DataMachineBusiness\Abilities\Search\ExaSearchAbility;
use DataMachine\Engine\AI\Tools\BaseTool;

defined( 'ABSPATH' ) || exit;

class ExaSearch extends BaseTool {

	public function __construct() {
		$this->registerConfigurationHandlers( 'exa_search' );
		$this->registerTool( 'exa_search', array( $this, 'getToolDefinition' ), array( 'chat', 'pipeline' ), array( 'access_level' => 'admin' ) );
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$result = ExaSearchAbility::execute( $parameters );
		if ( is_wp_error( $result ) ) {
			return array(
				'success'   => false,
				'error'     => $result->get_error_message(),
				'tool_name' => 'exa_search',
			);
		}

		$count             = count( $result['results'] );
		$result['message'] = "SEARCH COMPLETE: Found {$count} results for \"{$result['query']}\".";
		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'exa_search',
		);
	}

	public function getToolDefinition(): array {
		return array(
			'class'           => __CLASS__,
			'method'          => 'handle_tool_call',
			'description'     => 'Search the web using Exa and return up to 25 structured results with titles, URLs, publication dates, authors and optional text. Use for current information, verification and research when you do not have a URL yet.',
			'requires_config' => true,
			'parameters'      => ExaSearchAbility::input_schema(),
		);
	}

	public static function is_configured(): bool {
		return ! empty( self::get_config()['api_key'] );
	}

	public static function get_config(): array {
		$config = get_site_option( 'datamachine_search_config', array() );
		return $config['exa_search'] ?? array();
	}

	public function check_configuration( $configured, $tool_id ) {
		return 'exa_search' === $tool_id ? self::is_configured() : $configured;
	}

	public function get_configuration( $config, $tool_id ) {
		return 'exa_search' === $tool_id ? self::get_config() : $config;
	}

	/** Save a nested provider key without overwriting other search providers. */
	public function save_configuration( $result, $tool_id, $config_data ) {
		if ( 'exa_search' !== $tool_id ) {
			return $result;
		}
		$api_key = sanitize_text_field( $config_data['api_key'] ?? '' );
		if ( empty( $api_key ) ) {
			return array(
				'success' => false,
				'error'   => __( 'API Key is required', 'data-machine-business' ),
			);
		}

		$stored_config               = get_site_option( 'datamachine_search_config', array() );
		$stored_config['exa_search'] = array( 'api_key' => $api_key );
		if ( update_site_option( 'datamachine_search_config', $stored_config ) ) {
			return array(
				'success' => true,
				'message' => __( 'Exa Search configuration saved successfully', 'data-machine-business' ),
			);
		}
		return array(
			'success' => false,
			'error'   => __( 'Failed to save configuration', 'data-machine-business' ),
		);
	}

	public function get_config_fields( $fields = array(), $tool_id = '' ) {
		if ( ! empty( $tool_id ) && 'exa_search' !== $tool_id ) {
			return $fields;
		}
		return array(
			'api_key' => array(
				'type'        => 'text',
				'label'       => __( 'Exa API Key', 'data-machine-business' ),
				'placeholder' => __( 'Enter your Exa API key', 'data-machine-business' ),
				'required'    => true,
				'description' => __( 'Get your API key from https://dashboard.exa.ai/api-keys', 'data-machine-business' ),
			),
		);
	}
}
