<?php
/**
 * Bing Webmaster Tools AI tool.
 *
 * @package DataMachineBusiness\Tools
 */

namespace DataMachineBusiness\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachineBusiness\Abilities\Analytics\BingWebmasterAbilities;

defined( 'ABSPATH' ) || exit;

class BingWebmaster extends BaseTool {

	public function __construct() {
		$this->registerConfigurationHandlers( 'bing_webmaster' );
		$this->registerTool( 'bing_webmaster', array( $this, 'get_tool_definition' ), array( 'chat', 'pipeline' ), array( 'ability' => 'datamachine/bing-webmaster' ) );
	}

	/**
	 * Execute Bing Webmaster query by delegating to the ability.
	 *
	 * @param array $parameters Contains action and optional query args.
	 * @param array $tool_def   Tool definition.
	 * @return array<string,mixed> Tool result.
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_def;
		$ability = wp_get_ability( 'datamachine/bing-webmaster' );

		if ( ! $ability ) {
			return $this->buildErrorResponse(
				'Bing Webmaster ability not registered. Ensure Data Machine Business is active.',
				'bing_webmaster'
			);
		}

		$result = $ability->execute( $parameters );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'bing_webmaster' );
		}

		if ( ! empty( $result['error'] ) ) {
			return $this->buildErrorResponse( $result['error'], 'bing_webmaster' );
		}

		$result['tool_name'] = 'bing_webmaster';
		return $result;
	}

	/**
	 * Get tool definition for AI agents.
	 *
	 * @return array<string,mixed> Tool definition array.
	 */
	public function get_tool_definition(): array {
		return array(
			'class'           => __CLASS__,
			'method'          => 'handle_tool_call',
			'description'     => 'Fetch search analytics data from Bing Webmaster Tools. Returns query performance stats, traffic rankings, page-level stats, or crawl information for the configured site. Use to analyze search visibility, top queries, and crawl health on Bing.',
			'requires_config' => true,
			'parameters'      => array(
				'type'       => 'object',
				'properties' => array(
					'action'   => array(
						'type'        => 'string',
						'description' => 'Analytics action to perform: query_stats (search query performance), traffic_stats (rank and traffic data), page_stats (per-page metrics), crawl_stats (crawl information).',
					),
					'site_url' => array(
						'type'        => 'string',
						'description' => 'Site URL to query. Defaults to the configured site URL.',
					),
					'limit'    => array(
						'type'        => 'integer',
						'description' => 'Maximum number of results to return (default: 20).',
					),
				),
				'required'   => array( 'action' ),
			),
		);
	}

	public static function is_configured(): bool {
		return BingWebmasterAbilities::is_configured();
	}

	/**
	 * Get stored configuration.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_config(): array {
		return BingWebmasterAbilities::get_config();
	}

	public function check_configuration( $configured, $tool_id ) {
		if ( 'bing_webmaster' !== $tool_id ) {
			return $configured;
		}

		return self::is_configured();
	}

	public function get_configuration( $config, $tool_id ) {
		if ( 'bing_webmaster' !== $tool_id ) {
			return $config;
		}

		return self::get_config();
	}

	protected function get_config_option_name(): string {
		return BingWebmasterAbilities::CONFIG_OPTION;
	}

	protected function validate_and_build_config( array $config_data ): array {
		$api_key = sanitize_text_field( $config_data['api_key'] ?? '' );

		if ( empty( $api_key ) ) {
			return array( 'error' => __( 'Bing Webmaster API key is required', 'data-machine-business' ) );
		}

		return array(
			'config'  => array(
				'api_key'  => $api_key,
				'site_url' => esc_url_raw( $config_data['site_url'] ?? '' ),
			),
			'message' => __( 'Bing Webmaster Tools configuration saved successfully', 'data-machine-business' ),
		);
	}

	public function get_config_fields( $fields = array(), $tool_id = '' ) {
		if ( ! empty( $tool_id ) && 'bing_webmaster' !== $tool_id ) {
			return $fields;
		}

		return array(
			'api_key'  => array(
				'type'        => 'password',
				'label'       => __( 'Bing Webmaster API Key', 'data-machine-business' ),
				'placeholder' => __( 'Enter your Bing Webmaster API key', 'data-machine-business' ),
				'required'    => true,
				'description' => __( 'Get your API key from Bing Webmaster Tools -> Settings -> API Access', 'data-machine-business' ),
			),
			'site_url' => array(
				'type'        => 'text',
				'label'       => __( 'Site URL', 'data-machine-business' ),
				'placeholder' => 'https://yoursite.com',
				'required'    => false,
				'description' => __( 'The site URL registered in Bing Webmaster Tools. Defaults to your WordPress site URL.', 'data-machine-business' ),
			),
		);
	}
}
